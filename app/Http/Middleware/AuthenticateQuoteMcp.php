<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQuoteMcp
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && ! app()->environment(['local', 'testing'])) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $token = $request->bearerToken();
        $expectedHash = trim((string) config('mcp.quotes.token_hash'));
        $sellerAccountCode = strtoupper(trim((string) config('mcp.quotes.seller_account_code')));

        if (! is_string($token) || $token === '' || $expectedHash === '' || $sellerAccountCode === '') {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! hash_equals($expectedHash, hash('sha256', $token))) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $account = Account::query()->where('code', $sellerAccountCode)->first();

        if (
            ! $account instanceof Account
            || $account->type !== AccountType::Seller
            || $account->active !== true
            || ! str_starts_with($account->code, AccountType::Seller->codePrefix())
        ) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Auth::guard()->setUser($account);

        return $next($request);
    }
}
