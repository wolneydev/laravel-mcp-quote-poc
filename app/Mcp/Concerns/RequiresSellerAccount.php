<?php

namespace App\Mcp\Concerns;

use App\Models\Account;
use App\Models\Quote;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;

trait RequiresSellerAccount
{
    protected function sellerAccount(Request $request): Account
    {
        $user = $request->user();

        if (! $user instanceof Account) {
            throw new AuthenticationException('Authentication is required.');
        }

        Gate::authorize('create', Quote::class);

        return $user;
    }
}
