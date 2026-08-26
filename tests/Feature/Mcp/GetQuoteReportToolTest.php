<?php

namespace Tests\Feature\Mcp;

use App\Actions\Quotes\CreateQuoteAction;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\GetQuoteReportTool;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GetQuoteReportToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_persisted_prices_after_the_product_price_changes(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create();
        $customerAccount = Account::factory()->customer($profile)->create();
        $product = Product::factory()->create([
            'code' => 'PROD-000001',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);

        $quote = app(CreateQuoteAction::class)->handle(
            sellerAccount: $seller,
            customerAccount: $customerAccount,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        );

        $product->update(['price' => '99.99']);

        QuoteServer::actingAs($seller)
            ->tool(GetQuoteReportTool::class, [
                'quote_number' => $quote->number,
            ])
            ->assertOk()
            ->assertName('get_quote_report')
            ->assertStructuredContent(function ($json) use ($quote): void {
                $json->where('quote_id', $quote->id)
                    ->where('quote_number', $quote->number)
                    ->where('total', '20.00')
                    ->where('items.0.unit_price', '10.00')
                    ->where('items.0.line_total', '20.00')
                    ->etc();
            });

        QuoteServer::actingAs($seller)
            ->tool(GetQuoteReportTool::class, [
                'quote_id' => $quote->id,
            ])
            ->assertOk()
            ->assertSee($quote->number);
    }

    public function test_unauthorized_quote_reads_are_rejected(): void
    {
        $seller = Account::factory()->seller()->create();
        $otherSeller = Account::factory()->seller()->create();
        $quote = Quote::factory()->create([
            'seller_account_id' => $seller->id,
        ]);

        QuoteServer::actingAs($otherSeller)
            ->tool(GetQuoteReportTool::class, [
                'quote_id' => $quote->id,
            ])
            ->assertHasErrors();

        auth()->logout();

        QuoteServer::tool(GetQuoteReportTool::class, [
            'quote_id' => $quote->id,
        ])->assertHasErrors(['Authentication is required.']);
    }
}
