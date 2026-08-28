<?php

namespace Tests\Feature\Mcp;

use App\Actions\Quotes\CreateQuoteAction;
use App\Enums\QuoteStatus;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\GenerateQuoteDraftPdfTool;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GenerateQuoteDraftPdfToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_metadata_and_a_signed_download_without_changing_status(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create([
            'code' => 'CUST-000011',
            'name' => 'Acme Ltd',
            'document' => '99887766',
            'email' => 'privacy-acme@example.test',
            'phone' => '+15550001111',
            'contact_name' => 'Secret Contact',
        ]);
        $customerAccount = Account::factory()->customer($profile)->create(['code' => 'CLI-000011']);
        $product = Product::factory()->create([
            'code' => 'PROD-000011',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);

        $quote = app(CreateQuoteAction::class)->handle(
            sellerAccount: $seller,
            customerAccount: $customerAccount,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            notes: 'Optional notes',
        );

        $product->update(['price' => '99.99']);

        $this->fakePrivateStorageDisks();

        $filename = $quote->number.'-draft.pdf';
        $storagePath = 'quotes/drafts/'.$filename;

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteDraftPdfTool::class, [
                'quote_number' => $quote->number,
            ])
            ->assertOk()
            ->assertName('generate_quote_draft_pdf')
            ->assertStructuredContent(function ($json) use ($quote, $filename, $storagePath): void {
                $json->where('quote_number', $quote->number)
                    ->where('status', QuoteStatus::Draft->value)
                    ->where('total', '20.00')
                    ->where('currency', 'CAD')
                    ->where('filename', $filename)
                    ->where('storage_disk', 'local')
                    ->where('storage_path', $storagePath)
                    ->has('download_url')
                    ->missing('customer.document')
                    ->missing('customer.email')
                    ->missing('customer.phone')
                    ->missing('customer.contact_name')
                    ->etc();
            })
            ->assertSee($quote->number)
            ->assertSee('20.00')
            ->assertSee($filename)
            ->assertSee($storagePath)
            ->assertDontSee('%PDF')
            ->assertDontSee('99887766')
            ->assertDontSee('privacy-acme@example.test')
            ->assertDontSee('+15550001111')
            ->assertDontSee('Secret Contact');

        Storage::disk('local')->assertExists($storagePath);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($storagePath));
        Storage::disk('public')->assertMissing($storagePath);

        $quote->refresh();
        $this->assertSame(QuoteStatus::Draft, $quote->status);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteDraftPdfTool::class, [
                'quote_id' => $quote->id,
            ])
            ->assertOk()
            ->assertSee($quote->number);

        $pdf = $this->get(URL::temporarySignedRoute(
            'quotes.draft-pdf',
            now()->addMinutes(15),
            ['quote' => $quote],
        ));

        $pdf->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringNotContainsString('99887766', $pdf->getContent());
        $this->assertStringNotContainsString('privacy-acme@example.test', $pdf->getContent());
    }

    public function test_it_rejects_a_missing_identifier(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteDraftPdfTool::class, [])
            ->assertHasErrors();
    }

    public function test_unknown_quotes_are_not_found(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteDraftPdfTool::class, [
                'quote_number' => 'QUO-2099-999999',
            ])
            ->assertHasErrors(['not_found']);
    }

    public function test_unauthorized_sellers_cannot_generate_a_pdf(): void
    {
        $seller = Account::factory()->seller()->create();
        $otherSeller = Account::factory()->seller()->create();
        $quote = Quote::factory()->create([
            'seller_account_id' => $seller->id,
        ]);

        $this->fakePrivateStorageDisks();

        QuoteServer::actingAs($otherSeller)
            ->tool(GenerateQuoteDraftPdfTool::class, [
                'quote_id' => $quote->id,
            ])
            ->assertHasErrors();

        Storage::disk('local')->assertMissing('quotes/drafts/'.$quote->number.'-draft.pdf');

        auth()->logout();

        QuoteServer::tool(GenerateQuoteDraftPdfTool::class, [
            'quote_id' => $quote->id,
        ])->assertHasErrors(['Authentication is required.']);

        $this->get('/api/quotes/'.$quote->id.'/draft-pdf')->assertForbidden();
    }

    public function test_it_rejects_caller_provided_prices(): void
    {
        $seller = Account::factory()->seller()->create();
        $quote = Quote::factory()->create([
            'seller_account_id' => $seller->id,
        ]);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteDraftPdfTool::class, [
                'quote_id' => $quote->id,
                'total' => '1.00',
            ])
            ->assertHasErrors();
    }
}
