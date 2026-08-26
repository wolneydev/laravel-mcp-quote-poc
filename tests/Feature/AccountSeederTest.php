<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Database\Seeders\AccountSeeder;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_accounts_idempotently_by_code(): void
    {
        $this->seed(CustomerSeeder::class);
        $this->seed(AccountSeeder::class);
        $this->seed(AccountSeeder::class);

        $this->assertSame(5, Account::query()->count());
        $this->assertSame(5, Account::query()->distinct()->count('code'));
        $this->assertSame(5, Account::query()->distinct()->count('email'));
        $this->assertSame(2, Account::query()->where('type', AccountType::Seller)->count());
        $this->assertSame(3, Account::query()->where('type', AccountType::Customer)->count());
    }

    public function test_it_resolves_customer_accounts_by_customer_code(): void
    {
        $this->seed(CustomerSeeder::class);
        $this->seed(AccountSeeder::class);

        $customer = Customer::query()->where('code', 'CUST-000001')->first();
        $account = Account::query()->where('code', 'CLI-000001')->first();

        $this->assertNotNull($customer);
        $this->assertNotNull($account);
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertNull(Account::query()->where('code', 'VEN-000001')->value('customer_id'));
        $this->assertTrue(Hash::check('password', $account->password));
    }

    public function test_it_updates_existing_rows_with_matching_codes(): void
    {
        $this->seed(CustomerSeeder::class);
        $this->seed(AccountSeeder::class);

        Account::query()->where('code', 'VEN-000001')->update([
            'name' => 'Outdated Seller',
            'email' => 'old.seller@example.test',
        ]);

        $this->seed(AccountSeeder::class);

        $account = Account::query()->where('code', 'VEN-000001')->first();

        $this->assertNotNull($account);
        $this->assertSame('Jane Seller', $account->name);
        $this->assertSame('jane.seller@example.test', $account->email);
        $this->assertSame(5, Account::query()->count());
    }
}
