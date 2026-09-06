<?php

use App\Models\ContactFormTest;
use App\Models\Site;
use App\Models\User;
use App\Services\Forms\ContactFormTester;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects to login when hitting /forms unauthenticated', function () {
        $response = $this->get(route('forms.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('GET /forms (index)', function () {
    it('renders the fleet-wide form-tests list', function () {
        $cft = ContactFormTest::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('forms.index'));

        $response->assertOk();
        $response->assertSee($cft->site->domain);
    });

    it('filters by state', function () {
        $passing = ContactFormTest::factory()->create(['state' => ContactFormTest::STATE_SUCCESS]);
        $failing = ContactFormTest::factory()->failing()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('forms.index', ['state' => 'failed']));

        $response->assertOk();
        $response->assertSee($failing->site->domain);
        $response->assertDontSee($passing->site->domain);
    });

    it('renders the live search input, clear button, and data-search rows', function () {
        $cft = ContactFormTest::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('forms.index'));

        $response->assertOk()
            ->assertSee('id="forms-search"', false)
            ->assertSee('id="forms-search-clear"', false)
            ->assertSee('data-search="'.strtolower($cft->site->domain), false);
    });
});

describe('POST /sites/{site}/form-tests (store)', function () {
    it('adds a form-test to a care-plan site and redirects to the forms tab', function () {
        $site = Site::factory()->carePlan()->create();

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.store', ['site' => $site]),
            [
                'form_id' => 'contact-1',
                'form_plugin' => Site::CONTACT_FORM_PLUGIN_CF7,
                'form_url' => 'https://example.com/contact',
                'frequency' => 'weekly',
            ]
        );

        $response->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'forms']));
        $response->assertSessionHas('status', 'Form-test added.');

        expect($site->contactFormTests()->where('form_id', 'contact-1')->exists())->toBeTrue();
    });

    it('rejects adding a form-test when the site is not on a care plan', function () {
        $site = Site::factory()->create(['care_plan_enabled' => false]);

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.store', ['site' => $site]),
            [
                'form_id' => 'contact-1',
                'frequency' => 'weekly',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
        expect($site->contactFormTests()->count())->toBe(0);
    });

    it('rejects a 4th form-test once the 3-per-site cap is reached', function () {
        $site = Site::factory()->carePlan()->create();
        ContactFormTest::factory()->for($site)->count(ContactFormTest::MAX_PER_SITE)->sequence(
            ['slot' => 1, 'form_id' => 'a'],
            ['slot' => 2, 'form_id' => 'b'],
            ['slot' => 3, 'form_id' => 'c'],
        )->create();

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.store', ['site' => $site]),
            [
                'form_id' => 'd',
                'frequency' => 'weekly',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
        expect($site->contactFormTests()->count())->toBe(ContactFormTest::MAX_PER_SITE);
    });

    it('rejects a duplicate form_id on the same site', function () {
        $site = Site::factory()->carePlan()->create();
        ContactFormTest::factory()->for($site)->create(['form_id' => 'dupe']);

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.store', ['site' => $site]),
            [
                'form_id' => 'dupe',
                'frequency' => 'weekly',
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('status_error');
        expect($site->contactFormTests()->where('form_id', 'dupe')->count())->toBe(1);
    });

    it('fails validation when frequency is missing', function () {
        $site = Site::factory()->carePlan()->create();

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.store', ['site' => $site]),
            [
                'form_id' => 'contact-1',
            ]
        );

        $response->assertSessionHasErrors('frequency');
    });
});

describe('PATCH /sites/{site}/form-tests/{cft} (update)', function () {
    it('updates the row and redirects to the forms tab', function () {
        $site = Site::factory()->carePlan()->create();
        $cft = ContactFormTest::factory()->for($site)->create(['frequency' => ContactFormTest::FREQUENCY_DAILY]);

        $response = $this->actingAs(User::factory()->create())->patch(
            route('sites.forms.update', ['site' => $site, 'cft' => $cft]),
            ['frequency' => 'weekly']
        );

        $response->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'forms']));
        $response->assertSessionHas('status', 'Form-test updated.');
        expect($cft->refresh()->frequency)->toBe('weekly');
    });

    it('404s when the form-test does not belong to the given site', function () {
        $site = Site::factory()->carePlan()->create();
        $otherSite = Site::factory()->carePlan()->create();
        $cft = ContactFormTest::factory()->for($otherSite)->create();

        $response = $this->actingAs(User::factory()->create())->patch(
            route('sites.forms.update', ['site' => $site, 'cft' => $cft]),
            ['frequency' => 'weekly']
        );

        $response->assertNotFound();
    });
});

describe('DELETE /sites/{site}/form-tests/{cft} (destroy)', function () {
    it('deletes the row and redirects to the forms tab', function () {
        $site = Site::factory()->carePlan()->create();
        $cft = ContactFormTest::factory()->for($site)->create();

        $response = $this->actingAs(User::factory()->create())->delete(
            route('sites.forms.destroy', ['site' => $site, 'cft' => $cft])
        );

        $response->assertRedirect(route('sites.show', ['site' => $site, 'tab' => 'forms']));
        $response->assertSessionHas('status', 'Form-test removed.');
        expect(ContactFormTest::find($cft->id))->toBeNull();
    });

    it('404s when the form-test does not belong to the given site', function () {
        $site = Site::factory()->carePlan()->create();
        $otherSite = Site::factory()->carePlan()->create();
        $cft = ContactFormTest::factory()->for($otherSite)->create();

        $response = $this->actingAs(User::factory()->create())->delete(
            route('sites.forms.destroy', ['site' => $site, 'cft' => $cft])
        );

        $response->assertNotFound();
        expect(ContactFormTest::find($cft->id))->not->toBeNull();
    });
});

describe('POST /sites/{site}/form-tests/{cft}/test-now (testNow)', function () {
    it('runs the test via ContactFormTester and returns 200 with a success payload', function () {
        $site = Site::factory()->carePlan()->withCompanionInstalled()->create();
        $cft = ContactFormTest::factory()->for($site)->create();

        $this->mock(ContactFormTester::class, function ($mock) {
            $mock->shouldReceive('test')
                ->once()
                ->andReturn(['result' => ContactFormTester::RESULT_SUCCESS, 'message' => 'Test passed.']);
        });

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.test-now', ['site' => $site, 'cft' => $cft])
        );

        $response->assertOk();
        $response->assertJson(['result' => ContactFormTester::RESULT_SUCCESS]);
    });

    it('returns 422 when ContactFormTester reports a failure', function () {
        $site = Site::factory()->carePlan()->withCompanionInstalled()->create();
        $cft = ContactFormTest::factory()->for($site)->create();

        $this->mock(ContactFormTester::class, function ($mock) {
            $mock->shouldReceive('test')
                ->once()
                ->andReturn(['result' => ContactFormTester::RESULT_FAILED, 'message' => 'Test failed: timeout.']);
        });

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.test-now', ['site' => $site, 'cft' => $cft])
        );

        $response->assertStatus(422);
        $response->assertJson(['result' => ContactFormTester::RESULT_FAILED]);
    });

    it('404s when the form-test does not belong to the given site', function () {
        $site = Site::factory()->carePlan()->create();
        $otherSite = Site::factory()->carePlan()->create();
        $cft = ContactFormTest::factory()->for($otherSite)->create();

        $this->mock(ContactFormTester::class, function ($mock) {
            $mock->shouldNotReceive('test');
        });

        $response = $this->actingAs(User::factory()->create())->post(
            route('sites.forms.test-now', ['site' => $site, 'cft' => $cft])
        );

        $response->assertNotFound();
    });
});
