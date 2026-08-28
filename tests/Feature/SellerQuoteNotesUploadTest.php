<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerQuoteNotesUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_home_page_shows_the_seller_notes_upload_form(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('Turn a visit note into a draft quote')
            ->assertSee('/seller-notes', false)
            ->assertSee('type="file"', false)
            ->assertSee('name="notes"', false)
            ->assertSee('Notes file template')
            ->assertSee('INSTRUCTIONS:')
            ->assertSee('Northwind Industrial Ltd')
            ->assertSee('2x Access Doors and Panels')
            ->assertSee(route('seller-notes.template', absolute: false), false)
            ->assertSee('Create draft quote from notes')
            ->assertSee('generate_quote_report')
            ->assertSee('id="landing-split"', false)
            ->assertSee('id="notes-flow"', false)
            ->assertSee('id="mcp-connect"', false)
            ->assertSee('From a notes file')
            ->assertSee('From an LLM client')
            ->assertSee('grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)', false)
            ->assertSee('Claude Code (local stdio)')
            ->assertSee('Claude Code')
            ->assertSee('Codex')
            ->assertSee('/generate-quote-report')
            ->assertSee('/generate-quote-from-notes')
            ->assertSee('/generate-quote-draft-pdf')
            ->assertSee('search_products')
            ->assertSee('search_customers')
            ->assertSee('ingest_seller_quote_notes')
            ->assertSee('get_quote_report')
            ->assertSee('generate_quote_draft_pdf')
            ->assertSee('health_check')
            ->assertSee('Future improvements')
            ->assertSee('OpenAI')
            ->assertDontSee('Let\'s get started')
            ->assertDontSee('Laracasts')
            ->getContent();

        $split = strpos($html, 'id="landing-split"');
        $notes = strpos($html, 'id="notes-flow"');
        $template = strpos($html, 'id="seller-notes-template"');
        $upload = strpos($html, 'id="seller-notes"');
        $guide = strpos($html, 'id="mcp-connect"');
        $this->assertNotFalse($split);
        $this->assertNotFalse($notes);
        $this->assertNotFalse($template);
        $this->assertNotFalse($upload);
        $this->assertNotFalse($guide);
        $this->assertLessThan($notes, $split);
        $this->assertLessThan($template, $notes);
        $this->assertLessThan($upload, $template);
        $this->assertLessThan($guide, $notes);
    }

    public function test_the_notes_template_is_downloadable_as_utf8_text(): void
    {
        $this->get(route('seller-notes.template'))
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="seller-notes-template.txt"')
            ->assertSee('INSTRUCTIONS:', false)
            ->assertSee('Customer: Northwind Industrial Ltd', false)
            ->assertSee('2x Access Doors and Panels', false)
            ->assertDontSee('purchasing@northwind.test')
            ->assertDontSee('+14165550101');
    }

    public function test_viewing_the_home_page_or_template_does_not_store_notes_or_create_a_quote(): void
    {
        $this->fakePrivateStorageDisks();

        $this->get('/')->assertOk();
        $this->get(route('seller-notes.template'))->assertOk();

        Storage::disk('local')->assertDirectoryEmpty('quotes/notes');
        $this->assertSame(0, Quote::query()->count());
    }

    public function test_a_local_seller_upload_creates_a_draft_from_unambiguous_notes(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->fakePrivateStorageDisks();

        $profile = Customer::factory()->create(['name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create();
        $keyboard = Product::factory()->create([
            'name' => 'Premium Keyboard',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);
        $mouse = Product::factory()->create([
            'name' => 'Wireless Mouse',
            'price' => '5.00',
            'currency' => 'CAD',
        ]);

        $notes = <<<'TEXT'
Visit 28/08 — Acme Ltd
3x premium keyboard, 1 mouse
They mentioned R$ 199 but I said catalog price
Ship to the usual address — João 11 99999-0000
TEXT;

        $response = $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('visit-acme.txt', $notes),
        ]);

        $response->assertRedirect(route('home'))
            ->assertSessionHas('filename', 'visit-acme.txt')
            ->assertSessionHas('storage_path')
            ->assertSessionHas('quote_report');

        $this->assertSame(1, Quote::query()->count());
        $quote = Quote::query()->with('items')->first();
        $this->assertNotNull($quote);
        $this->assertSame('35.00', $quote->total);
        $this->assertSame('10.00', $quote->items->firstWhere('product_id', $keyboard->id)?->unit_price);
        $this->assertSame('5.00', $quote->items->firstWhere('product_id', $mouse->id)?->unit_price);
        $this->assertNotSame('199', $quote->items->first()?->unit_price);

        $storagePath = session('storage_path');
        $this->assertIsString($storagePath);
        Storage::disk('local')->assertExists($storagePath);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Draft quote created. It is not approved.')
            ->assertSee('Approve quote')
            ->assertSee($quote->number)
            ->assertSee('10.00')
            ->assertSee('5.00')
            ->assertSee('35.00')
            ->assertSee('CAD')
            ->assertSee($storagePath)
            ->assertDontSee('99999-0000')
            ->assertSee('Approve quote');

        $this->get('/')
            ->assertOk()
            ->assertSee('Draft quote created. It is not approved.')
            ->assertSee('Approve quote')
            ->assertSee('id="approve-quote"', false)
            ->assertSee($quote->number);
    }

    public function test_ambiguous_catalog_matches_store_the_file_and_do_not_create_a_quote(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->fakePrivateStorageDisks();

        $profile = Customer::factory()->create(['name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create(['name' => 'Premium Keyboard']);
        Product::factory()->create(['name' => 'Budget Keyboard']);

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme Ltd\n1x keyboard\n"),
        ])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors()
            ->assertSessionMissing('quote_report');

        $this->assertSame(0, Quote::query()->count());
        $this->assertNotEmpty(Storage::disk('local')->allFiles('quotes/notes'));
    }

    public function test_it_rejects_empty_oversize_and_disallowed_types(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->fakePrivateStorageDisks();

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('empty.txt', "   \n"),
        ])->assertRedirect('/')->assertSessionHasErrors('notes');

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('huge.txt', str_repeat('a', 65537)),
        ])->assertRedirect('/')->assertSessionHasErrors('notes');

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('notes.pdf', 'not a pdf really'),
        ])->assertRedirect('/')->assertSessionHasErrors('notes');

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('notes.bin', "\0\1\2binary"),
        ])->assertRedirect('/')->assertSessionHasErrors('notes');

        Storage::disk('local')->assertDirectoryEmpty('quotes/notes');
        $this->assertSame(0, Quote::query()->count());
    }

    public function test_a_customer_cannot_store_notes(): void
    {
        $customer = Account::factory()->customer()->create();
        $this->fakePrivateStorageDisks();

        $this->actingAs($customer)
            ->post(route('seller-notes.store'), [
                'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme\n1x mouse\n"),
            ])
            ->assertForbidden();

        Storage::disk('local')->assertDirectoryEmpty('quotes/notes');
        $this->assertSame(0, Quote::query()->count());
    }

    public function test_production_without_a_seller_session_cannot_store_notes(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app['env'] = 'production';
        $this->fakePrivateStorageDisks();

        $this->from('/')
            ->post(route('seller-notes.store'), [
                'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme\n1x mouse\n"),
            ])
            ->assertForbidden();

        Storage::disk('local')->assertDirectoryEmpty('quotes/notes');
        $this->assertSame(0, Quote::query()->count());
    }

    public function test_the_landing_approve_submits_then_approves_without_repricing(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->fakePrivateStorageDisks();

        $profile = Customer::factory()->create(['name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create([
            'name' => 'Premium Keyboard',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme Ltd\n2x premium keyboard\n"),
        ])->assertRedirect(route('home'));

        $quote = Quote::query()->first();
        $this->assertNotNull($quote);
        $this->assertSame(QuoteStatus::Draft, $quote->status);
        $this->assertSame('20.00', $quote->total);

        $response = $this->from('/')->post(route('seller-notes.quotes.approve', $quote));

        $response->assertRedirect(route('home'));
        $quote->refresh();
        $this->assertSame(QuoteStatus::Approved, $quote->status);
        $this->assertNotNull($quote->approved_at);
        $this->assertSame('20.00', $quote->total);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Quote approved.')
            ->assertSee($quote->number)
            ->assertSee('20.00')
            ->assertDontSee('Approve quote');
    }

    public function test_another_seller_cannot_approve_from_the_landing(): void
    {
        $owner = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $owner->code]);
        $this->fakePrivateStorageDisks();

        $profile = Customer::factory()->create(['name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create(['name' => 'Premium Keyboard', 'price' => '10.00']);

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme Ltd\n1x premium keyboard\n"),
        ])->assertRedirect(route('home'));

        $quote = Quote::query()->first();
        $this->assertNotNull($quote);

        $intruder = Account::factory()->seller()->create(['code' => 'VEN-000002']);

        $this->actingAs($intruder)
            ->post(route('seller-notes.quotes.approve', $quote))
            ->assertForbidden();

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()?->status);
    }

    public function test_a_customer_cannot_approve_from_the_landing(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);
        $this->fakePrivateStorageDisks();

        $profile = Customer::factory()->create(['name' => 'Acme Ltd']);
        $customer = Account::factory()->customer($profile)->create();
        Product::factory()->create(['name' => 'Premium Keyboard', 'price' => '10.00']);

        $this->from('/')->post(route('seller-notes.store'), [
            'notes' => UploadedFile::fake()->createWithContent('visit.txt', "Customer: Acme Ltd\n1x premium keyboard\n"),
        ])->assertRedirect(route('home'));

        $quote = Quote::query()->first();
        $this->assertNotNull($quote);

        $this->actingAs($customer)
            ->post(route('seller-notes.quotes.approve', $quote))
            ->assertForbidden();

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()?->status);
    }
}
