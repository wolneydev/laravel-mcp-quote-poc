<?php

namespace Tests\Unit;

use App\Mcp\Support\SellerQuoteNotesIngestor;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SellerQuoteNotesIngestorTest extends TestCase
{
    public function test_it_extracts_customer_mentions_and_quantities_from_sample_notes(): void
    {
        $briefing = app(SellerQuoteNotesIngestor::class)->ingest(<<<'TEXT'
Visit 28/08 — Acme Ltd
3x premium keyboard, 1 mouse
They mentioned R$ 199 but I said catalog price
Valid until end of month
Ship to the usual address — João 11 99999-0000
TEXT);

        $this->assertSame(['Acme Ltd'], $briefing['customer_mentions']);
        $this->assertSame(
            [
                ['product_mention' => 'premium keyboard', 'quantity' => 3],
                ['product_mention' => 'mouse', 'quantity' => 1],
            ],
            $briefing['line_candidates'],
        );
        $this->assertSame('end of month', $briefing['valid_until_hint']);
        $this->assertTrue($briefing['prices_ignored']);
        $this->assertTrue($briefing['pii_redacted']);
        $this->assertStringContainsString('Visit 28/08', $briefing['notes_remainder']);
        $this->assertStringContainsString('Ship to the usual address', $briefing['notes_remainder']);
        $this->assertStringNotContainsString('199', $briefing['notes_remainder']);
        $this->assertStringNotContainsString('R$', $briefing['notes_remainder']);
        $this->assertStringNotContainsString('99999', $briefing['notes_remainder']);
        $this->assertStringNotContainsString('11 99999-0000', json_encode($briefing));
        $this->assertSame([], $briefing['warnings']);
    }

    public function test_it_redacts_email_phone_and_document_like_digits(): void
    {
        $briefing = app(SellerQuoteNotesIngestor::class)->ingest(<<<'TEXT'
Customer: Acme Ltd
Contact jane@acme.test CPF 12345678901
3x mouse
TEXT);

        $encoded = json_encode($briefing);

        $this->assertTrue($briefing['pii_redacted']);
        $this->assertSame(['Acme Ltd'], $briefing['customer_mentions']);
        $this->assertStringNotContainsString('jane@acme.test', $encoded);
        $this->assertStringNotContainsString('12345678901', $encoded);
    }

    public function test_it_fails_closed_on_empty_input(): void
    {
        $this->expectException(ValidationException::class);

        app(SellerQuoteNotesIngestor::class)->ingest("   \n  ");
    }
}
