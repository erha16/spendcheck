<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OpeningBalanceRequest;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function propose(CashAccount $cashAccount, string $amount, DateTimeInterface $effectiveAt, string $reason, User $proposer): OpeningBalanceRequest
    {
        return DB::transaction(function () use ($cashAccount, $amount, $effectiveAt, $reason, $proposer): OpeningBalanceRequest {
            $request = OpeningBalanceRequest::create([
                'cash_account_id' => $cashAccount->id,
                'amount' => $amount,
                'effective_at' => $effectiveAt,
                'reason' => $reason,
                'status' => 'pending',
                'proposed_by' => $proposer->id,
                'proposed_at' => now(),
            ]);

            $this->auditLogger->record('opening_balance_proposed', 'cash_accounts', 'Usulan saldo awal dibuat.', $request, null, [
                'cash_account_id' => $request->cash_account_id,
                'amount' => $request->amount,
                'effective_at' => $request->effective_at->toISOString(),
                'status' => $request->status,
            ], $proposer->id);

            return $request;
        });
    }

    public function review(OpeningBalanceRequest $openingBalanceRequest, User $reviewer, string $decision, ?string $notes = null): OpeningBalanceRequest
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Keputusan saldo awal tidak valid.');
        }

        return DB::transaction(function () use ($openingBalanceRequest, $reviewer, $decision, $notes): OpeningBalanceRequest {
            $lockedRequest = OpeningBalanceRequest::query()->lockForUpdate()->findOrFail($openingBalanceRequest->id);

            if ($lockedRequest->status === $decision) {
                return $lockedRequest;
            }

            if ($lockedRequest->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Usulan saldo awal ini sudah ditinjau.']);
            }

            if ($lockedRequest->proposed_by === $reviewer->id) {
                throw ValidationException::withMessages(['reviewer' => 'Pemohon tidak dapat meninjau usulan saldo awal sendiri.']);
            }

            $lockedRequest->forceFill([
                'status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            if ($decision === 'approved') {
                CashAccount::query()->lockForUpdate()->findOrFail($lockedRequest->cash_account_id);
                CashMovement::create([
                    'cash_account_id' => $lockedRequest->cash_account_id,
                    'opening_balance_request_id' => $lockedRequest->id,
                    'kind' => 'opening_balance',
                    'direction' => 'in',
                    'amount' => $lockedRequest->amount,
                    'idempotency_key' => 'opening-balance:'.$lockedRequest->public_id,
                    'occurred_at' => $lockedRequest->effective_at,
                    'recorded_at' => now(),
                    'created_by' => $reviewer->id,
                ]);
            }

            $this->auditLogger->record('opening_balance_'.$decision, 'cash_accounts', 'Usulan saldo awal ditinjau.', $lockedRequest, ['status' => 'pending'], [
                'status' => $decision,
                'review_notes' => $notes,
            ], $reviewer->id);

            return $lockedRequest;
        });
    }
}
