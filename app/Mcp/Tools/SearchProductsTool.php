<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSellerAccount;
use App\Mcp\Lookups\ProductLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_products')]
#[Description('Search products by public code (PROD-*) or name. Prefer exact codes. Returns at most 10 results.')]
#[IsReadOnly]
class SearchProductsTool extends Tool
{
    use RequiresSellerAccount;

    public function __construct(private ProductLookup $products) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Product name or public code (PROD-*). Exact code matches are preferred.')
                ->min(1)
                ->max(255)
                ->required(),
            'active_only' => $schema->boolean()
                ->description('When true, inactive products are excluded.')
                ->default(true),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return.')
                ->min(1)
                ->max(ProductLookup::MAX_RESULTS)
                ->default(ProductLookup::MAX_RESULTS),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $this->sellerAccount($request);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'active_only' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ProductLookup::MAX_RESULTS],
        ]);

        $products = $this->products->search(
            $validated['query'],
            array_key_exists('active_only', $validated) ? (bool) $validated['active_only'] : true,
            (int) ($validated['limit'] ?? ProductLookup::MAX_RESULTS),
        );

        return Response::structured([
            'products' => $products->map(fn ($product): array => $this->products->summary($product))->values()->all(),
        ]);
    }
}
