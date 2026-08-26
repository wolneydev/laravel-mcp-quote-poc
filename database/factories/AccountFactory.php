<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('VEN-######')),
            'type' => AccountType::Seller,
            'customer_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
        ];
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes): array => [
            'code' => strtoupper(fake()->unique()->bothify('VEN-######')),
            'type' => AccountType::Seller,
            'customer_id' => null,
        ]);
    }

    public function customer(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer): array {
            $customer ??= Customer::factory()->create();

            return [
                'code' => strtoupper(fake()->unique()->bothify('CLI-######')),
                'type' => AccountType::Customer,
                'customer_id' => $customer->id,
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }
}
