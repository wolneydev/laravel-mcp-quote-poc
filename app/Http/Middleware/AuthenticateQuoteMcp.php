<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\McpClientToken;
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
        $sellerAccountCode = strtoupper(trim((string) config('mcp.quotes.seller_account_code')));

        if (! is_string($token) || $token === '' || $sellerAccountCode === '') {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        $tokenHash = hash('sha256', $token);
        $clientToken = McpClientToken::query()->where('token_hash', $tokenHash)->first();

        if (
            ! $clientToken instanceof McpClientToken
            || ! hash_equals($clientToken->token_hash, $tokenHash)
            || ! $clientToken->isUsable()
        ) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        $account = Account::query()->where('code', $sellerAccountCode)->first();

        if (
            ! $account instanceof Account
            || $account->type !== AccountType::Seller
            || $account->active !== true
            || ! str_starts_with($account->code, AccountType::Seller->codePrefix())
        ) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        Auth::guard()->setUser($account);

        return $next($request);
    }
}
