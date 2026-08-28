<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\Support\QuoteDraftPdfRenderer;
use App\Models\Quote;
use Symfony\Component\HttpFoundation\Response;

class QuoteDraftPdfController extends Controller
{
    public function __invoke(Quote $quote, QuoteDraftPdfRenderer $renderer): Response
    {
        $binary = $renderer->render($quote);
        $filename = $renderer->filename($quote);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
