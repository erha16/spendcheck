<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveOperationalAdvanceRequest;
use App\Http\Requests\CancelOperationalAdvanceRequest;
use App\Http\Requests\ReviewOperationalAdvanceRequest;
use App\Http\Requests\SaveOperationalAdvanceRequest;
use App\Models\Activity;
use App\Models\AdvanceAttachment;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\User;
use App\Services\OperationalAdvanceWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalAdvanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        Gate::authorize($user->can('operational_advances.view') ? 'operational_advances.view' : 'operational_advances.view_own');

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(fn (OperationalAdvanceStatus $status): string => $status->value, OperationalAdvanceStatus::cases()))],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $advances = OperationalAdvance::query()
            ->visibleTo($user)
            ->with(['requester:id,name', 'responsibleUser:id,name', 'plannedCashAccount:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('number', 'like', '%'.$search.'%')->orWhere('purpose', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('operational-advances.index', [
            'advances' => $advances,
            'filters' => $filters,
            'statuses' => OperationalAdvanceStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('operational_advances.create');

        return view('operational-advances.form', $this->formData($request->user()) + [
            'advance' => new OperationalAdvance,
            'isEditing' => false,
        ]);
    }

    public function store(SaveOperationalAdvanceRequest $request, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        $advance = $workflow->create($request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Draft bon operasional berhasil dibuat.');
    }

    public function show(Request $request, OperationalAdvance $operationalAdvance): View
    {
        $this->authorizeView($request->user(), $operationalAdvance);
        $operationalAdvance->load(['requester:id,name', 'responsibleUser:id,name', 'creator:id,name', 'approver:id,name', 'plannedCashAccount:id,name', 'activity:id,name', 'attachments.uploader:id,name']);
        $auditLogs = AuditLog::query()->with('user')->where('subject_type', $operationalAdvance->getMorphClass())
            ->where('subject_id', $operationalAdvance->id)->latest('created_at')->get();

        return view('operational-advances.show', [
            'advance' => $operationalAdvance,
            'auditLogs' => $auditLogs,
            'canEdit' => in_array($operationalAdvance->status, ['draft', 'revision_requested'], true)
                && ($request->user()->can('operational_advances.manage_all') || $operationalAdvance->isParticipant($request->user())),
            'canReview' => $operationalAdvance->status === OperationalAdvanceStatus::Submitted->value
                && $request->user()->can('operational_advances.review')
                && ! $operationalAdvance->isParticipant($request->user()),
            'canCancel' => in_array($operationalAdvance->status, ['draft', 'revision_requested', 'approved'], true)
                && ($request->user()->can('operational_advances.cancel')
                    || ($request->user()->can('operational_advances.cancel_own') && $operationalAdvance->isParticipant($request->user()))),
        ]);
    }

    public function edit(Request $request, OperationalAdvance $operationalAdvance): View
    {
        $this->authorizeView($request->user(), $operationalAdvance);
        abort_unless(in_array($operationalAdvance->status, ['draft', 'revision_requested'], true)
            && ($request->user()->can('operational_advances.manage_all') || $operationalAdvance->isParticipant($request->user())), 404);

        return view('operational-advances.form', $this->formData($request->user()) + [
            'advance' => $operationalAdvance->load('attachments'),
            'isEditing' => true,
        ]);
    }

    public function update(SaveOperationalAdvanceRequest $request, OperationalAdvance $operationalAdvance, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        $advance = $workflow->update($operationalAdvance, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Draft bon operasional berhasil diperbarui.');
    }

    public function submit(Request $request, OperationalAdvance $operationalAdvance, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('operational_advances.submit');
        $advance = $workflow->submit($operationalAdvance, $request->user());

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Bon berhasil diajukan untuk pemeriksaan.');
    }

    public function review(ReviewOperationalAdvanceRequest $request, OperationalAdvance $operationalAdvance, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        $advance = $workflow->review($operationalAdvance, $request->user(), $request->validated('decision'), $request->validated('reason'));

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Keputusan pemeriksaan berhasil dicatat.');
    }

    public function approve(ApproveOperationalAdvanceRequest $request, OperationalAdvance $operationalAdvance, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        $advance = $workflow->approve($operationalAdvance, $request->user(), $request->validated('approved_amount'));

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Pengajuan bon disetujui. Pencairan belum dilakukan.');
    }

    public function cancel(CancelOperationalAdvanceRequest $request, OperationalAdvance $operationalAdvance, OperationalAdvanceWorkflow $workflow): RedirectResponse
    {
        $advance = $workflow->cancel($operationalAdvance, $request->user(), $request->validated('reason'));

        return redirect()->route('operational-advances.show', $advance)->with('status', 'Bon operasional dibatalkan sebelum pencairan.');
    }

    public function attachment(Request $request, OperationalAdvance $operationalAdvance, AdvanceAttachment $attachment, string $mode = 'download'): Response|StreamedResponse
    {
        $this->authorizeView($request->user(), $operationalAdvance);
        abort_unless($attachment->operational_advance_id === $operationalAdvance->id, 404);

        $storage = Storage::disk($attachment->disk);
        abort_unless($storage->exists($attachment->path), 404);
        $disposition = $mode === 'preview' && in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'application/pdf'], true)
            ? 'inline'
            : 'attachment';

        return $storage->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    private function authorizeView(User $user, OperationalAdvance $advance): void
    {
        if ($user->can('operational_advances.view')) {
            Gate::authorize('operational_advances.view');

            return;
        }

        Gate::authorize('operational_advances.view_own');
        abort_unless($advance->isParticipant($user), 404);
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        return [
            'users' => $user->hasRole('keuangan_sarpras') ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'cashAccounts' => CashAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'activities' => Activity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
