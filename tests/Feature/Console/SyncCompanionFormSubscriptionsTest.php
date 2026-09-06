<?php

namespace Tests\Feature\Console;

use App\Models\ContactFormTest;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:sync-companion-form-subscriptions
|--------------------------------------------------------------------------
|
| Reconciles contact_form_tests rows with created_by=client against each
| care-plan site's Companion /form-subscriptions endpoint. Http::fake()
| asserts against the real Companion HMAC route pattern (see
| tests/Feature/Companion/CompanionHmacAuthTest.php) rather than mocking
| ClockworkCompanionClient itself.
*/

function scfsSite(array $overrides = []): Site
{
    return Site::factory()->carePlan()->withCompanionInstalled()->create($overrides);
}

function scfsFakeSubscriptions(Site $site, array $subscriptions): void
{
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/form-subscriptions" => Http::response(
            ['ok' => true, 'max' => 3, 'subscriptions' => $subscriptions],
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

describe('clockwork:sync-companion-form-subscriptions — additions', function () {
    it('creates a client-provenance contact_form_tests row for a Companion subscription with no matching row yet', function () {
        $site = scfsSite();
        scfsFakeSubscriptions($site, [
            ['form_id' => 7, 'plugin' => 'cf7', 'frequency' => 'daily', 'enabled' => true],
        ]);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertSuccessful();

        $row = ContactFormTest::query()->where('site_id', $site->id)->where('form_id', '7')->first();
        expect($row)->not->toBeNull()
            ->and($row->created_by)->toBe(ContactFormTest::CREATED_BY_CLIENT)
            ->and($row->form_plugin)->toBe('cf7')
            ->and($row->frequency)->toBe('daily')
            ->and($row->enabled)->toBeTrue()
            ->and($row->state)->toBe(ContactFormTest::STATE_PENDING)
            ->and($row->slot)->toBe(1);
    });

    /**
     * BUG FOUND WHILE WRITING THIS TEST (reported, not fixed — this phase is
     * test-only): SyncCompanionFormSubscriptions::syncSite() builds
     * $agencyOwnedIds via ->pluck('form_id')->all(), which yields real PHP
     * strings ("7") straight from the varchar column. It then iterates
     * $subs (keyed by (string) $s['form_id']) and checks
     * in_array($formId, $agencyOwnedIds, true) — but PHP silently
     * int-normalizes numeric-string ARRAY KEYS, so $formId arrives as
     * int(7), not string "7". in_array(7, ["7"], true) is strictly false
     * (confirmed directly: `$a["7"]="x"; array_key_first($a)` yields
     * int(7)), so the "agency already owns this form_id, silently honor
     * it" branch never actually matches for any purely-numeric form_id —
     * which covers essentially every real CF7/WPForms/Gravity Forms post
     * ID. The code falls through to create(), collides with the existing
     * agency row's unique(site_id, form_id) constraint, and that DB
     * exception is caught by handle()'s per-site try/catch — so instead of
     * silently skipping, the site is counted as failed and the command
     * exits non-zero. This test documents that actual behavior.
     */
    it('BUG: fails the site (unique-constraint collision) instead of silently honoring an agency-owned numeric form_id', function () {
        $site = scfsSite();
        ContactFormTest::factory()->for($site)->create([
            'form_id' => '7',
            'created_by' => ContactFormTest::CREATED_BY_AGENCY,
            'slot' => 1,
        ]);
        scfsFakeSubscriptions($site, [
            ['form_id' => 7, 'plugin' => 'cf7', 'frequency' => 'daily'],
        ]);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertFailed();

        // The agency row survives untouched; no duplicate client row was created.
        expect(ContactFormTest::query()->where('site_id', $site->id)->count())->toBe(1);
        expect(ContactFormTest::query()->where('site_id', $site->id)->where('created_by', ContactFormTest::CREATED_BY_CLIENT)->count())->toBe(0);
    });

    it('skips gracefully (without crashing) when the site is already at MAX_PER_SITE', function () {
        $site = scfsSite();
        // Keep all 3 existing rows subscribed in the Companion payload too, so
        // the removal pass (rows present here but absent from $subs) doesn't
        // delete them out from under this test — isolating the cap check.
        $subscriptions = [];
        for ($i = 1; $i <= ContactFormTest::MAX_PER_SITE; $i++) {
            ContactFormTest::factory()->for($site)->create([
                'form_id' => (string) $i,
                'slot' => $i,
                'created_by' => ContactFormTest::CREATED_BY_CLIENT,
            ]);
            $subscriptions[] = ['form_id' => $i, 'plugin' => 'cf7', 'frequency' => 'daily'];
        }
        // One more subscription arrives beyond the cap.
        $subscriptions[] = ['form_id' => 99, 'plugin' => 'cf7', 'frequency' => 'daily'];
        scfsFakeSubscriptions($site, $subscriptions);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertSuccessful();

        expect(ContactFormTest::query()->where('site_id', $site->id)->count())->toBe(ContactFormTest::MAX_PER_SITE);
        expect(ContactFormTest::query()->where('site_id', $site->id)->where('form_id', '99')->exists())->toBeFalse();
    });
});

describe('clockwork:sync-companion-form-subscriptions — removals', function () {
    it('deletes a client-provenance row no longer present in Companion subscriptions', function () {
        $site = scfsSite();
        ContactFormTest::factory()->for($site)->create([
            'form_id' => '4',
            'slot' => 1,
            'created_by' => ContactFormTest::CREATED_BY_CLIENT,
        ]);
        scfsFakeSubscriptions($site, []); // client unsubscribed on the WP side

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertSuccessful();

        expect(ContactFormTest::query()->where('site_id', $site->id)->where('form_id', '4')->exists())->toBeFalse();
    });

    it('never touches an agency-provenance row even when Companion stops listing that form_id', function () {
        $site = scfsSite();
        $agencyRow = ContactFormTest::factory()->for($site)->create([
            'form_id' => '4',
            'slot' => 1,
            'created_by' => ContactFormTest::CREATED_BY_AGENCY,
        ]);
        scfsFakeSubscriptions($site, []);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertSuccessful();

        expect(ContactFormTest::query()->whereKey($agencyRow->id)->exists())->toBeTrue();
    });
});

describe('clockwork:sync-companion-form-subscriptions — --dry-run', function () {
    it('reports planned additions and removals without writing to the database', function () {
        $site = scfsSite();
        ContactFormTest::factory()->for($site)->create([
            'form_id' => '4',
            'slot' => 1,
            'created_by' => ContactFormTest::CREATED_BY_CLIENT,
        ]);
        scfsFakeSubscriptions($site, [
            ['form_id' => 9, 'plugin' => 'wpforms', 'frequency' => 'weekly'],
        ]);

        $this->artisan('clockwork:sync-companion-form-subscriptions', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertSuccessful();

        // form_id=4 (no longer subscribed) still exists; form_id=9 (new) was NOT created.
        expect(ContactFormTest::query()->where('site_id', $site->id)->where('form_id', '4')->exists())->toBeTrue();
        expect(ContactFormTest::query()->where('site_id', $site->id)->where('form_id', '9')->exists())->toBeFalse();
    });
});

describe('clockwork:sync-companion-form-subscriptions — targeting and failure handling', function () {
    it('only targets care-plan sites with Companion installed, skipping everyone else', function () {
        $eligible = scfsSite();
        $notCarePlan = Site::factory()->withCompanionInstalled()->create(['care_plan_enabled' => false]);
        $notCompanion = Site::factory()->carePlan()->create(['companion_installed' => false]);

        scfsFakeSubscriptions($eligible, []);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), $eligible->domain));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $notCarePlan->domain));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $notCompanion->domain));
    });

    it('--site limits the run to a single site by domain', function () {
        $target = scfsSite();
        $other = scfsSite();
        scfsFakeSubscriptions($target, []);

        $this->artisan('clockwork:sync-companion-form-subscriptions', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), $target->domain));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('returns FAILURE overall and leaves the site untouched when the Companion call itself fails', function () {
        $site = scfsSite();
        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/form-subscriptions" => Http::response(
                ['message' => 'error'],
                500,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:sync-companion-form-subscriptions')->assertFailed();

        expect(ContactFormTest::query()->where('site_id', $site->id)->count())->toBe(0);
    });
});
