<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSellerAccount;
use App\Mcp\Support\SellerQuoteNotesDisk;
use App\Mcp\Support\SellerQuoteNotesIngestor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('ingest_seller_quote_notes')]
#[Description('Parse a seller notes file or pasted UTF-8 text into a quote briefing. Does not create, price, or approve a quote. Mentions are candidates for search_customers and search_products.')]
#[IsReadOnly]
class IngestSellerQuoteNotesTool extends Tool
{
    use RequiresSellerAccount;

    public function __construct(
        private SellerQuoteNotesDisk $disk,
        private SellerQuoteNotesIngestor $ingestor,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('UTF-8 notes body. Required when storage_path is omitted. Wins when both are present.')
                ->min(1)
                ->max(SellerQuoteNotesDisk::MAX_BYTES),
            'storage_path' => $schema->string()
                ->description('Object key on the local disk under quotes/notes/. Required when text is omitted.')
                ->min(1)
                ->max(255),
            'filename' => $schema->string()
                ->description('Optional original filename for metadata only. Path segments are stripped.')
                ->min(1)
                ->max(255),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $this->sellerAccount($request);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:'.SellerQuoteNotesDisk::MAX_BYTES],
            'storage_path' => ['nullable', 'string', 'min:1', 'max:255'],
            'filename' => ['nullable', 'string', 'min:1', 'max:255'],
            'unit_price' => ['prohibited'],
            'line_total' => ['prohibited'],
            'total' => ['prohibited'],
            'items' => ['prohibited'],
        ]);

        $text = isset($validated['text']) ? trim((string) $validated['text']) : '';
        $storagePath = isset($validated['storage_path']) ? (string) $validated['storage_path'] : '';

        if ($text === '' && $storagePath === '') {
            throw ValidationException::withMessages([
                'text' => '[missing_source] Provide text or a storage_path under quotes/notes/.',
            ]);
        }

        $resolvedPath = null;

        if ($text !== '') {
            $body = $this->disk->assertDecodableUtf8($validated['text'], 'text');
        } else {
            $resolvedPath = $this->disk->assertSafeObjectKey($storagePath);
            $body = $this->disk->get($resolvedPath);
        }

        $briefing = $this->ingestor->ingest($body);
        $filename = $this->disk->safeOriginalFilename($validated['filename'] ?? ($resolvedPath !== null ? basename($resolvedPath) : null));

        return Response::structured([
            'filename' => $filename,
            'storage_path' => $resolvedPath,
            'customer_mentions' => $briefing['customer_mentions'],
            'line_candidates' => $briefing['line_candidates'],
            'valid_until_hint' => $briefing['valid_until_hint'],
            'notes_remainder' => $briefing['notes_remainder'],
            'prices_ignored' => $briefing['prices_ignored'],
            'pii_redacted' => $briefing['pii_redacted'],
            'warnings' => $briefing['warnings'],
        ]);
    }
}
