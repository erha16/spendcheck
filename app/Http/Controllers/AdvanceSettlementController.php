<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateAdvanceSettlementAdjustmentRequest;
use App\Http\Requests\ReviewAdvanceSettlementAdjustmentRequest;
use App\Http\Requests\ReviewAdvanceSettlementItemRequest;
use App\Http\Requests\ReviseAdvanceSettlementItemRequest;
use App\Http\Requests\SubmitAdvanceSettlementRequest;
use App\Models\Activity;
use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementAttachment;
use App\Models\AdvanceSettlementItem;
use App\Models\Category;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\User;
use App\Services\AdvanceSettlementWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvanceSettlementController extends Controller
{
    public function create(Request $request, OperationalAdvance $operationalAdvance): View
    {
        $this->authorizeAdvanceView($request->user(), $operationalAdvance);
        abort_unless($this->canSubmit($request->user(), $operationalAdvance)
            && in_array($operationalAdvance->status, [
                OperationalAdvanceStatus::Disbursed->value,
                OperationalAdvanceStatus::PartiallyAccounted->value,
            ], true), 404);

        return view('operational-advances.settlements.create', [
            'advance' => $operationalAdvance,
            'categories' => Category::query()->where('is_active', true)->with(['subcategories' => fn ($query) => $query->where('is_active', true)])->orderBy('name')->get(),
            'activities' => Activity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'submissionKey' => (string) Str::uuid7(),
        ]);
    }

    public function store(
        SubmitAdvanceSettlementRequest $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        $workflow->submitBatch($operationalAdvance, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Batch pertanggungjawaban berhasil diajukan untuk pemeriksaan.');
    }

    public function createRevision(Request $request, OperationalAdvance $operationalAdvance, AdvanceSettlementItem $item): View
    {
        $this->authorizeAdvanceView($request->user(), $operationalAdvance);
        abort_unless($this->canSubmit($request->user(), $operationalAdvance)
            && (int) $item->settlement()->value('operational_advance_id') === (int) $operationalAdvance->id
            && $item->review_status === 'revision_requested'
            && ! $item->replacement()->exists(), 404);

        return view('operational-advances.settlements.revise', [
            'advance' => $operationalAdvance,
            'item' => $item->load('category', 'subcategory', 'activity'),
            'categories' => Category::query()->where('is_active', true)->with(['subcategories' => fn ($query) => $query->where('is_active', true)])->orderBy('name')->get(),
            'activities' => Activity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function revise(
        ReviseAdvanceSettlementItemRequest $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlementItem $item,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        $workflow->reviseItem($operationalAdvance, $item, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Item revisi disimpan sebagai versi baru dan diajukan ulang.');
    }

    public function reviewItem(
        ReviewAdvanceSettlementItemRequest $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlement $advanceSettlement,
        AdvanceSettlementItem $item,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        $workflow->reviewItem(
            $operationalAdvance,
            $advanceSettlement,
            $item,
            $request->user(),
            $request->validated('decision'),
            $request->validated('reason'),
        );

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Keputusan pemeriksaan item berhasil dicatat.');
    }

    public function rejectBatch(
        Request $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlement $advanceSettlement,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        Gate::authorize('operational_advances.settle');
        abort_unless($request->user()->hasRole('keuangan_sarpras'), 403);
        $values = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:5000']]);
        $workflow->rejectOpenItems($operationalAdvance, $advanceSettlement, $request->user(), $values['reason']);

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Seluruh item yang masih terbuka pada batch ditolak dengan alasan yang tercatat.');
    }

    public function requestAdjustment(
        CreateAdvanceSettlementAdjustmentRequest $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlementItem $item,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        $workflow->requestAdjustment($operationalAdvance, $item, $request->user(), $request->validated());

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Usulan koreksi beban dicatat dan menunggu pemeriksaan Keuangan Sarpras.');
    }

    public function reviewAdjustment(
        ReviewAdvanceSettlementAdjustmentRequest $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlementItem $item,
        string $adjustmentGroupId,
        AdvanceSettlementWorkflow $workflow,
    ): RedirectResponse {
        $workflow->reviewAdjustmentGroup(
            $operationalAdvance,
            $item,
            $adjustmentGroupId,
            $request->user(),
            $request->validated('decision'),
            $request->validated('review_reason'),
        );

        return redirect()->route('operational-advances.show', $operationalAdvance)
            ->with('status', 'Keputusan koreksi item berhasil dicatat.');
    }

    public function attachment(
        Request $request,
        OperationalAdvance $operationalAdvance,
        AdvanceSettlement $advanceSettlement,
        AdvanceSettlementItem $item,
        AdvanceSettlementAttachment $attachment,
        string $mode = 'download',
    ): Response|StreamedResponse {
        $this->authorizeAdvanceView($request->user(), $operationalAdvance);
        abort_unless((int) $advanceSettlement->operational_advance_id === (int) $operationalAdvance->id
            && (int) $item->advance_settlement_id === (int) $advanceSettlement->id
            && (int) $attachment->advance_settlement_item_id === (int) $item->id, 404);

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

    private function authorizeAdvanceView(User $user, OperationalAdvance $advance): void
    {
        if ($user->can('operational_advances.view')) {
            Gate::authorize('operational_advances.view');

            return;
        }

        Gate::authorize('operational_advances.view_own');
        abort_unless($advance->isParticipant($user), 404);
    }

    private function canSubmit(User $user, OperationalAdvance $advance): bool
    {
        return ((int) $advance->responsible_user_id === (int) $user->id && $user->can('operational_advances.submit'))
            || ($user->hasRole('keuangan_sarpras') && $user->can('operational_advances.settle'));
    }
}
