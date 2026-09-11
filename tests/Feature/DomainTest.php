<?php

namespace Tests\Feature;

use App\Classes\Cart as CartHelper;
use App\Helpers\ExtensionHelper;
use App\Jobs\Domain\RegisterJob;
use App\Jobs\Domain\RenewJob;
use App\Livewire\Cart;
use App\Livewire\Domains\Search;
use App\Livewire\Domains\Show;
use App\Models\CartItem;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Registrar;
use App\Models\Setting;
use App\Models\Tld;
use App\Models\User;
use App\Services\Domain\DomainPricingService;
use App\Services\Domain\ImportTldsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    private Registrar $registrar;

    private Tld $com;

    private array $catalog = [
        ['extension' => '.com', 'registrationPrice' => 1000, 'renewalPrice' => 1200, 'transferPrice' => 1000, 'minRegistrationPeriod' => 1, 'maxRegistrationPeriod' => 10],
        ['extension' => '.net', 'registrationPrice' => 1500, 'renewalPrice' => 1500, 'transferPrice' => 1500],
        ['extension' => '.eu', 'registrationPrice' => 800, 'renewalPrice' => 800, 'transferPrice' => 800, 'currency' => 'EUR'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/v1/public/catalog/domains' => fn () => Http::response(['extensions' => $this->catalog]),
            '*/v1/public/catalog/domains/taken.com/availability' => Http::response(['domain' => 'taken.com', 'available' => false, 'price' => 1039, 'currency' => 'USD']),
            '*/v1/public/catalog/domains/premium.com/availability' => Http::response(['domain' => 'premium.com', 'available' => true, 'premium' => true, 'price' => 5000, 'totalPrice' => 5000, 'renewPrice' => 6000, 'currency' => 'USD']),
            '*/v1/public/catalog/domains/live.com/availability' => Http::response(['domain' => 'live.com', 'available' => true, 'price' => 1039, 'totalPrice' => 1089, 'currency' => 'USD']),
            '*/v1/public/catalog/domains/promo.com/availability' => Http::response(['domain' => 'promo.com', 'available' => true, 'price' => 1039, 'totalPrice' => 1089, 'promoApplied' => true, 'promoPrice' => 499, 'currency' => 'USD']),
            // Names without a quote fall back to the TLD grid
            '*/v1/public/catalog/domains/*/availability' => Http::response(['available' => true]),
            '*/v2/domains/register' => Http::response(['success' => true, 'data' => ['domain' => 'example.com', 'expiryDate' => '2028-09-11T00:00:00Z']]),
            '*/v2/transfer/initiate' => Http::response(['success' => true]),
            '*/v2/domains/*/renew' => Http::response(['success' => true, 'data' => ['expiryDate' => '2029-09-11T00:00:00Z']]),
            '*/v2/domains/*/info' => Http::response(['success' => true, 'data' => ['domain' => 'example.com', 'nameservers' => ['ns1.osir.com', 'ns3.osir.com'], 'locked' => true, 'privacy' => false, 'expiryDate' => '2028-09-11T00:00:00Z', 'status' => 'active']]),
            '*/v2/domains/*/unlock' => Http::response(['success' => true]),
            '*/v2/domains/*/nameservers' => Http::response(['success' => true]),
            '*/v2/domains/*/authcode' => Http::response(['success' => true, 'data' => ['authCode' => 'SECRET-EPP']]),
            '*/v2/domains/*/contacts' => Http::response(['success' => true, 'data' => ['registrant' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com', 'country' => 'GB']]]),
        ]);

        $this->registrar = Registrar::create(['name' => 'OSIR', 'extension' => 'Osir', 'type' => 'registrar', 'enabled' => true]);
        foreach (['api_url' => 'https://be.osir.test', 'api_key' => 'osir_test_key', 'tenant_id' => 'osir', 'environment' => 'ote1'] as $key => $value) {
            $this->registrar->settings()->create(['key' => $key, 'value' => $value]);
        }

        $this->com = Tld::factory()->create(['tld' => 'com', 'registrar_id' => $this->registrar->id, 'featured' => true, 'sort' => 1]);
        foreach ([1 => [10, 12, 10], 2 => [20, 24, 20]] as $years => [$register, $renew, $transfer]) {
            $this->com->prices()->create(['currency_code' => 'USD', 'years' => $years, 'register' => $register, 'renew' => $renew, 'transfer' => $transfer]);
        }
        $net = Tld::factory()->create(['tld' => 'net', 'registrar_id' => $this->registrar->id, 'featured' => true, 'sort' => 2]);
        $net->prices()->create(['currency_code' => 'USD', 'years' => 1, 'register' => 15, 'renew' => 15, 'transfer' => 15]);
    }

    public function test_import_creates_tlds_and_price_grid(): void
    {
        $result = (new ImportTldsService)->handle($this->registrar, markup: 10);

        // .eu is priced in EUR which is not a configured currency
        $this->assertSame(['imported' => 2, 'skipped' => 1], $result);
        $this->assertDatabaseHas('tlds', ['tld' => 'net', 'registrar_id' => $this->registrar->id]);
        $this->assertEquals(11.00, $this->com->fresh()->price('USD', 1, 'register'));
        $this->assertEquals(33.00, $this->com->fresh()->price('USD', 3, 'register'));
        $this->assertEquals(39.60, $this->com->fresh()->price('USD', 3, 'renew'));
        $this->assertCount(10, $this->com->fresh()->prices);
    }

    public function test_live_registrar_price_wins_over_the_grid_and_gets_the_markup(): void
    {
        config(['settings.domain_markup' => 20]);
        $pricing = new DomainPricingService;

        $this->assertEquals(120.00, $pricing->quote($this->com, 'register', 2, 'USD', ['register' => 50, 'currency' => 'USD']));
        // Wrong currency: fall back to the grid
        $this->assertEquals(20.00, $pricing->quote($this->com, 'register', 2, 'USD', ['register' => 50, 'currency' => 'EUR']));
        $this->assertEquals(24.00, $pricing->quote($this->com, 'renew', 2, 'USD'));
        $this->assertNull($pricing->quote($this->com, 'register', 3, 'USD'));
    }

    public function test_search_checks_availability_and_adds_to_cart(): void
    {
        $component = Livewire::test(Search::class)
            ->set('query', 'example.com')
            ->set('years', 2)
            ->call('search')
            ->assertHasNoErrors()
            ->assertSet("results.{$this->com->id}.available", true)
            ->assertSet("results.{$this->com->id}.price", 20.0)
            ->call('addToCart', $this->com->id);

        $item = CartItem::firstOrFail();
        $this->assertSame('example', $item->domain);
        $this->assertSame($this->com->id, $item->tld_id);
        $this->assertSame(2, $item->years);
        $this->assertEquals(20.00, $item->domain_price);
        $this->assertSame('example.com', $item->domain_name);
        $component->assertSet("results.{$this->com->id}.in_cart", true);

        Livewire::test(Search::class)->set('query', 'taken')->call('search')
            ->assertSet("results.{$this->com->id}.available", false);
    }

    public function test_premium_domain_is_priced_from_the_registrar(): void
    {
        // Settings are loaded from the database on every request
        Setting::create(['key' => 'domain_markup', 'value' => 10]);

        $results = Livewire::test(Search::class)->set('query', 'premium.com')->set('years', 1)->call('search')->get('results');

        $this->assertTrue($results[$this->com->id]['premium']);
        $this->assertEquals(55.0, $results[$this->com->id]['price']);
        $this->assertEquals(15.0, $results[Tld::where('tld', 'net')->value('id')]['price']);

        // Regular names quoted by the registrar: total price (incl. fees) + markup, times the term
        $results = Livewire::test(Search::class)->set('query', 'live.com')->set('years', 2)->call('search')->get('results');
        $this->assertFalse($results[$this->com->id]['premium']);
        $this->assertEquals(23.96, $results[$this->com->id]['price']);

        // Registry promotions replace the price
        $results = Livewire::test(Search::class)->set('query', 'promo.com')->set('years', 1)->call('search')->get('results');
        $this->assertEquals(5.49, $results[$this->com->id]['price']);
    }

    public function test_transfer_adds_to_cart_with_auth_code(): void
    {
        Livewire::test(Search::class)
            ->set('transferDomain', 'mine.com')
            ->set('authCode', 'EPP123')
            ->set('years', 2)
            ->call('transfer')
            ->assertHasNoErrors()
            ->assertRedirect(route('cart'));

        $item = CartItem::firstOrFail();
        $this->assertSame(Domain::ACTION_TRANSFER, $item->domain_action);
        $this->assertSame('EPP123', $item->auth_code);
        $this->assertEquals(20.00, $item->domain_price);
    }

    public function test_checkout_creates_a_pending_domain_and_payment_registers_it(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user);
        CartHelper::addDomain($this->com, 'example', 2, Domain::ACTION_REGISTER, 20);
        // The cart cookie is only queued, make the test requests carry it
        $ulid = \App\Models\Cart::firstOrFail()->ulid;
        request()->cookies->set('cart', $ulid);

        Livewire::withCookie('cart', $ulid)->test(Cart::class)->call('checkout')->assertHasNoErrors();

        $domain = Domain::firstOrFail();
        $this->assertSame('example.com', $domain->domain);
        $this->assertSame(Domain::STATUS_PENDING, $domain->status);
        $this->assertSame(2, $domain->years);
        $this->assertEquals(20.00, $domain->price);
        $invoice = Invoice::firstOrFail();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        Queue::fake();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', $invoice->total);
        Queue::assertPushed(RegisterJob::class, fn ($job) => $job->domain->id === $domain->id);

        (new RegisterJob($domain, sendNotification: false))->handle();

        $this->assertSame(Domain::STATUS_ACTIVE, $domain->fresh()->status);
        $this->assertSame('2028-09-11', $domain->fresh()->expires_at->format('Y-m-d'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/domains/register')
            && $request['domain'] === 'example.com'
            && $request['period'] === 2
            && $request['registrant']['email'] === $user->email
            && $request->hasHeader('Idempotency-Key', 'paymenter-register-' . $domain->id));
    }

    public function test_cron_creates_renewal_invoice_and_payment_renews(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['user_id' => $user->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id, 'name' => 'example', 'domain' => 'example.com', 'years' => 2, 'expires_at' => now()->addDays(3)]);

        $this->artisan('app:cron-job')->assertSuccessful();

        $invoice = $domain->invoices()->firstOrFail();
        $this->assertEquals(24.00, $invoice->items->first()->price);
        $this->assertSame(1, $domain->invoices()->count());

        // Running again does not create a second invoice
        $this->artisan('app:cron-job')->assertSuccessful();
        $this->assertSame(1, $domain->invoices()->count());

        Queue::fake();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 24.00);
        Queue::assertPushed(RenewJob::class, fn ($job) => $job->domain->id === $domain->id && $job->years === 2);

        (new RenewJob($domain, 2, sendNotification: false))->handle();
        $this->assertSame('2029-09-11', $domain->fresh()->expires_at->format('Y-m-d'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/domains/example.com/renew') && $request['period'] === 2);
    }

    public function test_unpaid_domains_expire_and_auto_renew_off_skips_invoicing(): void
    {
        $user = User::factory()->create();
        $expired = Domain::factory()->create(['user_id' => $user->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id, 'expires_at' => now()->subDay()]);
        $manual = Domain::factory()->create(['user_id' => $user->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id, 'expires_at' => now()->addDays(2), 'auto_renew' => false]);

        $this->artisan('app:cron-job')->assertSuccessful();

        $this->assertSame(Domain::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertSame(0, $manual->invoices()->count());
    }

    public function test_customer_manages_nameservers_lock_auth_code_and_contacts(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['user_id' => $user->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id, 'name' => 'example', 'domain' => 'example.com']);
        $this->actingAs($user);

        Livewire::test(Show::class, ['domain' => $domain])
            ->call('changeTab', 'nameservers')
            ->assertSet('nameservers.0', 'ns1.osir.com')
            ->set('nameservers.1', 'ns2.example.org')
            ->call('updateNameservers')
            ->assertHasNoErrors()
            ->call('changeTab', 'transfer')
            ->assertSet('locked', true)
            ->call('toggleLock')
            ->assertSet('locked', false)
            ->call('showAuthCode')
            ->assertSet('authCode', 'SECRET-EPP')
            ->call('changeTab', 'contacts')
            ->assertSet('contacts.registrant.first_name', 'Ada')
            ->set('contacts.registrant.organization', 'Analytical Engines')
            ->call('updateContacts')
            ->assertHasNoErrors()
            ->call('toggleAutoRenew');

        $this->assertSame(['ns1.osir.com', 'ns2.example.org'], $domain->fresh()->nameservers);
        $this->assertFalse($domain->fresh()->auto_renew);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/v2/domains/example.com/nameservers') && $request['nameservers'] === ['ns1.osir.com', 'ns2.example.org']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/domains/example.com/unlock'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/v2/domains/example.com/contacts') && $request['registrant']['organization'] === 'Analytical Engines');
    }

    public function test_renew_now_creates_an_invoice_for_the_chosen_term(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create(['user_id' => $user->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id]);
        $this->actingAs($user);

        Livewire::test(Show::class, ['domain' => $domain])
            ->set('renewYears', 1)
            ->call('renew')
            ->assertRedirect();

        $invoice = $domain->invoices()->firstOrFail();
        $this->assertEquals(12.00, $invoice->items->first()->price);
        $this->assertSame(1, $domain->fresh()->years);
    }

    public function test_other_users_cannot_view_a_domain(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $domain = Domain::factory()->create(['user_id' => $owner->id, 'tld_id' => $this->com->id, 'registrar_id' => $this->registrar->id]);

        $this->actingAs($owner)->withSession($this->loginUser($owner))->get(route('domains.show', $domain))->assertOk();
        // Paymenter hides resources of other users (same as services)
        $this->actingAs($other)->withSession($this->loginUser($other))->get(route('domains.show', $domain))->assertNotFound();
    }
}
