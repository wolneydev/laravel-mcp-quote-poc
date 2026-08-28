<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Mcp\Support\BindLocalQuoteMcpSeller;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    public function __construct(
        private BindLocalQuoteMcpSeller $localQuoteMcpSeller,
        private QuoteReportPresenter $presenter,
    ) {}

    public function __invoke(Request $request): View
    {
        $this->bindLocalSellerIfNeeded($request);

        $report = $request->session()->get('quote_report') ?? $this->latestSellerReport($request);

        return view('welcome', [
            'quoteReport' => is_array($report) ? $report : null,
            'notesTemplate' => $this->notesTemplate(),
            'mcpGuideHtml' => $this->mcpGuideHtml(),
        ]);
    }

    private function bindLocalSellerIfNeeded(Request $request): void
    {
        if ($request->user() instanceof Account) {
            return;
        }

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        try {
            $this->localQuoteMcpSeller->authenticateConfiguredSeller();
        } catch (\Throwable) {
            // GET / still renders the upload form.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestSellerReport(Request $request): ?array
    {
        $user = $request->user();

        if (
            ! $user instanceof Account
            || $user->type !== AccountType::Seller
            || $user->active !== true
        ) {
            return null;
        }

        $quote = Quote::query()
            ->withDetails()
            ->where('seller_account_id', $user->id)
            ->latest('id')
            ->first();

        if ($quote === null) {
            return null;
        }

        $report = $this->presenter->present($quote);
        unset($report['markdown']);

        return $report;
    }

    private function notesTemplate(): string
    {
        $contents = file_get_contents(resource_path('seller-notes/template.txt'));

        return is_string($contents) ? $contents : '';
    }

    private function mcpGuideHtml(): string
    {
        $contents = file_get_contents(resource_path('seller-notes/mcp-connect.md'));

        if (! is_string($contents) || $contents === '') {
            return '';
        }

        return Str::markdown($contents, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
