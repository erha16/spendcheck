<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionNumberGenerator
{
    public function next(string $type, DateTimeInterface|string $occurredAt): string
    {
        $prefix = match ($type) {
            'expense' => 'EXP',
            'income' => 'INC',
            'transfer' => 'TRF',
            default => throw new InvalidArgumentException('Jenis transaksi tidak didukung.'),
        };
        $period = CarbonImmutable::parse($occurredAt)->format('Ym');

        return DB::transaction(function () use ($type, $period, $prefix): string {
            $now = now();
            DB::table('transaction_number_sequences')->insertOrIgnore([
                'type' => $type,
                'period' => $period,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = DB::table('transaction_number_sequences')
                ->where('type', $type)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            $nextNumber = $sequence->last_number + 1;
            DB::table('transaction_number_sequences')->where('id', $sequence->id)->update([
                'last_number' => $nextNumber,
                'updated_at' => $now,
            ]);

            return sprintf('%s/%s/%04d', $prefix, $period, $nextNumber);
        });
    }
}
