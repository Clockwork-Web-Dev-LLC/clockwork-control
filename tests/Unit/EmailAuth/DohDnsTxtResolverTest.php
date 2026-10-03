<?php

use Illuminate\Support\Facades\Http;
use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Services\DohDnsTxtResolver;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->resolver = new DohDnsTxtResolver;
});

test('resolves simple txt record and strips enclosing quotes via Cloudflare DoH', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['name' => 'example.com', 'type' => 16, 'data' => '"v=spf1 include:_spf.google.com ~all"'],
            ],
        ], 200),
    ]);

    $records = $this->resolver->resolveTxt('example.com');

    expect($records)->toHaveCount(1);
    expect($records[0])->toBe('v=spf1 include:_spf.google.com ~all');
});

test('concatenates split multi-chunk txt records properly', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['name' => 'example.com', 'type' => 16, 'data' => '"v=spf1 " "include:_spf.google.com " "~all"'],
            ],
        ], 200),
    ]);

    $records = $this->resolver->resolveTxt('example.com');

    expect($records)->toHaveCount(1);
    expect($records[0])->toBe('v=spf1 include:_spf.google.com ~all');
});

test('handles NXDOMAIN rcode 3 correctly without failing as an error', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 3, // NXDOMAIN
            'Answer' => [],
        ], 200),
    ]);

    $result = $this->resolver->query('nonexistent-domain.xyz', 'TXT');

    expect($result['status'])->toBe(DnsTxtResolver::STATUS_NXDOMAIN);
    expect($result['records'])->toBeEmpty();
});

test('handles NO_DATA rcode 0 with empty Answer correctly', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [],
        ], 200),
    ]);

    $result = $this->resolver->query('example.com', 'TXT');

    expect($result['status'])->toBe(DnsTxtResolver::STATUS_NO_DATA);
    expect($result['records'])->toBeEmpty();
});

test('falls back to Google DoH when Cloudflare fails', function () {
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response('Service Unavailable', 503),
        'https://dns.google/*' => Http::response([
            'Status' => 0,
            'Answer' => [
                ['name' => 'example.com', 'type' => 16, 'data' => '"v=DMARC1; p=reject;"'],
            ],
        ], 200),
    ]);

    $records = $this->resolver->resolveTxt('example.com');

    expect($records)->toHaveCount(1);
    expect($records[0])->toBe('v=DMARC1; p=reject;');
});
