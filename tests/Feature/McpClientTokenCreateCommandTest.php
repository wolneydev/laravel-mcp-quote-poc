<?php

namespace Tests\Feature;

use App\Models\McpClientToken;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class McpClientTokenCreateCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_persists_only_the_hash_and_prints_the_raw_token_once(): void
    {
        $buffer = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call(
            'mcp:client-token:create',
            ['--expires-in-days' => 90],
            $buffer,
        );
        $printed = $buffer->fetch();
        $output = trim($printed);
        $stored = McpClientToken::query()->first();

        $this->assertSame(0, $exitCode);
        $this->assertNotNull($stored);
        $this->assertSame(1, McpClientToken::query()->count());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $output);
        $this->assertSame($output.PHP_EOL, $printed);
        $this->assertSame(hash('sha256', $output), $stored->getAttributes()['token_hash']);
        $this->assertFalse($stored->revoked);
        $this->assertSame(90, $stored->expires_in_days);
        $this->assertStringNotContainsString($stored->getAttributes()['token_hash'], $printed);
    }

    public function test_it_requires_a_positive_expires_in_days(): void
    {
        $this->artisan('mcp:client-token:create')
            ->assertFailed();

        $this->artisan('mcp:client-token:create', ['--expires-in-days' => 0])
            ->assertFailed();

        $this->assertSame(0, McpClientToken::query()->count());
    }

    public function test_it_is_rejected_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('mcp:client-token:create', ['--expires-in-days' => 90])
            ->expectsOutput('This command is only available in the local and testing environments.')
            ->assertFailed();

        $this->assertSame(0, McpClientToken::query()->count());
    }
}
