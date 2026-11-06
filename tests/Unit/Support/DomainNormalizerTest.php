<?php

use App\Support\DomainNormalizer;

describe('DomainNormalizer::normalize()', function () {
    it('lowercases and strips scheme, www., path, query, port and trailing dot', function (string $input, string $expected) {
        expect(DomainNormalizer::normalize($input))->toBe($expected);
    })->with([
        'bare' => ['example.com', 'example.com'],
        'uppercase' => ['Example.COM', 'example.com'],
        'leading www' => ['www.example.com', 'example.com'],
        'scheme + www + path' => [' https://www.Example.com/about/ ', 'example.com'],
        'http scheme' => ['http://example.com', 'example.com'],
        'protocol-relative' => ['//www.example.com/', 'example.com'],
        'port' => ['example.com:443', 'example.com'],
        'query and fragment' => ['example.com?x=1#top', 'example.com'],
        'trailing dot' => ['sub.example.org.', 'sub.example.org'],
        'www + trailing dot' => ['www.example.org.', 'example.org'],
    ]);

    it('keeps subdomains other than a single leading www.', function () {
        expect(DomainNormalizer::normalize('shop.example.com'))->toBe('shop.example.com')
            ->and(DomainNormalizer::normalize('www.shop.example.com'))->toBe('shop.example.com')
            ->and(DomainNormalizer::normalize('wwwexample.com'))->toBe('wwwexample.com');
    });

    it('does not reduce "www.<tld>" to a bare TLD', function () {
        expect(DomainNormalizer::normalize('www.com'))->toBe('www.com');
    });

    it('returns an empty string for blank input', function () {
        expect(DomainNormalizer::normalize('   '))->toBe('');
    });
});

describe('DomainNormalizer::tld()', function () {
    it('returns the alphabetic top-level label', function () {
        expect(DomainNormalizer::tld('www.example.tv'))->toBe('tv')
            ->and(DomainNormalizer::tld('https://Example.LAW/'))->toBe('law')
            ->and(DomainNormalizer::tld('shop.example.co.uk'))->toBe('uk');
    });

    it('returns null when there is no usable TLD', function () {
        expect(DomainNormalizer::tld('localhost'))->toBeNull()
            ->and(DomainNormalizer::tld('192.0.2.10'))->toBeNull()
            ->and(DomainNormalizer::tld(''))->toBeNull();
    });
});
