<?php

namespace App\Services\Uptime;

/**
 * Inspects an already-fetched homepage body for white-screens and PHP/WP
 * fatals that still return HTTP 200. Does not issue HTTP itself.
 */
class HomepageBodyCheck
{
    public const MIN_VISIBLE_CHARS = 50;

    public const BODY_SAMPLE_BYTES = 65536;

    /**
     * @return list<string>
     */
    public const FAILURE_SIGNATURES = [
        'There has been a critical error',
        'Fatal error:',
        'Parse error:',
        'Allowed memory size',
        'Maximum execution time',
    ];

    /**
     * @return string|null Failure reason, or null when the body looks fine.
     */
    public function evaluate(?string $body, ?string $requireKeyword = null): ?string
    {
        $raw = (string) $body;
        $sample = mb_substr($raw, 0, self::BODY_SAMPLE_BYTES);
        $visible = trim(html_entity_decode(strip_tags($sample), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($visible === '' || mb_strlen($visible) < self::MIN_VISIBLE_CHARS) {
            return 'Homepage body too short (possible white screen)';
        }

        foreach (self::FAILURE_SIGNATURES as $signature) {
            if (stripos($sample, $signature) !== false) {
                return 'Homepage contains error signature: '.$signature;
            }
        }

        $keyword = trim((string) $requireKeyword);
        if ($keyword !== '' && mb_stripos($raw, $keyword) === false) {
            return 'Homepage missing required keyword';
        }

        return null;
    }
}
