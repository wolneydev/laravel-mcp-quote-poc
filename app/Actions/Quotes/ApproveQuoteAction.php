<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use Illuminate\Validation\ValidationException;

class ApproveQuoteAction
{
    public function handle(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'status' => 'Only quotes pending approval can be approved.',
            ]);
        }

        $quote->update([
            'status' => QuoteStatus::Approved,
            'approved_at' => now(),
            'rejected_at' => null,
        ]);

        return $quote->fresh()
            ->load([
                'sellerAccount',
                'customerAccount.customer',
                'items',
            ]);
    }
}
