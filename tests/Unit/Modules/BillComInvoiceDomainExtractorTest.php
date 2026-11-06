<?php

use Modules\BillCom\InvoiceDomainExtractor;

describe('InvoiceDomainExtractor', function () {
    it('extracts the domain from the observed description shapes', function (string $description, string $expected) {
        expect(InvoiceDomainExtractor::extract($description))->toBe($expected);
    })->with([
        'plain' => ['Website hosting for example.com', 'example.com'],
        'scheme + www' => ['http://www.example.com - - Plugin updates, backups, security scans, etc.', 'example.com'],
        'https + www + path' => ['Care plan: https://www.example.org/ (monthly)', 'example.org'],
        'bare www' => ['Monthly maintenance — www.example.net', 'example.net'],
        'uppercase' => ['Hosting for WWW.EXAMPLE.COM', 'example.com'],
    ]);

    it('normalizes a www. prefix so it matches a www-stored or bare site alike', function () {
        expect(InvoiceDomainExtractor::extractAll('http://www.example.com - WordPress Care Plan'))
            ->toBe(['example.com']);
    });

    it('returns every distinct domain in order, deduped after normalization', function () {
        expect(InvoiceDomainExtractor::extractAll('HOSTING WEBSITES: example.com, www.example.org, http://www.example.com'))
            ->toBe(['example.com', 'example.org']);
    });

    it('returns nothing for a description without a domain', function () {
        expect(InvoiceDomainExtractor::extractAll('WordPress Care Plan - 12 months of backups'))->toBe([])
            ->and(InvoiceDomainExtractor::extract(''))->toBeNull();
    });

    it('ignores TLDs outside the static allow-list by default', function () {
        expect(InvoiceDomainExtractor::extractAll('Care plan for example.tv'))->toBe([])
            ->and(InvoiceDomainExtractor::extractAll('Care plan for example.law'))->toBe([]);
    });

    it('recognises a .tv domain when tv is passed as an extra TLD', function () {
        expect(InvoiceDomainExtractor::extractAll('http://www.example.tv - WordPress Care Plan', ['tv']))
            ->toBe(['example.tv']);
    });

    it('recognises a .law domain when law is passed as an extra TLD', function () {
        expect(InvoiceDomainExtractor::extract('Monthly maintenance — Example.LAW', ['law', 'tv']))
            ->toBe('example.law');
    });

    it('keeps rejecting dotted words on TLDs nobody passed in', function () {
        expect(InvoiceDomainExtractor::extractAll('See readme.txt and index.php for example.tv', ['tv']))
            ->toBe(['example.tv']);
    });

    it('ignores malformed extra TLDs instead of widening the pattern', function () {
        expect(InvoiceDomainExtractor::extractAll('Hosting for example.tv and example.x', ['.TV', 'x', '[a-z]+', 'c|m', '']))
            ->toBe(['example.tv']);
    });

    it('still tells .co and .com apart for the same name', function () {
        expect(InvoiceDomainExtractor::extractAll('Hosting for example.co and example.com'))
            ->toBe(['example.co', 'example.com']);
    });
});
