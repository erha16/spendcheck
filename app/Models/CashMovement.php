<?php

namespace App\Models;

use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['cash_account_id', 'transaction_id', 'opening_balance_request_id', 'kind', 'direction', 'amount', 'idempotency_key', 'occurred_at', 'recorded_at', 'created_by', 'reversal_of_id', 'transfer_leg'])]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cash ledger movements are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Cash ledger movements are append-only.'));

        static::creating(function (CashMovement $movement): void {
            if (! in_array($movement->direction, ['in', 'out'], true) || ! preg_match('/^(?=.*[1-9])\d+(\.\d{1,2})?$/', (string) $movement->amount)) {
                throw new \InvalidArgumentException('Cash movement harus memiliki arah valid dan nominal positif.');
            }

            $expectedTransferLeg = match ($movement->kind) {
                'transfer_out' => 'out',
                'transfer_in' => 'in',
                default => null,
            };
            if ($movement->transfer_leg !== $expectedTransferLeg) {
                throw new \InvalidArgumentException('Jenis movement transfer dan leg harus konsisten.');
            }

            $movement->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function openingBalanceRequest(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }
}
