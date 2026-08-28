<?php

namespace App\Mcp\Tools;

use App\Mcp\Exceptions\QuoteLookupException;
use App\Mcp\Support\QuoteDraftPdfRenderer;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('generate_quote_draft_pdf')]
#[Description('Save a printable PDF of a persisted quote to private storage from stored snapshots. Does not approve the quote, reprice items, or accept caller-provided prices. Laravel writes the file; the result is metadata plus a storage path, not PDF bytes.')]
class GenerateQuoteDraftPdfTool extends Tool
{
    public function __construct(
        private QuoteReportPresenter $presenter,
        private QuoteDraftPdfRenderer $renderer,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()
                ->description('Persisted quote ID. Provide quote_id, quote_number, or both.')
                ->min(1),
            'quote_number' => $schema->string()
                ->description('Public quote number (QUO-*). Provide quote_id, quote_number, or both.')
                ->min(1)
                ->max(32),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof Account) {
            throw new AuthenticationException('Authentication is required.');
        }

        $validated = $request->validate([
            'quote_id' => ['nullable', 'integer', 'min:1', 'required_without:quote_number'],
            'quote_number' => ['nullable', 'string', 'min:1', 'max:32', 'required_without:quote_id'],
            'unit_price' => ['prohibited'],
            'line_total' => ['prohibited'],
            'total' => ['prohibited'],
            'items' => ['prohibited'],
        ]);

        try {
            $quote = $this->findQuote($validated);
        } catch (QuoteLookupException $exception) {
            throw $exception->toValidationException();
        }

        Gate::authorize('view', $quote);

        $stored = $this->renderer->store($quote);
        $report = $this->presenter->present($quote);

        return Response::structured([
            'quote_number' => $report['quote_number'],
            'status' => $report['status'],
            'total' => $report['total'],
            'currency' => $report['currency'],
            'filename' => $stored['filename'],
            'storage_disk' => $stored['storage_disk'],
            'storage_path' => $stored['storage_path'],
            'download_url' => URL::temporarySignedRoute(
                'quotes.draft-pdf',
                now()->addMinutes(15),
                ['quote' => $quote],
            ),
            'approval_summary' => $report['approval_summary'],
        ]);
    }

    /**
     * @param  array{quote_id?: int, quote_number?: string}  $validated
     */
    private function findQuote(array $validated): Quote
    {
        $query = Quote::query()->withDetails();

        if (isset($validated['quote_id'])) {
            $query->where('id', $validated['quote_id']);
        }

        if (isset($validated['quote_number'])) {
            $query->where('number', $validated['quote_number']);
        }

        $quote = $query->first();

        if ($quote === null) {
            $identifier = $validated['quote_number'] ?? (string) ($validated['quote_id'] ?? '');

            throw new QuoteLookupException(
                'not_found',
                "Quote [{$identifier}] was not found.",
                field: isset($validated['quote_number']) ? 'quote_number' : 'quote_id',
            );
        }

        return $quote;
    }
}
