<?php

namespace Modules\ContactForms;

use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Modules\ContactForms\Commands\DetectContactForms;
use Modules\ContactForms\Commands\SyncCompanionFormSubscriptions;
use Modules\ContactForms\Commands\TestContactForms;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;

class ContactFormsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(\App\Services\Forms\ContactFormTester::class, function ($app) {
            return new ContactFormTester(
                mattermost: $app->make(ChatNotifier::class),
            );
        });
        $this->app->bind(ContactFormTester::class, function ($app) {
            return $app->make(\App\Services\Forms\ContactFormTester::class);
        });

        $this->app->singleton(\App\Services\Forms\ContactFormDetector::class, function () {
            return new ContactFormDetector;
        });
        $this->app->bind(ContactFormDetector::class, function ($app) {
            return $app->make(\App\Services\Forms\ContactFormDetector::class);
        });

        $this->commands([
            DetectContactForms::class,
            SyncCompanionFormSubscriptions::class,
            TestContactForms::class,
        ]);

        if (! class_exists(\App\Services\Forms\ContactFormTester::class, false)) {
            class_alias(ContactFormTester::class, \App\Services\Forms\ContactFormTester::class);
        }

        if (! class_exists(\App\Services\Forms\ContactFormDetector::class, false)) {
            class_alias(ContactFormDetector::class, \App\Services\Forms\ContactFormDetector::class);
        }

        if (! class_exists(\App\Services\Forms\MonthlyStats::class, false)) {
            class_alias(MonthlyStats::class, \App\Services\Forms\MonthlyStats::class);
        }

        if (! class_exists(\App\Http\Controllers\FormsController::class, false)) {
            class_alias(FormsController::class, \App\Http\Controllers\FormsController::class);
        }

        if (! class_exists(\App\Console\Commands\TestContactForms::class, false)) {
            class_alias(TestContactForms::class, \App\Console\Commands\TestContactForms::class);
        }

        if (! class_exists(\App\Console\Commands\DetectContactForms::class, false)) {
            class_alias(DetectContactForms::class, \App\Console\Commands\DetectContactForms::class);
        }

        if (! class_exists(\App\Console\Commands\SyncCompanionFormSubscriptions::class, false)) {
            class_alias(SyncCompanionFormSubscriptions::class, \App\Console\Commands\SyncCompanionFormSubscriptions::class);
        }
    }

    public function boot(): void
    {
        if ($this->enabled()) {
            $this->registerRoutes();
        }
    }

    protected function registerRoutes(): void
    {
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/forms', [FormsController::class, 'index'])->name('forms.index');
            Route::post('/sites/{site}/form-tests', [FormsController::class, 'store'])->name('sites.forms.store');
            Route::patch('/sites/{site}/form-tests/{cft}', [FormsController::class, 'update'])->name('sites.forms.update');
            Route::delete('/sites/{site}/form-tests/{cft}', [FormsController::class, 'destroy'])->name('sites.forms.destroy');
            Route::post('/sites/{site}/form-tests/{cft}/test-now', [FormsController::class, 'testNow'])->name('sites.forms.test-now');
        });
    }

    public function scheduledTasks(Schedule $schedule): void
    {
        if ($this->enabled()) {
            $schedule->command('clockwork:detect-contact-forms')
                ->dailyAt('04:50')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('clockwork:sync-companion-form-subscriptions')
                ->dailyAt('04:55')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('clockwork:test-contact-forms')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'contact-forms',
            name: 'Contact Form Testing',
            description: 'Automated synthetic end-to-end testing and delivery verification for WordPress contact forms via Clockwork Companion.',
            credentialFields: [],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Verified synthetic form testing and email round-trip delivery verification.',
        );
    }
}
