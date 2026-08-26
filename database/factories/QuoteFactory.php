<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => sprintf(
                'QUO-%d-%s',
                now()->year,
                str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            ),
            'seller_account_id' => Account::factory(),
            'customer_account_id' => Account::factory()->customer(),
            'status' => QuoteStatus::Draft,
            'currency' => 'CAD',
            'total' => '0.00',
            'valid_until' => now()->addMonth()->toDateString(),
            'notes' => null,
            'approved_at' => null,
            'rejected_at' => null,
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::PendingApproval,
            'approved_at' => null,
            'rejected_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::Approved,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuoteStatus::Rejected,
            'rejected_at' => now(),
            'approved_at' => null,
        ]);
    }
}
