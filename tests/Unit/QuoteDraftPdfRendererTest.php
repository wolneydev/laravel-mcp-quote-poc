<?php

namespace Tests\Unit;

use App\Actions\Quotes\CreateQuoteAction;
use App\Mcp\Support\QuoteDraftPdfRenderer;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuoteDraftPdfRendererTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_renders_persisted_money_and_a_draft_label_without_catalog_repricing(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000021', 'name' => 'North Seller']);
        $profile = Customer::factory()->create([
            'code' => 'CUST-000021',
            'name' => 'Report Privacy Co',
            'document' => '44445555',
            'email' => 'report-privacy@example.test',
            'phone' => '+15554445555',
            'contact_name' => 'Markdown Secret',
        ]);
        $customerAccount = Account::factory()->customer($profile)->create(['code' => 'CLI-000021']);
        $product = Product::factory()->create([
            'code' => 'PROD-000021',
            'name' => 'Premium Keyboard',
            'unit' => 'unit',
            'price' => '15.00',
            'currency' => 'CAD',
        ]);

        $quote = app(CreateQuoteAction::class)->handle(
            sellerAccount: $seller,
            customerAccount: $customerAccount,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            validUntil: '2026-09-30',
            notes: 'Ship to warehouse',
        );

        $product->update(['price' => '99.99']);

        $report = app(QuoteReportPresenter::class)->present($quote->fresh());
        unset($report['markdown']);
        $html = view('quotes.draft-pdf', ['report' => $report])->render();

        $this->assertStringContainsString($quote->number, $html);
        $this->assertStringContainsString('draft', $html);
        $this->assertStringContainsString('not approved', $html);
        $this->assertStringContainsString('North Seller', $html);
        $this->assertStringContainsString('VEN-000021', $html);
        $this->assertStringContainsString('Report Privacy Co', $html);
        $this->assertStringContainsString('CUST-000021', $html);
        $this->assertStringContainsString('CLI-000021', $html);
        $this->assertStringContainsString('PROD-000021', $html);
        $this->assertStringContainsString('Premium Keyboard', $html);
        $this->assertStringContainsString('15.00', $html);
        $this->assertStringContainsString('30.00', $html);
        $this->assertStringContainsString('CAD', $html);
        $this->assertStringContainsString('2026-09-30', $html);
        $this->assertStringContainsString('Ship to warehouse', $html);
        $this->assertStringNotContainsString('99.99', $html);
        $this->assertStringNotContainsString('44445555', $html);
        $this->assertStringNotContainsString('report-privacy@example.test', $html);
        $this->assertStringNotContainsString('+15554445555', $html);
        $this->assertStringNotContainsString('Markdown Secret', $html);

        $pdf = app(QuoteDraftPdfRenderer::class)->render($quote->fresh());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringNotContainsString('44445555', $pdf);
        $this->assertStringNotContainsString('report-privacy@example.test', $pdf);
        $this->assertSame($quote->number.'-draft.pdf', app(QuoteDraftPdfRenderer::class)->filename($quote));
        $this->assertSame(
            'quotes/drafts/'.$quote->number.'-draft.pdf',
            app(QuoteDraftPdfRenderer::class)->storagePath($quote),
        );
    }

    public function test_it_stores_the_pdf_on_the_private_local_disk(): void
    {
        $seller = Account::factory()->seller()->create();
        $customerAccount = Account::factory()->customer()->create();
        $product = Product::factory()->create(['price' => '15.00', 'currency' => 'CAD']);

        $quote = app(CreateQuoteAction::class)->handle(
            sellerAccount: $seller,
            customerAccount: $customerAccount,
            items: [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        );

        $this->fakePrivateStorageDisks();

        $stored = app(QuoteDraftPdfRenderer::class)->store($quote->fresh());
        $path = 'quotes/drafts/'.$quote->number.'-draft.pdf';

        $this->assertSame('local', $stored['storage_disk']);
        $this->assertSame($path, $stored['storage_path']);
        $this->assertSame($quote->number.'-draft.pdf', $stored['filename']);

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($path));
        Storage::disk('public')->assertMissing($path);

        app(QuoteDraftPdfRenderer::class)->store($quote->fresh());
        Storage::disk('local')->assertExists($path);
    }
}
