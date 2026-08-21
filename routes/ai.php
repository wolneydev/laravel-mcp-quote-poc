<?php

use App\Mcp\Servers\ApplicationServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('application', ApplicationServer::class);
