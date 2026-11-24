<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class OperationalAdvanceNumberGenerator
{
    public function next(DateTimeInterface|string $requestedAt): string
    {
        $period = CarbonImmutable::parse($requestedAt)->format('Ym');

        return DB::transaction(function () use ($period): string {
            $now = now();
            DB::table('advance_number_sequences')->insertOrIgnore([
                'period' => $period,
                'last_number' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = DB::table('advance_number_sequences')
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            $nextNumber = $sequence->last_number + 1;
            DB::table('advance_number_sequences')->where('id', $sequence->id)->update([
                'last_number' => $nextNumber,
                'updated_at' => $now,
            ]);

            return sprintf('BON/%s/%04d', $period, $nextNumber);
        });
    }
}
