<?php

use App\Services\Uptime\UptimeProber;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

describe('UptimeProber SSRF guard', function () {
    it('does not fetch loopback or metadata addresses', function () {
        Http::fake();
        $result = (new UptimeProber)->probe('http://127.0.0.1/');

        expect($result->succeeded)->toBeFalse();
        expect($result->error)->toContain('private/reserved');
        Http::assertNothingSent();
    });

    it('does not fetch a host that resolves to a private IP', function () {
        Http::fake();
        SsrfGuard::fake(['evil.example' => ['169.254.169.254']]);

        $result = (new UptimeProber)->probe('https://evil.example/');

        expect($result->succeeded)->toBeFalse();
        expect($result->error)->toContain('private/reserved');
        Http::assertNothingSent();
    });

    it('still probes a public host', function () {
        SsrfGuard::fake(['up.example' => ['93.184.216.34']]);
        Http::fake([
            'https://up.example/' => Http::response(str_repeat('Welcome to this public homepage. ', 20), 200),
        ]);

        $result = (new UptimeProber)->probe('https://up.example/');

        expect($result->succeeded)->toBeTrue();
        expect($result->statusCode)->toBe(200);
    });

    it('allows fetching private hosts when allow_private_hosts is enabled', function () {
        config(['clockwork.security.allow_private_hosts' => true]);
        Http::fake([
            'http://192.168.1.100/' => Http::response(str_repeat('Welcome to this internal homepage. ', 20), 200),
        ]);

        $result = (new UptimeProber)->probe('http://192.168.1.100/');

        expect($result->succeeded)->toBeTrue();
        expect($result->statusCode)->toBe(200);
    });
});
