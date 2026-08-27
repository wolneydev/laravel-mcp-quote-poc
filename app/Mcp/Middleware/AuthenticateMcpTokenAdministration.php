<?php

namespace App\Mcp\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpTokenAdministration
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expectedHash = trim((string) config('mcp.administration.token_hash'));

        if (! is_string($token) || $token === '' || $expectedHash === '') {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        if (! hash_equals($expectedHash, hash('sha256', $token))) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        return $next($request);
    }
}
