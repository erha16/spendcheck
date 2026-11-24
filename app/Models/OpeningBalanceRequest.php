<?php

namespace App\Models;

use Database\Factories\OpeningBalanceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['cash_account_id', 'amount', 'effective_at', 'reason', 'status', 'proposed_by', 'proposed_at', 'reviewed_by', 'reviewed_at', 'review_notes'])]
class OpeningBalanceRequest extends Model
{
    /** @use HasFactory<OpeningBalanceRequestFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (OpeningBalanceRequest $request): void {
            if (! preg_match('/^(?=.*[1-9])\d+(\.\d{1,2})?$/', (string) $request->amount)) {
                throw new \InvalidArgumentException('Saldo awal harus bernilai positif.');
            }

            $request->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_at' => 'datetime', 'proposed_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(CashMovement::class);
    }
}
