<?php

namespace Database\Factories;

use App\Models\McpClientToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpClientToken>
 */
class McpClientTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'revoked' => false,
            'expires_in_days' => 90,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked' => true,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_in_days' => 1,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
    }
}
