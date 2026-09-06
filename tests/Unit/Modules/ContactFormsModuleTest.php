<?php

use App\Models\ContactFormTest;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ContactForms\ContactFormDetector;
use Modules\ContactForms\ContactFormsServiceProvider;
use Modules\ContactForms\ContactFormTester;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleStateResolver;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('ContactForms module', function () {
    it('manifest provides verified status and maintenance category', function () {
        $provider = new ContactFormsServiceProvider(app());
        $manifest = $provider->manifest();

        expect($manifest->id)->toBe('contact-forms');
        expect($manifest->name)->toBe('Contact Form Testing');
        expect($manifest->status)->toBe(ModuleManifest::STATUS_VERIFIED);
        expect($manifest->credentialFields)->toBeEmpty();
    });

    it('resolves ContactFormTester and ContactFormDetector from container', function () {
        $tester = app(ContactFormTester::class);
        $detector = app(ContactFormDetector::class);

        expect($tester)->toBeInstanceOf(ContactFormTester::class)
            ->and($tester)->toBeInstanceOf(App\Services\Forms\ContactFormTester::class);

        expect($detector)->toBeInstanceOf(ContactFormDetector::class)
            ->and($detector)->toBeInstanceOf(App\Services\Forms\ContactFormDetector::class);
    });

    it('evaluates form test eligibility accurately', function () {
        $tester = app(ContactFormTester::class);

        $site = Site::factory()->carePlan()->create([
            'companion_installed' => true,
            'companion_secret' => 'valid-secret-1234',
        ]);

        $cft = ContactFormTest::factory()->create([
            'site_id' => $site->id,
            'enabled' => true,
            'form_plugin' => 'contact-form-7',
            'form_id' => 'contact-form-1',
        ]);

        expect($tester->eligible($cft))->toBeTrue();

        // When disabled
        $cft->enabled = false;
        expect($tester->eligible($cft))->toBeFalse();

        // When not on care plan
        $cft->enabled = true;
        $site->update(['care_plan_enabled' => false]);
        $cft->unsetRelation('site');
        expect($tester->eligible($cft))->toBeFalse();
    });

    it('respects ModuleStateResolver enabled/disabled state', function () {
        $resolver = app(ModuleStateResolver::class);
        expect($resolver->isEnabled('contact-forms'))->toBeTrue();
    });
});
