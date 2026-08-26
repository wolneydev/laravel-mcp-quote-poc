<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_users_idempotently_by_email(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, User::query()->where('email', 'test@example.com')->count());
    }

    public function test_it_updates_existing_rows_with_matching_emails(): void
    {
        $this->seed(UserSeeder::class);

        User::query()->where('email', 'test@example.com')->update([
            'name' => 'Outdated User',
        ]);

        $this->seed(UserSeeder::class);

        $user = User::query()->where('email', 'test@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Test User', $user->name);
        $this->assertSame(1, User::query()->count());
    }
}
