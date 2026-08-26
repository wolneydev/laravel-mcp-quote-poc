<?php

namespace App\Mcp\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

class QuoteLookupException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly array $candidates = [],
        public readonly string $field = 'query',
    ) {
        parent::__construct($message);
    }

    public function toValidationException(): ValidationException
    {
        $message = "[{$this->error}] {$this->getMessage()}";

        if ($this->candidates !== []) {
            $message .= ' Candidates: '.json_encode($this->candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return ValidationException::withMessages([
            $this->field => $message,
        ]);
    }
}
