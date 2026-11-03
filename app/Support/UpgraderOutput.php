<?php

namespace App\Support;

/**
 * Reads and sanitises the WordPress upgrader output that Companion and
 * Renegade return with every plugin, theme and core update (`messages`).
 *
 * Redaction. Before every download the upgrader logs "Downloading update
 * from <url>…". For premium plugins that URL carries the licence key, a
 * vendor authorisation token or JWT, or a signed, expiring download link —
 * in the query string, and sometimes in the path. Everything Control keeps
 * or sends from an update result (plugin_update_jobs.messages and .error,
 * action_logs details/summary/error, failure streaks, ignores, chat alerts
 * and the Companion activity mirror) goes through redact()/redactText()
 * first:
 *   - a download URL is cut down to scheme://host/[redacted];
 *   - any other URL loses its credentials, query string and fragment, and
 *     token-like path segments are masked;
 *   - JWTs, bearer tokens and secret-named key=value pairs are masked
 *     wherever they appear.
 *
 * Classification. Companion/Renegade versions in the field report ok=true
 * even when the upgrader logged "Error: …" and nothing changed on the site.
 * errorLines() pulls out the real error — only the last "Error:" line plus
 * the line before it, which carries the detail (e.g. the file that could
 * not be copied) — never a download line. classify() maps those lines to a
 * reason code so failures can be grouped by cause.
 */
final class UpgraderOutput
{
    /** The site's files or wp-content/upgrade are not writable by PHP. A site problem, not a plugin one. */
    public const REASON_FS_PERMISSION = 'fs_permission';

    /** The vendor refused the download (licence inactive or expired, 401/403). */
    public const REASON_DOWNLOAD_UNAUTHORIZED = 'download_unauthorized';

    /** The download was not a usable zip (vendor returned an error page, truncated file). */
    public const REASON_BAD_PACKAGE = 'bad_package';

    /** WordPress had no download URL for the update (typical of an unlicensed premium plugin). */
    public const REASON_NO_PACKAGE = 'no_package';

    /** No upgrader error at all: WordPress simply no longer offered the update when the job ran. */
    public const REASON_NOT_OFFERED = 'not_offered';

    /** An upgrader error that matches none of the known causes. */
    public const REASON_UPGRADER_ERROR = 'upgrader_error';

    public const REDACTED = '[redacted]';

    private const MAX_LINE_LENGTH = 300;

    /**
     * Parameter names whose values are secrets wherever a name=value pair
     * shows up (query strings that escaped URL parsing, HTML-escaped
     * `&amp;key=` runs, error text).
     */
    private const SECRET_PARAM_NAMES = 'license|licence|license_key|licence_key|license_hash|license_id|key|api_key|apikey|site_key|token|access_token|auth|authorization|password|passwd|pwd|secret|signature|sig|purchase_code|purchase_key|code|x-amz-signature|x-amz-credential|x-amz-security-token|awsaccesskeyid|key-pair-id';

    /**
     * A URL, optionally preceded by the "Downloading … from " phrase the
     * upgrader logs before fetching a package. No /u modifier on purpose:
     * an invalid UTF-8 byte must not make preg_* bail out and hand back
     * the unredacted text.
     */
    private const URL_PATTERN = '~(Downloading\b[^\r\n]{0,80}?\bfrom\s+)?((?:https?|ftp)://[^\s<>"\'`]+)~i';

