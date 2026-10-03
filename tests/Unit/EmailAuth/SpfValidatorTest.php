<?php

use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Modules\EmailAuth\Services\SpfValidator;
use Tests\TestCase;

uses(TestCase::class);

test('valid SPF record passes validation', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn(['v=spf1 include:_spf.google.com ~all']);

    $resolver->shouldReceive('query')
        ->with('_spf.google.com', 'TXT')
        ->andReturn([
            'status' => DnsTxtResolver::STATUS_OK,
            'records' => ['v=spf1 ip4:192.0.2.0/24 ~all'],
        ]);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('pass');
    expect($result['lookup_count'])->toBe(1);
    expect($result['findings'])->toBeEmpty();
});

test('missing SPF record produces fail finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn([]);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect($result['findings'])->toHaveCount(1);
    expect($result['findings'][0]['code'])->toBe('spf_missing');
});

test('duplicate SPF records produce fail finding per RFC 7208', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn([
            'v=spf1 include:_spf.google.com ~all',
            'v=spf1 include:mail.zendesk.com ~all',
        ]);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect($result['findings'][0]['code'])->toBe('spf_duplicate');
});

test('SPF exceeding 10 DNS lookups produces fail finding', function () {
    // 11 mechanisms causing DNS lookups (e.g. 11 includes)
    $includes = [];
    for ($i = 1; $i <= 11; $i++) {
        $includes[] = "include:service{$i}.com";
    }
    $spfRecord = 'v=spf1 '.implode(' ', $includes).' ~all';

    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn([$spfRecord]);

    for ($i = 1; $i <= 11; $i++) {
        $resolver->shouldReceive('query')
            ->with("service{$i}.com", 'TXT')
            ->andReturn([
                'status' => DnsTxtResolver::STATUS_OK,
                'records' => ['v=spf1 ip4:198.51.100.0/24 ~all'],
            ]);
    }

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect($result['lookup_count'])->toBe(11);
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_too_many_lookups');
});

test('SPF include circular loop is detected and fails', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn(['v=spf1 include:loop-a.com ~all']);

    $resolver->shouldReceive('query')
        ->with('loop-a.com', 'TXT')
        ->andReturn([
            'status' => DnsTxtResolver::STATUS_OK,
            'records' => ['v=spf1 include:loop-b.com ~all'],
        ]);

    $resolver->shouldReceive('query')
        ->with('loop-b.com', 'TXT')
        ->andReturn([
            'status' => DnsTxtResolver::STATUS_OK,
            'records' => ['v=spf1 include:loop-a.com ~all'], // Loop back to A
        ]);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_include_loop');
});

test('SPF with more than 2 void lookups produces warning finding', function () {
    $spfRecord = 'v=spf1 include:void1.com include:void2.com include:void3.com ~all';

    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn([$spfRecord]);

    $resolver->shouldReceive('query')
        ->with('void1.com', 'TXT')
        ->andReturn(['status' => DnsTxtResolver::STATUS_NXDOMAIN, 'records' => []]);

    $resolver->shouldReceive('query')
        ->with('void2.com', 'TXT')
        ->andReturn(['status' => DnsTxtResolver::STATUS_NO_DATA, 'records' => []]);

    $resolver->shouldReceive('query')
        ->with('void3.com', 'TXT')
        ->andReturn(['status' => DnsTxtResolver::STATUS_NXDOMAIN, 'records' => []]);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_too_many_void_lookups');
});

test('permissive +all qualifier fails validation', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn(['v=spf1 ip4:192.0.2.1 +all']);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('fail');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_permissive_all');
});

test('neutral ?all qualifier generates warning finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn(['v=spf1 ip4:192.0.2.1 ?all']);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_neutral_all');
});

test('deprecated ptr mechanism generates warning finding', function () {
    $resolver = Mockery::mock(DnsTxtResolver::class);
    $resolver->shouldReceive('resolveTxt')
        ->with('example.com')
        ->andReturn(['v=spf1 ptr:mail.example.com -all']);

    $validator = new SpfValidator($resolver);
    $result = $validator->validate('example.com');

    expect($result['status'])->toBe('warn');
    expect(collect($result['findings'])->pluck('code')->all())->toContain('spf_ptr_deprecated');
});
