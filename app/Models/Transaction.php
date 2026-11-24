<?php

namespace App\Models;

use App\Services\TransactionNumberGenerator;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['type', 'occurred_at', 'description', 'notes', 'responsible_user_id', 'payment_method_id', 'source_cash_account_id', 'destination_cash_account_id', 'vendor_id', 'fund_source_id'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction): void {
            $transaction->public_id ??= (string) Str::uuid7();
            $transaction->number ??= app(TransactionNumberGenerator::class)->next($transaction->type, $transaction->occurred_at);
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'negative_balance_override_at' => 'datetime',
            'negative_balance_override' => 'boolean', 'total_amount' => 'decimal:2', 'negative_balance_shortfall' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function negativeBalanceOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'negative_balance_override_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function sourceCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'source_cash_account_id');
    }

    public function destinationCashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'destination_cash_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function fundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TransactionRevision::class);
    }

    public function replacementRevision(): HasOne
    {
        return $this->hasOne(TransactionRevision::class, 'replacement_transaction_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
