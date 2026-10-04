<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\Core\ModuleStateResolver;
use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Jobs\ScanEmailAuthFleetJob;
use Modules\EmailAuth\Models\EmailAuthCheck;
use Modules\EmailAuth\Models\EmailAuthDomain;
use Modules\EmailAuth\Services\EmailAuthScanner;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->mockIssueCounterZero();
    app(ModuleStateResolver::class)->flush();

    $this->server = Server::factory()->create();
    $this->site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'clientdomain.com',
        'is_inactive' => false,
    ]);
});

test('scanner detects passing SPF, DMARC, and DKIM and stores check', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);

    // MX query
    $resolver->shouldReceive('resolveMx')
        ->with('clientdomain.com')
        ->andReturn([['priority' => 10, 'target' => 'aspmx.l.google.com']]);

    // SPF query
    $resolver->shouldReceive('resolveTxt')
        ->with('clientdomain.com')
        ->andReturn(['v=spf1 include:_spf.google.com ~all']);

    $resolver->shouldReceive('query')
        ->with('_spf.google.com', 'TXT')
        ->andReturn([
            'status' => DnsTxtResolver::STATUS_OK,
            'records' => ['v=spf1 ip4:192.0.2.0/24 ~all'],
        ]);

    // DMARC query
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.clientdomain.com')
        ->andReturn(['v=DMARC1; p=reject; rua=mailto:dmarc@clientdomain.com;']);

    // DKIM queries
    $resolver->shouldReceive('resolveTxt')
        ->andReturnUsing(function ($host) {
            if ($host === 'google._domainkey.clientdomain.com') {
                return ['v=DKIM1; p=MIGfMA0GCSqGSIb3...'];
            }

            return [];
        });

    $this->app->instance(DnsTxtResolver::class, $resolver);

    $scanner = $this->app->make(EmailAuthScanner::class);
    $check = $scanner->scan('clientdomain.com');

    expect($check->overall_status)->toBe(EmailAuthCheck::STATUS_PASS);
    expect($check->spf_status)->toBe('pass');
    expect($check->dmarc_status)->toBe('pass');
    expect($check->dkim_status)->toBe('pass');
    expect($check->mx_present)->toBeTrue();

    $this->assertDatabaseHas('email_auth_checks', [
        'domain' => 'clientdomain.com',
        'overall_status' => 'pass',
    ]);

    $this->assertDatabaseHas('email_auth_domains', [
        'domain' => 'clientdomain.com',
        'last_overall_status' => 'pass',
    ]);
});

test('scanner handles parked domain with null MX', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);

    // Null MX (0 .)
    $resolver->shouldReceive('resolveMx')
        ->with('parked.com')
        ->andReturn([['priority' => 0, 'target' => '.']]);

    // Hardened defensive SPF
    $resolver->shouldReceive('resolveTxt')
        ->with('parked.com')
        ->andReturn(['v=spf1 -all']);

    // Hardened DMARC
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.parked.com')
        ->andReturn(['v=DMARC1; p=reject; rua=mailto:admin@parked.com;']);

    $this->app->instance(DnsTxtResolver::class, $resolver);

    $scanner = $this->app->make(EmailAuthScanner::class);
    $check = $scanner->scan('parked.com');

    expect($check->mx_present)->toBeFalse();
    expect($check->overall_status)->toBe(EmailAuthCheck::STATUS_PASS);
    expect(collect($check->findings)->pluck('code')->all())->toContain('mx_parked_domain');
});

test('fires chat alert on transition to fail, but does not duplicate when already failed or ignored', function () {
    $chatMock = Mockery::mock(ChatNotifier::class);
    $this->app->instance(ChatNotifier::class, $chatMock);

    // Initial domain state: pass
    $domainModel = EmailAuthDomain::create([
        'domain' => 'alerttest.com',
        'last_overall_status' => 'pass',
    ]);

    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveMx')->andReturn([['priority' => 10, 'target' => 'mail.alerttest.com']]);
    $resolver->shouldReceive('resolveTxt')->andReturn([]); // Missing SPF & DMARC -> fail

    $this->app->instance(DnsTxtResolver::class, $resolver);

    // 1. First scan: transitions pass -> fail. Alert must fire once.
    $chatMock->shouldReceive('emailAuthDegraded')
        ->once()
        ->with('alerttest.com', Mockery::type('array'), 'pass')
        ->andReturn(true);

    $scanner = $this->app->make(EmailAuthScanner::class);
    $scanner->scan('alerttest.com');

    // 2. Second scan: domain is already fail. Alert must NOT fire.
    $scanner->scan('alerttest.com');

    // 3. Third scan: domain is ignored. Alert must NOT fire even if transition occurred.
    $domainModel->refresh();
    $domainModel->update(['ignored_at' => now(), 'last_overall_status' => 'pass']);

    $scanner->scan('alerttest.com');
});

test('artisan command check-email-auth runs and deduplicates apex domains', function () {
    // Add a subdomain site sharing the same apex domain
    Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'sub.clientdomain.com',
        'is_inactive' => false,
    ]);

    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveMx')->andReturn([['priority' => 10, 'target' => 'mail.test.com']]);
    $resolver->shouldReceive('resolveTxt')->andReturn(['v=spf1 -all']);
    $this->app->instance(DnsTxtResolver::class, $resolver);

    $exitCode = Artisan::call('clockwork:check-email-auth');
    expect($exitCode)->toBe(0);

    // Verify clientdomain.com was checked exactly once in email_auth_domains
    expect(EmailAuthDomain::where('domain', 'clientdomain.com')->count())->toBe(1);
});

