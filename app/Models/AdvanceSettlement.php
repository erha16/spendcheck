<?php

namespace App\Models;

use Database\Factories\AdvanceSettlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'operational_advance_id', 'batch_number', 'status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'submission_key',
])]
class AdvanceSettlement extends Model
{
    /** @use HasFactory<AdvanceSettlementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (AdvanceSettlement $settlement): void {
            $validStatuses = array_map(fn (AdvanceSettlementStatus $status): string => $status->value, AdvanceSettlementStatus::cases());
            if ($settlement->batch_number < 1 || ! in_array($settlement->status, $validStatuses, true)) {
                throw new \InvalidArgumentException('Nomor batch atau status pertanggungjawaban tidak valid.');
            }
        });
    }

    public function operationalAdvance(): BelongsTo
    {
        return $this->belongsTo(OperationalAdvance::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AdvanceSettlementItem::class);
    }
}
