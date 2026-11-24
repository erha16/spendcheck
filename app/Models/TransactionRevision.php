<?php

namespace App\Models;

use Database\Factories\TransactionRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TransactionRevision extends Model
{
    /** @use HasFactory<TransactionRevisionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'corrected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Transaction revisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Transaction revisions are append-only.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'replacement_transaction_id');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
