<?php

namespace Modules\Seo\Pages;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads the on-page SEO basics out of a page's HTML: title, meta
 * description, headings, canonical, robots, schema types and word count.
 */
final class PageParser
{
    /**
     * @return array{title: ?string, meta_description: ?string, h1s: list<string>, h2s: list<string>, canonical: ?string, meta_robots: ?string, schema_types: list<string>, word_count: int}
     */
    public static function parse(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The XML prefix makes DOMDocument read the bytes as UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $lower = fn (string $attribute) => "translate(@{$attribute}, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";

        $first = function (string $query) use ($xpath): ?string {
            $node = $xpath->query($query)?->item(0);

            return $node === null ? null : self::clean($node instanceof DOMElement && $node->hasAttribute('content') ? $node->getAttribute('content') : ($node instanceof DOMElement && $node->hasAttribute('href') ? $node->getAttribute('href') : $node->textContent));
        };

        $list = function (string $query, int $max) use ($xpath): array {
            $out = [];
            foreach ($xpath->query($query) ?: [] as $node) {
                $text = self::clean($node->textContent);
                if ($text !== null) {
                    $out[] = mb_substr($text, 0, 300);
                }
                if (count($out) >= $max) {
                    break;
                }
            }

            return $out;
        };

        // Schema types are read before scripts are stripped for the word count.
        $types = [];
        foreach ($xpath->query("//script[{$lower('type')}='application/ld+json']") ?: [] as $script) {
            $decoded = json_decode(trim($script->textContent), true);
            if (is_array($decoded)) {
                self::collectTypes($decoded, $types);
            }
        }

        $result = [
            'title' => $first('//head/title') ?? $first('//title'),
            'meta_description' => $first("//meta[{$lower('name')}='description']"),
            'h1s' => $list('//h1', 10),
            'h2s' => $list('//h2', 30),
            'canonical' => $first("//link[{$lower('rel')}='canonical']"),
            'meta_robots' => $first("//meta[{$lower('name')}='robots']"),
            'schema_types' => array_slice(array_keys($types), 0, 40),
            'word_count' => 0,
        ];

        // Words a visitor reads: the body without code, menus and the footer.
        $body = $xpath->query('//body')?->item(0);
        if ($body instanceof DOMNode) {
            foreach (iterator_to_array($xpath->query('.//script | .//style | .//noscript | .//template | .//svg | .//nav | .//footer', $body) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
            // Joined piece by piece: textContent runs "</h1><h2>" together
            // and would count two headings as one word.
            $pieces = [];
            foreach ($xpath->query('.//text()', $body) ?: [] as $node) {
                $pieces[] = $node->nodeValue;
            }
            $text = self::clean(implode(' ', $pieces)) ?? '';
            $result['word_count'] = $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        return $result;
    }

    /** Does this look like a firewall's "checking your browser" page rather than the real one? */
    public static function looksLikeChallenge(string $html): bool
    {
        $head = mb_strtolower(mb_substr($html, 0, 6000));

        foreach (['just a moment...', 'attention required! | cloudflare', 'cf-browser-verification', 'challenge-platform', 'checking your browser before accessing', 'sgcaptcha', '_incapsula_resource', 'access denied | '] as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, true>  $types
     */
    private static function collectTypes(array $node, array &$types, int $level = 0): void
    {
        if ($level > 8) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === '@type') {
                foreach ((array) $value as $type) {
                    if (is_string($type) && $type !== '' && mb_strlen($type) <= 80) {
                        $types[$type] = true;
                    }
                }
            } elseif (is_array($value)) {
                self::collectTypes($value, $types, $level + 1);
            }
        }
    }
}
