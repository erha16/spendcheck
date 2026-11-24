<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionRevision;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class TransactionWorkflow
{
    /** @var list<string> */
    private array $storedPaths = [];

    public function __construct(private AuditLogger $auditLogger, private TransactionNumberGenerator $numberGenerator) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Transaction
    {
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $data): Transaction {
                [$attributes, $items] = $this->prepareData($data);
                $transaction = new Transaction;
                $transaction->forceFill([...$attributes, 'created_by' => $actor->id, 'status' => 'draft'])->save();
                $this->replaceItems($transaction, $items);
                $this->storeAttachments($transaction, $data['attachments'] ?? [], $actor);
                $this->auditLogger->record('transaction_created', 'transactions', 'Draft transaksi dibuat.', $transaction, null, $this->snapshot($transaction));

                return $transaction;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Transaction $transaction, User $actor, array $data): Transaction
    {
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($transaction, $actor, $data): Transaction {
                $lockedTransaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
                $this->assertEditable($lockedTransaction, $actor);
                $oldValues = $this->snapshot($lockedTransaction);
                [$attributes, $items] = $this->prepareData($data);

                if ($lockedTransaction->occurred_at->format('Ym') !== $attributes['occurred_at']->format('Ym')) {
                    $attributes['number'] = $this->numberGenerator->next($lockedTransaction->type, $attributes['occurred_at']);
                }

                $lockedTransaction->forceFill($attributes)->save();
                $this->replaceItems($lockedTransaction, $items);
                $this->storeAttachments($lockedTransaction, $data['attachments'] ?? [], $actor);

                $this->auditLogger->record('transaction_updated', 'transactions', 'Draft transaksi diperbarui.', $lockedTransaction, $oldValues, [
                    ...$this->snapshot($lockedTransaction),
                    'change_reason' => $data['change_reason'] ?? null,
                ]);

                return $lockedTransaction;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    public function submit(Transaction $transaction, User $actor): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor): Transaction {
            $lockedTransaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($lockedTransaction->created_by !== $actor->id || ! in_array($lockedTransaction->status, ['draft', 'revision_requested'], true)) {
                throw ValidationException::withMessages(['transaction' => 'Transaksi ini tidak dapat diajukan oleh Anda.']);
            }

            $this->validateForSubmission($lockedTransaction);
            $oldValues = ['status' => $lockedTransaction->status, 'revision_reason' => $lockedTransaction->revision_reason];
            $lockedTransaction->forceFill([
                'status' => 'submitted',
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'revision_reason' => null,
            ])->save();
            $this->auditLogger->record('transaction_submitted', 'transactions', 'Transaksi diajukan untuk pemeriksaan.', $lockedTransaction, $oldValues, ['status' => 'submitted']);

            return $lockedTransaction;
        });
    }

    public function review(Transaction $transaction, User $reviewer, string $decision, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $reviewer, $decision, $reason): Transaction {
            $lockedTransaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($lockedTransaction->status !== 'submitted') {
                throw ValidationException::withMessages(['transaction' => 'Hanya transaksi yang diajukan yang dapat diperiksa.']);
            }

            $oldValues = ['status' => $lockedTransaction->status];
            $attributes = ['reviewed_by' => $reviewer->id, 'reviewed_at' => now()];
            if ($decision === 'revision') {
                $attributes += ['status' => 'revision_requested', 'revision_reason' => $reason];
                $action = 'transaction_revision_requested';
                $description = 'Perbaikan transaksi diminta.';
            } else {
                $attributes += ['status' => 'rejected', 'rejected_by' => $reviewer->id, 'rejected_at' => now(), 'rejection_reason' => $reason];
                $action = 'transaction_rejected';
                $description = 'Transaksi ditolak.';
            }

            $lockedTransaction->forceFill($attributes)->save();
            $this->auditLogger->record($action, 'transactions', $description, $lockedTransaction, $oldValues, ['status' => $lockedTransaction->status, 'reason' => $reason]);

            return $lockedTransaction;
        });
    }

    public function cancel(Transaction $transaction, User $actor, string $reason): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor, $reason): Transaction {
            $lockedTransaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if ($lockedTransaction->status !== 'draft') {
                throw ValidationException::withMessages(['transaction' => 'Hanya draft yang dapat dibatalkan.']);
            }

            $canCancelAny = $actor->can('transactions.cancel');
            if (! $canCancelAny && ($lockedTransaction->created_by !== $actor->id || ! $actor->can('transactions.expense.create'))) {
                throw ValidationException::withMessages(['transaction' => 'Anda tidak dapat membatalkan transaksi ini.']);
            }

            $oldValues = ['status' => $lockedTransaction->status];
            $lockedTransaction->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $this->auditLogger->record('transaction_cancelled', 'transactions', 'Draft transaksi dibatalkan.', $lockedTransaction, $oldValues, [
                'status' => 'cancelled', 'reason' => $reason,
            ]);

            return $lockedTransaction;
        });
    }

    public function post(Transaction $transaction, User $actor, bool $allowNegative = false, ?string $overrideReason = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $actor, $allowNegative, $overrideReason): Transaction {
            $lockedTransaction = Transaction::query()->with(['items', 'attachments'])->lockForUpdate()->findOrFail($transaction->id);
            if ($lockedTransaction->status !== 'submitted') {
                throw ValidationException::withMessages(['transaction' => 'Transaksi hanya dapat diposting setelah diajukan.']);
            }
            if ($lockedTransaction->movements()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Transaksi ini sudah memiliki movement ledger.']);
            }

            $this->validateForPosting($lockedTransaction, $actor);
            $total = BigDecimal::of($lockedTransaction->total_amount)->toScale(2);
            $shortfall = BigDecimal::zero()->toScale(2);
            $cashAccount = null;
            $transferSource = null;
            $transferDestination = null;

            if ($lockedTransaction->type === 'transfer') {
                $accounts = $this->lockCashAccounts([
                    $lockedTransaction->source_cash_account_id,
                    $lockedTransaction->destination_cash_account_id,
                ]);
                $transferSource = $accounts[$lockedTransaction->source_cash_account_id];
                $transferDestination = $accounts[$lockedTransaction->destination_cash_account_id];
                $sourceBalance = BigDecimal::of($transferSource->ledgerBalance())->toScale(2);

                if (! $transferDestination->is_active) {
                    throw ValidationException::withMessages(['destination_cash_account_id' => 'Akun tujuan harus aktif saat transfer diposting.']);
                }
                if (! $transferSource->is_active && ! $actor->can('transactions.transfer.create')) {
                    throw ValidationException::withMessages(['source_cash_account_id' => 'Akun sumber nonaktif hanya dapat digunakan Keuangan Sarpras untuk penyelesaian saldo.']);
                }
                if ($sourceBalance->isLessThan($total)) {
                    throw ValidationException::withMessages(['source_cash_account_id' => 'Saldo akun sumber tidak mencukupi untuk transfer.']);
                }
            } else {
                $accountId = $lockedTransaction->type === 'expense'
                    ? $lockedTransaction->source_cash_account_id
                    : $lockedTransaction->destination_cash_account_id;
                $cashAccount = CashAccount::query()->lockForUpdate()->findOrFail($accountId);
                $balance = BigDecimal::of($cashAccount->ledgerBalance())->toScale(2);
                $shortfall = $lockedTransaction->type === 'expense' && $balance->isLessThan($total)
                    ? $total->minus($balance)->toScale(2)
                    : BigDecimal::zero()->toScale(2);

                if (! $cashAccount->is_active && ($lockedTransaction->type !== 'expense' || $shortfall->isGreaterThan(0))) {
                    throw ValidationException::withMessages(['source_cash_account_id' => 'Akun kas nonaktif hanya dapat digunakan untuk menyelesaikan saldo tersisa.']);
                }
            }
            if ($shortfall->isGreaterThan(0) && (! $allowNegative || ! $actor->can('transactions.negative_balance.override') || blank($overrideReason))) {
                throw ValidationException::withMessages(['allow_negative' => 'Saldo tidak mencukupi. Override saldo negatif memerlukan kewenangan dan alasan.']);
            }

            $oldValues = ['status' => $lockedTransaction->status, 'total_amount' => $lockedTransaction->total_amount];
            $lockedTransaction->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'negative_balance_override' => $shortfall->isGreaterThan(0),
                'negative_balance_shortfall' => (string) $shortfall,
                'negative_balance_override_reason' => $shortfall->isGreaterThan(0) ? $overrideReason : null,
                'negative_balance_override_by' => $shortfall->isGreaterThan(0) ? $actor->id : null,
                'negative_balance_override_at' => $shortfall->isGreaterThan(0) ? now() : null,
            ])->save();

            $this->createPostingMovements($lockedTransaction, $actor);

            $this->auditLogger->record('transaction_posted', 'transactions', 'Transaksi diposting ke cash ledger.', $lockedTransaction, $oldValues, [
                'status' => 'posted',
                'cash_account_id' => $cashAccount?->id,
                'source_cash_account_id' => $transferSource?->id,
                'destination_cash_account_id' => $transferDestination?->id,
                'total_amount' => $lockedTransaction->total_amount,
                'negative_balance_override' => $lockedTransaction->negative_balance_override,
                'negative_balance_shortfall' => $lockedTransaction->negative_balance_shortfall,
                'override_reason' => $lockedTransaction->negative_balance_override_reason,
            ]);

            return $lockedTransaction->fresh(['movements', 'poster']);
        });
    }

    /** @param array<string, mixed> $data */
    public function correct(Transaction $original, User $actor, array $data, string $reason, bool $allowNegative = false, ?string $overrideReason = null): Transaction
    {
        if (! $actor->hasRole('keuangan_sarpras') || ! $actor->can('transactions.correct')) {
            throw ValidationException::withMessages(['transaction' => 'Anda tidak memiliki akses untuk mengoreksi transaksi.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi.']);
        }

        $this->storedPaths = [];
        try {
            return DB::transaction(function () use ($original, $actor, $data, $reason, $allowNegative, $overrideReason): Transaction {
                $lockedOriginal = Transaction::query()->with('items')->lockForUpdate()->findOrFail($original->id);
                if ($lockedOriginal->status !== 'posted' || $lockedOriginal->type !== ($data['type'] ?? null)
                    || $lockedOriginal->replacements()->exists()) {
                    throw ValidationException::withMessages(['transaction' => 'Transaksi ini tidak dapat dikoreksi kembali atau datanya tidak sesuai.']);
                }

                $originalMovements = $lockedOriginal->movements()->orderBy('id')->lockForUpdate()->get();
                $this->validateOriginalMovements($lockedOriginal, $originalMovements);
                [$attributes, $items] = $this->prepareData($data);
                $accountIds = $originalMovements->pluck('cash_account_id')->all();
                $accountIds = [...$accountIds, $attributes['source_cash_account_id'], $attributes['destination_cash_account_id']];
                $accounts = $this->lockCashAccounts(array_filter($accountIds, fn ($id): bool => $id !== null));
                $correctedAt = now();

                foreach ($originalMovements as $movement) {
                    CashMovement::create([
                        'cash_account_id' => $movement->cash_account_id,
                        'transaction_id' => $lockedOriginal->id,
                        'kind' => 'reversal',
                        'direction' => $movement->direction === 'in' ? 'out' : 'in',
                        'amount' => $movement->amount,
                        'idempotency_key' => 'transaction-reversal:'.$movement->public_id,
                        'occurred_at' => $movement->occurred_at,
                        'recorded_at' => $correctedAt,
                        'created_by' => $actor->id,
                        'reversal_of_id' => $movement->id,
                    ]);
                }

                $beforeValues = $this->snapshot($lockedOriginal);
                $lockedOriginal->forceFill(['status' => 'reversed'])->save();

                $replacement = new Transaction;
                $replacement->forceFill([
                    ...$attributes,
                    'created_by' => $actor->id,
                    'status' => 'draft',
                    'reversal_of_id' => $lockedOriginal->id,
                ])->save();
                $this->replaceItems($replacement, $items);
                $this->storeAttachments($replacement, $data['attachments'] ?? [], $actor);
                $replacement->load(['items', 'attachments']);
                $this->validateForPosting($replacement, $actor);

                $total = BigDecimal::of($replacement->total_amount)->toScale(2);
                $shortfall = BigDecimal::zero()->toScale(2);
                if ($replacement->type === 'transfer') {
                    $source = $accounts[$replacement->source_cash_account_id];
                    $destination = $accounts[$replacement->destination_cash_account_id];
                    if (! $destination->is_active) {
                        throw ValidationException::withMessages(['destination_cash_account_id' => 'Akun tujuan harus aktif saat transfer diposting.']);
                    }
                    if (BigDecimal::of($source->ledgerBalance())->toScale(2)->isLessThan($total)) {
                        throw ValidationException::withMessages(['source_cash_account_id' => 'Saldo akun sumber tidak mencukupi untuk transfer.']);
                    }
                } else {
                    $accountId = $replacement->type === 'expense' ? $replacement->source_cash_account_id : $replacement->destination_cash_account_id;
                    $account = $accounts[$accountId];
                    $balance = BigDecimal::of($account->ledgerBalance())->toScale(2);
                    $shortfall = $replacement->type === 'expense' && $balance->isLessThan($total)
                        ? $total->minus($balance)->toScale(2)
                        : BigDecimal::zero()->toScale(2);
                    if ($replacement->type === 'income' && ! $account->is_active) {
                        throw ValidationException::withMessages(['destination_cash_account_id' => 'Akun kas tujuan harus aktif untuk pemasukan.']);
                    }
                    if (! $account->is_active && ($replacement->type !== 'expense' || $shortfall->isGreaterThan(0))) {
                        throw ValidationException::withMessages(['source_cash_account_id' => 'Akun kas nonaktif hanya dapat digunakan untuk menyelesaikan saldo tersisa.']);
                    }
                    if ($shortfall->isGreaterThan(0) && (! $allowNegative || ! $actor->can('transactions.negative_balance.override') || blank($overrideReason))) {
                        throw ValidationException::withMessages(['allow_negative' => 'Saldo tidak mencukupi. Override saldo negatif memerlukan kewenangan dan alasan.']);
                    }
                }

                $replacement->forceFill([
                    'status' => 'posted',
                    'posted_by' => $actor->id,
                    'posted_at' => $correctedAt,
                    'negative_balance_override' => $shortfall->isGreaterThan(0),
                    'negative_balance_shortfall' => (string) $shortfall,
                    'negative_balance_override_reason' => $shortfall->isGreaterThan(0) ? $overrideReason : null,
                    'negative_balance_override_by' => $shortfall->isGreaterThan(0) ? $actor->id : null,
                    'negative_balance_override_at' => $shortfall->isGreaterThan(0) ? $correctedAt : null,
                ])->save();
                $this->createPostingMovements($replacement, $actor, $correctedAt);

                $afterValues = [...$this->snapshot($replacement), 'transaction_id' => $replacement->id, 'public_id' => $replacement->public_id];
                $revision = TransactionRevision::create([
                    'transaction_id' => $lockedOriginal->id,
                    'replacement_transaction_id' => $replacement->id,
                    'revision_number' => ((int) $lockedOriginal->revisions()->max('revision_number')) + 1,
                    'reason' => $reason,
                    'before_values' => $beforeValues,
                    'after_values' => $afterValues,
                    'corrected_by' => $actor->id,
                    'corrected_at' => $correctedAt,
                ]);
                $this->auditLogger->record('transaction_corrected', 'transactions', 'Transaksi dikoreksi dan transaksi pengganti diposting.', $lockedOriginal, $beforeValues, [
                    ...$afterValues, 'original_status' => 'reversed', 'reason' => $reason, 'revision_id' => $revision->id,
                    'replacement_transaction_id' => $replacement->id, 'replacement_number' => $replacement->number,
                    'corrected_at' => $correctedAt->toISOString(), 'negative_balance_override' => $replacement->negative_balance_override,
                    'negative_balance_shortfall' => $replacement->negative_balance_shortfall,
                    'override_reason' => $replacement->negative_balance_override_reason,
                ]);
                $this->auditLogger->record('transaction_replacement_posted', 'transactions', 'Transaksi pengganti diposting sebagai bagian dari koreksi.', $replacement,
                    ['status' => 'draft'], [...$afterValues, 'reason' => $reason, 'original_transaction_id' => $lockedOriginal->id,
                        'original_number' => $lockedOriginal->number, 'revision_id' => $revision->id]);

                return $replacement->fresh(['movements', 'poster', 'reversalOf']);
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    /** @param Collection<int, CashMovement> $movements */
    private function validateOriginalMovements(Transaction $transaction, Collection $movements): void
    {
        $expectedCount = $transaction->type === 'transfer' ? 2 : 1;
        if ($movements->count() !== $expectedCount) {
            throw ValidationException::withMessages(['transaction' => 'Ledger transaksi asli tidak lengkap atau tidak konsisten.']);
        }
        if ($transaction->type === 'transfer' && $movements->pluck('transfer_leg')->sort()->values()->all() !== ['in', 'out']) {
            throw ValidationException::withMessages(['transaction' => 'Kedua movement transfer asli harus tersedia dan seimbang.']);
        }
        foreach ($movements as $movement) {
            $expectedDirection = match ($transaction->type) {
                'income' => 'in',
                'expense' => 'out',
                default => $movement->transfer_leg === 'out' ? 'out' : 'in',
            };
            $expectedAccountId = match ([$transaction->type, $expectedDirection]) {
                ['income', 'in'] => $transaction->destination_cash_account_id,
                ['expense', 'out'] => $transaction->source_cash_account_id,
                ['transfer', 'out'] => $transaction->source_cash_account_id,
                ['transfer', 'in'] => $transaction->destination_cash_account_id,
                default => null,
            };
            $expectedKind = match ([$transaction->type, $expectedDirection]) {
                ['transfer', 'out'] => 'transfer_out',
                ['transfer', 'in'] => 'transfer_in',
                default => $transaction->type,
            };
            if ($movement->kind !== $expectedKind || $movement->direction !== $expectedDirection
                || (int) $movement->cash_account_id !== (int) $expectedAccountId
                || ($transaction->type === 'transfer' && $movement->transfer_leg !== $expectedDirection)
                || BigDecimal::of($movement->amount)->compareTo(BigDecimal::of($transaction->total_amount)) !== 0
                || $movement->reversals()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Ledger transaksi asli sudah memiliki reversal atau tidak sesuai.']);
            }
        }
    }

    private function createPostingMovements(Transaction $transaction, User $actor, ?\DateTimeInterface $recordedAt = null): void
    {
        $recordedAt ??= now();
        if ($transaction->type === 'transfer') {
            foreach ([['out', 'transfer_out', $transaction->source_cash_account_id], ['in', 'transfer_in', $transaction->destination_cash_account_id]] as [$direction, $kind, $accountId]) {
                CashMovement::create([
                    'cash_account_id' => $accountId, 'transaction_id' => $transaction->id, 'kind' => $kind,
                    'direction' => $direction, 'transfer_leg' => $direction, 'amount' => $transaction->total_amount,
                    'idempotency_key' => 'transaction-transfer-'.$direction.':'.$transaction->public_id,
                    'occurred_at' => $transaction->occurred_at, 'recorded_at' => $recordedAt, 'created_by' => $actor->id,
                ]);
            }

            return;
        }
        $accountId = $transaction->type === 'expense' ? $transaction->source_cash_account_id : $transaction->destination_cash_account_id;
        CashMovement::create([
            'cash_account_id' => $accountId, 'transaction_id' => $transaction->id, 'kind' => $transaction->type,
            'direction' => $transaction->type === 'income' ? 'in' : 'out', 'amount' => $transaction->total_amount,
            'idempotency_key' => 'transaction-posting:'.$transaction->public_id,
            'occurred_at' => $transaction->occurred_at, 'recorded_at' => $recordedAt, 'created_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function prepareData(array $data): array
    {
        $type = $data['type'];
        $isExpense = $type === 'expense';
        $isIncome = $type === 'income';
        $isTransfer = $type === 'transfer';
        $items = [];
        $total = BigDecimal::zero();

        if ($isExpense || ($isIncome && ($data['income_mode'] ?? null) === 'items')) {
            foreach ($data['items'] as $item) {
                $quantity = BigDecimal::of($item['quantity'] ?? '1')->toScale(4, RoundingMode::HalfUp);
                $unitPrice = BigDecimal::of($item['unit_price'])->toScale(2, RoundingMode::Unnecessary);
                $subtotal = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp);
                $total = $total->plus($subtotal);
                $items[] = [
                    'description' => $item['description'],
                    'category_id' => $isExpense ? $item['category_id'] : null,
                    'subcategory_id' => $isExpense ? ($item['subcategory_id'] ?? null) : null,
                    'activity_id' => $isExpense ? ($item['activity_id'] ?? null) : null,
                    'quantity' => (string) $quantity,
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => (string) $unitPrice,
                    'subtotal' => (string) $subtotal,
                ];
            }
        } elseif ($isIncome) {
            $total = BigDecimal::of($data['income_amount'])->toScale(2, RoundingMode::Unnecessary);
        } else {
            $total = BigDecimal::of($data['transfer_amount'])->toScale(2, RoundingMode::Unnecessary);
        }

        $total = $total->toScale(2, RoundingMode::HalfUp);
        if ($total->isLessThanOrEqualTo(0) || strlen(explode('.', (string) $total)[0]) > 17) {
            throw ValidationException::withMessages(['items' => 'Total transaksi berada di luar batas nominal yang diizinkan.']);
        }

        return [[
            'type' => $type,
            'occurred_at' => CarbonImmutable::parse($data['occurred_at']),
            'description' => $data['description'],
            'notes' => $isTransfer ? ($data['notes'] ?? null) : null,
            'responsible_user_id' => $isTransfer ? null : $data['responsible_user_id'],
            'payment_method_id' => $isTransfer ? null : $data['payment_method_id'],
            'source_cash_account_id' => $isExpense || $isTransfer ? $data['source_cash_account_id'] : null,
            'destination_cash_account_id' => $isIncome || $isTransfer ? $data['destination_cash_account_id'] : null,
            'vendor_id' => $isExpense ? ($data['vendor_id'] ?? null) : null,
            'fund_source_id' => $isExpense ? ($data['fund_source_id'] ?? null) : null,
            'total_amount' => (string) $total,
        ], $items];
    }

    /** @param list<array<string, mixed>> $items */
    private function replaceItems(Transaction $transaction, array $items): void
    {
        $transaction->items()->delete();
        foreach ($items as $item) {
            $transaction->items()->create($item);
        }
    }

    /** @param list<UploadedFile> $files */
    private function storeAttachments(Transaction $transaction, array $files, User $actor): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('transactions/'.$transaction->public_id, 'local');
            if (! is_string($path)) {
                throw ValidationException::withMessages(['attachments' => 'Bukti transaksi gagal disimpan.']);
            }
            $this->storedPaths[] = $path;
            $attachment = $transaction->attachments()->create([
                'original_name' => basename($file->getClientOriginalName()),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $actor->id,
            ]);
            $this->auditLogger->record('transaction_attachment_uploaded', 'transactions', 'Bukti transaksi diunggah.', $transaction, null, [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ]);
        }
    }

    private function deleteStoredFiles(): void
    {
        if ($this->storedPaths !== []) {
            Storage::disk('local')->delete($this->storedPaths);
        }
    }

    /** @param list<int> $accountIds
     * @return array<int, CashAccount>
     */
    private function lockCashAccounts(array $accountIds): array
    {
        $accountIds = array_values(array_unique($accountIds));
        sort($accountIds, SORT_NUMERIC);

        $lockedAccounts = [];
        foreach ($accountIds as $accountId) {
            $lockedAccounts[$accountId] = CashAccount::query()->lockForUpdate()->findOrFail($accountId);
        }

        return $lockedAccounts;
    }

    private function assertEditable(Transaction $transaction, User $actor): void
    {
        if (! in_array($transaction->status, ['draft', 'revision_requested'], true)) {
            throw ValidationException::withMessages(['transaction' => 'Transaksi ini tidak dapat diubah pada status sekarang.']);
        }
        if ($transaction->status === 'revision_requested' && $transaction->created_by !== $actor->id) {
            throw ValidationException::withMessages(['transaction' => 'Pembuat transaksi harus melakukan perbaikan yang diminta.']);
        }
        if ($transaction->created_by !== $actor->id && ! $actor->can('transactions.manage')) {
            throw ValidationException::withMessages(['transaction' => 'Anda tidak dapat mengubah transaksi ini.']);
        }
        if ($transaction->type === 'income' && ! $actor->can('transactions.income.create') && $transaction->created_by !== $actor->id) {
            throw ValidationException::withMessages(['transaction' => 'Anda tidak dapat mengubah transaksi ini.']);
        }
    }

    private function validateForSubmission(Transaction $transaction): void
    {
        if ($transaction->type === 'expense' && (! $transaction->items()->exists() || ! $transaction->fund_source_id || ! $transaction->source_cash_account_id)) {
            throw ValidationException::withMessages(['transaction' => 'Pengeluaran memerlukan akun sumber, sumber dana, dan minimal satu item.']);
        }
        if ($transaction->type === 'income' && ! $transaction->destination_cash_account_id) {
            throw ValidationException::withMessages(['transaction' => 'Pemasukan memerlukan akun tujuan.']);
        }
        if ($transaction->type === 'transfer' && (! $transaction->source_cash_account_id || ! $transaction->destination_cash_account_id
            || $transaction->source_cash_account_id === $transaction->destination_cash_account_id)) {
            throw ValidationException::withMessages(['transaction' => 'Transfer memerlukan dua akun kas yang berbeda.']);
        }
    }

    private function validateForPosting(Transaction $transaction, User $actor): void
    {
        $transaction->loadMissing('items.category.subcategories', 'items.subcategory', 'items.activity', 'attachments');
        if (! in_array($transaction->type, ['income', 'expense', 'transfer'], true)
            || BigDecimal::of($transaction->total_amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['transaction' => 'Jenis dan total transaksi tidak valid untuk posting.']);
        }
        if ($transaction->occurred_at->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'Transaksi belum dapat diposting sebelum tanggal kejadian.']);
        }
        if ($transaction->type !== 'transfer' && (! $transaction->responsibleUser()->where('is_active', true)->exists()
            || ! $transaction->paymentMethod()->where('is_active', true)->exists())) {
            throw ValidationException::withMessages(['transaction' => 'Penanggung jawab dan metode pembayaran harus aktif.']);
        }
        if ($transaction->fund_source_id !== null && ! $transaction->fundSource()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['fund_source_id' => 'Sumber dana yang dipilih sudah tidak aktif.']);
        }
        $expectedTotal = BigDecimal::zero();
        foreach ($transaction->items as $item) {
            $subtotal = BigDecimal::of($item->quantity)
                ->multipliedBy(BigDecimal::of($item->unit_price))
                ->toScale(2, RoundingMode::HalfUp);
            if ($subtotal->compareTo(BigDecimal::of($item->subtotal)->toScale(2)) !== 0) {
                throw ValidationException::withMessages(['transaction' => 'Subtotal item tidak sesuai dengan kuantitas dan harga satuan.']);
            }
            $expectedTotal = $expectedTotal->plus($subtotal);
        }
        if ($transaction->type !== 'transfer' && $transaction->items->isNotEmpty()
            && $expectedTotal->compareTo(BigDecimal::of($transaction->total_amount)->toScale(2)) !== 0) {
            throw ValidationException::withMessages(['transaction' => 'Total transaksi tidak sesuai dengan rincian item.']);
        }
        if ($transaction->type === 'expense') {
            if (! $transaction->fundSource()->where('is_active', true)->exists() || $transaction->items->isEmpty() || $transaction->attachments->isEmpty()) {
                throw ValidationException::withMessages(['transaction' => 'Pengeluaran memerlukan sumber dana, item, dan minimal satu bukti sebelum posting.']);
            }
            if ($transaction->vendor_id !== null && ! $transaction->vendor()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Vendor yang dipilih sudah tidak aktif.']);
            }
            foreach ($transaction->items as $item) {
                if (! $item->category?->is_active
                    || ($item->subcategory_id !== null && (! $item->subcategory?->is_active || $item->subcategory->category_id !== $item->category_id))
                    || ($item->activity_id !== null && ! $item->activity?->is_active)) {
                    throw ValidationException::withMessages(['transaction' => 'Kategori, subkategori, atau aktivitas item harus tetap aktif dan sesuai.']);
                }
                if ($item->category->subcategories()->where('is_active', true)->exists() && $item->subcategory_id === null) {
                    throw ValidationException::withMessages(['transaction' => 'Subkategori wajib dipilih untuk kategori item ini.']);
                }
            }
        }
        foreach ($transaction->attachments as $attachment) {
            if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
                throw ValidationException::withMessages(['transaction' => 'Berkas bukti tidak tersedia dan harus diunggah ulang.']);
            }
        }
        if ($transaction->type === 'income' && ! $transaction->destinationCashAccount()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['destination_cash_account_id' => 'Akun kas tujuan harus aktif untuk pemasukan.']);
        }
        if ($transaction->type === 'transfer') {
            if ($transaction->items->isNotEmpty() || $transaction->vendor_id !== null || $transaction->fund_source_id !== null
                || $transaction->source_cash_account_id === $transaction->destination_cash_account_id
                || ! $transaction->sourceCashAccount()->exists() || ! $transaction->destinationCashAccount()->where('is_active', true)->exists()
                || $transaction->attachments->isEmpty()) {
                throw ValidationException::withMessages(['transaction' => 'Transfer memerlukan dua akun berbeda, akun tujuan aktif, tanpa rincian biaya, dan minimal satu bukti.']);
            }
            if ($transaction->source_cash_account_id !== null && ! $actor->can('transactions.transfer.create')) {
                throw ValidationException::withMessages(['source_cash_account_id' => 'Hanya Keuangan Sarpras yang dapat mem-posting transfer.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Transaction $transaction): array
    {
        $transaction->loadMissing('items');

        return [
            'number' => $transaction->number,
            'type' => $transaction->type,
            'occurred_at' => $transaction->occurred_at?->toISOString(),
            'description' => $transaction->description,
            'notes' => $transaction->notes,
            'responsible_user_id' => $transaction->responsible_user_id,
            'payment_method_id' => $transaction->payment_method_id,
            'source_cash_account_id' => $transaction->source_cash_account_id,
            'destination_cash_account_id' => $transaction->destination_cash_account_id,
            'vendor_id' => $transaction->vendor_id,
            'fund_source_id' => $transaction->fund_source_id,
            'total_amount' => $transaction->total_amount,
            'status' => $transaction->status,
            'items' => $transaction->items->map(fn (TransactionItem $item): array => $item->only([
                'description', 'category_id', 'subcategory_id', 'activity_id', 'quantity', 'unit', 'unit_price', 'subtotal',
            ]))->all(),
        ];
    }
}
