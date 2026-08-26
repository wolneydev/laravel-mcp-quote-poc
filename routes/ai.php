<?php

use App\Http\Middleware\AuthenticateQuoteMcp;
use App\Mcp\Servers\ApplicationServer;
use App\Mcp\Servers\QuoteServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('application', ApplicationServer::class);
Mcp::web('/mcp', ApplicationServer::class);

Mcp::local('quotes', QuoteServer::class);
Mcp::web('/mcp/quotes', QuoteServer::class)
    ->middleware([
        AuthenticateQuoteMcp::class,
        'throttle:mcp',
    ]);
