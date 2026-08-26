<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();
        $password = Hash::make('password');

        $accounts = [
            [
                'code' => 'VEN-000001',
                'type' => AccountType::Seller->value,
                'customer_id' => null,
                'name' => 'Jane Seller',
                'email' => 'jane.seller@example.test',
                'password' => $password,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'VEN-000002',
                'type' => AccountType::Seller->value,
                'customer_id' => null,
                'name' => 'John Vendor',
                'email' => 'john.vendor@example.test',
                'password' => $password,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->customerAccount('CLI-000001', 'CUST-000001', 'Alice Hart', 'alice.hart@northwind.test', $password, $now),
            $this->customerAccount('CLI-000002', 'CUST-000002', 'Ben Ortega', 'ben.ortega@harbourroofing.test', $password, $now),
            $this->customerAccount('CLI-000003', 'CUST-000003', 'Clara Nguyen', 'clara.nguyen@summitsafety.test', $password, $now),
        ];

        Account::upsert(
            $accounts,
            ['code'],
            ['type', 'customer_id', 'name', 'email', 'password', 'active', 'updated_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function customerAccount(
        string $accountCode,
        string $customerCode,
        string $name,
        string $email,
        string $password,
        mixed $now,
    ): array {
        $customerId = Customer::query()->where('code', $customerCode)->value('id');

        if ($customerId === null) {
            throw new RuntimeException("Customer [{$customerCode}] must exist before seeding accounts.");
        }

        return [
            'code' => $accountCode,
            'type' => AccountType::Customer->value,
            'customer_id' => $customerId,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
