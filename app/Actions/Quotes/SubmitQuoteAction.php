<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use Illuminate\Validation\ValidationException;

class SubmitQuoteAction
{
    public function handle(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft quotes can be submitted for approval.',
            ]);
        }

        $quote->update([
            'status' => QuoteStatus::PendingApproval,
        ]);

        return $quote->fresh()
            ->load([
                'sellerAccount',
                'customerAccount.customer',
                'items',
            ]);
    }
}
