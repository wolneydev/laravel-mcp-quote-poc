<?php

namespace App\Mcp\Support;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Throwable;

class BindLocalQuoteMcpSeller
{
    public function bindIfLocalStdio(Transport $transport): void
    {
        if (! $transport instanceof StdioTransport) {
            return;
        }

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        try {
            $this->authenticateConfiguredSeller();
        } catch (Throwable) {
            // Leave the process unauthenticated. Quote tools still fail closed
            // via RequiresSellerAccount. Throwing here would print on stdout and
            // prevent Claude Code from completing the MCP handshake.
        }
    }

    public function authenticateConfiguredSeller(): void
    {
        $sellerAccountCode = strtoupper(trim((string) config('mcp.quotes.seller_account_code')));

        if ($sellerAccountCode === '') {
            throw new AuthenticationException('Authentication is required.');
        }

        $account = Account::query()->where('code', $sellerAccountCode)->first();

        if (
            ! $account instanceof Account
            || $account->type !== AccountType::Seller
            || $account->active !== true
            || ! str_starts_with($account->code, AccountType::Seller->codePrefix())
        ) {
            throw new AuthenticationException('Authentication is required.');
        }

        Auth::guard()->setUser($account);
    }
}
