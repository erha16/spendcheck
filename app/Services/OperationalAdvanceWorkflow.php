<?php

namespace App\Services;

use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceStatus;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class OperationalAdvanceWorkflow
{
    /** @var list<string> */
    private array $storedPaths = [];

    public function __construct(private AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): OperationalAdvance
    {
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $data): OperationalAdvance {
                $advance = new OperationalAdvance;
                $advance->forceFill([
                    'purpose' => $data['purpose'],
                    'requested_amount' => $data['requested_amount'],
                    'requester_user_id' => $data['requester_user_id'] ?? $actor->id,
                    'responsible_user_id' => $data['responsible_user_id'] ?? $actor->id,
                    'activity_id' => $data['activity_id'] ?? null,
                    'planned_cash_account_id' => $data['planned_cash_account_id'],
                    'due_date' => $data['due_date'],
                    'mandate_reason' => $data['mandate_reason'] ?? null,
                    'created_by' => $actor->id,
                    'status' => OperationalAdvanceStatus::Draft->value,
                ])->save();

                $this->storeAttachments($advance, $data['attachments'] ?? [], $actor);
                $this->auditLogger->record('operational_advance_created', 'operational_advances', 'Draft bon operasional dibuat.', $advance, null, $this->snapshot($advance), $actor->id);

                return $advance;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(OperationalAdvance $advance, User $actor, array $data): OperationalAdvance
    {
        $this->storedPaths = [];

        try {
            return DB::transaction(function () use ($advance, $actor, $data): OperationalAdvance {
                $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
                $this->assertCanEdit($lockedAdvance, $actor);
                $beforeValues = $this->snapshot($lockedAdvance);
                $lockedAdvance->forceFill([
                    'purpose' => $data['purpose'],
                    'requested_amount' => $data['requested_amount'],
                    'requester_user_id' => $data['requester_user_id'] ?? $lockedAdvance->requester_user_id,
                    'responsible_user_id' => $data['responsible_user_id'] ?? $lockedAdvance->responsible_user_id,
                    'activity_id' => $data['activity_id'] ?? null,
                    'planned_cash_account_id' => $data['planned_cash_account_id'],
                    'due_date' => $data['due_date'],
                    'mandate_reason' => $data['mandate_reason'] ?? null,
                ])->save();

                $this->storeAttachments($lockedAdvance, $data['attachments'] ?? [], $actor);
                $this->auditLogger->record('operational_advance_updated', 'operational_advances', 'Draft bon operasional diperbarui.', $lockedAdvance, $beforeValues, $this->snapshot($lockedAdvance), $actor->id);

                return $lockedAdvance;
            });
        } catch (Throwable $exception) {
            $this->deleteStoredFiles();
            throw $exception;
        }
    }

    public function submit(OperationalAdvance $advance, User $actor): OperationalAdvance
    {
        return DB::transaction(function () use ($advance, $actor): OperationalAdvance {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if (! in_array($lockedAdvance->status, [OperationalAdvanceStatus::Draft->value, OperationalAdvanceStatus::RevisionRequested->value], true)
                || (! $lockedAdvance->isParticipant($actor) && $lockedAdvance->created_by !== $actor->id)) {
                throw ValidationException::withMessages(['operational_advance' => 'Bon ini tidak dapat diajukan oleh Anda.']);
            }

            $oldStatus = $lockedAdvance->status;
            $lockedAdvance->forceFill(['status' => OperationalAdvanceStatus::Submitted->value, 'submitted_at' => now()])->save();
            $this->auditLogger->record('operational_advance_submitted', 'operational_advances', 'Bon operasional diajukan untuk pemeriksaan.', $lockedAdvance, ['status' => $oldStatus], ['status' => $lockedAdvance->status], $actor->id);

            return $lockedAdvance;
        });
    }

    public function review(OperationalAdvance $advance, User $reviewer, string $decision, string $reason): OperationalAdvance
    {
        if (! in_array($decision, [OperationalAdvanceStatus::RevisionRequested->value, OperationalAdvanceStatus::Rejected->value], true)) {
            throw new \InvalidArgumentException('Keputusan review bon tidak valid.');
        }

        return DB::transaction(function () use ($advance, $reviewer, $decision, $reason): OperationalAdvance {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->assertReviewable($lockedAdvance, $reviewer);
            $beforeValues = ['status' => $lockedAdvance->status];
            $attributes = ['status' => $decision];

            if ($decision === OperationalAdvanceStatus::RevisionRequested->value) {
                $attributes += ['revision_requested_by' => $reviewer->id, 'revision_requested_at' => now(), 'revision_reason' => $reason];
            } else {
                $attributes += ['rejected_by' => $reviewer->id, 'rejected_at' => now(), 'rejection_reason' => $reason];
            }

            $lockedAdvance->forceFill($attributes)->save();
            $this->auditLogger->record('operational_advance_'.$decision, 'operational_advances', 'Bon operasional ditinjau.', $lockedAdvance, $beforeValues, [
                'status' => $decision,
                'reason' => $reason,
            ], $reviewer->id);

            return $lockedAdvance;
        });
    }

    public function approve(OperationalAdvance $advance, User $approver, string $approvedAmount): OperationalAdvance
    {
        return DB::transaction(function () use ($advance, $approver, $approvedAmount): OperationalAdvance {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $this->assertReviewable($lockedAdvance, $approver);

            if (! BigDecimal::of($approvedAmount)->isGreaterThan('0')
                || BigDecimal::of($approvedAmount)->isGreaterThan(BigDecimal::of($lockedAdvance->requested_amount))) {
                throw ValidationException::withMessages(['approved_amount' => 'Nominal persetujuan harus positif dan tidak boleh melebihi nominal pengajuan.']);
            }

            $lockedAdvance->forceFill([
                'status' => OperationalAdvanceStatus::Approved->value,
                'approved_amount' => $approvedAmount,
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();
            $this->auditLogger->record('operational_advance_approved', 'operational_advances', 'Pengajuan bon operasional disetujui.', $lockedAdvance, [
                'status' => OperationalAdvanceStatus::Submitted->value,
                'requested_amount' => $lockedAdvance->requested_amount,
            ], [
                'status' => OperationalAdvanceStatus::Approved->value,
                'approved_amount' => $approvedAmount,
            ], $approver->id);

            return $lockedAdvance;
        });
    }

    public function cancel(OperationalAdvance $advance, User $actor, string $reason): OperationalAdvance
    {
        return DB::transaction(function () use ($advance, $actor, $reason): OperationalAdvance {
            $lockedAdvance = OperationalAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $canManageAll = $actor->can('operational_advances.cancel');
            $canCancelOwn = $actor->can('operational_advances.cancel_own') && $lockedAdvance->isParticipant($actor);

            if ((! $canManageAll && ! $canCancelOwn)
                || ! in_array($lockedAdvance->status, [OperationalAdvanceStatus::Draft->value, OperationalAdvanceStatus::RevisionRequested->value, OperationalAdvanceStatus::Approved->value], true)
                || BigDecimal::of($lockedAdvance->disbursed_amount ?? '0')->isGreaterThan('0')) {
                throw ValidationException::withMessages(['operational_advance' => 'Bon ini tidak dapat dibatalkan pada status sekarang.']);
            }

            $oldStatus = $lockedAdvance->status;
            $lockedAdvance->forceFill([
                'status' => OperationalAdvanceStatus::Cancelled->value,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();
            $this->auditLogger->record('operational_advance_cancelled', 'operational_advances', 'Bon operasional dibatalkan sebelum pencairan.', $lockedAdvance, ['status' => $oldStatus], [
                'status' => OperationalAdvanceStatus::Cancelled->value,
                'reason' => $reason,
            ], $actor->id);

            return $lockedAdvance;
        });
    }

    private function assertCanEdit(OperationalAdvance $advance, User $actor): void
    {
        if (! in_array($advance->status, [OperationalAdvanceStatus::Draft->value, OperationalAdvanceStatus::RevisionRequested->value], true)
            || (! $actor->can('operational_advances.manage_all') && ! $advance->isParticipant($actor))) {
            throw ValidationException::withMessages(['operational_advance' => 'Bon ini tidak dapat diubah oleh Anda.']);
        }
    }

    private function assertReviewable(OperationalAdvance $advance, User $reviewer): void
    {
        if ($advance->status !== OperationalAdvanceStatus::Submitted->value) {
            throw ValidationException::withMessages(['operational_advance' => 'Bon ini tidak sedang menunggu keputusan.']);
        }

        if ($advance->isParticipant($reviewer)) {
            abort(403, 'Pemohon atau penerima bon tidak dapat meninjau bonnya sendiri.');
        }
    }

    /** @param list<UploadedFile> $files */
    private function storeAttachments(OperationalAdvance $advance, array $files, User $actor): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('operational-advances/'.$advance->public_id.'/request', 'local');
            if (! is_string($path)) {
                throw ValidationException::withMessages(['attachments' => 'Lampiran bon gagal disimpan.']);
            }

            $this->storedPaths[] = $path;
            $attachment = $advance->attachments()->create([
                'original_name' => basename($file->getClientOriginalName()),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $actor->id,
            ]);
            $this->auditLogger->record('operational_advance_attachment_uploaded', 'operational_advances', 'Lampiran bon diunggah.', $advance, null, [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ], $actor->id);
        }
    }

    private function deleteStoredFiles(): void
    {
        if ($this->storedPaths !== []) {
            Storage::disk('local')->delete($this->storedPaths);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(OperationalAdvance $advance): array
    {
        return [
            'number' => $advance->number,
            'status' => $advance->status,
            'purpose' => $advance->purpose,
            'requested_amount' => $advance->requested_amount,
            'requester_user_id' => $advance->requester_user_id,
            'responsible_user_id' => $advance->responsible_user_id,
            'activity_id' => $advance->activity_id,
            'planned_cash_account_id' => $advance->planned_cash_account_id,
            'due_date' => $advance->due_date?->toDateString(),
            'mandate_reason' => $advance->mandate_reason,
        ];
    }
}
