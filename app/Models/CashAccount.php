<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'account_number', 'bank_name', 'is_active'])]
class CashAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function openingBalanceRequests(): HasMany
    {
        return $this->hasMany(OpeningBalanceRequest::class);
    }

    public function ledgerBalance(): string
    {
        $balanceInCents = '0';
        $isNegative = false;
        foreach ($this->movements()->cursor(['direction', 'amount']) as $movement) {
            $amountInCents = ltrim(str_replace('.', '', $movement->amount), '0') ?: '0';
            $movementIsNegative = $movement->direction === 'out';
            if ($balanceInCents === '0' || $isNegative === $movementIsNegative) {
                $balanceInCents = self::addMagnitudes($balanceInCents, $amountInCents);
                $isNegative = $movementIsNegative;
            } else {
                $comparison = self::compareMagnitudes($balanceInCents, $amountInCents);
                if ($comparison === 0) {
                    $balanceInCents = '0';
                    $isNegative = false;
                } elseif ($comparison > 0) {
                    $balanceInCents = self::subtractMagnitudes($balanceInCents, $amountInCents);
                } else {
                    $balanceInCents = self::subtractMagnitudes($amountInCents, $balanceInCents);
                    $isNegative = $movementIsNegative;
                }
            }
        }

        $whole = substr($balanceInCents, 0, -2) ?: '0';
        $fraction = str_pad(substr($balanceInCents, -2), 2, '0', STR_PAD_LEFT);

        return ($isNegative && $balanceInCents !== '0' ? '-' : '').$whole.'.'.$fraction;
    }

    private static function addMagnitudes(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        $left = strrev($left);
        $right = strrev($right);

        for ($index = 0, $length = max(strlen($left), strlen($right)); $index < $length; $index++) {
            $sum = ((int) ($left[$index] ?? 0)) + ((int) ($right[$index] ?? 0)) + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return strrev($result);
    }

    private static function subtractMagnitudes(string $left, string $right): string
    {
        $result = '';
        $borrow = 0;
        $left = strrev($left);
        $right = strrev($right);

        for ($index = 0, $length = max(strlen($left), strlen($right)); $index < $length; $index++) {
            $digit = (int) ($left[$index] ?? 0) - $borrow - (int) ($right[$index] ?? 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result .= (string) ($digit < 0 ? $digit + 10 : $digit);
        }

        return ltrim(strrev($result), '0') ?: '0';
    }

    private static function compareMagnitudes(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
