<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\SearchCustomersTool;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SearchCustomersToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_finds_customers_by_code_account_code_and_name(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create([
            'code' => 'CUST-000001',
            'name' => 'Acme Ltd',
            'document' => '12345678',
        ]);
        $account = Account::factory()->customer($profile)->create([
            'code' => 'CLI-000001',
        ]);
        Customer::factory()->create(['name' => 'Other Co']);

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => 'CUST-000001'])
            ->assertOk()
            ->assertName('search_customers')
            ->assertStructuredContent(function ($json) use ($profile, $account): void {
                $json->has('customers', 1)
                    ->where('customers.0.customer_id', $profile->id)
                    ->where('customers.0.customer_code', 'CUST-000001')
                    ->where('customers.0.customer_name', 'Acme Ltd')
                    ->where('customers.0.document_present', true)
                    ->missing('customers.0.document')
                    ->missing('customers.0.email')
                    ->missing('customers.0.phone')
                    ->missing('customers.0.contact_name')
                    ->where('customers.0.customer_account_id', $account->id)
                    ->where('customers.0.customer_account_code', 'CLI-000001')
                    ->etc();
            })
            ->assertDontSee('password')
            ->assertDontSee('12345678');

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => 'CLI-000001'])
            ->assertOk()
            ->assertSee('CUST-000001');

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => 'Acme'])
            ->assertOk()
            ->assertSee('CUST-000001')
            ->assertDontSee('Other Co');
    }

    public function test_ambiguous_names_return_candidates_and_secrets_are_never_returned(): void
    {
        $seller = Account::factory()->seller()->create();
        $first = Customer::factory()->create(['name' => 'Acme North']);
        $second = Customer::factory()->create(['name' => 'Acme South']);
        Account::factory()->customer($first)->create();
        Account::factory()->customer($second)->create();

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => 'Acme'])
            ->assertOk()
            ->assertSee('Acme North')
            ->assertSee('Acme South')
            ->assertDontSee('password')
            ->assertStructuredContent(fn ($json) => $json->has('customers', 2)->etc());
    }
}
