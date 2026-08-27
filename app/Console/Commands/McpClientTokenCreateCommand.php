<?php

namespace App\Console\Commands;

use App\Models\McpClientToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('mcp:client-token:create {--expires-in-days= : Days until the token expires (required, positive integer)}')]
#[Description('Create an HTTP Quote MCP client token (local and testing only). Prints the raw token once.')]
class McpClientTokenCreateCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is only available in the local and testing environments.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['expires_in_days' => $this->option('expires-in-days')],
            ['expires_in_days' => ['required', 'integer', 'min:1']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        /** @var array{expires_in_days: int} $validated */
        $validated = $validator->validated();

        $rawToken = bin2hex(random_bytes(32));

        McpClientToken::query()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_in_days' => $validated['expires_in_days'],
            'revoked' => false,
        ]);

        $this->line($rawToken);

        return self::SUCCESS;
    }
}
