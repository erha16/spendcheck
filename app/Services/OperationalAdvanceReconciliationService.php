<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalAdvanceReconciliationService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private AdvanceSettlementWorkflow $settlementWorkflow,
    ) {}

    /** @param array<string, mixed> $data */
    public function submitReturn(OperationalAdvance $advance, User $actor, array $data): Transaction
    {
        if ((int) $advance->responsible_user_id !== (int) $actor->id || ! $actor->can('operational_advances.submit')) {
            abort(403);
        }

        return DB::transaction(function () use ($advance, $actor, $data): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $existing = Transaction::query()->where('advance_event_key', $data['advance_event_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->operational_advance_id !== (int) $lockedAdvance->id
                    || (int) $existing->created_by !== (int) $actor->id
                    || $existing->type !== 'advance_return') {
                    throw ValidationException::withMessages(['advance_event_key' => 'Kunci pengajuan pengembalian sudah digunakan.']);
                }

                return $existing;
            }

            $this->assertReturnCanBeRequested($lockedAdvance, $data['amount']);
            if (Transaction::query()->where('operational_advance_id', $lockedAdvance->id)
                ->where('type', 'advance_return')->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages(['operational_advance' => 'Masih ada pengembalian bon yang menunggu verifikasi Keuangan Sarpras.']);
            }

            $occurredAt = CarbonImmutable::parse($data['occurred_at']);
            if ($occurredAt->isFuture()) {
                throw ValidationException::withMessages(['occurred_at' => 'Tanggal pengembalian tidak boleh di masa depan.']);
            }

            $transaction = new Transaction;
            $transaction->forceFill([
                'type' => 'advance_return',
                'occurred_at' => $occurredAt,
                'description' => 'Pengajuan pengembalian sisa bon '.$lockedAdvance->number,
                'notes' => $data['notes'] ?? null,
                'responsible_user_id' => $lockedAdvance->responsible_user_id,
                'payment_method_id' => null,
                'source_cash_account_id' => null,
                'destination_cash_account_id' => null,
                'vendor_id' => null,
                'fund_source_id' => null,
                'operational_advance_id' => $lockedAdvance->id,
                'advance_event_key' => $data['advance_event_key'],
                'total_amount' => (string) BigDecimal::of($data['amount'])->toScale(2),
                'status' => 'submitted',
                'created_by' => $actor->id,
                'submitted_at' => now(),
            ])->save();

            $this->auditLogger->record(
                'operational_advance_return_submitted',
                'operational_advances',
                'Pengajuan pengembalian sisa bon menunggu verifikasi dan penerimaan Keuangan Sarpras.',
                $transaction,
                null,
                [
                    'operational_advance_id' => $lockedAdvance->id,
                    'amount' => $transaction->total_amount,
                    'occurred_at' => $occurredAt->toIso8601String(),
                    'actor_id' => $actor->id,
                    'advance_event_key' => $transaction->advance_event_key,
                ],
                $actor->id,
            );

            return $transaction;
        }, attempts: 3);
    }

    public function receiveReturn(
        OperationalAdvance $advance,
        Transaction $transaction,
        User $actor,
        int $cashAccountId,
    ): Transaction {
        $this->assertFinanceActor($advance, $actor, 'operational_advances.return');

        return DB::transaction(function () use ($advance, $transaction, $actor, $cashAccountId): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedTransaction = Transaction::query()->where('operational_advance_id', $lockedAdvance->id)
                ->lockForUpdate()->findOrFail($transaction->id);
            $this->assertReturnTransaction($lockedAdvance, $lockedTransaction);

            if ($lockedTransaction->status === 'posted' && $lockedTransaction->movements()->where('kind', 'advance_return')->exists()) {
                return $lockedTransaction->fresh(['movements']);
            }
            if ($lockedTransaction->status !== 'submitted') {
                throw ValidationException::withMessages(['transaction' => 'Pengajuan pengembalian ini tidak lagi menunggu verifikasi.']);
            }

            $this->assertReturnCanBeRequested($lockedAdvance, $lockedTransaction->total_amount);
            $cashAccount = CashAccount::query()->lockForUpdate()->findOrFail($cashAccountId);
            if (! $cashAccount->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Rekening penerima pengembalian harus aktif.']);
            }

            $recordedAt = now();
            $lockedTransaction->forceFill([
                'destination_cash_account_id' => $cashAccount->id,
                'status' => 'posted',
                'reviewed_by' => $actor->id,
                'reviewed_at' => $recordedAt,
                'posted_by' => $actor->id,
                'posted_at' => $recordedAt,
            ])->save();
            CashMovement::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_id' => $lockedTransaction->id,
                'kind' => 'advance_return',
                'direction' => 'in',
                'amount' => $lockedTransaction->total_amount,
                'idempotency_key' => $this->movementIdempotencyKey($lockedAdvance, 'advance_return', $lockedTransaction),
                'occurred_at' => $lockedTransaction->occurred_at,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
            ]);

            $this->auditLogger->record(
                'operational_advance_return_received',
                'operational_advances',
                'Pengembalian sisa bon diverifikasi dan diposting sebagai kas masuk.',
                $lockedTransaction,
                ['status' => 'submitted', 'amount' => $lockedTransaction->total_amount],
                [
                    'status' => 'posted',
                    'amount' => $lockedTransaction->total_amount,
                    'cash_account_id' => $cashAccount->id,
                    'transaction_id' => $lockedTransaction->id,
                    'transaction_number' => $lockedTransaction->number,
                    'actor_id' => $actor->id,
                    'recorded_at' => $recordedAt->toIso8601String(),
                ],
                $actor->id,
            );
            $this->settlementWorkflow->refreshAdvanceStatus($lockedAdvance, $actor);

            return $lockedTransaction->fresh(['movements', 'poster', 'reviewer', 'destinationCashAccount']);
        }, attempts: 3);
    }

    public function rejectReturn(OperationalAdvance $advance, Transaction $transaction, User $actor, string $reason): Transaction
    {
        $this->assertFinanceActor($advance, $actor, 'operational_advances.return');

        return DB::transaction(function () use ($advance, $transaction, $actor, $reason): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedTransaction = Transaction::query()->where('operational_advance_id', $lockedAdvance->id)
                ->lockForUpdate()->findOrFail($transaction->id);
            $this->assertReturnTransaction($lockedAdvance, $lockedTransaction);
            if ($lockedTransaction->status !== 'submitted') {
                throw ValidationException::withMessages(['transaction' => 'Hanya pengajuan pengembalian yang menunggu verifikasi dapat ditolak.']);
            }

            $lockedTransaction->forceFill([
                'status' => 'rejected',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();
            $this->auditLogger->record(
                'operational_advance_return_rejected',
                'operational_advances',
                'Pengajuan pengembalian sisa bon ditolak.',
                $lockedTransaction,
                ['status' => 'submitted'],
                ['status' => 'rejected', 'amount' => $lockedTransaction->total_amount, 'reason' => $reason],
                $actor->id,
            );

            return $lockedTransaction->fresh(['reviewer']);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function payReimbursement(OperationalAdvance $advance, User $actor, array $data): Transaction
    {
        $this->assertFinanceActor($advance, $actor, 'operational_advances.reimburse');

        return DB::transaction(function () use ($advance, $actor, $data): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $existing = Transaction::query()->where('advance_event_key', $data['advance_event_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->operational_advance_id !== (int) $lockedAdvance->id
                    || (int) $existing->created_by !== (int) $actor->id
                    || $existing->type !== 'advance_repay') {
                    throw ValidationException::withMessages(['advance_event_key' => 'Kunci pembayaran penggantian sudah digunakan.']);
                }

                return $existing;
            }

            $occurredAt = CarbonImmutable::parse($data['occurred_at']);
            if ($occurredAt->isFuture()) {
                throw ValidationException::withMessages(['occurred_at' => 'Tanggal pembayaran tidak boleh di masa depan.']);
            }
            $amount = BigDecimal::of($data['amount'])->toScale(2);
            $summary = $this->settlementWorkflow->summary($lockedAdvance);
            $payable = BigDecimal::of($summary['reimbursement_outstanding'])->toScale(2);
            if ($amount->isGreaterThan($payable) || ! $amount->isGreaterThan('0')) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi payable penggantian organisasi yang masih terbuka.']);
            }

            $cashAccount = CashAccount::query()->lockForUpdate()->findOrFail($data['cash_account_id']);
            if (! $cashAccount->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Rekening kas pembayaran harus aktif.']);
            }
            $balance = BigDecimal::of($cashAccount->ledgerBalance())->toScale(2);
            $shortfall = $balance->isLessThan($amount) ? $amount->minus($balance)->toScale(2) : BigDecimal::zero()->toScale(2);
            if ($shortfall->isGreaterThan('0')
                && (! ($data['allow_negative'] ?? false) || ! $actor->can('transactions.negative_balance.override') || blank($data['override_reason'] ?? null))) {
                throw ValidationException::withMessages(['cash_account_id' => 'Saldo rekening kas tidak mencukupi. Override memerlukan kewenangan dan alasan.']);
            }

            $recordedAt = now();
            $transaction = new Transaction;
            $transaction->forceFill([
                'type' => 'advance_repay',
                'occurred_at' => $occurredAt,
                'description' => 'Pembayaran penggantian kepada '.$lockedAdvance->responsibleUser->name.' untuk bon '.$lockedAdvance->number,
                'responsible_user_id' => $lockedAdvance->responsible_user_id,
                'payment_method_id' => null,
                'source_cash_account_id' => $cashAccount->id,
                'destination_cash_account_id' => null,
                'vendor_id' => null,
                'fund_source_id' => null,
                'operational_advance_id' => $lockedAdvance->id,
                'advance_event_key' => $data['advance_event_key'],
                'total_amount' => (string) $amount,
                'status' => 'posted',
                'created_by' => $actor->id,
                'posted_by' => $actor->id,
                'posted_at' => $recordedAt,
                'negative_balance_override' => $shortfall->isGreaterThan('0'),
                'negative_balance_shortfall' => (string) $shortfall,
                'negative_balance_override_reason' => $shortfall->isGreaterThan('0') ? $data['override_reason'] : null,
                'negative_balance_override_by' => $shortfall->isGreaterThan('0') ? $actor->id : null,
                'negative_balance_override_at' => $shortfall->isGreaterThan('0') ? $recordedAt : null,
            ])->save();
            CashMovement::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_id' => $transaction->id,
                'kind' => 'advance_repay',
                'direction' => 'out',
                'amount' => (string) $amount,
                'idempotency_key' => $this->movementIdempotencyKey($lockedAdvance, 'advance_repay', $transaction),
                'occurred_at' => $occurredAt,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
            ]);
            $this->auditLogger->record(
                'operational_advance_reimbursement_paid',
                'operational_advances',
                'Pembayaran payable penggantian kepada penerima bon diposting sebagai kas keluar.',
                $transaction,
                ['reimbursement_outstanding' => $payable],
                [
                    'amount' => $transaction->total_amount,
                    'cash_account_id' => $cashAccount->id,
                    'transaction_id' => $transaction->id,
                    'transaction_number' => $transaction->number,
                    'actor_id' => $actor->id,
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'negative_balance_override' => $transaction->negative_balance_override,
                    'override_reason' => $transaction->negative_balance_override_reason,
                ],
                $actor->id,
            );
            $this->settlementWorkflow->refreshAdvanceStatus($lockedAdvance, $actor);

            return $transaction->fresh(['movements', 'poster', 'sourceCashAccount']);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function correctCashEvent(Transaction $original, User $actor, array $data): Transaction
    {
        $type = $original->type;
        $permission = $type === 'advance_return' ? 'operational_advances.return' : 'operational_advances.reimburse';
        if (! in_array($type, ['advance_return', 'advance_repay'], true)
            || ! $actor->hasRole('keuangan_sarpras')
            || ! $actor->can($permission)
            || ! $actor->can('transactions.correct')
            || ! $actor->can('transactions.post')) {
            abort(403);
        }

        if (blank($data['correction_reason'] ?? null)) {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi.']);
        }

        return DB::transaction(function () use ($original, $actor, $data, $type): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($original->operational_advance_id);
            $this->assertFinanceActor($lockedAdvance, $actor, $type === 'advance_return' ? 'operational_advances.return' : 'operational_advances.reimburse');
            $lockedOriginal = Transaction::query()->where('operational_advance_id', $lockedAdvance->id)
                ->lockForUpdate()->findOrFail($original->id);
            $existing = Transaction::query()->where('advance_event_key', $data['advance_event_key'])->first();
            if ($existing !== null) {
                if ((int) $existing->operational_advance_id !== (int) $lockedAdvance->id
                    || (int) $existing->created_by !== (int) $actor->id
                    || (int) $existing->reversal_of_id !== (int) $lockedOriginal->id
                    || $existing->type !== $type) {
                    throw ValidationException::withMessages(['advance_event_key' => 'Kunci koreksi cash event sudah digunakan.']);
                }

                return $existing;
            }

            if ($lockedOriginal->type !== $type || $lockedOriginal->status !== 'posted'
                || $lockedOriginal->replacements()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Cash event ini tidak dapat dikoreksi lagi.']);
            }
            $originalMovements = $lockedOriginal->movements()->orderBy('id')->lockForUpdate()->get();
            if ($originalMovements->count() !== 1) {
                throw ValidationException::withMessages(['transaction' => 'Cash event asli tidak memiliki tepat satu movement.']);
            }
            $originalMovement = $originalMovements->first();
            $expectedDirection = $type === 'advance_return' ? 'in' : 'out';
            $expectedAccount = $type === 'advance_return'
                ? $lockedOriginal->destination_cash_account_id
                : $lockedOriginal->source_cash_account_id;
            if ($originalMovement->kind !== $type || $originalMovement->direction !== $expectedDirection
                || (int) $originalMovement->cash_account_id !== (int) $expectedAccount
                || BigDecimal::of($originalMovement->amount)->compareTo(BigDecimal::of($lockedOriginal->total_amount)) !== 0
                || $originalMovement->reversals()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Movement cash event asli tidak sesuai atau sudah direversal.']);
            }
            $beforeValues = $this->transactionSnapshot($lockedOriginal);

            $newAccountId = (int) $data['cash_account_id'];
            $accountIds = array_values(array_unique([(int) $originalMovement->cash_account_id, $newAccountId]));
            sort($accountIds, SORT_NUMERIC);
            $accounts = [];
            foreach ($accountIds as $accountId) {
                $accounts[$accountId] = CashAccount::query()->lockForUpdate()->findOrFail($accountId);
            }

            $recordedAt = now();
            CashMovement::create([
                'cash_account_id' => $originalMovement->cash_account_id,
                'transaction_id' => $lockedOriginal->id,
                'kind' => 'reversal',
                'direction' => $originalMovement->direction === 'in' ? 'out' : 'in',
                'amount' => $originalMovement->amount,
                'idempotency_key' => 'transaction-reversal:'.$originalMovement->public_id,
                'occurred_at' => $originalMovement->occurred_at,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
                'reversal_of_id' => $originalMovement->id,
            ]);
            $lockedOriginal->forceFill(['status' => 'reversed'])->save();

            $summary = $this->settlementWorkflow->summary($lockedAdvance);
            $obligationKey = $type === 'advance_return' ? 'return_outstanding' : 'reimbursement_outstanding';
            $obligation = BigDecimal::of($summary[$obligationKey])->toScale(2);
            $amount = BigDecimal::of($data['amount'])->toScale(2);
            if ($amount->isGreaterThan($obligation)) {
                throw ValidationException::withMessages(['amount' => 'Nominal pengganti melebihi kewajiban terkini setelah reversal.']);
            }

            $cashAccount = $accounts[$newAccountId];
            if (! $cashAccount->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Rekening kas pengganti harus aktif.']);
            }
            $occurredAt = CarbonImmutable::parse($data['occurred_at']);
            if ($occurredAt->isFuture()) {
                throw ValidationException::withMessages(['occurred_at' => 'Tanggal transaksi pengganti tidak boleh di masa depan.']);
            }
            $isReturn = $type === 'advance_return';
            $shortfall = BigDecimal::zero()->toScale(2);
            if (! $isReturn) {
                $balance = BigDecimal::of($cashAccount->ledgerBalance())->toScale(2);
                $shortfall = $balance->isLessThan($amount) ? $amount->minus($balance)->toScale(2) : $shortfall;
                if ($shortfall->isGreaterThan('0')
                    && (! ($data['allow_negative'] ?? false) || ! $actor->can('transactions.negative_balance.override') || blank($data['override_reason'] ?? null))) {
                    throw ValidationException::withMessages(['cash_account_id' => 'Saldo rekening kas tidak mencukupi. Override memerlukan kewenangan dan alasan.']);
                }
            }

            $replacement = new Transaction;
            $replacement->forceFill([
                'type' => $type,
                'occurred_at' => $occurredAt,
                'description' => ($isReturn ? 'Koreksi pengembalian sisa bon ' : 'Koreksi pembayaran penggantian bon ').$lockedAdvance->number,
                'responsible_user_id' => $lockedAdvance->responsible_user_id,
                'payment_method_id' => null,
                'source_cash_account_id' => $isReturn ? null : $cashAccount->id,
                'destination_cash_account_id' => $isReturn ? $cashAccount->id : null,
                'vendor_id' => null,
                'fund_source_id' => null,
                'operational_advance_id' => $lockedAdvance->id,
                'advance_event_key' => $data['advance_event_key'],
                'total_amount' => (string) $amount,
                'status' => 'posted',
                'created_by' => $actor->id,
                'posted_by' => $actor->id,
                'posted_at' => $recordedAt,
                'reversal_of_id' => $lockedOriginal->id,
                'negative_balance_override' => $shortfall->isGreaterThan('0'),
                'negative_balance_shortfall' => (string) $shortfall,
                'negative_balance_override_reason' => $shortfall->isGreaterThan('0') ? $data['override_reason'] : null,
                'negative_balance_override_by' => $shortfall->isGreaterThan('0') ? $actor->id : null,
                'negative_balance_override_at' => $shortfall->isGreaterThan('0') ? $recordedAt : null,
            ])->save();
            CashMovement::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_id' => $replacement->id,
                'kind' => $type,
                'direction' => $expectedDirection,
                'amount' => (string) $amount,
                'idempotency_key' => $this->movementIdempotencyKey($lockedAdvance, $type, $replacement),
                'occurred_at' => $occurredAt,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
            ]);

            $afterValues = $this->transactionSnapshot($replacement);
            $revision = TransactionRevision::create([
                'transaction_id' => $lockedOriginal->id,
                'replacement_transaction_id' => $replacement->id,
                'revision_number' => ((int) $lockedOriginal->revisions()->max('revision_number')) + 1,
                'reason' => $data['correction_reason'],
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
                'corrected_by' => $actor->id,
                'corrected_at' => $recordedAt,
            ]);
            $this->auditLogger->record(
                'transaction_corrected',
                'transactions',
                'Cash event bon dikoreksi dengan reversal dan transaksi pengganti.',
                $lockedOriginal,
                $beforeValues,
                [...$afterValues, 'reason' => $data['correction_reason'], 'revision_id' => $revision->id],
                $actor->id,
            );
            $this->auditLogger->record(
                'operational_advance_cash_event_corrected',
                'operational_advances',
                'Cash event bon dikoreksi melalui reversal dan replacement Phase 3A.',
                $lockedAdvance,
                ['original_transaction_id' => $lockedOriginal->id],
                [
                    'type' => $type,
                    'original_transaction_id' => $lockedOriginal->id,
                    'replacement_transaction_id' => $replacement->id,
                    'amount' => $replacement->total_amount,
                    'reason' => $data['correction_reason'],
                ],
                $actor->id,
            );
            $this->settlementWorkflow->refreshAdvanceStatus($lockedAdvance, $actor);

            return $replacement->fresh(['movements', 'poster', 'reversalOf']);
        }, attempts: 3);
    }

    private function assertReturnCanBeRequested(OperationalAdvance $advance, string $amount): void
    {
        $allowedStatuses = [
            OperationalAdvanceStatus::ReturnDue->value,
            OperationalAdvanceStatus::SettlementInReview->value,
            OperationalAdvanceStatus::PartiallyAccounted->value,
            OperationalAdvanceStatus::Disbursed->value,
            OperationalAdvanceStatus::ReimbursementDue->value,
        ];
        if (! in_array($advance->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages(['operational_advance' => 'Bon tidak dapat menerima pengajuan pengembalian pada status ini.']);
        }

        $summary = $this->settlementWorkflow->summary($advance);
        $outstanding = BigDecimal::of($summary['return_outstanding'])->toScale(2);
        if (! $outstanding->isGreaterThan('0') || BigDecimal::of($amount)->isGreaterThan($outstanding)) {
            throw ValidationException::withMessages(['amount' => 'Nominal pengembalian melebihi sisa dana bon yang wajib dikembalikan.']);
        }
    }

    private function assertReturnTransaction(OperationalAdvance $advance, Transaction $transaction): void
    {
        if ($transaction->type !== 'advance_return'
            || (int) $transaction->operational_advance_id !== (int) $advance->id
            || (int) $transaction->responsible_user_id !== (int) $advance->responsible_user_id) {
            throw ValidationException::withMessages(['transaction' => 'Pengajuan pengembalian tidak sesuai dengan bon ini.']);
        }
    }

    private function assertFinanceActor(OperationalAdvance $advance, User $actor, string $permission): void
    {
        if (! $actor->hasRole('keuangan_sarpras') || ! $actor->can($permission) || ! $actor->can('transactions.post')) {
            abort(403);
        }
        if ($advance->isParticipant($actor)) {
            abort(403, 'Pemohon atau penerima bon tidak dapat memverifikasi atau mem-posting event kas bonnya sendiri.');
        }
    }

    private function movementIdempotencyKey(OperationalAdvance $advance, string $type, Transaction $transaction): string
    {
        $prefix = $type === 'advance_return' ? 'advance-return:' : 'advance-repay:';

        return $prefix.$advance->public_id.':'.$transaction->number;
    }

    /** @return array<string, mixed> */
    private function transactionSnapshot(Transaction $transaction): array
    {
        return [
            'number' => $transaction->number,
            'public_id' => $transaction->public_id,
            'status' => $transaction->status,
            'type' => $transaction->type,
            'occurred_at' => $transaction->occurred_at->toISOString(),
            'source_cash_account_id' => $transaction->source_cash_account_id,
            'destination_cash_account_id' => $transaction->destination_cash_account_id,
            'total_amount' => $transaction->total_amount,
            'operational_advance_id' => $transaction->operational_advance_id,
        ];
    }
}
