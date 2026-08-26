<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_default_auth_provider_uses_the_account_model(): void
    {
        $this->assertSame(Account::class, config('auth.providers.users.model'));
    }

    public function test_authentication_can_resolve_an_account_by_email_and_password(): void
    {
        $account = Account::factory()->create([
            'email' => 'jane.seller@example.test',
            'password' => 'password',
        ]);

        $this->assertTrue(Auth::attempt([
            'email' => 'jane.seller@example.test',
            'password' => 'password',
        ]));
        $this->assertAuthenticatedAs($account);
    }

    public function test_passwords_are_hashed_and_hidden(): void
    {
        $account = Account::factory()->create([
            'password' => 'secret-password',
        ]);

        $this->assertNotSame('secret-password', $account->password);
        $this->assertTrue(Hash::check('secret-password', $account->password));
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->assertArrayNotHasKey('password', json_decode($account->toJson(), true));
    }

    public function test_account_age_days_is_calculated_from_created_at(): void
    {
        $account = Account::factory()->create();

        $this->assertSame(0, $account->account_age_days);

        $this->travel(10)->days();

        $this->assertSame(10, $account->fresh()->account_age_days);
    }

    public function test_a_seller_account_has_no_customer_relationship(): void
    {
        $account = Account::factory()->seller()->create();

        $this->assertSame(AccountType::Seller, $account->type);
        $this->assertNull($account->customer_id);
        $this->assertNull($account->customer);
        $this->assertTrue(str_starts_with($account->code, 'VEN-'));
    }

    public function test_a_customer_account_belongs_to_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $account = Account::factory()->customer($customer)->create();

        $this->assertSame(AccountType::Customer, $account->type);
        $this->assertTrue($account->customer()->is($customer));
        $this->assertTrue(str_starts_with($account->code, 'CLI-'));
    }
}
