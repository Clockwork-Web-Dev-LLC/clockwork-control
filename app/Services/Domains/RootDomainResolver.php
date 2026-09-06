<?php

namespace App\Services\Domains;

use Pdp\Rules;
use Throwable;

class RootDomainResolver
{
    private static ?Rules $rules = null;

    /**
     * Resolve a host or FQDN to its registrable root domain using the Public Suffix List.
     * For example: 'sub.example.co.uk' -> 'example.co.uk', 'www.domain.com' -> 'domain.com'.
     */
    public static function resolve(string $domain): string
    {
        $clean = strtolower(trim($domain));
        $clean = preg_replace('#^https?://#i', '', $clean);
        $clean = explode(':', $clean)[0];
        $clean = explode('/', $clean)[0];
        $clean = explode('?', $clean)[0];
        $clean = explode('#', $clean)[0];
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        try {
            $rules = self::getRules();
            $resolved = $rules->resolve($clean);
            $regDomain = $resolved->registrableDomain()->toString();
            if ($regDomain !== '') {
                return $regDomain;
            }
        } catch (Throwable) {
            // Fall through to raw domain if unresolvable
        }

        return $clean;
    }

    public static function getSuffix(string $domain): ?string
    {
        $clean = self::resolve($domain);
        if ($clean === '') {
            return null;
        }

        try {
            $rules = self::getRules();
            $resolved = $rules->resolve($clean);
            $suffix = $resolved->suffix()->toString();

            return $suffix !== '' ? $suffix : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function setRules(?Rules $rules): void
    {
        self::$rules = $rules;
    }

    private static function getRules(): Rules
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $path = resource_path('data/public-suffix-list.dat');
        if (file_exists($path)) {
            self::$rules = Rules::fromPath($path);
        } else {
            self::$rules = Rules::fromString("com\norg\nnet\nedo\ngov\nco.uk\ncom.au\n");
        }

        return self::$rules;
    }
}
