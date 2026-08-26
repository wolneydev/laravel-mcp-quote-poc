<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactSensitiveLogContext
{
    /**
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'php-auth-pw',
        'x-csrf-token',
        'x-xsrf-token',
        'mcp_quote_token',
        'token_hash',
        'mcp_quote_token_hash',
    ];

    public function __invoke(Logger|\Monolog\Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor($this->process(...));
        }
    }

    public function process(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redactArray(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true) || str_contains($normalizedKey, 'authorization')) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = $this->redactString($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $value);

        return is_string($redacted) ? $redacted : '[redacted]';
    }
}
