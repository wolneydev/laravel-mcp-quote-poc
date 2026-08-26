<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        $name = $this->stringValue('name');

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if ($this->exists('email')) {
            $payload['email'] = $this->normalizedEmail();
        }

        if ($this->exists('customer_id') && $this->input('customer_id') === '') {
            $payload['customer_id'] = null;
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('accounts', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'type' => ['required', Rule::enum(AccountType::class)],
            'customer_id' => [
                Rule::requiredIf($this->input('type') === AccountType::Customer->value),
                Rule::prohibitedIf($this->input('type') === AccountType::Seller->value),
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('active', true),
                Rule::unique('accounts', 'customer_id'),
            ],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    private function stringValue(string $key): ?string
    {
        if (! $this->exists($key) || ! is_string($this->input($key))) {
            return null;
        }

        $value = (string) Str::of($this->input($key))->trim();

        return $value === '' ? null : $value;
    }

    private function normalizedEmail(): ?string
    {
        $email = $this->input('email');

        if (! is_string($email)) {
            return null;
        }

        return Account::normalizeEmail($email);
    }
}
