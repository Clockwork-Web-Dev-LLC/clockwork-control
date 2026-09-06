<?php

use App\Models\Site;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Site::sslState() / sslStateLabel()
|--------------------------------------------------------------------------
|
| sslState() is a pure computation over cert_source/cert_expires_at/
| cert_renews_at, so these use unsaved `new Site([...])` instances rather
| than ->create() — no DB needed. It lives under tests/Feature (not
| tests/Unit) because sslState() reads config('clockwork.monitoring.
| ssl_renewal_grace_hours'), and tests/Pest.php only binds Laravel's
| TestCase (which boots the app container config() needs) to the Feature
| suite — plain PHPUnit\Framework\TestCase under Unit has no app bound and
| config() throws "Target class [config] does not exist."
*/

describe('Site::sslState()', function () {
    it('returns NONE for cert_source none regardless of cert_expires_at', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_NONE,
            'cert_expires_at' => Carbon::now()->addDays(90),
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_NONE);
    });

    it('returns NONE for cert_source redirect_only regardless of cert_expires_at', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_REDIRECT_ONLY,
            'cert_expires_at' => Carbon::now()->addDays(90),
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_NONE);
    });

    it('returns NONE for cert_source none even when cert_expires_at is in the past', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_NONE,
            'cert_expires_at' => Carbon::now()->subDays(10),
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_NONE);
    });

    it('returns NONE when cert_expires_at is missing entirely, even for a normally-monitored cert_source', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => null,
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_NONE);
    });

    it('returns RED when cert_expires_at is in the past, for spinupwp_le', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
            'cert_expires_at' => Carbon::now()->subDay(),
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_RED);
    });

    it('returns RED when cert_expires_at is in the past, for external certs', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => Carbon::now()->subDay(),
        ]);

        expect($site->sslState())->toBe(Site::SSL_STATE_RED);
    });

    it('returns RED when cert_expires_at is exactly now (boundary is inclusive)', function () {
        $now = Carbon::now();
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => $now,
        ]);

        Carbon::setTestNow($now);
        expect($site->sslState())->toBe(Site::SSL_STATE_RED);
        Carbon::setTestNow();
    });

    describe('spinupwp_le renewal grace period', function () {
        it('stays GREEN while cert_renews_at is past but still inside the default 48h grace window', function () {
            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
                'cert_expires_at' => Carbon::now()->addDays(30),
                'cert_renews_at' => Carbon::now()->subHours(10),
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_GREEN);
        });

        it('flips to YELLOW once cert_renews_at plus the default 48h grace period has passed', function () {
            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
                'cert_expires_at' => Carbon::now()->addDays(30),
                'cert_renews_at' => Carbon::now()->subHours(49),
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_YELLOW);
        });

        it('honors a custom ssl_renewal_grace_hours config value', function () {
            config(['clockwork.monitoring.ssl_renewal_grace_hours' => 6]);

            $stillInGrace = new Site([
                'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
                'cert_expires_at' => Carbon::now()->addDays(30),
                'cert_renews_at' => Carbon::now()->subHours(5),
            ]);
            $pastGrace = new Site([
                'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
                'cert_expires_at' => Carbon::now()->addDays(30),
                'cert_renews_at' => Carbon::now()->subHours(7),
            ]);

            expect($stillInGrace->sslState())->toBe(Site::SSL_STATE_GREEN)
                ->and($pastGrace->sslState())->toBe(Site::SSL_STATE_YELLOW);
        });

        it('returns GREEN when cert_renews_at is null and the cert has not expired', function () {
            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_SPINUPWP_LE,
                'cert_expires_at' => Carbon::now()->addDays(30),
                'cert_renews_at' => null,
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_GREEN);
        });
    });

    describe('external certs', function () {
        it('returns YELLOW when within 30 days of expiry', function () {
            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_EXTERNAL,
                'cert_expires_at' => Carbon::now()->addDays(15),
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_YELLOW);
        });

        it('returns YELLOW at exactly the 30-day boundary', function () {
            $now = Carbon::now();
            Carbon::setTestNow($now);

            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_EXTERNAL,
                'cert_expires_at' => $now->copy()->addDays(30),
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_YELLOW);
            Carbon::setTestNow();
        });

        it('returns GREEN when more than 30 days from expiry', function () {
            $site = new Site([
                'cert_source' => Site::CERT_SOURCE_EXTERNAL,
                'cert_expires_at' => Carbon::now()->addDays(45),
            ]);

            expect($site->sslState())->toBe(Site::SSL_STATE_GREEN);
        });
    });
});

describe('Site::sslStateLabel()', function () {
    it('maps GREEN to "SSL ok"', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => Carbon::now()->addDays(45),
        ]);

        expect($site->sslStateLabel())->toBe('SSL ok');
    });

    it('maps YELLOW to "SSL renewal"', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => Carbon::now()->addDays(10),
        ]);

        expect($site->sslStateLabel())->toBe('SSL renewal');
    });

    it('maps RED to "SSL expired"', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
            'cert_expires_at' => Carbon::now()->subDay(),
        ]);

        expect($site->sslStateLabel())->toBe('SSL expired');
    });

    it('maps NONE to "No SSL"', function () {
        $site = new Site([
            'cert_source' => Site::CERT_SOURCE_NONE,
        ]);

        expect($site->sslStateLabel())->toBe('No SSL');
    });
});
