<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\GenerateQuoteDraftPdfPrompt;
use App\Mcp\Prompts\GenerateQuoteFromNotesPrompt;
use App\Mcp\Prompts\GenerateQuoteReportPrompt;
use App\Mcp\Support\BindLocalQuoteMcpSeller;
use App\Mcp\Tools\GenerateQuoteDraftPdfTool;
use App\Mcp\Tools\GenerateQuoteReportTool;
use App\Mcp\Tools\GetQuoteReportTool;
use App\Mcp\Tools\IngestSellerQuoteNotesTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchProductsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Quote Server')]
#[Version('0.0.1')]
#[Instructions('Quote report MCP server. Use /generate-quote-report (prompt generate-quote-report) to collect seller, customer, product, and quantity, then call generate_quote_report. Use /generate-quote-from-notes (prompt generate-quote-from-notes) after a notes file upload or paste: call ingest_seller_quote_notes, then search_customers and search_products, then generate_quote_report. Search with search_products and search_customers. Retrieve persisted reports with get_quote_report. Use /generate-quote-draft-pdf (prompt generate-quote-draft-pdf) to ask Laravel to render a printable PDF of a persisted quote. REST quote CRUD remains on /api/quotes.')]
class QuoteServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchProductsTool::class,
        SearchCustomersTool::class,
        GenerateQuoteReportTool::class,
        GetQuoteReportTool::class,
        GenerateQuoteDraftPdfTool::class,
        IngestSellerQuoteNotesTool::class,
    ];

    protected array $resources = [
        //
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        GenerateQuoteReportPrompt::class,
        GenerateQuoteDraftPdfPrompt::class,
        GenerateQuoteFromNotesPrompt::class,
    ];

    public function __construct(
        Transport $transport,
        private BindLocalQuoteMcpSeller $localQuoteMcpSeller,
    ) {
        parent::__construct($transport);
    }

    protected function boot(): void
    {
        $this->localQuoteMcpSeller->bindIfLocalStdio($this->transport);
    }
}
