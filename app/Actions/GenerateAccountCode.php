<?php

namespace App\Actions;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateAccountCode
{
    public function handle(AccountType $type): string
    {
        $prefix = $type->codePrefix();

        return Cache::lock('account-code:'.$type->value, 10)->block(5, function () use ($type, $prefix): string {
            return DB::transaction(function () use ($type, $prefix): string {
                $lastCode = Account::query()
                    ->where('type', $type)
                    ->lockForUpdate()
                    ->orderByDesc('code')
                    ->value('code');

                $sequence = 0;

                if (is_string($lastCode) && Str::startsWith($lastCode, $prefix)) {
                    $sequence = (int) Str::after($lastCode, $prefix);
                }

                return sprintf('%s%06d', $prefix, $sequence + 1);
            });
        });
    }
}
