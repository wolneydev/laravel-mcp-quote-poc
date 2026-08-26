<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'currency' => $this->currency,
            'total' => $this->total,
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'seller_account' => $this->whenLoaded('sellerAccount', fn (): array => [
                'id' => $this->sellerAccount->id,
                'code' => $this->sellerAccount->code,
                'name' => $this->sellerAccount->name,
            ]),
            'customer_account' => $this->whenLoaded('customerAccount', fn (): array => [
                'id' => $this->customerAccount->id,
                'code' => $this->customerAccount->code,
                'name' => $this->customerAccount->name,
            ]),
            'customer' => $this->when(
                $this->relationLoaded('customerAccount') && $this->customerAccount?->relationLoaded('customer'),
                fn (): ?array => $this->customerAccount?->customer === null ? null : [
                    'id' => $this->customerAccount->customer->id,
                    'code' => $this->customerAccount->customer->code,
                    'name' => $this->customerAccount->customer->name,
                ],
            ),
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
