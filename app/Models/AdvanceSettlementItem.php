<?php

namespace App\Models;

use Database\Factories\AdvanceSettlementItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'advance_settlement_id', 'replaces_item_id', 'occurred_at', 'description', 'category_id', 'subcategory_id',
    'activity_id', 'vendor_id', 'amount', 'review_status', 'reviewed_by', 'reviewed_at', 'review_reason', 'submitted_by',
])]
class AdvanceSettlementItem extends Model
{
    /** @use HasFactory<AdvanceSettlementItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (AdvanceSettlementItem $item): void {
            $validStatuses = array_map(fn (AdvanceSettlementItemStatus $status): string => $status->value, AdvanceSettlementItemStatus::cases());
            if (preg_match('/^(?=.*[1-9])\d{1,17}(\.\d{1,2})?$/', (string) $item->amount) !== 1
                || ! in_array($item->review_status, $validStatuses, true)) {
                throw new \InvalidArgumentException('Nominal atau status rincian pertanggungjawaban tidak valid.');
            }
        });
        static::updating(function (AdvanceSettlementItem $item): void {
            if ($item->getOriginal('review_status') === AdvanceSettlementItemStatus::Accepted->value) {
                throw new LogicException('Rincian bon yang diterima bersifat immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Riwayat item pertanggungjawaban tidak dapat dihapus.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(AdvanceSettlement::class, 'advance_settlement_id');
    }

    public function replacesItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_item_id');
    }

    public function replacement(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_item_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AdvanceSettlementAttachment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(AdvanceSettlementItemAdjustment::class);
    }
}
