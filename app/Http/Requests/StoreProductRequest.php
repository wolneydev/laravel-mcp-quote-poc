<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'code' => $this->stringValue('code'),
            'name' => $this->stringValue('name'),
            'unit' => $this->stringValue('unit'),
            'currency' => $this->normalizedCurrency(),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:32'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function stringValue(string $key): ?string
    {
        if (! $this->exists($key) || ! is_string($this->input($key))) {
            return null;
        }

        return (string) Str::of($this->input($key))->trim();
    }

    private function normalizedCurrency(): ?string
    {
        $currency = $this->stringValue('currency');

        if ($currency === null) {
            return null;
        }

        return (string) Str::of($currency)->upper();
    }
}
