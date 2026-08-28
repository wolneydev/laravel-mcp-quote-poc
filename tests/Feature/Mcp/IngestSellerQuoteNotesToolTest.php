<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\QuoteServer;
use App\Mcp\Support\SellerQuoteNotesDisk;
use App\Mcp\Tools\IngestSellerQuoteNotesTool;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestSellerQuoteNotesToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_seller_can_ingest_pasted_notes_without_creating_a_quote(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'text' => <<<'TEXT'
Visit 28/08 — Acme Ltd
3x premium keyboard, 1 mouse
They mentioned R$ 199 but I said catalog price
Valid until end of month
Ship to the usual address — João 11 99999-0000
TEXT,
                'filename' => 'visit-acme.txt',
            ])
            ->assertOk()
            ->assertName('ingest_seller_quote_notes')
            ->assertSee('Acme Ltd')
            ->assertSee('premium keyboard')
            ->assertSee('mouse')
            ->assertSee('end of month')
            ->assertDontSee('R$ 199')
            ->assertDontSee('199')
            ->assertDontSee('99999-0000')
            ->assertDontSee('11 99999-0000')
            ->assertStructuredContent(function ($json): void {
                $json->where('filename', 'visit-acme.txt')
                    ->where('storage_path', null)
                    ->where('prices_ignored', true)
                    ->where('pii_redacted', true)
                    ->etc();
            });

        $this->assertSame(0, Quote::query()->count());
    }

    public function test_a_seller_can_ingest_a_private_notes_path(): void
    {
        $seller = Account::factory()->seller()->create();
        $this->fakePrivateStorageDisks();

        $path = SellerQuoteNotesDisk::PREFIX.'/notes.txt';
        Storage::disk('local')->put($path, "Customer: Acme Ltd\n2x mouse\n");

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'storage_path' => $path,
            ])
            ->assertOk()
            ->assertSee('Acme Ltd')
            ->assertSee('mouse')
            ->assertStructuredContent(function ($json) use ($path): void {
                $json->where('storage_path', $path)->etc();
            });
    }

    public function test_text_wins_when_both_text_and_storage_path_are_present(): void
    {
        $seller = Account::factory()->seller()->create();
        $this->fakePrivateStorageDisks();

        $path = SellerQuoteNotesDisk::PREFIX.'/ignored.txt';
        Storage::disk('local')->put($path, "Customer: Other Co\n1x ignored\n");

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'text' => "Customer: Acme Ltd\n3x premium keyboard\n",
                'storage_path' => $path,
            ])
            ->assertOk()
            ->assertSee('Acme Ltd')
            ->assertDontSee('Other Co')
            ->assertStructuredContent(function ($json): void {
                $json->where('storage_path', null)->etc();
            });
    }

    public function test_it_rejects_path_traversal(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'storage_path' => 'quotes/notes/../../../.env',
            ])
            ->assertHasErrors(['path_traversal']);
    }

    public function test_it_rejects_missing_source_and_oversize_text(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [])
            ->assertHasErrors(['missing_source']);

        QuoteServer::actingAs($seller)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'text' => str_repeat('a', SellerQuoteNotesDisk::MAX_BYTES + 1),
            ])
            ->assertHasErrors();
    }

    public function test_a_customer_cannot_ingest_notes(): void
    {
        $customer = Account::factory()->customer()->create();

        QuoteServer::actingAs($customer)
            ->tool(IngestSellerQuoteNotesTool::class, [
                'text' => "Customer: Acme Ltd\n1x mouse\n",
            ])
            ->assertHasErrors();
    }

    public function test_unauthenticated_users_cannot_ingest_notes(): void
    {
        QuoteServer::tool(IngestSellerQuoteNotesTool::class, [
            'text' => "Customer: Acme Ltd\n1x mouse\n",
        ])->assertHasErrors(['Authentication is required.']);
    }
}
