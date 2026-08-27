<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class McpClientTokenResource extends JsonResource
{
    private ?string $plainToken = null;

    public function withPlainToken(string $plainToken): static
    {
        $this->plainToken = $plainToken;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->when($this->plainToken !== null, $this->plainToken),
            'revoked' => $this->revoked,
            'expires_in_days' => $this->expires_in_days,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
