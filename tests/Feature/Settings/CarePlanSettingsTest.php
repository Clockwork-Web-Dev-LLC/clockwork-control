<?php

use App\Models\Site;
use App\Models\User;
use App\Support\Settings;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

test('guests are redirected to login from care plans settings', function () {
    $this->get(route('settings.care-plans.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view care plans policy settings', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.care-plans.index'));

    $response->assertOk()
        ->assertSee('Care Plans Policy')
        ->assertSee('Platform Policy')
        ->assertSee('Enable Care Plans Tiering')
        ->assertSee('Tiered Enrollment');
});

test('operators can update care plans policy setting', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();
    $settings = app(Settings::class);

    // Disable care plans globally
    $response = $this->actingAs($user)->patch(route('settings.care-plans.update'), [
        'care_plans_enabled' => '0',
    ]);

    $response->assertRedirect(route('settings.care-plans.index'))
        ->assertSessionHas('status');

    expect($settings->get('care_plans.enabled'))->toBeFalse()
        ->and(Site::areCarePlansEnabled())->toBeFalse();

    // Re-enable care plans
    $response = $this->actingAs($user)->patch(route('settings.care-plans.update'), [
        'care_plans_enabled' => '1',
    ]);

    $response->assertRedirect(route('settings.care-plans.index'))
        ->assertSessionHas('status');

    expect($settings->get('care_plans.enabled'))->toBeTrue()
        ->and(Site::areCarePlansEnabled())->toBeTrue();
});

test('isCarePlanActive and carePlanEligible scope behave correctly in both modes', function () {
    $settings = app(Settings::class);

    $enrolled = Site::factory()->create(['care_plan_enabled' => true, 'domain' => 'enrolled.test']);
    $notEnrolled = Site::factory()->create(['care_plan_enabled' => false, 'domain' => 'notenrolled.test']);

    // Mode 1: Care Plans Enabled (Default)
    $settings->put('care_plans.enabled', true);
    expect(Site::areCarePlansEnabled())->toBeTrue()
        ->and($enrolled->isCarePlanActive())->toBeTrue()
        ->and($notEnrolled->isCarePlanActive())->toBeFalse();

    $eligibleIds = Site::query()->carePlanEligible()->pluck('id')->all();
    expect($eligibleIds)->toContain($enrolled->id)
        ->and($eligibleIds)->not->toContain($notEnrolled->id);

    // Mode 2: Care Plans Disabled Globally
    $settings->put('care_plans.enabled', false);
    expect(Site::areCarePlansEnabled())->toBeFalse()
        ->and($enrolled->isCarePlanActive())->toBeTrue()
        ->and($notEnrolled->isCarePlanActive())->toBeTrue();

    $eligibleIds = Site::query()->carePlanEligible()->pluck('id')->all();
    expect($eligibleIds)->toContain($enrolled->id)
        ->and($eligibleIds)->toContain($notEnrolled->id);
});

test('care plan badges and warnings are suppressed in UI when care plans are disabled', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();
    $site = Site::factory()->create(['care_plan_enabled' => false]);
    $settings = app(Settings::class);

    // When Enabled: site overview shows "No care plan" pill
    $settings->put('care_plans.enabled', true);
    $response = $this->actingAs($user)->get(route('sites.show', $site));
    $response->assertOk()
        ->assertSee('No care plan');

    // When Disabled: site overview does NOT show "No care plan" or "Care plan"
    $settings->put('care_plans.enabled', false);
    $response = $this->actingAs($user)->get(route('sites.show', $site));
    $response->assertOk()
        ->assertDontSee('No care plan')
        ->assertDontSee('On care plan');

    // Updates tab: does NOT show warning banner when disabled
    $response = $this->actingAs($user)->get(route('sites.show', ['site' => $site, 'tab' => 'updates']));
    $response->assertOk()
        ->assertDontSee('Not on a care plan');

    // Settings tab: does NOT show Card 5 Care Plan when disabled (shows Nightly auto-updates instead)
    $response = $this->actingAs($user)->get(route('sites.show', ['site' => $site, 'tab' => 'settings']));
    $response->assertOk()
        ->assertDontSee('Care Plan Contract')
        ->assertDontSee('Mark on care plan')
        ->assertSee('Nightly auto-updates');
});
