<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelTransactionRequest;
use App\Http\Requests\CorrectTransactionRequest;
use App\Http\Requests\PostTransactionRequest;
use App\Http\Requests\ReviewTransactionRequest;
use App\Http\Requests\SaveTransactionRequest;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\FundSource;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\TransactionWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('transactions.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:income,expense,transfer'],
            'status' => ['nullable', 'in:draft,submitted,revision_requested,rejected,posted,reversed,cancelled'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $transactions = $this->visibleTransactions($request->user())
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->where('number', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
            ->when(($filters['responsible_user_id'] ?? null) && ! $request->user()->hasRole('petugas_sarpras'), fn (Builder $query) => $query->where('responsible_user_id', $filters['responsible_user_id']))
            ->with(['responsibleUser:id,name', 'creator:id,name'])
            ->latest('occurred_at')
            ->paginate(15)
            ->withQueryString();

        $responsibleUsers = $request->user()->hasRole('petugas_sarpras')
            ? collect()
            : User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('transactions.index', compact('transactions', 'filters', 'responsibleUsers'));
    }

    public function create(Request $request, ?string $type = null): View|RedirectResponse
    {
        if ($type !== null && ! in_array($type, ['income', 'expense', 'transfer'], true)) {
            abort(404);
        }
        if ($type !== null) {
            Gate::authorize('transactions.'.$type.'.create');
        }

        $viewData = [...$this->formData($request->user()), 'transaction' => new Transaction, 'type' => $type];

        return view($type === 'transfer' ? 'transactions.transfer-form' : 'transactions.form', $viewData);
    }

    public function store(SaveTransactionRequest $request, TransactionWorkflow $workflow): RedirectResponse
    {
        $transaction = $workflow->create($request->user(), $request->validated());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Draft transaksi berhasil disimpan.');
    }

    public function show(Request $request, Transaction $transaction): View
    {
        $this->authorizeView($request->user(), $transaction);
        $transaction->load([
            'responsibleUser:id,name', 'creator:id,name', 'poster:id,name', 'reviewer:id,name', 'rejector:id,name',
            'canceller:id,name', 'negativeBalanceOverrideBy:id,name', 'paymentMethod', 'sourceCashAccount',
            'destinationCashAccount', 'vendor', 'fundSource', 'items.category', 'items.subcategory', 'items.activity',
            'attachments.uploader:id,name', 'movements.cashAccount', 'reversalOf', 'replacementRevision.corrector:id,name',
            'replacementRevision.replacement', 'revisions.corrector:id,name', 'revisions.replacement',
        ]);
        $auditLogs = AuditLog::query()
            ->with('user:id,name')
            ->where('subject_type', $transaction->getMorphClass())
            ->where('subject_id', $transaction->id)
            ->latest('created_at')
            ->get();

        return view('transactions.show', compact('transaction', 'auditLogs'));
    }

    public function createCorrection(Request $request, Transaction $transaction): View
    {
        $this->authorizeView($request->user(), $transaction);
        abort_unless($request->user()->hasRole('keuangan_sarpras'), 403);
        Gate::authorize('transactions.correct');
        abort_unless($transaction->status === 'posted' && ! $transaction->replacements()->exists(), 404);
        $transaction->load(['items', 'attachments']);
        $viewData = [...$this->formData($request->user()), 'transaction' => $transaction, 'type' => $transaction->type, 'correctionOriginal' => $transaction];

        return view($transaction->type === 'transfer' ? 'transactions.transfer-form' : 'transactions.form', $viewData);
    }

    public function storeCorrection(CorrectTransactionRequest $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeView($request->user(), $transaction);
        $validated = $request->validated();
        $replacement = $workflow->correct($transaction, $request->user(), $validated, $validated['correction_reason'],
            (bool) ($validated['allow_negative'] ?? false), $validated['override_reason'] ?? null);

        return redirect()->route('transactions.show', $replacement)->with('status', 'Koreksi berhasil dicatat dan transaksi pengganti telah diposting.');
    }

    public function edit(Request $request, Transaction $transaction): View
    {
        $this->authorizeEdit($request->user(), $transaction);

        $viewData = [...$this->formData($request->user()), 'transaction' => $transaction->load('items', 'attachments'), 'type' => $transaction->type];

        return view($transaction->type === 'transfer' ? 'transactions.transfer-form' : 'transactions.form', $viewData);
    }

    public function update(SaveTransactionRequest $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        $workflow->update($transaction, $request->user(), $request->validated());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Draft transaksi berhasil diperbarui.');
    }

    public function submit(Request $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('transactions.'.$transaction->type.'.create');
        $workflow->submit($transaction, $request->user());

        return back()->with('status', 'Transaksi berhasil diajukan untuk pemeriksaan.');
    }

    public function reviewQueue(): View
    {
        Gate::authorize('transactions.review');
        $transactions = Transaction::query()->where('status', 'submitted')->with(['creator:id,name', 'responsibleUser:id,name'])
            ->latest('submitted_at')->paginate(15);

        return view('transactions.review-queue', compact('transactions'));
    }

    public function review(ReviewTransactionRequest $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validated();
        $workflow->review($transaction, $request->user(), $validated['decision'], $validated['reason']);

        return redirect()->route('transactions.show', $transaction)->with('status', $validated['decision'] === 'revision'
            ? 'Permintaan revisi berhasil dikirim.'
            : 'Transaksi berhasil ditolak.');
    }

    public function post(PostTransactionRequest $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validated();
        $workflow->post($transaction, $request->user(), (bool) ($validated['allow_negative'] ?? false), $validated['override_reason'] ?? null);

        return redirect()->route('transactions.show', $transaction)->with('status', 'Transaksi berhasil diposting ke cash ledger.');
    }

    public function cancel(CancelTransactionRequest $request, Transaction $transaction, TransactionWorkflow $workflow): RedirectResponse
    {
        $workflow->cancel($transaction, $request->user(), $request->validated('reason'));

        return redirect()->route('transactions.show', $transaction)->with('status', 'Draft transaksi berhasil dibatalkan.');
    }

    public function attachment(Request $request, Transaction $transaction, TransactionAttachment $attachment, string $mode = 'download'): Response|StreamedResponse
    {
        $this->authorizeView($request->user(), $transaction);
        abort_unless($attachment->transaction_id === $transaction->id, 404);
        $storage = Storage::disk($attachment->disk);
        abort_unless($storage->exists($attachment->path), 404);

        $disposition = 'attachment';
        if ($mode === 'preview' && in_array($attachment->mime_type, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            $disposition = 'inline';
        }

        return $storage->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    private function authorizeView(User $user, Transaction $transaction): void
    {
        Gate::authorize('transactions.view');
        if ($user->hasRole('petugas_sarpras') && $transaction->created_by !== $user->id) {
            abort(404);
        }
    }

    private function authorizeEdit(User $user, Transaction $transaction): void
    {
        Gate::authorize('transactions.view');
        if (! in_array($transaction->status, ['draft', 'revision_requested'], true)) {
            abort(404);
        }
        if ($transaction->status === 'revision_requested' && $transaction->created_by !== $user->id) {
            abort(404);
        }
        if ($transaction->created_by !== $user->id && ! $user->can('transactions.manage')) {
            abort(404);
        }
        if ($user->hasRole('petugas_sarpras') && $transaction->created_by !== $user->id) {
            abort(404);
        }
    }

    /** @return Builder<Transaction> */
    private function visibleTransactions(User $user): Builder
    {
        return Transaction::query()->when($user->hasRole('petugas_sarpras'), fn (Builder $query) => $query->where('created_by', $user->id));
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        $accounts = CashAccount::query()->when($user->hasRole('petugas_sarpras'), fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')->get(['id', 'name', 'is_active']);

        return [
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'cashAccounts' => $accounts,
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'fundSources' => FundSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->where('is_active', true)->with(['subcategories' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('name')])->orderBy('name')->get(['id', 'name']),
            'activities' => Activity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
