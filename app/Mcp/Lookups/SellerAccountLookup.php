<?php

namespace App\Mcp\Lookups;

use App\Enums\AccountType;
use App\Mcp\Exceptions\QuoteLookupException;
use App\Models\Account;
use Illuminate\Contracts\Auth\Authenticatable;

class SellerAccountLookup
{
    public function resolve(?string $code, ?Authenticatable $authenticated): Account
    {
        if (! $authenticated instanceof Account) {
            throw new QuoteLookupException(
                'unauthorized_seller',
                'Authentication is required to generate a quote report.',
                field: 'seller_account_code',
            );
        }

        if ($authenticated->active !== true) {
            throw new QuoteLookupException(
                'inactive_account',
                'The authenticated account is inactive.',
                field: 'seller_account_code',
            );
        }

        if ($authenticated->type !== AccountType::Seller) {
            throw new QuoteLookupException(
                'wrong_account_type',
                'Only seller accounts can generate quote reports.',
                field: 'seller_account_code',
            );
        }

        $requestedCode = is_string($code) ? strtoupper(trim($code)) : '';

        if ($requestedCode === '') {
            return $authenticated;
        }

        $seller = Account::query()->where('code', $requestedCode)->first();

        if ($seller === null) {
            throw new QuoteLookupException(
                'not_found',
                "Seller account [{$requestedCode}] was not found.",
                field: 'seller_account_code',
            );
        }

        if ($seller->active !== true) {
            throw new QuoteLookupException(
                'inactive_account',
                "Seller account [{$requestedCode}] is inactive.",
                field: 'seller_account_code',
            );
        }

        if ($seller->type !== AccountType::Seller) {
            throw new QuoteLookupException(
                'wrong_account_type',
                "Account [{$requestedCode}] is not a seller account.",
                field: 'seller_account_code',
            );
        }

        if ($seller->id !== $authenticated->id) {
            throw new QuoteLookupException(
                'unauthorized_seller',
                'The seller account must match the authenticated seller.',
                field: 'seller_account_code',
            );
        }

        return $seller;
    }
}
