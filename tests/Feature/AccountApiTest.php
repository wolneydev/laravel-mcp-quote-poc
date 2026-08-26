<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_seller_account_with_a_generated_code(): void
    {
        $response = $this->postJson('/api/accounts', $this->sellerPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'VEN-000001')
            ->assertJsonPath('data.type', AccountType::Seller->value)
            ->assertJsonPath('data.name', 'Jane Seller')
            ->assertJsonPath('data.email', 'jane.seller@example.test')
            ->assertJsonPath('data.customer_id', null)
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.user_id');

        $account = Account::query()->first();

        $this->assertNotNull($account);
        $this->assertTrue(Hash::check('password', $account->password));
        $this->assertFalse(Schema::hasColumn('accounts', 'user_id'));
        $this->assertArrayNotHasKey('password', $account->toArray());
    }

    public function test_it_creates_a_customer_account_linked_to_a_customer(): void
    {
        $customer = Customer::factory()->create(['code' => 'CUST-000001']);

        $this->postJson('/api/accounts', $this->customerPayload([
            'customer_id' => $customer->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CLI-000001')
            ->assertJsonPath('data.type', AccountType::Customer->value)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonMissingPath('data.password');
    }

    public function test_it_lists_paginated_accounts(): void
    {
        Account::factory()->count(16)->create();

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ])
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_it_shows_an_account(): void
    {
        $account = Account::factory()->create([
            'code' => 'VEN-000010',
            'name' => 'Jane Seller',
        ]);

        $this->getJson('/api/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.code', 'VEN-000010')
            ->assertJsonMissingPath('data.password');
    }

    public function test_it_updates_an_account(): void
    {
        $account = Account::factory()->create($this->sellerPayload([
            'code' => 'VEN-000001',
        ]));

        $this->putJson('/api/accounts/'.$account->id, $this->sellerPayload([
            'name' => 'Jane Seller-Updated',
            'email' => 'jane.updated@example.test',
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Seller-Updated')
            ->assertJsonPath('data.email', 'jane.updated@example.test')
            ->assertJsonPath('data.code', 'VEN-000001')
            ->assertJsonPath('data.type', AccountType::Seller->value);
    }

    public function test_it_inactivates_an_account_instead_of_deleting_it(): void
    {
        $account = Account::factory()->create(['active' => true]);

        $this->deleteJson('/api/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertModelExists($account);
        $this->assertFalse($account->refresh()->active);
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        Account::factory()->create(['email' => 'jane.seller@example.test']);

        $this->postJson('/api/accounts', $this->sellerPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_rejects_a_seller_with_a_customer_id(): void
    {
        $customer = Customer::factory()->create();

        $this->postJson('/api/accounts', $this->sellerPayload([
            'customer_id' => $customer->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_a_customer_account_without_a_customer_id(): void
    {
        $this->postJson('/api/accounts', $this->customerPayload([
            'customer_id' => null,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_a_customer_account_for_an_inactive_customer(): void
    {
        $customer = Customer::factory()->inactive()->create();

        $this->postJson('/api/accounts', $this->customerPayload([
            'customer_id' => $customer->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_a_second_account_for_the_same_customer(): void
    {
        $customer = Customer::factory()->create();
        Account::factory()->customer($customer)->create();

        $this->postJson('/api/accounts', $this->customerPayload([
            'customer_id' => $customer->id,
            'email' => 'second@example.test',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_it_rejects_changing_the_account_type(): void
    {
        $account = Account::factory()->create();
        $customer = Customer::factory()->create();

        $this->putJson('/api/accounts/'.$account->id, $this->sellerPayload([
            'type' => AccountType::Customer->value,
            'customer_id' => $customer->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_it_normalizes_email_to_lowercase(): void
    {
        $this->postJson('/api/accounts', $this->sellerPayload([
            'email' => '  Jane.Seller@Example.TEST  ',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.email', 'jane.seller@example.test');
    }

    public function test_it_searches_by_partial_name_email_or_code(): void
    {
        Account::factory()->create([
            'name' => 'Jane Seller',
            'email' => 'jane.seller@example.test',
            'code' => 'VEN-000001',
        ]);
        Account::factory()->create([
            'name' => 'John Vendor',
            'email' => 'john.vendor@example.test',
            'code' => 'VEN-000002',
        ]);

        $this->getJson('/api/accounts?search=jane')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Jane Seller');

        $this->getJson('/api/accounts?search=john.vendor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'john.vendor@example.test');

        $this->getJson('/api/accounts?search=VEN-000002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VEN-000002');
    }

    public function test_it_filters_by_exact_code(): void
    {
        Account::factory()->create(['code' => 'VEN-000001']);
        Account::factory()->create(['code' => 'VEN-000011']);

        $this->getJson('/api/accounts?code=VEN-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VEN-000001');
    }

    public function test_it_filters_by_type(): void
    {
        Account::factory()->seller()->create(['name' => 'Jane Seller']);
        Account::factory()->customer()->create(['name' => 'Alice Hart']);

        $this->getJson('/api/accounts?type=seller')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Jane Seller');

        $this->getJson('/api/accounts?type=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice Hart');
    }

    public function test_it_filters_by_customer_id(): void
    {
        $customer = Customer::factory()->create();
        Account::factory()->customer($customer)->create();
        Account::factory()->seller()->create();

        $this->getJson('/api/accounts?customer_id='.$customer->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_id', $customer->id);
    }

    public function test_it_filters_by_active_status(): void
    {
        Account::factory()->create(['name' => 'Active Account', 'active' => true]);
        Account::factory()->inactive()->create(['name' => 'Inactive Account']);

        $this->getJson('/api/accounts?active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Account');

        $this->getJson('/api/accounts?active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Inactive Account');
    }

    public function test_it_increments_generated_codes_per_type(): void
    {
        $this->postJson('/api/accounts', $this->sellerPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'VEN-000001');

        $this->postJson('/api/accounts', $this->sellerPayload([
            'email' => 'second.seller@example.test',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'VEN-000002');

        $customer = Customer::factory()->create();

        $this->postJson('/api/accounts', $this->customerPayload([
            'customer_id' => $customer->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CLI-000001');
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_it_rejects_invalid_payloads(array $payload, array $errors): void
    {
        $this->postJson('/api/accounts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'name' => 'Jane Seller',
            'email' => 'jane.seller@example.test',
            'password' => 'password',
            'type' => AccountType::Seller->value,
            'active' => true,
        ];

        return [
            'missing name' => [array_diff_key($valid, ['name' => true]), ['name']],
            'missing email' => [array_diff_key($valid, ['email' => true]), ['email']],
            'missing password' => [array_diff_key($valid, ['password' => true]), ['password']],
            'missing type' => [array_diff_key($valid, ['type' => true]), ['type']],
            'invalid type' => [array_merge($valid, ['type' => 'admin']), ['type']],
            'invalid email' => [array_merge($valid, ['email' => 'not-an-email']), ['email']],
            'short password' => [array_merge($valid, ['password' => 'short']), ['password']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sellerPayload(array $overrides = []): array
    {
        return [
            'name' => 'Jane Seller',
            'email' => 'jane.seller@example.test',
            'password' => 'password',
            'type' => AccountType::Seller->value,
            'active' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return [
            'name' => 'Alice Hart',
            'email' => 'alice.hart@northwind.test',
            'password' => 'password',
            'type' => AccountType::Customer->value,
            'active' => true,
            ...$overrides,
        ];
    }
}
