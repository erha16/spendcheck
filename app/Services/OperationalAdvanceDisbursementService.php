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
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalAdvanceDisbursementService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function disburse(
        OperationalAdvance $advance,
        User $actor,
        int $cashAccountId,
        DateTimeInterface|string $occurredAt,
        bool $allowNegative = false,
        ?string $overrideReason = null,
    ): Transaction {
        if (! $actor->can('operational_advances.disburse') || ! $actor->can('transactions.post')) {
            abort(403);
        }

        if ($advance->isParticipant($actor)) {
            abort(403, 'Pemohon atau penerima bon tidak dapat mencairkan bonnya sendiri.');
        }

        $actualDisbursementAt = CarbonImmutable::parse($occurredAt);

        return DB::transaction(function () use ($advance, $actor, $cashAccountId, $actualDisbursementAt, $allowNegative, $overrideReason): Transaction {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);

            if ($lockedAdvance->status !== OperationalAdvanceStatus::Approved->value
                || $lockedAdvance->approved_amount === null
                || BigDecimal::of($lockedAdvance->approved_amount)->isLessThanOrEqualTo('0')
                || BigDecimal::of($lockedAdvance->disbursed_amount ?? '0')->isGreaterThan('0')
                || $lockedAdvance->payoutTransaction()->exists()) {
                throw ValidationException::withMessages([
                    'operational_advance' => 'Bon harus berstatus disetujui dan belum pernah dicairkan.',
                ]);
            }

            if ($actualDisbursementAt->isFuture()) {
                throw ValidationException::withMessages(['occurred_at' => 'Tanggal pencairan tidak boleh di masa depan.']);
            }

            $approvedAmount = BigDecimal::of($lockedAdvance->approved_amount)->toScale(2);
            $transaction = new Transaction;
            $transaction->forceFill([
                'type' => 'advance_payout',
                'occurred_at' => $actualDisbursementAt,
                'description' => 'Pencairan bon '.$lockedAdvance->number,
                'responsible_user_id' => $lockedAdvance->responsible_user_id,
                'payment_method_id' => null,
                'source_cash_account_id' => $cashAccountId,
                'destination_cash_account_id' => null,
                'vendor_id' => null,
                'fund_source_id' => null,
                'operational_advance_id' => $lockedAdvance->id,
                'total_amount' => (string) $approvedAmount,
                'status' => 'draft',
                'created_by' => $actor->id,
            ])->save();

            $cashAccount = CashAccount::query()->lockForUpdate()->findOrFail($cashAccountId);
            if (! $cashAccount->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Rekening kas harus aktif saat pencairan.']);
            }

            $currentBalance = BigDecimal::of($cashAccount->ledgerBalance())->toScale(2);
            $shortfall = $currentBalance->isLessThan($approvedAmount)
                ? $approvedAmount->minus($currentBalance)->toScale(2)
                : BigDecimal::zero()->toScale(2);

            if ($shortfall->isGreaterThan('0')
                && (! $allowNegative || ! $actor->can('transactions.negative_balance.override') || blank($overrideReason))) {
                throw ValidationException::withMessages([
                    'cash_account_id' => 'Saldo rekening kas tidak mencukupi. Override memerlukan kewenangan dan alasan.',
                ]);
            }

            $recordedAt = now();
            $transaction->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => $recordedAt,
                'negative_balance_override' => $shortfall->isGreaterThan('0'),
                'negative_balance_shortfall' => (string) $shortfall,
                'negative_balance_override_reason' => $shortfall->isGreaterThan('0') ? $overrideReason : null,
                'negative_balance_override_by' => $shortfall->isGreaterThan('0') ? $actor->id : null,
                'negative_balance_override_at' => $shortfall->isGreaterThan('0') ? $recordedAt : null,
            ])->save();

            CashMovement::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_id' => $transaction->id,
                'kind' => 'advance_payout',
                'direction' => 'out',
                'amount' => (string) $approvedAmount,
                'idempotency_key' => 'advance-payout:'.$lockedAdvance->public_id,
                'occurred_at' => $actualDisbursementAt,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
            ]);

            $previousValues = [
                'status' => $lockedAdvance->status,
                'approved_amount' => $lockedAdvance->approved_amount,
            ];
            $lockedAdvance->forceFill([
                'status' => OperationalAdvanceStatus::Disbursed->value,
                'disbursed_amount' => (string) $approvedAmount,
                'disbursed_by' => $actor->id,
                'disbursed_at' => $actualDisbursementAt,
            ])->save();

            $this->auditLogger->record(
                'operational_advance_disbursed',
                'operational_advances',
                'Bon operasional dicairkan penuh melalui cash ledger.',
                $lockedAdvance,
                $previousValues,
                [
                    'status' => $lockedAdvance->status,
                    'cash_account_id' => $cashAccount->id,
                    'amount' => (string) $approvedAmount,
                    'occurred_at' => $actualDisbursementAt->toIso8601String(),
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'actor_id' => $actor->id,
                    'transaction_id' => $transaction->id,
                    'transaction_number' => $transaction->number,
                    'negative_balance_override' => $shortfall->isGreaterThan('0'),
                    'negative_balance_shortfall' => (string) $shortfall,
                    'override_reason' => $shortfall->isGreaterThan('0') ? $overrideReason : null,
                ],
                $actor->id,
            );

            return $transaction->fresh(['movements', 'poster', 'sourceCashAccount']);
        }, attempts: 3);
    }

    public function correctPayout(
        Transaction $original,
        User $actor,
        int $cashAccountId,
        DateTimeInterface|string $occurredAt,
        string $reason,
        bool $allowNegative = false,
        ?string $overrideReason = null,
    ): Transaction {
        if (! $actor->hasRole('keuangan_sarpras')
            || ! $actor->can('transactions.correct')
            || ! $actor->can('transactions.post')
            || ! $actor->can('operational_advances.disburse')) {
            abort(403);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi.']);
        }

        $actualDisbursementAt = CarbonImmutable::parse($occurredAt);
        if ($actualDisbursementAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'Tanggal pencairan tidak boleh di masa depan.']);
        }

        return DB::transaction(function () use ($original, $actor, $cashAccountId, $actualDisbursementAt, $reason, $allowNegative, $overrideReason): Transaction {
            if ($original->type !== 'advance_payout' || $original->operational_advance_id === null) {
                throw ValidationException::withMessages(['transaction' => 'Hanya transaksi pencairan bon yang dapat dikoreksi melalui alur ini.']);
            }

            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($original->operational_advance_id);
            if ($lockedAdvance->status !== OperationalAdvanceStatus::Disbursed->value || $lockedAdvance->isParticipant($actor)) {
                throw ValidationException::withMessages(['operational_advance' => 'Bon ini tidak dapat dikoreksi oleh Anda.']);
            }

            $lockedOriginal = Transaction::query()->lockForUpdate()->findOrFail($original->id);
            if ($lockedOriginal->status !== 'posted'
                || $lockedOriginal->type !== 'advance_payout'
                || (int) $lockedOriginal->operational_advance_id !== (int) $lockedAdvance->id
                || $lockedAdvance->payoutTransaction()->value('id') !== $lockedOriginal->id
                || $lockedOriginal->replacements()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Transaksi payout ini telah dikoreksi atau bukan payout bon aktif.']);
            }

            $approvedAmount = BigDecimal::of($lockedAdvance->approved_amount)->toScale(2);
            $movements = $lockedOriginal->movements()->orderBy('id')->lockForUpdate()->get();
            if ($movements->count() !== 1) {
                throw ValidationException::withMessages(['transaction' => 'Ledger payout asli tidak lengkap.']);
            }

            $originalMovement = $movements->first();
            if ($originalMovement->kind !== 'advance_payout'
                || $originalMovement->direction !== 'out'
                || BigDecimal::of($originalMovement->amount)->compareTo($approvedAmount) !== 0
                || (int) $originalMovement->cash_account_id !== (int) $lockedOriginal->source_cash_account_id
                || $originalMovement->reversals()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Ledger payout asli tidak sesuai atau telah memiliki reversal.']);
            }

            $replacement = new Transaction;
            $replacement->forceFill([
                'type' => 'advance_payout',
                'occurred_at' => $actualDisbursementAt,
                'description' => 'Koreksi pencairan bon '.$lockedAdvance->number,
                'responsible_user_id' => $lockedAdvance->responsible_user_id,
                'payment_method_id' => null,
                'source_cash_account_id' => $cashAccountId,
                'destination_cash_account_id' => null,
                'vendor_id' => null,
                'fund_source_id' => null,
                'operational_advance_id' => $lockedAdvance->id,
                'total_amount' => (string) $approvedAmount,
                'status' => 'draft',
                'created_by' => $actor->id,
                'reversal_of_id' => $lockedOriginal->id,
            ])->save();

            $accountIds = array_values(array_unique([$originalMovement->cash_account_id, $cashAccountId]));
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
                'direction' => 'in',
                'amount' => $originalMovement->amount,
                'idempotency_key' => 'transaction-reversal:'.$originalMovement->public_id,
                'occurred_at' => $originalMovement->occurred_at,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
                'reversal_of_id' => $originalMovement->id,
            ]);
            $lockedOriginal->forceFill(['status' => 'reversed'])->save();

            $cashAccount = $accounts[$cashAccountId];
            if (! $cashAccount->is_active) {
                throw ValidationException::withMessages(['source_cash_account_id' => 'Rekening kas harus aktif untuk koreksi payout.']);
            }

            $currentBalance = BigDecimal::of($cashAccount->ledgerBalance())->toScale(2);
            $shortfall = $currentBalance->isLessThan($approvedAmount)
                ? $approvedAmount->minus($currentBalance)->toScale(2)
                : BigDecimal::zero()->toScale(2);
            if ($shortfall->isGreaterThan('0')
                && (! $allowNegative || ! $actor->can('transactions.negative_balance.override') || blank($overrideReason))) {
                throw ValidationException::withMessages([
                    'source_cash_account_id' => 'Saldo rekening kas tidak mencukupi. Override memerlukan kewenangan dan alasan.',
                ]);
            }

            $replacement->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => $recordedAt,
                'negative_balance_override' => $shortfall->isGreaterThan('0'),
                'negative_balance_shortfall' => (string) $shortfall,
                'negative_balance_override_reason' => $shortfall->isGreaterThan('0') ? $overrideReason : null,
                'negative_balance_override_by' => $shortfall->isGreaterThan('0') ? $actor->id : null,
                'negative_balance_override_at' => $shortfall->isGreaterThan('0') ? $recordedAt : null,
            ])->save();
            CashMovement::create([
                'cash_account_id' => $cashAccount->id,
                'transaction_id' => $replacement->id,
                'kind' => 'advance_payout',
                'direction' => 'out',
                'amount' => (string) $approvedAmount,
                'idempotency_key' => 'transaction-posting:'.$replacement->public_id,
                'occurred_at' => $actualDisbursementAt,
                'recorded_at' => $recordedAt,
                'created_by' => $actor->id,
            ]);

            $beforeValues = [
                'number' => $lockedOriginal->number,
                'status' => 'posted',
                'type' => $lockedOriginal->type,
                'occurred_at' => $lockedOriginal->occurred_at->toISOString(),
                'source_cash_account_id' => $lockedOriginal->source_cash_account_id,
                'total_amount' => $lockedOriginal->total_amount,
            ];
            $afterValues = [
                'number' => $replacement->number,
                'public_id' => $replacement->public_id,
                'status' => $replacement->status,
                'type' => $replacement->type,
                'occurred_at' => $replacement->occurred_at->toISOString(),
                'source_cash_account_id' => $replacement->source_cash_account_id,
                'total_amount' => $replacement->total_amount,
                'transaction_id' => $replacement->id,
            ];
            $revision = TransactionRevision::create([
                'transaction_id' => $lockedOriginal->id,
                'replacement_transaction_id' => $replacement->id,
                'revision_number' => ((int) $lockedOriginal->revisions()->max('revision_number')) + 1,
                'reason' => $reason,
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
                'corrected_by' => $actor->id,
                'corrected_at' => $recordedAt,
            ]);
            $lockedAdvance->forceFill([
                'disbursed_by' => $actor->id,
                'disbursed_at' => $actualDisbursementAt,
            ])->save();

            $this->auditLogger->record(
                'transaction_corrected',
                'transactions',
                'Transaksi pencairan bon dikoreksi dengan reversal dan pengganti.',
                $lockedOriginal,
                $beforeValues,
                [...$afterValues, 'reason' => $reason, 'revision_id' => $revision->id],
                $actor->id,
            );
            $this->auditLogger->record(
                'operational_advance_payout_corrected',
                'operational_advances',
                'Pencairan bon dikoreksi; nilai payout tetap sama dengan nominal persetujuan.',
                $lockedAdvance,
                ['disbursed_by' => $original->posted_by, 'disbursed_at' => $original->occurred_at->toISOString()],
                [
                    'disbursed_by' => $actor->id,
                    'disbursed_at' => $actualDisbursementAt->toISOString(),
                    'cash_account_id' => $cashAccount->id,
                    'amount' => (string) $approvedAmount,
                    'original_transaction_id' => $lockedOriginal->id,
                    'replacement_transaction_id' => $replacement->id,
                    'reason' => $reason,
                ],
                $actor->id,
            );

            return $replacement->fresh(['movements', 'poster', 'sourceCashAccount', 'reversalOf']);
        }, attempts: 3);
    }
}