    /**
     * Recursively redact every string in a value (an update result, a
     * messages list, action_log details). Keys and non-string scalars are
     * left alone.
     */
    public static function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redactText($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::redact($item);
            }
        }

        return $value;
    }

    public static function redactText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $redacted = preg_replace_callback(self::URL_PATTERN, function (array $m): string {
            [$url, $suffix] = self::splitTrailing($m[2]);
            $isDownload = ($m[1] ?? '') !== '';

            return $m[1].($isDownload ? self::redactDownloadUrl($url) : self::redactUrl($url)).$suffix;
        }, $text);

        if ($redacted === null) {
            // Regex engine gave up (backtrack limit, bad input). Fail closed.
            $redacted = preg_replace('~(?:https?|ftp)://\S+~i', '[url '.self::REDACTED.']', $text);
            if ($redacted === null) {
                return self::REDACTED;
            }
        }

        // A JWT (or any base64url-encoded JSON object) starts with "eyJ".
        $redacted = preg_replace('/eyJ[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]*){0,2}/', self::REDACTED, $redacted) ?? self::REDACTED;
        $redacted = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 '.self::REDACTED, $redacted) ?? self::REDACTED;

        return preg_replace(
            '/(?<![A-Za-z0-9_-])('.self::SECRET_PARAM_NAMES.')=[^\s&"\'<>]+/i',
            '$1='.self::REDACTED,
            $redacted,
        ) ?? self::REDACTED;
    }

    /**
     * Strip credentials, query string and fragment from a URL and mask
     * token-like path segments. Keeps scheme, host, port and the rest of
     * the path so the URL stays useful for diagnosis.
     */
    public static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $url = preg_replace('~^([a-z][a-z0-9+.-]*://)[^/?#]*@~i', '$1', $url) ?? '';

            return preg_replace('~[?#].*$~s', '', $url) ?? self::REDACTED;
        }

        $segments = explode('/', (string) ($parts['path'] ?? ''));
        foreach ($segments as $i => $segment) {
            if ($segment !== '' && self::looksLikeSecret($segment)) {
                $segments[$i] = self::REDACTED;
            }
        }

        return self::origin($parts).implode('/', $segments);
    }

    public static function isErrorLine(string $line): bool
    {
        return preg_match('/^\s*Error:/i', $line) === 1;
    }

    public static function isDownloadLine(string $line): bool
    {
        return preg_match('/^\s*Downloading\b/i', $line) === 1;
    }

    /**
     * The upgrader's real error: the last line starting with "Error:" plus
     * the line just before it (unless that one is a download line), cleaned
     * of HTML and redacted. Empty when the output holds no "Error:" line.
     *
     * @return list<string>
     */
    public static function errorLines(mixed $messages): array
    {
        if (is_string($messages)) {
            $messages = preg_split('/\R/', $messages) ?: [];
        }
        if (! is_array($messages)) {
            return [];
        }

        $lines = [];
        foreach ($messages as $message) {
            if (! is_scalar($message)) {
                continue;
            }
            $line = self::cleanLine((string) $message);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (! self::isErrorLine($lines[$i])) {
                continue;
            }

            $out = [];
            if ($i > 0 && ! self::isDownloadLine($lines[$i - 1])) {
                $out[] = $lines[$i - 1];
            }
            $out[] = $lines[$i];

            return $out;
        }

        return [];
    }

    /**
     * Map upgrader error lines (see errorLines()) to a reason code.
     *
     * @param  list<string>  $lines
     */
    public static function classify(array $lines): string
    {
        $text = strtolower(implode("\n", $lines));

        return match (true) {
            (bool) preg_match('/could not (copy|create|remove|delete|move|write)|could not access filesystem|filesystem error|unable to locate (wordpress|needed folder)|not writable|permission denied|could not be copied|inconsistent file permissions/', $text) => self::REASON_FS_PERMISSION,
            (bool) preg_match('/unauthori[sz]ed|forbidden|not authori[sz]ed|\b40[13]\b|licen[cs]e|purchase code/', $text) => self::REASON_DOWNLOAD_UNAUTHORIZED,
            (bool) preg_match('/pclzip|incompatible archive|central dir|not a valid zip|package could not be installed|contains no files|could not extract|unable to unzip/', $text) => self::REASON_BAD_PACKAGE,
            (bool) preg_match('/valid url was not provided|package not available|no update package/', $text) => self::REASON_NO_PACKAGE,
            default => self::REASON_UPGRADER_ERROR,
        };
    }

    private static function cleanLine(string $line): string
    {
        $line = html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = self::redactText($line);
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        return mb_strimwidth($line, 0, self::MAX_LINE_LENGTH, '…');
    }

    /**
     * Download URLs are where licence keys, vendor tokens and signatures
     * live — in the query string and, for some vendors, in the path — so
     * only the origin survives.
     */
    private static function redactDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return '[url '.self::REDACTED.']';
        }

        return self::origin($parts).'/'.self::REDACTED;
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private static function origin(array $parts): string
    {
        return strtolower((string) $parts['scheme']).'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Peel trailing punctuation and the upgrader's ellipsis off a matched
     * URL, so "…/pkg.zip&#8230;" keeps its "&#8230;" after redaction.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitTrailing(string $url): array
    {
        $suffix = '';
        while (preg_match('/(&#8230;|&hellip;|\xE2\x80\xA6|\.\.\.|[.,;:!?)\]}])$/', $url, $m) === 1) {
            $suffix = $m[1].$suffix;
            $url = substr($url, 0, -strlen($m[1]));
        }

        return [$url, $suffix];
    }

    /**
     * A path segment that is (or contains) a JWT, or a long run of mixed
     * letters and digits — the shape of licence keys, hashes and download
     * tokens. Ordinary slugs and versioned zip names stay readable.
     */
    private static function looksLikeSecret(string $segment): bool
    {
        $segment = rawurldecode($segment);

        if (preg_match('/eyJ[A-Za-z0-9_-]{8,}/', $segment) === 1) {
            return true;
        }

        foreach (preg_split('/[^A-Za-z0-9_-]+/', $segment) ?: [] as $run) {
            $length = strlen($run);
            if ($length < 12) {
                continue;
            }
            $hasDigit = preg_match('/\d/', $run) === 1;
            if (! $hasDigit) {
                continue;
            }
            if ($length >= 16 && preg_match('/[A-Za-z]/', $run) === 1) {
                return true;
            }
            if (preg_match('/[a-z]/', $run) === 1 && preg_match('/[A-Z]/', $run) === 1) {
                return true;
            }
        }

        return false;
    }
}
