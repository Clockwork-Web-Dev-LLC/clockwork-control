<?php

use App\Services\Uptime\UptimeProber;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

describe('UptimeProber maintenance mode detection', function () {
    beforeEach(function () {
        SsrfGuard::fake(['maint.example' => ['93.184.216.34']]);
    });

    it('identifies 503 with Retry-After header as maintenance mode', function () {
        Http::fake([
            'https://maint.example/' => Http::response('Service Temporarily Unavailable', 503, [
                'Retry-After' => '600',
            ]),
        ]);

        $result = (new UptimeProber)->probe('https://maint.example/');

        expect($result->succeeded)->toBeTrue()
            ->and($result->isMaintenance)->toBeTrue()
            ->and($result->retryAfter)->toBe('600')
            ->and($result->statusCode)->toBe(503)
            ->and($result->error)->toContain('Retry-After: 600');
    });

    it('identifies 503 with WordPress core maintenance copy as maintenance mode', function () {
        Http::fake([
            'https://maint.example/' => Http::response('<!DOCTYPE html><html><body>Briefly unavailable for scheduled maintenance. Check back in a minute.</body></html>', 503),
        ]);

        $result = (new UptimeProber)->probe('https://maint.example/');

        expect($result->succeeded)->toBeTrue()
            ->and($result->isMaintenance)->toBeTrue()
            ->and($result->statusCode)->toBe(503)
            ->and($result->error)->toContain('scheduled maintenance');
    });

    it('identifies 503 with common plugin Maintenance Mode title as maintenance mode', function () {
        Http::fake([
            'https://maint.example/' => Http::response('<!DOCTYPE html><html><head><title>Maintenance Mode</title></head><body>We will be back soon!</body></html>', 503),
        ]);

        $result = (new UptimeProber)->probe('https://maint.example/');

        expect($result->succeeded)->toBeTrue()
            ->and($result->isMaintenance)->toBeTrue()
            ->and($result->statusCode)->toBe(503);
    });

    it('treats bare 503 without Retry-After or maintenance copy as a server failure', function () {
        Http::fake([
            'https://maint.example/' => Http::response('upstream connect error or disconnect/reset before headers. reset reason: connection failure', 503),
        ]);

        $result = (new UptimeProber)->probe('https://maint.example/');

        expect($result->succeeded)->toBeFalse()
            ->and($result->isMaintenance)->toBeFalse()
            ->and($result->statusCode)->toBe(503)
            ->and($result->error)->toBe('HTTP 503');
    });
});
