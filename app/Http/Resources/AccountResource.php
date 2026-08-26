<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'name' => $this->name,
            'email' => $this->email,
            'customer_id' => $this->customer_id,
            'active' => $this->active,
            'account_age_days' => $this->account_age_days,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
