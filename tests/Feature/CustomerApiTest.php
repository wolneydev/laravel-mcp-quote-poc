<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_customer(): void
    {
        $response = $this->postJson('/api/customers', $this->customerPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'CUST-000001')
            ->assertJsonPath('data.name', 'Northwind Industrial Ltd')
            ->assertJsonPath('data.document', '123456789')
            ->assertJsonPath('data.email', 'purchasing@northwind.test')
            ->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.user_id');

        $this->assertSame(1, Customer::query()->count());
        $this->assertFalse(Schema::hasColumn('customers', 'password'));
        $this->assertFalse(Schema::hasColumn('customers', 'user_id'));
        $this->assertFalse(Schema::hasColumn('customers', 'metadata'));
    }

    public function test_it_lists_paginated_customers(): void
    {
        Customer::factory()->count(16)->create();

        $response = $this->getJson('/api/customers');

        $response
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);
    }

    public function test_it_shows_a_customer(): void
    {
        $customer = Customer::factory()->create([
            'code' => 'CUST-000010',
            'name' => 'Harbour Roofing Co',
        ]);

        $this->getJson('/api/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.code', 'CUST-000010')
            ->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.password');
    }

    public function test_it_updates_a_customer(): void
    {
        $customer = Customer::factory()->create($this->customerPayload());

        $this->putJson('/api/customers/'.$customer->id, $this->customerPayload([
            'name' => 'Updated Northwind',
            'contact_name' => 'Alice Hart-Updated',
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Northwind')
            ->assertJsonPath('data.contact_name', 'Alice Hart-Updated');
    }

    public function test_it_inactivates_a_customer_instead_of_deleting_it(): void
    {
        $customer = Customer::factory()->create(['active' => true]);

        $this->deleteJson('/api/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertModelExists($customer);
        $this->assertFalse($customer->refresh()->active);
    }

    public function test_it_rejects_a_duplicate_code(): void
    {
        Customer::factory()->create(['code' => 'CUST-000001']);

        $this->postJson('/api/customers', $this->customerPayload([
            'code' => 'CUST-000001',
            'name' => 'Another Customer',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_it_rejects_a_duplicate_document_when_present(): void
    {
        Customer::factory()->create(['document' => '123456789']);

        $this->postJson('/api/customers', $this->customerPayload([
            'code' => 'CUST-000099',
            'document' => '123.456.789',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document']);
    }

    public function test_it_allows_multiple_customers_without_a_document(): void
    {
        Customer::factory()->create(['document' => null]);

        $this->postJson('/api/customers', $this->customerPayload([
            'code' => 'CUST-000099',
            'document' => null,
            'email' => 'second@example.test',
        ]))
            ->assertCreated();

        $this->assertSame(2, Customer::query()->whereNull('document')->count());
    }

    public function test_it_rejects_a_duplicate_code_on_update_for_another_customer(): void
    {
        Customer::factory()->create(['code' => 'CUST-000001']);
        $customer = Customer::factory()->create(['code' => 'CUST-000002']);

        $this->putJson('/api/customers/'.$customer->id, $this->customerPayload([
            'code' => 'CUST-000001',
            'document' => $customer->document,
            'email' => $customer->email,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_it_allows_keeping_the_same_code_on_update(): void
    {
        $customer = Customer::factory()->create($this->customerPayload());

        $this->putJson('/api/customers/'.$customer->id, $this->customerPayload([
            'name' => 'Renamed Northwind',
        ]))
            ->assertOk()
            ->assertJsonPath('data.code', 'CUST-000001')
            ->assertJsonPath('data.name', 'Renamed Northwind');
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->postJson('/api/customers', $this->customerPayload([
            'email' => 'not-an-email',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_it_trims_code_and_name_and_normalizes_document_and_email(): void
    {
        $this->postJson('/api/customers', $this->customerPayload([
            'code' => '  CUST-000001  ',
            'name' => '  Northwind Industrial Ltd  ',
            'document' => '123.456.789-00',
            'email' => '  Purchasing@Northwind.TEST  ',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CUST-000001')
            ->assertJsonPath('data.name', 'Northwind Industrial Ltd')
            ->assertJsonPath('data.document', '12345678900')
            ->assertJsonPath('data.email', 'purchasing@northwind.test');
    }

    public function test_it_searches_by_partial_name(): void
    {
        Customer::factory()->create(['name' => 'Northwind Industrial Ltd', 'code' => 'CUST-000001']);
        Customer::factory()->create(['name' => 'Harbour Roofing Co', 'code' => 'CUST-000002']);

        $this->getJson('/api/customers?search=northwind')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Northwind Industrial Ltd');
    }

    public function test_it_searches_by_partial_code(): void
    {
        Customer::factory()->create(['name' => 'Northwind Industrial Ltd', 'code' => 'CUST-000001']);
        Customer::factory()->create(['name' => 'Harbour Roofing Co', 'code' => 'CUST-000002']);

        $this->getJson('/api/customers?search=CUST-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CUST-000001');
    }

    public function test_it_searches_by_partial_contact_name(): void
    {
        Customer::factory()->create(['contact_name' => 'Alice Hart', 'code' => 'CUST-000001']);
        Customer::factory()->create(['contact_name' => 'Ben Ortega', 'code' => 'CUST-000002']);

        $this->getJson('/api/customers?search=alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.contact_name', 'Alice Hart');
    }

    public function test_it_filters_by_exact_code(): void
    {
        Customer::factory()->create(['code' => 'CUST-000001']);
        Customer::factory()->create(['code' => 'CUST-000011']);

        $this->getJson('/api/customers?code=CUST-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CUST-000001');
    }

    public function test_it_filters_by_exact_normalized_document(): void
    {
        Customer::factory()->create(['document' => '123456789']);
        Customer::factory()->create(['document' => '987654321']);

        $this->getJson('/api/customers?document=123.456.789')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document', '123456789');
    }

    public function test_it_filters_by_exact_normalized_email(): void
    {
        Customer::factory()->create(['email' => 'purchasing@northwind.test']);
        Customer::factory()->create(['email' => 'ops@harbourroofing.test']);

        $this->getJson('/api/customers?email=Purchasing@Northwind.TEST')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'purchasing@northwind.test');
    }

    public function test_it_filters_by_active_status(): void
    {
        Customer::factory()->create(['name' => 'Active Customer', 'active' => true]);
        Customer::factory()->inactive()->create(['name' => 'Inactive Customer']);

        $this->getJson('/api/customers?active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Customer');

        $this->getJson('/api/customers?active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Inactive Customer');
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_it_rejects_invalid_payloads(array $payload, array $errors): void
    {
        $this->postJson('/api/customers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'code' => 'CUST-000001',
            'name' => 'Northwind Industrial Ltd',
            'document' => '123456789',
            'email' => 'purchasing@northwind.test',
            'phone' => '+14165550101',
            'contact_name' => 'Alice Hart',
            'active' => true,
        ];

        return [
            'missing name' => [array_diff_key($valid, ['name' => true]), ['name']],
            'missing code' => [array_diff_key($valid, ['code' => true]), ['code']],
            'invalid email' => [array_merge($valid, ['email' => 'bad-email']), ['email']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return [
            'code' => 'CUST-000001',
            'name' => 'Northwind Industrial Ltd',
            'document' => '123456789',
            'email' => 'purchasing@northwind.test',
            'phone' => '+14165550101',
            'contact_name' => 'Alice Hart',
            'active' => true,
            ...$overrides,
        ];
    }
}
