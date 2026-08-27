<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\GenerateQuoteReportPrompt;
use App\Mcp\Support\BindLocalQuoteMcpSeller;
use App\Mcp\Tools\GenerateQuoteReportTool;
use App\Mcp\Tools\GetQuoteReportTool;
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
#[Instructions('Quote report MCP server. Use /generate-quote-report (prompt generate-quote-report) to collect seller, customer, product, and quantity, then call generate_quote_report. Search with search_products and search_customers. Retrieve persisted reports with get_quote_report. REST quote CRUD remains on /api/quotes.')]
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
    ];

    protected array $resources = [
        //
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        GenerateQuoteReportPrompt::class,
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
