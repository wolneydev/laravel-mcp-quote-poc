<?php

namespace App\Models;

use Database\Factories\McpClientTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['token_hash', 'revoked', 'expires_in_days'])]
#[Hidden(['token_hash'])]
class McpClientToken extends Model
{
    /** @use HasFactory<McpClientTokenFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revoked' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked' => 'boolean',
            'expires_in_days' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        if ($this->revoked) {
            return false;
        }

        if ($this->created_at === null) {
            return false;
        }

        return now()->lt($this->created_at->copy()->addDays($this->expires_in_days));
    }
}
