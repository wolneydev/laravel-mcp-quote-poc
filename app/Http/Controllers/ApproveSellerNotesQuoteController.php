<?php

namespace App\Http\Controllers;

use App\Actions\Quotes\ApproveQuoteAction;
use App\Actions\Quotes\SubmitQuoteAction;
use App\Enums\QuoteStatus;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproveSellerNotesQuoteController extends Controller
{
    public function __construct(
        private SubmitQuoteAction $submitQuote,
        private ApproveQuoteAction $approveQuote,
        private QuoteReportPresenter $presenter,
    ) {}

    public function __invoke(Request $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('submit', $quote);

        try {
            if ($quote->status === QuoteStatus::Draft) {
                $quote = $this->submitQuote->handle($quote);
            }

            if ($quote->status === QuoteStatus::PendingApproval) {
                $quote = $this->approveQuote->handle($quote);
            }
        } catch (ValidationException $exception) {
            return redirect()
                ->route('home')
                ->with('quote_report', $this->report($quote->fresh() ?? $quote))
                ->withErrors($exception->errors());
        }

        $report = $this->report($quote);

        return redirect()
            ->route('home')
            ->with('quote_report', $report)
            ->with('filename', $request->session()->get('filename'))
            ->with('storage_path', $request->session()->get('storage_path'));
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Quote $quote): array
    {
        $report = $this->presenter->present($quote);
        unset($report['markdown']);

        return $report;
    }
}
