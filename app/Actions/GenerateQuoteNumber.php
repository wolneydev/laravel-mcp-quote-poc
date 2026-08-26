<?php

namespace App\Actions;

use App\Models\Quote;
use Illuminate\Support\Str;

class GenerateQuoteNumber
{
    public function handle(?int $year = null): string
    {
        $year ??= now()->year;
        $prefix = sprintf('QUO-%d-', $year);

        $lastNumber = Quote::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $sequence = 0;

        if (is_string($lastNumber) && Str::startsWith($lastNumber, $prefix)) {
            $sequence = (int) Str::after($lastNumber, $prefix);
        }

        return sprintf('%s%06d', $prefix, $sequence + 1);
    }
}
