<?php

namespace App\Models;

use Database\Factories\AdvanceSettlementItemAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'adjustment_group_id', 'advance_settlement_item_id', 'kind', 'status', 'occurred_at', 'description', 'category_id',
    'subcategory_id', 'activity_id', 'vendor_id', 'amount_delta', 'reason', 'created_by', 'reviewed_by', 'reviewed_at', 'review_reason', 'created_at',
])]
class AdvanceSettlementItemAdjustment extends Model
{
    /** @use HasFactory<AdvanceSettlementItemAdjustmentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (AdvanceSettlementItemAdjustment $adjustment): void {
            $amountDelta = (string) $adjustment->amount_delta;
            $kindIsValid = in_array($adjustment->kind, [AdvanceSettlementAdjustmentType::Reversal->value, AdvanceSettlementAdjustmentType::Replacement->value], true);
            $amountIsValid = preg_match('/^-?\d{1,17}(\.\d{1,2})?$/', $amountDelta) === 1
                && preg_match('/[1-9]/', $amountDelta) === 1;
            $signIsValid = $adjustment->kind === AdvanceSettlementAdjustmentType::Reversal->value
                ? str_starts_with($amountDelta, '-')
                : ! str_starts_with($amountDelta, '-');

            if (! $kindIsValid || ! $amountIsValid || ! $signIsValid) {
                throw new \InvalidArgumentException('Adjustment harus memiliki jenis dan arah nominal yang valid.');
            }

            if (! $adjustment->item()->where('review_status', AdvanceSettlementItemStatus::Accepted->value)->exists()) {
                throw new \InvalidArgumentException('Adjustment hanya dapat merujuk rincian bon yang telah diterima.');
            }

            $adjustment->adjustment_group_id ??= (string) Str::uuid7();
        });

        static::updating(fn (): never => throw new LogicException('Adjustment bon bersifat append-only.'));
        static::deleting(fn (): never => throw new LogicException('Adjustment bon bersifat append-only.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'amount_delta' => 'decimal:2', 'reviewed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AdvanceSettlementItem::class, 'advance_settlement_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
