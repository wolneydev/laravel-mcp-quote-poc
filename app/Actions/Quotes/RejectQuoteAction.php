<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use Illuminate\Validation\ValidationException;

class RejectQuoteAction
{
    public function handle(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'status' => 'Only quotes pending approval can be rejected.',
            ]);
        }

        $quote->update([
            'status' => QuoteStatus::Rejected,
            'rejected_at' => now(),
            'approved_at' => null,
        ]);

        return $quote->fresh()
            ->load([
                'sellerAccount',
                'customerAccount.customer',
                'items',
            ]);
    }
}
