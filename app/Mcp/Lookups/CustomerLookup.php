<?php

namespace App\Mcp\Lookups;

use App\Enums\AccountType;
use App\Mcp\Exceptions\QuoteLookupException;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerLookup
{
    public const MAX_RESULTS = 10;

    /**
     * @return Collection<int, Customer>
     */
    public function search(string $query, bool $activeOnly = true, int $limit = self::MAX_RESULTS): Collection
    {
        $term = trim($query);
        $limit = min(max($limit, 1), self::MAX_RESULTS);
        $normalizedDocument = Customer::normalizeDocument($term);

        return Customer::query()
            ->with('account')
            ->when($activeOnly, fn (Builder $query): Builder => $query->where('active', true))
            ->where(function (Builder $query) use ($term, $normalizedDocument): void {
                $like = '%'.$this->escapeLike($term).'%';

                $query->where('code', $term)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('account', function (Builder $accountQuery) use ($term): void {
                        $accountQuery->where('code', $term)
                            ->where('type', AccountType::Customer);
                    });

                if ($normalizedDocument !== null) {
                    $query->orWhere('document', $normalizedDocument)
                        ->orWhere('document', 'like', '%'.$this->escapeLike($normalizedDocument).'%');
                }
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN EXISTS (SELECT 1 FROM accounts WHERE accounts.customer_id = customers.id AND accounts.code = ?) THEN 1 ELSE 2 END', [$term, $term])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function resolve(string $identifier): Customer
    {
        $term = trim($identifier);

        $customer = Customer::query()->with('account')->where('code', $term)->first();

        if ($customer === null) {
            $account = Account::query()
                ->with('customer')
                ->where('code', $term)
                ->first();

            if ($account !== null) {
                if ($account->type !== AccountType::Customer) {
                    throw new QuoteLookupException(
                        'wrong_account_type',
                        "Account [{$term}] is not a customer account.",
                        field: 'customer',
                    );
                }

                if ($account->active !== true) {
                    throw new QuoteLookupException(
                        'inactive_account',
                        "Customer account [{$term}] is inactive.",
                        field: 'customer',
                    );
                }

                $customer = $account->customer;
            }
        }

        if ($customer === null) {
            $normalizedDocument = Customer::normalizeDocument($term);

            if ($normalizedDocument !== null) {
                $customer = Customer::query()->with('account')->where('document', $normalizedDocument)->first();
            }
        }

        if ($customer !== null) {
            $this->assertQuoteReady($customer, $term);

            return $customer;
        }

        $matches = Customer::query()
            ->with('account')
            ->where('name', 'like', '%'.$this->escapeLike($term).'%')
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$term])
            ->latest('id')
            ->limit(self::MAX_RESULTS + 1)
            ->get();

        if ($matches->isEmpty()) {
            throw new QuoteLookupException(
                'not_found',
                "Customer [{$term}] was not found.",
                field: 'customer',
            );
        }

        $quoteReady = $matches->filter(function (Customer $match): bool {
            $account = $match->account;

            return $match->active === true
                && $account !== null
                && $account->active === true
                && $account->type === AccountType::Customer;
        })->values();

        if ($quoteReady->count() === 1) {
            return $quoteReady->first();
        }

        if ($matches->count() === 1) {
            $this->assertQuoteReady($matches->first(), $term);
        }

        $candidates = ($quoteReady->isNotEmpty() ? $quoteReady : $matches)
            ->take(self::MAX_RESULTS)
            ->map(fn (Customer $customer): array => $this->summary($customer))
            ->values()
            ->all();

        throw new QuoteLookupException(
            'ambiguous_match',
            "Customer [{$term}] matches more than one customer.",
            $candidates,
            'customer',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Customer $customer): array
    {
        $account = $customer->account;

        return [
            'customer_id' => $customer->id,
            'customer_code' => $customer->code,
            'customer_name' => $customer->name,
            'document_present' => $customer->document !== null && $customer->document !== '',
            'customer_account_id' => $account?->id,
            'customer_account_code' => $account?->code,
            'active' => $customer->active,
        ];
    }

    private function assertQuoteReady(Customer $customer, string $term): void
    {
        if ($customer->active !== true) {
            throw new QuoteLookupException(
                'inactive_customer',
                "Customer [{$term}] is inactive.",
                field: 'customer',
            );
        }

        $account = $customer->account;

        if ($account === null) {
            throw new QuoteLookupException(
                'not_found',
                "Customer [{$term}] does not have a related customer account.",
                field: 'customer',
            );
        }

        if ($account->active !== true) {
            throw new QuoteLookupException(
                'inactive_account',
                "The account for customer [{$term}] is inactive.",
                field: 'customer',
            );
        }

        if ($account->type !== AccountType::Customer) {
            throw new QuoteLookupException(
                'wrong_account_type',
                "The account for customer [{$term}] is not a customer account.",
                field: 'customer',
            );
        }
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
