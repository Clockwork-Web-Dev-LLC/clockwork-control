<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\ClientManagement\Database\Factories\ClientFactory;
use Modules\Core\ModuleStateResolver;
use Modules\EmailAuth\Mail\EmailAuthClientNotice;
use Modules\EmailAuth\Models\EmailAuthCheck;
use Modules\EmailAuth\Models\EmailAuthDomain;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

beforeEach(function () {
    Http::fake(['*' => Http::response('', 404)]);
    $this->admin = User::factory()->admin()->create(['email' => 'operator@agency.test']);
    $this->mockIssueCounterZero();
    app(ModuleStateResolver::class)->flush();

    $this->client = ClientFactory::new()->create([
        'name' => 'Reece',
        'company_name' => 'Precision Paint',
        'email' => 'reece@precision.test',
        'additional_emails' => ['office@precision.test'],
    ]);
    Site::factory()->create([
        'server_id' => Server::factory()->create()->id,
        'domain' => 'www.precision.test',
        'client_id' => $this->client->id,
        'is_inactive' => false,
    ]);

    $this->domain = EmailAuthDomain::create(['domain' => 'precision.test', 'last_overall_status' => 'fail', 'last_checked_at' => now()]);
    EmailAuthCheck::create([
        'domain' => 'precision.test',
        'overall_status' => 'fail',
        'spf_status' => 'fail',
        'spf_record' => null,
        'spf_lookup_count' => 0,
        'dmarc_status' => 'fail',
        'dkim_status' => 'pass',
        'dkim_selectors_found' => ['google'],
        'mx_present' => true,
        'checked_at' => now(),
        'findings' => [
            ['code' => 'spf_missing', 'check' => 'spf', 'severity' => 'fail', 'message' => 'No SPF (v=spf1) record published for domain.'],
            ['code' => 'dmarc_missing_rua', 'check' => 'dmarc', 'severity' => 'warn', 'message' => 'DMARC record has no rua= reporting address.'],
            ['code' => 'mx_parked_domain', 'check' => 'mx', 'severity' => 'info', 'message' => 'No active mail exchangers (MX) found.'],
            ['code' => 'dkim_found', 'check' => 'dkim', 'severity' => 'pass', 'message' => 'DKIM key found for selector google.'],
        ],
    ]);
});

it('opens the composer pre-filled with the client contacts and problem findings', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('email-auth.notify', 'precision.test'))
        ->assertOk()
        ->assertSee('reece@precision.test, office@precision.test')
        ->assertSee('Precision Paint')
        ->assertSee('No SPF record')
        ->assertSee('No DMARC reporting address')
        ->assertDontSee('value="dkim_found"', false)      // passes are never offered
        ->getContent();

    // fail + warn pre-selected, info offered but unticked
    expect($html)->toMatch('/value="spf_missing"[^>]*\bchecked\b/s')
        ->toMatch('/value="dmarc_missing_rua"[^>]*\bchecked\b/s')
        ->not->toMatch('/value="mx_parked_domain"[^>]*\bchecked\b/s');
});

it('renders a white-labeled preview with the note first and technical details below', function () {
    $html = $this->actingAs($this->admin)
        ->post(route('email-auth.notify.preview', 'precision.test'), [
            'subject' => 'Email on precision.test',
            'note' => 'We noticed DNS issues affecting your email. Would you like us to fix this?',
            'findings' => ['spf_missing', 'dmarc_missing_rua'],
            'greeting_name' => 'Reece',
        ])
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Hi Reece')
        ->toContain('Would you like us to fix this?')
        ->toContain('No SPF record')                    // plain-English title
        ->toContain('No SPF (v=spf1) record published')  // technical detail
        ->toContain('Technical details')
        ->toContain('bgcolor="#2D2062"')                // brand purple header by default
        ->toContain('precision&zwnj;.test')
        ->not->toContain('Domain doesn’t receive email'); // unselected info finding left out

    expect(strpos($html, 'Would you like us to fix this?'))->toBeLessThan(strpos($html, 'Technical details'));

    preg_match_all('/href="([^"]+)"/', $html, $m);
    expect(collect($m[1])->reject(fn ($h) => str_starts_with($h, 'mailto:'))->all())->toBe([]);
});

it('sends to the edited recipients, copies the operator, and logs it', function () {
    Mail::fake();

    $this->actingAs($this->admin)
        ->post(route('email-auth.notify.send', 'precision.test'), [
            'to' => 'reece@precision.test; Office@Precision.test',
            'subject' => 'Email on precision.test',
            'note' => 'Would you like us to fix this?',
            'findings' => ['spf_missing', 'not_a_real_code'],
            'copy_me' => '1',
        ])
        ->assertRedirect(route('email-auth.index', ['search' => 'precision.test']));

    Mail::assertSent(EmailAuthClientNotice::class, function (EmailAuthClientNotice $mail) {
        return $mail->hasTo('reece@precision.test')
            && $mail->hasTo('office@precision.test')
            && $mail->hasBcc('operator@agency.test')
            && $mail->findingCodes === ['spf_missing'];   // unknown codes are dropped
    });

    $log = ActionLog::where('action_type', 'email_auth_client_notice_sent')->firstOrFail();
    expect($log->target)->toBe('precision.test');
});

it('rejects an invalid recipient address', function () {
    Mail::fake();

    $this->actingAs($this->admin)
        ->from(route('email-auth.notify', 'precision.test'))
        ->post(route('email-auth.notify.send', 'precision.test'), [
            'to' => 'reece@precision.test, not-an-email',
            'subject' => 'x',
            'note' => 'y',
        ])
        ->assertSessionHasErrors('to_list.1');

    Mail::assertNothingSent();
});

it('offers the composer from the email-auth drawer', function () {
    $this->actingAs($this->admin)
        ->get(route('email-auth.index'))
        ->assertOk()
        ->assertSee('Email the client about this')
        ->assertSee('Compose email');
});
