<?php

namespace App\Models;

use App\Services\OperationalAdvanceNumberGenerator;
use Brick\Math\BigDecimal;
use Database\Factories\OperationalAdvanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'purpose', 'requested_amount', 'requester_user_id', 'responsible_user_id', 'activity_id',
    'planned_cash_account_id', 'due_date', 'mandate_reason',
])]
class OperationalAdvance extends Model
{
    /** @use HasFactory<OperationalAdvanceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (OperationalAdvance $advance): void {
            $advance->public_id ??= (string) Str::uuid7();
            $advance->number ??= app(OperationalAdvanceNumberGenerator::class)->next($advance->created_at ?? now());
            $advance->status ??= OperationalAdvanceStatus::Draft->value;
        });

        static::saving(function (OperationalAdvance $advance): void {
            $amountIsValid = preg_match('/^(?=.*[1-9])\d{1,17}(\.\d{1,2})?$/', (string) $advance->requested_amount) === 1;
            $statusIsValid = in_array($advance->status, array_map(fn (OperationalAdvanceStatus $status): string => $status->value, OperationalAdvanceStatus::cases()), true);

            if (! $amountIsValid || ! $statusIsValid) {
                throw new \InvalidArgumentException('Nilai atau status bon tidak valid.');
            }

            if ($advance->approved_amount !== null
                && (! preg_match('/^(?=.*[1-9])\d{1,17}(\.\d{1,2})?$/', (string) $advance->approved_amount)
                    || BigDecimal::of($advance->approved_amount)->isGreaterThan(BigDecimal::of($advance->requested_amount)))) {
                throw new \InvalidArgumentException('Nominal persetujuan tidak valid.');
            }

            if ($advance->disbursed_amount !== null && BigDecimal::of($advance->disbursed_amount)->isLessThan('0')) {
                throw new \InvalidArgumentException('Nominal pencairan tidak boleh negatif.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Riwayat bon operasional tidak dapat dihapus.'));
    }

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'disbursed_amount' => 'decimal:2',
            'due_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revision_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function plannedCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'planned_cash_account_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function revisionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_requested_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(AdvanceSettlement::class);
    }

    public function payoutTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class)
            ->where('type', 'advance_payout')
            ->where('status', 'posted')
            ->latestOfMany('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AdvanceAttachment::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('operational_advances.view')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('requester_user_id', $user->id)
                ->orWhere('responsible_user_id', $user->id);
        });
    }

    public function isParticipant(User $user): bool
    {
        return $this->requester_user_id === $user->id || $this->responsible_user_id === $user->id;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
