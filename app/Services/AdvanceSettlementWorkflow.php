<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\AdvanceSettlementAdjustmentType;
use App\Models\AdvanceSettlementItem;
use App\Models\AdvanceSettlementItemAdjustment;
use App\Models\AdvanceSettlementItemStatus;
use App\Models\AdvanceSettlementStatus;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdvanceSettlementWorkflow
{
    /** @var list<string> */
    private array $storedPaths = [];

    public function __construct(private AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function submitBatch(OperationalAdvance $advance, User $actor, array $data): AdvanceSettlement
    {
        $this->assertSubmitPermission($advance, $actor);
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($advance, $actor, $data): AdvanceSettlement {
                $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
                $existing = $lockedAdvance->settlements()->where('submission_key', $data['submission_key'])->first();
                if ($existing !== null) {
                    if ((int) $existing->submitted_by !== (int) $actor->id) {
                        throw ValidationException::withMessages(['submission_key' => 'Kunci pengajuan sudah digunakan.']);
                    }

                    return $existing->load('items');
                }

                $this->assertCanSubmit($lockedAdvance, $actor);
                $batchNumber = ((int) $lockedAdvance->settlements()->max('batch_number')) + 1;
                $submittedAt = now();
                $settlement = $lockedAdvance->settlements()->create([
                    'batch_number' => $batchNumber,
                    'status' => AdvanceSettlementStatus::Submitted->value,
                    'submitted_by' => $actor->id,
                    'submitted_at' => $submittedAt,
                    'submission_key' => $data['submission_key'],
                ]);

                $submittedTotal = BigDecimal::zero()->toScale(2);
                foreach ($data['items'] as $itemData) {
                    $submittedTotal = $submittedTotal->plus(BigDecimal::of($itemData['amount']))->toScale(2);
                    $item = $settlement->items()->create([
                        'occurred_at' => $itemData['occurred_at'],
                        'description' => $itemData['description'],
                        'category_id' => $itemData['category_id'],
                        'subcategory_id' => $itemData['subcategory_id'] ?? null,
                        'activity_id' => $itemData['activity_id'] ?? null,
                        'amount' => (string) BigDecimal::of($itemData['amount'])->toScale(2),
                        'review_status' => AdvanceSettlementItemStatus::Pending->value,
                        'submitted_by' => $actor->id,
                    ]);
                    $this->storeItemAttachments($lockedAdvance, $item, $itemData['attachments'], $actor);
                }

                $previousStatus = $lockedAdvance->status;
                $lockedAdvance->forceFill(['status' => OperationalAdvanceStatus::SettlementInReview->value])->save();
                $this->auditLogger->record(
                    'advance_settlement_submitted',
                    'operational_advances',
                    'Batch pertanggungjawaban bon diajukan untuk pemeriksaan.',
                    $settlement,
                    null,
                    [
                        'operational_advance_id' => $lockedAdvance->id,
                        'batch_number' => $settlement->batch_number,
                        'status' => $settlement->status,
                        'submitter_id' => $actor->id,
                        'recipient_id' => $lockedAdvance->responsible_user_id,
                        'item_count' => count($data['items']),
                        'submitted_total' => (string) $submittedTotal,
                        'submission_key' => $settlement->submission_key,
                    ],
                    $actor->id,
                );
                $this->auditLogger->record(
                    'operational_advance_settlement_review_started',
                    'operational_advances',
                    'Bon masuk ke pemeriksaan pertanggungjawaban.',
                    $lockedAdvance,
                    ['status' => $previousStatus],
                    ['status' => $lockedAdvance->status, 'batch_number' => $batchNumber],
                    $actor->id,
                );

                return $settlement->load('items.attachments');
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function reviseItem(
        OperationalAdvance $advance,
        AdvanceSettlementItem $original,
        User $actor,
        array $data,
    ): AdvanceSettlementItem {
        $this->assertCanSubmit($advance, $actor, allowInReview: true);
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($advance, $original, $actor, $data): AdvanceSettlementItem {
                $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
                $settlement = AdvanceSettlement::query()->where('operational_advance_id', $lockedAdvance->id)
                    ->lockForUpdate()->findOrFail($original->advance_settlement_id);
                $lockedOriginal = AdvanceSettlementItem::query()->lockForUpdate()->findOrFail($original->id);

                $this->assertCanSubmit($lockedAdvance, $actor, allowInReview: true);
                if ($lockedOriginal->advance_settlement_id !== $settlement->id
                    || $lockedOriginal->review_status !== AdvanceSettlementItemStatus::RevisionRequested->value
                    || $lockedOriginal->replacement()->exists()) {
                    throw ValidationException::withMessages(['item' => 'Item ini tidak lagi menunggu revisi atau sudah memiliki versi pengganti.']);
                }

                $replacement = $settlement->items()->create([
                    'replaces_item_id' => $lockedOriginal->id,
                    'occurred_at' => $data['occurred_at'],
                    'description' => $data['description'],
                    'category_id' => $data['category_id'],
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'activity_id' => $data['activity_id'] ?? null,
                    'amount' => (string) BigDecimal::of($data['amount'])->toScale(2),
                    'review_status' => AdvanceSettlementItemStatus::Pending->value,
                    'submitted_by' => $actor->id,
                ]);
                $this->storeItemAttachments($lockedAdvance, $replacement, $data['attachments'], $actor);

                $lockedOriginal->forceFill(['review_status' => AdvanceSettlementItemStatus::Superseded->value])->save();
                $settlement->forceFill([
                    'status' => AdvanceSettlementStatus::Submitted->value,
                    'submitted_by' => $actor->id,
                    'submitted_at' => now(),
                ])->save();
                $this->setAdvanceStatus($lockedAdvance, OperationalAdvanceStatus::SettlementInReview->value, $actor);
                $this->auditLogger->record(
                    'advance_settlement_item_resubmitted',
                    'operational_advances',
                    'Item revisi diajukan sebagai versi baru.',
                    $replacement,
                    ['replaces_item_id' => $lockedOriginal->id, 'previous_status' => AdvanceSettlementItemStatus::RevisionRequested->value],
                    ['status' => $replacement->review_status, 'submitter_id' => $actor->id, 'batch_number' => $settlement->batch_number],
                    $actor->id,
                );

                return $replacement->load('attachments');
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    public function reviewItem(
        OperationalAdvance $advance,
        AdvanceSettlement $settlement,
        AdvanceSettlementItem $item,
        User $reviewer,
        string $decision,
        ?string $reason,
    ): AdvanceSettlementItem {
        $this->assertCanReview($advance, $reviewer);

        return DB::transaction(function () use ($advance, $settlement, $item, $reviewer, $decision, $reason): AdvanceSettlementItem {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedSettlement = AdvanceSettlement::query()->where('operational_advance_id', $lockedAdvance->id)
                ->lockForUpdate()->findOrFail($settlement->id);
            $lockedItem = AdvanceSettlementItem::query()->where('advance_settlement_id', $lockedSettlement->id)
                ->lockForUpdate()->findOrFail($item->id);
            $this->assertCanReview($lockedAdvance, $reviewer);

            if ($lockedItem->review_status !== AdvanceSettlementItemStatus::Pending->value
                || ! in_array($lockedSettlement->status, [
                    AdvanceSettlementStatus::Submitted->value,
                    AdvanceSettlementStatus::InReview->value,
                    AdvanceSettlementStatus::RevisionRequested->value,
                ], true)) {
                throw ValidationException::withMessages(['item' => 'Item ini sudah diperiksa atau batch tidak sedang aktif.']);
            }
            if ((int) $lockedItem->submitted_by === (int) $reviewer->id || $lockedAdvance->isParticipant($reviewer)) {
                abort(403, 'Pengaju atau penerima bon tidak dapat memeriksa itemnya sendiri.');
            }
            if ($decision === AdvanceSettlementItemStatus::Accepted->value && ! $lockedItem->attachments()->exists()) {
                throw ValidationException::withMessages(['item' => 'Item tanpa bukti tidak dapat diterima.']);
            }

            $oldStatus = $lockedItem->review_status;
            $lockedItem->forceFill([
                'review_status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_reason' => $reason,
            ])->save();

            $this->refreshBatchStatus($lockedSettlement, $reviewer, $reason);
            $this->setAdvanceStatus($lockedAdvance, $this->calculatedAdvanceStatus($lockedAdvance), $reviewer);
            $this->auditLogger->record(
                'advance_settlement_item_'.$decision,
                'operational_advances',
                'Item pertanggungjawaban diperiksa.',
                $lockedItem,
                ['review_status' => $oldStatus],
                [
                    'review_status' => $decision,
                    'review_reason' => $reason,
                    'amount' => $lockedItem->amount,
                    'batch_number' => $lockedSettlement->batch_number,
                ],
                $reviewer->id,
            );

            return $lockedItem->fresh(['reviewer', 'submitter', 'attachments']);
        });
    }

    public function rejectOpenItems(
        OperationalAdvance $advance,
        AdvanceSettlement $settlement,
        User $reviewer,
        string $reason,
    ): AdvanceSettlement {
        $this->assertCanReview($advance, $reviewer);

        return DB::transaction(function () use ($advance, $settlement, $reviewer, $reason): AdvanceSettlement {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedSettlement = AdvanceSettlement::query()->where('operational_advance_id', $lockedAdvance->id)
                ->lockForUpdate()->findOrFail($settlement->id);
            $this->assertCanReview($lockedAdvance, $reviewer);
            $items = $lockedSettlement->items()->whereIn('review_status', [
                AdvanceSettlementItemStatus::Pending->value,
                AdvanceSettlementItemStatus::RevisionRequested->value,
            ])->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['settlement' => 'Batch ini tidak memiliki item terbuka untuk ditolak.']);
            }
            if ($items->contains(fn (AdvanceSettlementItem $item): bool => (int) $item->submitted_by === (int) $reviewer->id)
                || $lockedAdvance->isParticipant($reviewer)) {
                abort(403, 'Pengaju atau penerima bon tidak dapat menolak batch bonnya sendiri.');
            }

            $reviewedAt = now();
            foreach ($items as $item) {
                $oldStatus = $item->review_status;
                $item->forceFill([
                    'review_status' => AdvanceSettlementItemStatus::Rejected->value,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => $reviewedAt,
                    'review_reason' => $reason,
                ])->save();
                $this->auditLogger->record(
                    'advance_settlement_item_rejected',
                    'operational_advances',
                    'Item terbuka dalam batch ditolak.',
                    $item,
                    ['review_status' => $oldStatus],
                    ['review_status' => AdvanceSettlementItemStatus::Rejected->value, 'review_reason' => $reason],
                    $reviewer->id,
                );
            }

            $lockedSettlement->forceFill([
                'status' => AdvanceSettlementStatus::Completed->value,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
                'review_notes' => $reason,
            ])->save();
            $this->setAdvanceStatus($lockedAdvance, $this->calculatedAdvanceStatus($lockedAdvance), $reviewer);
            $this->auditLogger->record(
                'advance_settlement_batch_rejected',
                'operational_advances',
                'Semua item terbuka pada batch pertanggungjawaban ditolak.',
                $lockedSettlement,
                ['status' => $settlement->status],
                ['status' => $lockedSettlement->status, 'reason' => $reason, 'rejected_item_count' => $items->count()],
                $reviewer->id,
            );

            return $lockedSettlement->fresh('items');
        });
    }

    /** @param array<string, mixed> $data @return list<AdvanceSettlementItemAdjustment> */
    public function requestAdjustment(
        OperationalAdvance $advance,
        AdvanceSettlementItem $item,
        User $actor,
        array $data,
    ): array {
        $this->assertCanSubmit($advance, $actor, allowInReview: true, allowSettled: true);

        return DB::transaction(function () use ($advance, $item, $actor, $data): array {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedItem = AdvanceSettlementItem::query()->whereHas('settlement', fn ($query) => $query->where('operational_advance_id', $lockedAdvance->id))
                ->lockForUpdate()->findOrFail($item->id);
            $this->assertCanSubmit($lockedAdvance, $actor, allowInReview: true, allowSettled: true);

            $existing = AdvanceSettlementItemAdjustment::query()
                ->where('adjustment_group_id', $data['adjustment_group_id'])
                ->where('advance_settlement_item_id', $lockedItem->id)
                ->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                if ((int) $existing->first()->created_by !== (int) $actor->id) {
                    throw ValidationException::withMessages(['adjustment_group_id' => 'Kunci koreksi sudah digunakan.']);
                }

                return $existing->all();
            }

            if ($lockedItem->review_status !== AdvanceSettlementItemStatus::Accepted->value) {
                throw ValidationException::withMessages(['item' => 'Hanya item yang telah diterima yang dapat dikoreksi.']);
            }
            if (AdvanceSettlementItemAdjustment::query()->where('advance_settlement_item_id', $lockedItem->id)
                ->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['item' => 'Masih ada koreksi item yang menunggu pemeriksaan.']);
            }

            $currentAmount = $this->effectiveItemAmount($lockedItem);
            if (! $currentAmount->isGreaterThan('0')) {
                throw ValidationException::withMessages(['item' => 'Tidak ada nilai beban tersisa untuk dikoreksi.']);
            }

            $groupId = $data['adjustment_group_id'];
            $occurredAt = $data['occurred_at'];
            $createdAt = now();
            $reversal = AdvanceSettlementItemAdjustment::create([
                'adjustment_group_id' => $groupId,
                'advance_settlement_item_id' => $lockedItem->id,
                'kind' => AdvanceSettlementAdjustmentType::Reversal->value,
                'status' => 'pending',
                'occurred_at' => $occurredAt,
                'description' => $lockedItem->description,
                'category_id' => $lockedItem->category_id,
                'subcategory_id' => $lockedItem->subcategory_id,
                'activity_id' => $lockedItem->activity_id,
                'vendor_id' => $lockedItem->vendor_id,
                'amount_delta' => (string) BigDecimal::zero()->minus($currentAmount)->toScale(2),
                'reason' => $data['reason'],
                'created_by' => $actor->id,
                'created_at' => $createdAt,
            ]);
            $adjustments = [$reversal];

            if ($data['mode'] === 'replace') {
                $replacement = AdvanceSettlementItemAdjustment::create([
                    'adjustment_group_id' => $groupId,
                    'advance_settlement_item_id' => $lockedItem->id,
                    'kind' => AdvanceSettlementAdjustmentType::Replacement->value,
                    'status' => 'pending',
                    'occurred_at' => $occurredAt,
                    'description' => $data['description'],
                    'category_id' => $data['category_id'],
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'activity_id' => $data['activity_id'] ?? null,
                    'vendor_id' => null,
                    'amount_delta' => (string) BigDecimal::of($data['amount'])->toScale(2),
                    'reason' => $data['reason'],
                    'created_by' => $actor->id,
                    'created_at' => $createdAt,
                ]);
                $adjustments[] = $replacement;
            }

            $this->auditLogger->record(
                'advance_settlement_item_adjustment_submitted',
                'operational_advances',
                'Usulan koreksi beban item pertanggungjawaban diajukan.',
                $lockedItem,
                ['accepted_amount' => $lockedItem->amount, 'effective_amount' => (string) $currentAmount],
                [
                    'adjustment_group_id' => $groupId,
                    'mode' => $data['mode'],
                    'amount_delta' => array_map(fn (AdvanceSettlementItemAdjustment $adjustment): string => $adjustment->amount_delta, $adjustments),
                    'reason' => $data['reason'],
                    'actor_id' => $actor->id,
                ],
                $actor->id,
            );
            $this->setAdvanceStatus($lockedAdvance, OperationalAdvanceStatus::SettlementInReview->value, $actor);

            return $adjustments;
        });
    }

    /** @return list<AdvanceSettlementItemAdjustment> */
    public function reviewAdjustmentGroup(
        OperationalAdvance $advance,
        AdvanceSettlementItem $item,
        string $groupId,
        User $reviewer,
        string $decision,
        ?string $reason,
    ): array {
        $this->assertCanReview($advance, $reviewer);

        return DB::transaction(function () use ($advance, $item, $groupId, $reviewer, $decision, $reason): array {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $lockedItem = AdvanceSettlementItem::query()->whereHas('settlement', fn ($query) => $query->where('operational_advance_id', $lockedAdvance->id))
                ->lockForUpdate()->findOrFail($item->id);
            $this->assertCanReview($lockedAdvance, $reviewer);
            $adjustments = AdvanceSettlementItemAdjustment::query()
                ->where('advance_settlement_item_id', $lockedItem->id)
                ->where('adjustment_group_id', $groupId)
                ->orderBy('id')->lockForUpdate()->get();

            if ($adjustments->isEmpty() || $adjustments->contains(fn (AdvanceSettlementItemAdjustment $adjustment): bool => $adjustment->status !== 'pending')) {
                throw ValidationException::withMessages(['adjustment' => 'Koreksi ini tidak lagi menunggu pemeriksaan.']);
            }
            if ($adjustments->contains(fn (AdvanceSettlementItemAdjustment $adjustment): bool => (int) $adjustment->created_by === (int) $reviewer->id)
                || $lockedAdvance->isParticipant($reviewer)) {
                abort(403, 'Pengusul atau penerima bon tidak dapat memeriksa koreksinya sendiri.');
            }

            if ($decision === 'approved') {
                $groupDelta = BigDecimal::zero()->toScale(2);
                foreach ($adjustments as $adjustment) {
                    $groupDelta = $groupDelta->plus(BigDecimal::of($adjustment->amount_delta))->toScale(2);
                }
                if ($this->effectiveItemAmount($lockedItem)->plus($groupDelta)->isLessThan('0')) {
                    throw ValidationException::withMessages(['adjustment' => 'Koreksi tidak boleh membuat realisasi item negatif.']);
                }
            }

            $reviewedAt = now();
            foreach ($adjustments as $adjustment) {
                $adjustment->forceFill([
                    'status' => $decision,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => $reviewedAt,
                    'review_reason' => $reason,
                ])->save();
            }
            $this->setAdvanceStatus($lockedAdvance, $this->calculatedAdvanceStatus($lockedAdvance), $reviewer);
            $this->auditLogger->record(
                'advance_settlement_item_adjustment_'.$decision,
                'operational_advances',
                'Koreksi item pertanggungjawaban diperiksa.',
                $lockedItem,
                ['adjustment_group_id' => $groupId, 'status' => 'pending'],
                [
                    'adjustment_group_id' => $groupId,
                    'status' => $decision,
                    'review_reason' => $reason,
                    'reviewer_id' => $reviewer->id,
                    'amount_deltas' => $adjustments->pluck('amount_delta')->all(),
                ],
                $reviewer->id,
            );

            return $adjustments->all();
        });
    }

    /** @return array{disbursed: string, accepted_expense: string, returned: string, reimbursed: string, return_outstanding: string, reimbursement_outstanding: string, outstanding: string, overspend: string} */
    public function summary(OperationalAdvance $advance): array
    {
        $disbursed = $this->netCashEventAmount($advance->id, 'advance_payout', 'out');
        $acceptedExpense = $this->acceptedExpenseTotal($advance->id);
        $returned = $this->netCashEventAmount($advance->id, 'advance_return', 'in');
        $reimbursed = $this->netCashEventAmount($advance->id, 'advance_repay', 'out');
        $returnOutstanding = $disbursed->minus($acceptedExpense)->minus($returned)->toScale(2);
        $reimbursementOutstanding = $acceptedExpense->plus($returned)->minus($disbursed)->minus($reimbursed)->toScale(2);
        $overspend = $acceptedExpense->minus($disbursed)->toScale(2);

        $returnOutstanding = $returnOutstanding->isLessThan('0') ? BigDecimal::zero()->toScale(2) : $returnOutstanding;
        $reimbursementOutstanding = $reimbursementOutstanding->isLessThan('0') ? BigDecimal::zero()->toScale(2) : $reimbursementOutstanding;

        return [
            'disbursed' => (string) $disbursed,
            'accepted_expense' => (string) $acceptedExpense,
            'returned' => (string) $returned,
            'reimbursed' => (string) $reimbursed,
            'return_outstanding' => (string) $returnOutstanding,
            'reimbursement_outstanding' => (string) $reimbursementOutstanding,
            'outstanding' => (string) $returnOutstanding,
            'overspend' => (string) ($overspend->isLessThan('0') ? BigDecimal::zero()->toScale(2) : $overspend),
        ];
    }

    public function refreshAdvanceStatus(OperationalAdvance $advance, User $actor): void
    {
        $this->setAdvanceStatus($advance, $this->calculatedAdvanceStatus($advance), $actor);
    }

    private function assertSubmitPermission(OperationalAdvance $advance, User $actor): void
    {
        $isRecipient = (int) $advance->responsible_user_id === (int) $actor->id
            && $actor->can('operational_advances.submit');
        $isFinanceAssisting = $actor->hasRole('keuangan_sarpras') && $actor->can('operational_advances.settle');
        if (! $isRecipient && ! $isFinanceAssisting) {
            abort(403);
        }
    }

    private function assertCanSubmit(OperationalAdvance $advance, User $actor, bool $allowInReview = false, bool $allowSettled = false): void
    {
        $this->assertSubmitPermission($advance, $actor);
        $allowedStatuses = [
            OperationalAdvanceStatus::Disbursed->value,
            OperationalAdvanceStatus::PartiallyAccounted->value,
            OperationalAdvanceStatus::ReturnDue->value,
            OperationalAdvanceStatus::ReimbursementDue->value,
        ];
        if ($allowInReview) {
            $allowedStatuses[] = OperationalAdvanceStatus::SettlementInReview->value;
        }
        if ($allowSettled) {
            $allowedStatuses[] = OperationalAdvanceStatus::Settled->value;
        }
        if (! in_array($advance->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages(['operational_advance' => 'Bon belum dapat menerima batch atau koreksi pertanggungjawaban.']);
        }
    }

    private function assertCanReview(OperationalAdvance $advance, User $reviewer): void
    {
        if (! $reviewer->hasRole('keuangan_sarpras') || ! $reviewer->can('operational_advances.settle')) {
            abort(403);
        }
        if ($advance->isParticipant($reviewer)) {
            abort(403, 'Penerima atau pemohon bon tidak dapat memeriksa pertanggungjawaban bonnya sendiri.');
        }
    }

    private function refreshBatchStatus(AdvanceSettlement $settlement, User $reviewer, ?string $reason): void
    {
        $statuses = $settlement->items()->pluck('review_status');
        $status = $statuses->contains(AdvanceSettlementItemStatus::Pending->value)
            ? AdvanceSettlementStatus::InReview->value
            : ($statuses->contains(AdvanceSettlementItemStatus::RevisionRequested->value)
                ? AdvanceSettlementStatus::RevisionRequested->value
                : AdvanceSettlementStatus::Completed->value);
        $settlement->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reason,
        ])->save();
    }

    private function calculatedAdvanceStatus(OperationalAdvance $advance): string
    {
        $hasPendingAdjustment = AdvanceSettlementItemAdjustment::query()
            ->where('status', 'pending')
            ->whereHas('item.settlement', fn ($query) => $query->where('operational_advance_id', $advance->id))
            ->exists();
        $hasOpenBatch = $advance->settlements()->whereIn('status', [
            AdvanceSettlementStatus::Submitted->value,
            AdvanceSettlementStatus::InReview->value,
            AdvanceSettlementStatus::RevisionRequested->value,
        ])->exists();
        if ($hasOpenBatch || $hasPendingAdjustment) {
            return OperationalAdvanceStatus::SettlementInReview->value;
        }

        $summary = $this->summary($advance);
        if (BigDecimal::of($summary['return_outstanding'])->isGreaterThan('0')) {
            return OperationalAdvanceStatus::ReturnDue->value;
        }
        if (BigDecimal::of($summary['reimbursement_outstanding'])->isGreaterThan('0')) {
            return OperationalAdvanceStatus::ReimbursementDue->value;
        }
        if (BigDecimal::of($summary['disbursed'])->isGreaterThan('0')) {
            return OperationalAdvanceStatus::Settled->value;
        }

        return BigDecimal::of($summary['accepted_expense'])->isGreaterThan('0')
            ? OperationalAdvanceStatus::ReimbursementDue->value
            : OperationalAdvanceStatus::Disbursed->value;
    }

    private function setAdvanceStatus(OperationalAdvance $advance, string $status, User $actor): void
    {
        if ($advance->status === $status) {
            return;
        }

        $previousStatus = $advance->status;
        $advance->forceFill(['status' => $status])->save();
        $this->auditLogger->record(
            'operational_advance_settlement_status_changed',
            'operational_advances',
            'Status bon diperbarui berdasarkan item accepted dan kewajiban kas yang masih terbuka.',
            $advance,
            ['status' => $previousStatus],
            ['status' => $status],
            $actor->id,
        );
    }

    private function acceptedExpenseTotal(int $advanceId): BigDecimal
    {
        $acceptedItems = AdvanceSettlementItem::query()
            ->where('review_status', AdvanceSettlementItemStatus::Accepted->value)
            ->whereHas('settlement', fn ($query) => $query->where('operational_advance_id', $advanceId))
            ->with('adjustments')
            ->get();
        $total = BigDecimal::zero()->toScale(2);

        foreach ($acceptedItems as $item) {
            $total = $total->plus(BigDecimal::of($item->amount))->toScale(2);
            foreach ($item->adjustments->where('status', 'approved') as $adjustment) {
                $total = $total->plus(BigDecimal::of($adjustment->amount_delta))->toScale(2);
            }
        }

        return $total;
    }

    private function netCashEventAmount(int $advanceId, string $type, string $direction): BigDecimal
    {
        $amount = DB::table('cash_movements')
            ->join('transactions', 'transactions.id', '=', 'cash_movements.transaction_id')
            ->where('transactions.operational_advance_id', $advanceId)
            ->where('transactions.type', $type)
            ->whereIn('transactions.status', ['posted', 'reversed'])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cash_movements.direction = ? THEN cash_movements.amount ELSE -cash_movements.amount END), 0) as net_amount',
                [$direction],
            )
            ->value('net_amount');

        $netAmount = BigDecimal::of((string) ($amount ?? '0'))->toScale(2);

        return $netAmount->isLessThan('0') ? BigDecimal::zero()->toScale(2) : $netAmount;
    }

    private function effectiveItemAmount(AdvanceSettlementItem $item): BigDecimal
    {
        $amount = BigDecimal::of($item->amount)->toScale(2);
        $approvedDeltas = AdvanceSettlementItemAdjustment::query()
            ->where('advance_settlement_item_id', $item->id)
            ->where('status', 'approved')
            ->pluck('amount_delta');

        foreach ($approvedDeltas as $delta) {
            $amount = $amount->plus(BigDecimal::of($delta))->toScale(2);
        }

        return $amount;
    }

    /** @param list<UploadedFile> $files */
    private function storeItemAttachments(OperationalAdvance $advance, AdvanceSettlementItem $item, array $files, User $actor): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('operational-advances/'.$advance->public_id.'/settlements/'.$item->id, 'local');
            if (! is_string($path)) {
                throw ValidationException::withMessages(['items' => 'Bukti pertanggungjawaban gagal disimpan.']);
            }

            $this->storedPaths[] = $path;
            $attachment = $item->attachments()->create([
                'original_name' => basename($file->getClientOriginalName()),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
                'uploaded_by' => $actor->id,
            ]);
            $this->auditLogger->record(
                'advance_settlement_attachment_uploaded',
                'operational_advances',
                'Bukti item pertanggungjawaban diunggah ke penyimpanan privat.',
                $attachment,
                null,
                [
                    'item_id' => $item->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'uploader_id' => $actor->id,
                ],
                $actor->id,
            );
        }
    }

    private function deleteStoredFiles(): void
    {
        if ($this->storedPaths !== []) {
            Storage::disk('local')->delete($this->storedPaths);
        }
    }
}
