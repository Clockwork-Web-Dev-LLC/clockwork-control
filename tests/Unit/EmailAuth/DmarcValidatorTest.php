<?php

use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Services\DmarcValidator;
use Tests\TestCase;

uses(TestCase::class);

test('valid strict DMARC record passes validation', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=reject; rua=mailto:dmarc-reports@example.com; pct=100; sp=reject']);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('pass');
    expect($result['policy'])->toBe('reject');
    expect($result['findings'])->toBeEmpty();
});

test('missing DMARC record produces fail finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn([]);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dmarc_missing');
});

test('duplicate DMARC records produce fail finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn([
            'v=DMARC1; p=reject;',
            'v=DMARC1; p=quarantine;',
        ]);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dmarc_duplicate');
});

test('DMARC policy p=none produces warning finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=none; rua=mailto:reports@example.com;']);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect($result['policy'])->toBe('none');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dmarc_policy_none');
});

test('DMARC record missing rua produces warning finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=reject;']);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dmarc_missing_rua');
});

test('DMARC record with pct < 100 produces partial rollout warning finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('_dmarc.example.com')
        ->andReturn(['v=DMARC1; p=quarantine; rua=mailto:reports@example.com; pct=50']);

    $validator = new DmarcValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('dmarc_partial_pct');
});
