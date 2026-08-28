<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Mcp\Support\BindLocalQuoteMcpSeller;
use App\Models\Account;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeSellerNotesUpload
{
    public function __construct(private BindLocalQuoteMcpSeller $localQuoteMcpSeller) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Account && app()->environment(['local', 'testing'])) {
            try {
                $this->localQuoteMcpSeller->authenticateConfiguredSeller();
                $user = $request->user();
            } catch (AuthenticationException) {
                abort(403);
            }
        }

        if (
            ! $user instanceof Account
            || $user->type !== AccountType::Seller
            || $user->active !== true
            || ! str_starts_with($user->code, AccountType::Seller->codePrefix())
        ) {
            abort(403);
        }

        return $next($request);
    }
}
