<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotePayloadRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'seller_account_id' => [
                'required',
                'integer',
                'different:customer_account_id',
                Rule::exists('accounts', 'id')
                    ->where('type', AccountType::Seller->value)
                    ->where('active', true)
                    ->whereNull('customer_id'),
            ],
            'customer_account_id' => [
                'required',
                'integer',
                'different:seller_account_id',
                Rule::exists('accounts', 'id')
                    ->where('type', AccountType::Customer->value)
                    ->where('active', true)
                    ->whereNotNull('customer_id'),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('active', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.total' => ['prohibited'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'currency' => ['prohibited'],
            'total' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'line_total' => ['prohibited'],
            'status' => ['prohibited'],
            'number' => ['prohibited'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public static function after(FormRequest $request): array
    {
        return [
            function (Validator $validator) use ($request): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $authenticated = $request->user();

                if (
                    $authenticated instanceof Account
                    && $authenticated->type === AccountType::Seller
                    && (int) $request->input('seller_account_id') !== (int) $authenticated->id
                ) {
                    $validator->errors()->add(
                        'seller_account_id',
                        'The seller account must match the authenticated seller.',
                    );
                }

                $customerAccount = Account::query()
                    ->with('customer')
                    ->find($request->input('customer_account_id'));

                if ($customerAccount?->customer === null || $customerAccount->customer->active !== true) {
                    $validator->errors()->add(
                        'customer_account_id',
                        'The customer account must belong to an active customer.',
                    );
                }

                $productIds = collect($request->input('items', []))
                    ->pluck('product_id')
                    ->filter()
                    ->unique()
                    ->values();

                $currencies = Product::query()
                    ->whereIn('id', $productIds)
                    ->pluck('currency')
                    ->unique()
                    ->values();

                if ($currencies->count() > 1) {
                    $validator->errors()->add(
                        'items',
                        'All products in a quote must use the same currency.',
                    );
                }
            },
        ];
    }
}