test('email-auth web interface renders and supports ignore toggle and custom selectors', function () {
    EmailAuthDomain::create([
        'domain' => 'clientdomain.com',
        'last_overall_status' => 'pass',
        'last_checked_at' => now(),
    ]);

    // 1. Index page
    $response = $this->actingAs($this->admin)
        ->get(route('email-auth.index'));

    $response->assertOk()
        ->assertSee('Email Authentication')
        ->assertSee('clientdomain.com');

    // 2. Toggle ignore
    $ignoreRes = $this->actingAs($this->admin)
        ->postJson(route('email-auth.ignore', 'clientdomain.com'));

    $ignoreRes->assertOk()->assertJson(['ok' => true, 'ignored' => true]);

    $domain = EmailAuthDomain::where('domain', 'clientdomain.com')->first();
    expect($domain->isIgnored())->toBeTrue();

    // 3. Update custom DKIM selectors
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveMx')->andReturn([['priority' => 10, 'target' => 'mail.test.com']]);
    $resolver->shouldReceive('resolveTxt')->andReturn([]);
    $this->app->instance(DnsTxtResolver::class, $resolver);

    $selRes = $this->actingAs($this->admin)
        ->postJson(route('email-auth.selectors', 'clientdomain.com'), [
            'selectors' => 'custom1, custom2',
        ]);

    $selRes->assertOk()->assertJson(['ok' => true]);

    $domain->refresh();
    expect($domain->custom_dkim_selectors)->toBe(['custom1', 'custom2']);
});

test('scanNow rejects non-full domains and non-platform domains', function () {
    // 1. Non-full domain ('clock' without dot or TLD)
    $res1 = $this->actingAs($this->admin)
        ->postJson(route('email-auth.scan'), ['domain' => 'clock']);

    $res1->assertStatus(422)
        ->assertJson(['ok' => false]);

    // 2. Off-platform domain ('offplatform.com' is full domain but not on platform)
    $res2 = $this->actingAs($this->admin)
        ->postJson(route('email-auth.scan'), ['domain' => 'offplatform.com']);

    $res2->assertStatus(422)
        ->assertJson(['ok' => false]);
});

test('destroy removes domain from email-auth and persists exclusion on index', function () {
    EmailAuthDomain::create([
        'domain' => 'clientdomain.com',
        'last_overall_status' => 'pass',
        'last_checked_at' => now(),
    ]);

    // 1. Delete domain
    $delRes = $this->actingAs($this->admin)
        ->deleteJson(route('email-auth.destroy', 'clientdomain.com'));

    $delRes->assertOk()->assertJson(['ok' => true]);

    $domain = EmailAuthDomain::withTrashed()->where('domain', 'clientdomain.com')->first();
    expect($domain->trashed())->toBeTrue();

    // 2. Visiting index should not show clientdomain.com and should not resurrect it
    $indexRes = $this->actingAs($this->admin)->get(route('email-auth.index'));
    $indexRes->assertOk();
    $indexRes->assertDontSee('p-3.5 font-medium font-data text-sm', false);

    expect(EmailAuthDomain::where('domain', 'clientdomain.com')->count())->toBe(0);
});

test('security tabs include Email Auth and highlight navigation', function () {
    // Security Scans view should include Email Auth tab
    $scansRes = $this->actingAs($this->admin)->get(route('security.scans'));
    $scansRes->assertOk()
        ->assertSee('Email Auth')
        ->assertSee(route('email-auth.index'), false);

    // Email Auth index view should include Security tabs and mark Security as active
    $emailAuthRes = $this->actingAs($this->admin)->get(route('email-auth.index'));
    $emailAuthRes->assertOk()
        ->assertSee('Email Auth')
        ->assertSee('Scan All Domains')
        ->assertSee(route('security.scans'), false);
});

test('scan-all initiates background fleet scan and blocks concurrent runs', function () {
    // 1. First trigger
    $res1 = $this->actingAs($this->admin)
        ->postJson(route('email-auth.scan-all'));

    $res1->assertOk()->assertJson(['ok' => true]);
    expect(Cache::has('email_auth.scan_fleet'))->toBeTrue();

    // 2. Index should show in-progress indicator
    $indexRes = $this->actingAs($this->admin)->get(route('email-auth.index'));
    $indexRes->assertOk()->assertSee('Scan in Progress...');

    // 3. Second concurrent trigger gets 409 already running
    $res2 = $this->actingAs($this->admin)
        ->postJson(route('email-auth.scan-all'));

    $res2->assertStatus(409)->assertJson(['already_running' => true]);

    Cache::forget('email_auth.scan_fleet');
});

test('ScanEmailAuthFleetJob runs Artisan command and releases lock', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveMx')->andReturn([['priority' => 10, 'target' => 'mail.test.com']]);
    $resolver->shouldReceive('resolveTxt')->andReturn(['v=spf1 -all']);
    $this->app->instance(DnsTxtResolver::class, $resolver);

    $job = new ScanEmailAuthFleetJob;
    $job->handle();

    expect(Cache::has('email_auth.scan_fleet'))->toBeFalse();
    expect(EmailAuthDomain::where('domain', 'clientdomain.com')->count())->toBe(1);
});
