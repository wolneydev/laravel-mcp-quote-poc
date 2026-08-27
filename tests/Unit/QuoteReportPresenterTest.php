<?php

namespace Tests\Unit;

use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class QuoteReportPresenterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_includes_persisted_money_fields_on_a_draft(): void
    {
        $quote = Quote::factory()->create([
            'total' => '30.00',
            'currency' => 'CAD',
        ]);
        QuoteItem::factory()->create([
            'quote_id' => $quote->id,
            'quantity' => '2.00',
            'unit_price' => '15.00',
            'line_total' => '30.00',
        ]);

        $report = app(QuoteReportPresenter::class)->present($quote->fresh());

        $this->assertSame('draft', $report['status']);
        $this->assertSame('30.00', $report['total']);
        $this->assertSame('CAD', $report['currency']);
        $this->assertSame('15.00', $report['items'][0]['unit_price']);
        $this->assertSame('30.00', $report['items'][0]['line_total']);
        $this->assertStringContainsString('Total: CAD 30.00', $report['markdown']);
        $this->assertStringContainsString('Unit price', $report['markdown']);
        $this->assertStringContainsString('Line total', $report['markdown']);
    }

    public function test_it_fails_closed_when_line_items_are_missing(): void
    {
        $quote = Quote::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing line items');

        app(QuoteReportPresenter::class)->present($quote);
    }
}
