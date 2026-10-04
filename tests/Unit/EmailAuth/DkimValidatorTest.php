<?php

use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Services\DkimValidator;
use Tests\TestCase;

uses(TestCase::class);

test('probing detects standard known DKIM selectors', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);

    // Mock responses for probed selectors
    $resolver->shouldReceive('resolveTxt')
        ->andReturnUsing(function ($host) {
            if ($host === 'google._domainkey.example.com') {
                return ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ...'];
            }

            return [];
        });

    $validator = new DkimValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('pass');
    expect($result['selectors_found'])->toContain('google');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dkim_found');
});

test('probing detects configured custom selector', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);

    $resolver->shouldReceive('resolveTxt')
        ->andReturnUsing(function ($host) {
            if ($host === 'customsel._domainkey.example.com') {
                return ['v=DKIM1; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A...'];
            }

            return [];
        });

    $validator = new DkimValidator($resolver);
    $result = $validator->validate('example.com', ['customsel']);

    expect($result['status'])->toBe('pass');
    expect($result['selectors_found'])->toContain('customsel');
});

test('when no known selectors found status is warning and never fail', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->andReturn([]);

    $validator = new DkimValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect($result['selectors_found'])->toBeEmpty();
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dkim_none_found');
});
