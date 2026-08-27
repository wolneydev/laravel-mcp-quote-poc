<?php

namespace Tests\Feature\Mcp;

use App\Actions\Quotes\CreateQuoteAction;
use App\Logging\RedactSensitiveLogContext;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\GenerateQuoteReportTool;
use App\Mcp\Tools\GetQuoteReportTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class QuoteMcpVendorPrivacyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_search_customers_omits_document_and_contact_pii(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create([
            'code' => 'CUST-000001',
            'name' => 'Acme Ltd',
            'document' => '99887766',
            'email' => 'privacy-acme@example.test',
            'phone' => '+15550001111',
            'contact_name' => 'Secret Contact',
        ]);
        $customerAccount = Account::factory()->customer($profile)->create([
            'password' => 'plain-secret-password',
        ]);
        $customerAccount->refresh();

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => 'Acme Ltd'])
            ->assertOk()
            ->assertStructuredContent(function ($json): void {
                $json->has('customers', 1)
                    ->where('customers.0.customer_code', 'CUST-000001')
                    ->where('customers.0.document_present', true)
                    ->missing('customers.0.document')
                    ->missing('customers.0.email')
                    ->missing('customers.0.phone')
                    ->missing('customers.0.contact_name')
                    ->missing('customers.0.password')
                    ->etc();
            })
            ->assertDontSee('99887766')
            ->assertDontSee('privacy-acme@example.test')
            ->assertDontSee('+15550001111')
            ->assertDontSee('Secret Contact')
            ->assertDontSee('plain-secret-password')
            ->assertDontSee((string) $customerAccount->password);
    }

    public function test_search_customers_resolves_by_document_without_echoing_it(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create([
            'code' => 'CUST-000044',
            'name' => 'Document Lookup Co',
            'document' => '11223344',
        ]);
        Account::factory()->customer($profile)->create(['code' => 'CLI-000044']);

        QuoteServer::actingAs($seller)
            ->tool(SearchCustomersTool::class, ['query' => '112.233.44'])
            ->assertOk()
            ->assertSee('CUST-000044')
            ->assertStructuredContent(function ($json): void {
                $json->has('customers', 1)
                    ->where('customers.0.customer_code', 'CUST-000044')
                    ->where('customers.0.document_present', true)
                    ->missing('customers.0.document')
                    ->etc();
            });
    }

    public function test_ambiguous_customer_errors_omit_document_values(): void
    {
        $seller = Account::factory()->seller()->create();
        $first = Customer::factory()->create([
            'name' => 'Acme North',
            'document' => '55511122',
            'email' => 'north-privacy@example.test',
        ]);
        $second = Customer::factory()->create([
            'name' => 'Acme South',
            'document' => '55533344',
            'email' => 'south-privacy@example.test',
        ]);
        Account::factory()->customer($first)->create();
        Account::factory()->customer($second)->create();

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'Acme',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ])
            ->assertHasErrors(['ambiguous_match'])
            ->assertSee('Acme North')
            ->assertSee('Acme South')
            ->assertDontSee('55511122')
            ->assertDontSee('55533344')
            ->assertDontSee('north-privacy@example.test')
            ->assertDontSee('south-privacy@example.test');
    }

    public function test_generate_quote_report_resolves_customer_by_document_without_pii(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        $profile = Customer::factory()->create([
            'code' => 'CUST-000088',
            'name' => 'Quote By Document',
            'document' => '66778899',
            'email' => 'quote-doc@example.test',
            'phone' => '+15552223333',
            'contact_name' => 'Hidden Buyer',
        ]);
        Account::factory()->customer($profile)->create(['code' => 'CLI-000088']);
        Product::factory()->create([
            'code' => 'PROD-000088',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => '66778899',
                'items' => [
                    ['product' => 'PROD-000088', 'quantity' => 2],
                ],
            ])
            ->assertOk()
            ->assertSee('CUST-000088')
            ->assertSee('20.00')
            ->assertStructuredContent(function ($json): void {
                $json->where('customer.customer_code', 'CUST-000088')
                    ->missing('customer.document')
                    ->missing('customer.email')
                    ->missing('customer.phone')
                    ->missing('customer.contact_name')
                    ->etc();
            })
            ->assertDontSee('66778899')
            ->assertDontSee('quote-doc@example.test')
            ->assertDontSee('+15552223333')
            ->assertDontSee('Hidden Buyer');
    }

    public function test_generate_quote_report_rejects_caller_provided_prices(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create(['code' => 'CUST-000001']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create(['code' => 'PROD-000001']);

        QuoteServer::actingAs($seller)
            ->tool(GenerateQuoteReportTool::class, [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1, 'unit_price' => '1.00'],
                ],
            ])
            ->assertHasErrors();
    }

    public function test_get_quote_report_omits_customer_pii_from_structure_and_markdown(): void
    {
        $seller = Account::factory()->seller()->create();
        $profile = Customer::factory()->create([
            'code' => 'CUST-000077',
            'name' => 'Report Privacy Co',
            'document' => '44445555',
            'email' => 'report-privacy@example.test',
            'phone' => '+15554445555',
            'contact_name' => 'Markdown Secret',
        ]);
        $customerAccount = Account::factory()->customer($profile)->create();
        $product = Product::factory()->create([
            'code' => 'PROD-000077',
            'price' => '5.00',
            'currency' => 'CAD',
        ]);

        $quote = app(CreateQuoteAction::class)->handle(
            sellerAccount: $seller,
            customerAccount: $customerAccount,
            items: [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        );

        QuoteServer::actingAs($seller)
            ->tool(GetQuoteReportTool::class, [
                'quote_number' => $quote->number,
            ])
            ->assertOk()
            ->assertSee($quote->number)
            ->assertSee('CUST-000077')
            ->assertSee('5.00')
            ->assertStructuredContent(function ($json): void {
                $json->where('customer.customer_code', 'CUST-000077')
                    ->missing('customer.document')
                    ->missing('customer.email')
                    ->missing('customer.phone')
                    ->missing('customer.contact_name')
                    ->etc();
            })
            ->assertDontSee('44445555')
            ->assertDontSee('report-privacy@example.test')
            ->assertDontSee('+15554445555')
            ->assertDontSee('Markdown Secret');
    }

    public function test_search_products_stays_limited_to_identity_unit_price_and_currency(): void
    {
        $seller = Account::factory()->seller()->create();
        Product::factory()->create([
            'code' => 'PROD-000055',
            'name' => 'Privacy Widget',
            'description' => 'Do not leak this catalog description',
            'unit' => 'box',
            'price' => '12.50',
            'currency' => 'CAD',
        ]);

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'PROD-000055'])
            ->assertOk()
            ->assertStructuredContent(function ($json): void {
                $json->has('products', 1)
                    ->where('products.0.code', 'PROD-000055')
                    ->where('products.0.unit', 'box')
                    ->where('products.0.price', '12.50')
                    ->where('products.0.currency', 'CAD')
                    ->missing('products.0.description')
                    ->etc();
            })
            ->assertDontSee('Do not leak this catalog description');
    }

    public function test_log_redaction_covers_document_email_phone_and_password_keys(): void
    {
        $processor = new RedactSensitiveLogContext;
        $record = $processor->process(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Customer lookup',
            context: [
                'document' => '99887766',
                'email' => 'privacy-acme@example.test',
                'phone' => '+15550001111',
                'password' => 'hashed-secret',
                'contact_name' => 'Secret Contact',
                'nested' => [
                    'Authorization' => 'Bearer raw-token-value',
                ],
            ],
        ));

        $this->assertSame('[redacted]', $record->context['document']);
        $this->assertSame('[redacted]', $record->context['email']);
        $this->assertSame('[redacted]', $record->context['phone']);
        $this->assertSame('[redacted]', $record->context['password']);
        $this->assertSame('[redacted]', $record->context['contact_name']);
        $this->assertSame('[redacted]', $record->context['nested']['Authorization']);
        $this->assertSame('Customer lookup', $record->message);
    }
}
