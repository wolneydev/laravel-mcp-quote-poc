<?php

namespace Tests\Feature\Mcp;

use App\Actions\Quotes\CreateQuoteAction;
use App\Enums\QuoteStatus;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\GenerateQuoteReportTool;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Quotes\QuotePricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GenerateQuoteReportToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_quote_from_public_codes_using_shared_pricing(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        $profile = Customer::factory()->create([
            'code' => 'CUST-000001',
            'name' => 'Acme Ltd',
        ]);
        $customerAccount = Account::factory()->customer($profile)->create(['code' => 'CLI-000001']);
        $product = Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Premium Keyboard',
            'unit' => 'unit',
            'price' => '450.50',
            'currency' => 'CAD',
        ]);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'seller_account_code' => 'VEN-000001',
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 3],
                ],
                'valid_until' => '2026-09-30',
                'notes' => 'Optional',
            ])
            ->assertOk()
            ->assertName('generate_quote_report')
            ->assertStructuredContent(function ($json) use ($seller, $profile, $customerAccount, $product): void {
                $json->where('quote_number', 'QUO-'.now()->year.'-000001')
                    ->where('status', QuoteStatus::Draft->value)
                    ->where('total', '1351.50')
                    ->where('currency', 'CAD')
                    ->where('seller.account_id', $seller->id)
                    ->where('seller.account_code', 'VEN-000001')
                    ->where('customer.customer_id', $profile->id)
                    ->where('customer.customer_code', 'CUST-000001')
                    ->where('customer.account_id', $customerAccount->id)
                    ->where('customer.account_code', 'CLI-000001')
                    ->where('items.0.product_id', $product->id)
                    ->where('items.0.product_code', 'PROD-000001')
                    ->where('items.0.unit_price', '450.50')
                    ->where('items.0.line_total', '1351.50')
                    ->where('approval_summary', 'Quote QUO-'.now()->year.'-000001 has status draft and is not approved.')
                    ->etc();
            })
            ->assertSee('is not approved')
            ->assertSee('Total: CAD 1351.50')
            ->assertSee('Unit price')
            ->assertSee('Line total');

        $this->assertSame(1, Quote::query()->count());
        $quote = Quote::query()->first();
        $this->assertNotNull($quote);
        $this->assertSame($seller->id, $quote->seller_account_id);
        $this->assertSame($customerAccount->id, $quote->customer_account_id);
        $this->assertSame('1351.50', $quote->total);
        $this->assertSame('450.50', $quote->items->first()?->unit_price);
        $this->assertInstanceOf(CreateQuoteAction::class, app(CreateQuoteAction::class));
        $this->assertInstanceOf(QuotePricingService::class, app(QuotePricingService::class));
    }

    public function test_it_resolves_unique_names_and_rejects_ambiguous_inactive_and_invalid_inputs(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        $otherSeller = Account::factory()->seller()->create(['code' => 'VEN-000002']);
        $profile = Customer::factory()->create(['code' => 'CUST-000001', 'name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create(['code' => 'CLI-000001']);
        $secondProfile = Customer::factory()->create(['name' => 'Acme North']);
        Account::factory()->customer($secondProfile)->create();
        $thirdProfile = Customer::factory()->create(['name' => 'Acme South']);
        Account::factory()->customer($thirdProfile)->create();
        $inactiveCustomer = Customer::factory()->inactive()->create(['name' => 'Inactive Co']);
        Account::factory()->customer($inactiveCustomer)->create();
        $product = Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Unique Gadget',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);
        Product::factory()->create(['name' => 'Blue Widget', 'currency' => 'CAD']);
        Product::factory()->create(['name' => 'Blue Widget Pro', 'currency' => 'CAD']);
        $inactiveProduct = Product::factory()->inactive()->create(['code' => 'PROD-000099']);
        $usd = Product::factory()->create(['currency' => 'USD']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'Acme Ltd',
                'items' => [
                    ['product' => 'Unique Gadget', 'quantity' => 2],
                ],
            ])
            ->assertOk()
            ->assertSee('QUO-');

        $this->assertSame(1, Quote::query()->count());

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'Acme',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['ambiguous_match']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'Blue Widget', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['ambiguous_match']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'Inactive Co',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['inactive_customer']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000099', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['inactive_product']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'seller_account_code' => 'VEN-000002',
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['unauthorized_seller']);

        QuoteServer::actingAs($otherSeller)
            ->tool(GenerateQuoteReportTool::class, [
                'seller_account_code' => 'VEN-000001',
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['unauthorized_seller']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 0],
                ],
            ])
            ->assertHasErrors();

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                    ['product' => 'PROD-000001', 'quantity' => 2],
                ],
            ])
            ->assertHasErrors(['repeated_product']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                    ['product' => $usd->code, 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['mixed_currency']);

        $this->assertSame(1, Quote::query()->count());
        $this->assertTrue($product->is(Product::query()->find($product->id)));
    }

    public function test_a_customer_account_cannot_generate_a_quote_report(): void
    {
        $customer = Account::factory()->customer()->create();
        Product::factory()->create(['code' => 'PROD-000001']);

        QuoteServer::actingAs($customer)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors();
    }

    public function test_it_rejects_caller_provided_prices_and_totals(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create(['code' => 'CUST-000001']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create(['code' => 'PROD-000001', 'price' => '10.00', 'currency' => 'CAD']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1, 'unit_price' => '1.00'],
                ],
            ])
            ->assertHasErrors();

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
                'total' => '1.00',
            ])
            ->assertHasErrors();

        $this->assertSame(0, Quote::query()->count());
    }

    public function test_an_inactive_seller_cannot_generate_a_quote_report(): void
    {
        $seller = Account::factory()->seller()->inactive()->create();
        Product::factory()->create(['code' => 'PROD-000001']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors();
    }
}
