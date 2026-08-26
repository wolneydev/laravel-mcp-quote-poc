<?php

namespace Tests\Feature;

use App\Actions\Quotes\CreateQuoteAction;
use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QuoteApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_quote_with_price_snapshots_and_totals(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Mechanical Keyboard',
            'unit' => 'unit',
            'price' => '450.50',
            'currency' => 'CAD',
        ]);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 3],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.number', 'QUO-'.now()->year.'-000001')
            ->assertJsonPath('data.status', QuoteStatus::Draft->value)
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.total', '1351.50')
            ->assertJsonPath('data.seller_account.id', $seller->id)
            ->assertJsonPath('data.seller_account.code', $seller->code)
            ->assertJsonPath('data.customer_account.id', $customer->id)
            ->assertJsonPath('data.customer.id', $customer->customer_id)
            ->assertJsonPath('data.items.0.product_code', 'PROD-000001')
            ->assertJsonPath('data.items.0.unit_price', '450.50')
            ->assertJsonPath('data.items.0.line_total', '1351.50')
            ->assertJsonMissingPath('data.metadata');

        $quote = Quote::query()->first();

        $this->assertNotNull($quote);
        $this->assertSame('1351.50', $quote->total);
        $this->assertSame($quote->total, $quote->items->first()?->line_total);
        $this->assertTrue($seller->sellerQuotes->contains($quote));
        $this->assertTrue($customer->customerQuotes->contains($quote));
    }

    public function test_changing_the_product_price_does_not_change_the_persisted_quote(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create(['price' => '10.00', 'currency' => 'CAD']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.total', '20.00');

        $product->update(['price' => '99.99']);
        $quote = Quote::query()->firstOrFail();

        $this->actingAs($seller)
            ->getJson('/api/quotes/'.$quote->id)
            ->assertOk()
            ->assertJsonPath('data.total', '20.00')
            ->assertJsonPath('data.items.0.unit_price', '10.00')
            ->assertJsonPath('data.items.0.line_total', '20.00');
    }

    public function test_it_rejects_a_customer_account_as_the_seller(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create();

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'seller_account_id' => $customer->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seller_account_id']);
    }

    public function test_it_rejects_a_seller_account_as_the_customer(): void
    {
        $seller = Account::factory()->seller()->create();
        $otherSeller = Account::factory()->seller()->create();
        $product = Product::factory()->create();

        $this->actingAs($seller)
            ->postJson('/api/quotes', [
                'seller_account_id' => $seller->id,
                'customer_account_id' => $otherSeller->id,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_account_id']);
    }

    public function test_it_rejects_inactive_accounts_customers_and_products(): void
    {
        $seller = Account::factory()->seller()->create();
        $inactiveSeller = Account::factory()->seller()->inactive()->create();
        $inactiveCustomer = Account::factory()->customer()->inactive()->create();
        $customerWithInactiveProfile = Account::factory()->customer(Customer::factory()->inactive()->create())->create();
        $activeCustomer = Account::factory()->customer()->create();
        $inactiveProduct = Product::factory()->inactive()->create();
        $activeProduct = Product::factory()->create();

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($inactiveSeller, $activeCustomer, [
                'items' => [
                    ['product_id' => $activeProduct->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seller_account_id']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $inactiveCustomer, [
                'items' => [
                    ['product_id' => $activeProduct->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_account_id']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customerWithInactiveProfile, [
                'items' => [
                    ['product_id' => $activeProduct->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_account_id']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $activeCustomer, [
                'items' => [
                    ['product_id' => $inactiveProduct->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_it_rejects_invalid_quantities_repeated_products_and_mixed_currencies(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $cad = Product::factory()->create(['currency' => 'CAD']);
        $usd = Product::factory()->create(['currency' => 'USD']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $cad->id, 'quantity' => 0],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $cad->id, 'quantity' => -1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $cad->id, 'quantity' => 1],
                    ['product_id' => $cad->id, 'quantity' => 2],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id', 'items.1.product_id']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $cad->id, 'quantity' => 1],
                    ['product_id' => $usd->id, 'quantity' => 1],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    public function test_a_failed_quote_item_creation_rolls_back_the_quote(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create(['price' => '10.00']);

        QuoteItem::creating(function (): void {
            throw new RuntimeException('Unable to persist quote item.');
        });

        try {
            app(CreateQuoteAction::class)->handle(
                sellerAccount: $seller,
                customerAccount: $customer,
                items: [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            );

            $this->fail('Expected quote item persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to persist quote item.', $exception->getMessage());
        } finally {
            QuoteItem::flushEventListeners();
        }

        $this->assertSame(0, Quote::query()->count());
        $this->assertSame(0, QuoteItem::query()->count());
    }

    public function test_it_lists_only_quotes_owned_by_the_authenticated_seller(): void
    {
        $seller = Account::factory()->seller()->create();
        $otherSeller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();

        $owned = Quote::factory()->create([
            'seller_account_id' => $seller->id,
            'customer_account_id' => $customer->id,
        ]);
        Quote::factory()->create([
            'seller_account_id' => $otherSeller->id,
            'customer_account_id' => $customer->id,
        ]);

        $this->actingAs($seller)
            ->getJson('/api/quotes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id);
    }

    public function test_a_seller_cannot_view_another_sellers_quote(): void
    {
        $seller = Account::factory()->seller()->create();
        $otherSeller = Account::factory()->seller()->create();
        $quote = Quote::factory()->create([
            'seller_account_id' => $otherSeller->id,
        ]);

        $this->actingAs($seller)
            ->getJson('/api/quotes/'.$quote->id)
            ->assertForbidden();
    }

    public function test_it_updates_a_draft_quote_and_rejects_edits_after_submit(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $first = Product::factory()->create(['price' => '10.00', 'currency' => 'CAD']);
        $second = Product::factory()->create(['price' => '7.50', 'currency' => 'CAD']);

        $quoteId = $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $first->id, 'quantity' => 1],
                ],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($seller)
            ->putJson('/api/quotes/'.$quoteId, $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $second->id, 'quantity' => 2],
                ],
                'notes' => 'Updated',
            ]))
            ->assertOk()
            ->assertJsonPath('data.total', '15.00')
            ->assertJsonPath('data.notes', 'Updated')
            ->assertJsonPath('data.items.0.product_id', $second->id);

        $this->actingAs($seller)
            ->postJson('/api/quotes/'.$quoteId.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', QuoteStatus::PendingApproval->value);

        $this->actingAs($seller)
            ->putJson('/api/quotes/'.$quoteId, $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $first->id, 'quantity' => 9],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_submits_approves_and_rejects_quotes_with_authorization(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $otherCustomer = Account::factory()->customer()->create();
        $product = Product::factory()->create(['price' => '8.00']);

        $quoteId = $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]))
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson('/api/quotes/'.$quoteId.'/submit')
            ->assertForbidden();

        $this->actingAs($seller)
            ->postJson('/api/quotes/'.$quoteId.'/approve')
            ->assertForbidden();

        $this->actingAs($seller)
            ->postJson('/api/quotes/'.$quoteId.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', QuoteStatus::PendingApproval->value);

        $this->actingAs($otherCustomer)
            ->postJson('/api/quotes/'.$quoteId.'/approve')
            ->assertForbidden();

        $this->actingAs($customer)
            ->postJson('/api/quotes/'.$quoteId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', QuoteStatus::Approved->value);

        $this->assertNotNull(Quote::query()->find($quoteId)?->approved_at);

        $secondQuoteId = $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]))
            ->json('data.id');

        $this->actingAs($seller)->postJson('/api/quotes/'.$secondQuoteId.'/submit')->assertOk();

        $this->actingAs($customer)
            ->postJson('/api/quotes/'.$secondQuoteId.'/reject')
            ->assertOk()
            ->assertJsonPath('data.status', QuoteStatus::Rejected->value);

        $this->actingAs($seller)
            ->postJson('/api/quotes/'.$secondQuoteId.'/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_deletes_a_draft_quote_and_rejects_deleting_submitted_quotes(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create();

        $draftId = $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]))
            ->json('data.id');

        $this->actingAs($seller)
            ->deleteJson('/api/quotes/'.$draftId)
            ->assertOk();

        $this->assertSame(0, Quote::query()->count());

        $submittedId = $this->actingAs($seller)
            ->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]))
            ->json('data.id');

        $this->actingAs($seller)->postJson('/api/quotes/'.$submittedId.'/submit')->assertOk();

        $this->actingAs($seller)
            ->deleteJson('/api/quotes/'.$submittedId)
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_cannot_create_quotes(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/quotes', $this->quotePayload($seller, $customer, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]))->assertUnauthorized();
    }

    public function test_quote_relationships_resolve_accounts_items_and_products(): void
    {
        $seller = Account::factory()->seller()->create();
        $customer = Account::factory()->customer()->create();
        $product = Product::factory()->create();
        $quote = Quote::factory()->create([
            'seller_account_id' => $seller->id,
            'customer_account_id' => $customer->id,
        ]);
        $item = QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit' => $product->unit,
        ]);

        $this->assertTrue($quote->sellerAccount()->is($seller));
        $this->assertTrue($quote->customerAccount()->is($customer));
        $this->assertTrue($quote->customerAccount->customer()->is($customer->customer));
        $this->assertTrue($item->quote()->is($quote));
        $this->assertTrue($item->product()->is($product));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function quotePayload(Account $seller, Account $customer, array $overrides = []): array
    {
        return [
            'seller_account_id' => $seller->id,
            'customer_account_id' => $customer->id,
            'valid_until' => '2026-09-30',
            'notes' => 'Optional',
            ...$overrides,
        ];
    }
}
