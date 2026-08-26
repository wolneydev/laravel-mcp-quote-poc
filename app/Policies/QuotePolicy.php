<?php

namespace App\Policies;

use App\Enums\AccountType;
use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Quote;

class QuotePolicy
{
    public function viewAny(Account $account): bool
    {
        return $account->active;
    }

    public function view(Account $account, Quote $quote): bool
    {
        return $this->isSellerOwner($account, $quote)
            || $this->isCustomerOwner($account, $quote);
    }

    public function create(Account $account): bool
    {
        return $account->active && $account->type === AccountType::Seller;
    }

    public function update(Account $account, Quote $quote): bool
    {
        return $this->isSellerOwner($account, $quote);
    }

    public function delete(Account $account, Quote $quote): bool
    {
        return $this->isSellerOwner($account, $quote) && $quote->status === QuoteStatus::Draft;
    }

    public function submit(Account $account, Quote $quote): bool
    {
        return $this->isSellerOwner($account, $quote);
    }

    public function approve(Account $account, Quote $quote): bool
    {
        return $this->isCustomerOwner($account, $quote);
    }

    public function reject(Account $account, Quote $quote): bool
    {
        return $this->isCustomerOwner($account, $quote);
    }

    private function isSellerOwner(Account $account, Quote $quote): bool
    {
        return $account->active
            && $account->type === AccountType::Seller
            && $quote->seller_account_id === $account->id;
    }

    private function isCustomerOwner(Account $account, Quote $quote): bool
    {
        return $account->active
            && $account->type === AccountType::Customer
            && $quote->customer_account_id === $account->id;
    }
}
