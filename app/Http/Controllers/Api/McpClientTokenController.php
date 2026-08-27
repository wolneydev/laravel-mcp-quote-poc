<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMcpClientTokenRequest;
use App\Http\Requests\UpdateMcpClientTokenRequest;
use App\Http\Resources\McpClientTokenResource;
use App\Models\McpClientToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class McpClientTokenController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = McpClientToken::query()
            ->when(
                $request->has('revoked'),
                fn (Builder $query) => $query->where('revoked', $request->boolean('revoked')),
            )
            ->latest('id')
            ->paginate();

        return McpClientTokenResource::collection($tokens);
    }

    public function store(StoreMcpClientTokenRequest $request): JsonResponse
    {
        $rawToken = bin2hex(random_bytes(32));

        $token = McpClientToken::query()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_in_days' => $request->integer('expires_in_days'),
            'revoked' => false,
        ]);

        return (new McpClientTokenResource($token))
            ->withPlainToken($rawToken)
            ->response()
            ->setStatusCode(201);
    }

    public function show(McpClientToken $mcpClientToken): McpClientTokenResource
    {
        return new McpClientTokenResource($mcpClientToken);
    }

    public function update(UpdateMcpClientTokenRequest $request, McpClientToken $mcpClientToken): McpClientTokenResource
    {
        $mcpClientToken->update($request->validated());

        return new McpClientTokenResource($mcpClientToken);
    }

    public function destroy(McpClientToken $mcpClientToken): McpClientTokenResource
    {
        $mcpClientToken->update(['revoked' => true]);

        return new McpClientTokenResource($mcpClientToken->refresh());
    }
}
