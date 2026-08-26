<?php

namespace Tests\Feature;

use App\Models\Customer;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CustomerSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_customers_idempotently_by_code(): void
    {
        $this->seed(CustomerSeeder::class);
        $this->seed(CustomerSeeder::class);

        $this->assertSame(5, Customer::query()->count());
        $this->assertSame(5, Customer::query()->distinct()->count('code'));
    }

    public function test_it_updates_existing_rows_with_matching_codes(): void
    {
        $this->seed(CustomerSeeder::class);

        Customer::query()->where('code', 'CUST-000001')->update([
            'name' => 'Outdated Northwind',
            'email' => 'old@northwind.test',
        ]);

        $this->seed(CustomerSeeder::class);

        $customer = Customer::query()->where('code', 'CUST-000001')->first();

        $this->assertNotNull($customer);
        $this->assertSame('Northwind Industrial Ltd', $customer->name);
        $this->assertSame('purchasing@northwind.test', $customer->email);
        $this->assertSame(5, Customer::query()->count());
    }
}
