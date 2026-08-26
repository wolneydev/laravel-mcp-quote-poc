<?php

namespace App\Mcp\Tools;

use App\Mcp\Exceptions\QuoteLookupException;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_quote_report')]
#[Description('Load a persisted quote report by quote ID or quote number (QUO-*). Uses stored item prices and totals; does not recalculate from current product prices.')]
#[IsReadOnly]
class GetQuoteReportTool extends Tool
{
    public function __construct(private QuoteReportPresenter $presenter) {}

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
        ]);

        try {
            $quote = $this->findQuote($validated);
        } catch (QuoteLookupException $exception) {
            throw $exception->toValidationException();
        }

        Gate::authorize('view', $quote);

        return Response::structured($this->presenter->present($quote));
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
