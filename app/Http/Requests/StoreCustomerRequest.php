<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = array_filter([
            'code' => $this->stringValue('code'),
            'name' => $this->stringValue('name'),
            'phone' => $this->stringValue('phone'),
            'contact_name' => $this->stringValue('contact_name'),
        ], fn (mixed $value): bool => $value !== null);

        if ($this->exists('document')) {
            $payload['document'] = $this->normalizedDocument();
        }

        if ($this->exists('email')) {
            $payload['email'] = $this->normalizedEmail();
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('customers', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'document')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'contact_name' => ['nullable', 'string', 'max:255'],
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

    private function normalizedDocument(): ?string
    {
        if (! $this->exists('document')) {
            return null;
        }

        $document = $this->input('document');

        if (! is_string($document)) {
            return null;
        }

        return Customer::normalizeDocument($document);
    }

    private function normalizedEmail(): ?string
    {
        if (! $this->exists('email')) {
            return null;
        }

        $email = $this->input('email');

        if (! is_string($email)) {
            return null;
        }

        return Customer::normalizeEmail($email);
    }
}
