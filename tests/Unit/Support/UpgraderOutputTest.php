<?php

namespace Tests\Unit\Support;

use App\Support\UpgraderOutput;

/*
|--------------------------------------------------------------------------
| UpgraderOutput — redaction + classification of WordPress upgrader output
|--------------------------------------------------------------------------
|
| Every licence key, token, signature and URL below is invented; hosts are
| reserved example domains. The shapes mirror what Companion returns in
| `messages`: the upgrader logs "Downloading update from <url>&#8230;" with
| the vendor's (often licensed or signed) package URL, then the outcome, and
| on failure a detail line followed by "Error: …".
*/

const FAKE_LICENSE = 'FAKE-LICENSE-0000-1111-2222';
const FAKE_JWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJsaWNlbnNlIjoiZmFrZSJ9.ZmFrZXNpZ25hdHVyZQ';
const FAKE_AWS_KEY = 'AKIAFAKEFAKEFAKE0000';
const FAKE_SIGNATURE = 'ZmFrZXNpZ25hdHVyZTEyMw%3D%3D';

function fakeLicensedDownloadLine(): string
{
    return 'Downloading update from https://updates.example.com/?action=package_download&amp;license='.FAKE_LICENSE.'&amp;domain=client.example.org&amp;version=2.0.0&#8230;';
}

function fakeJwtPathDownloadLine(): string
{
    return 'Downloading update from https://vendor.example.net/api/v2/plugins/download/'.FAKE_JWT.'/sample_pro&#8230;';
}

function fakeSignedDownloadLine(): string
{
    return 'Downloading update from https://downloads.example.org/packages/sample-plugin/sample-plugin_2.0.0.zip?AWSAccessKeyId='.FAKE_AWS_KEY.'&amp;Expires=1790000000&amp;Signature='.FAKE_SIGNATURE.'&#8230;';
}

describe('UpgraderOutput::redactText', function () {
    it('cuts a licensed download URL down to its origin and keeps the ellipsis', function () {
        $out = UpgraderOutput::redactText(fakeLicensedDownloadLine());

        expect($out)->toBe('Downloading update from https://updates.example.com/[redacted]&#8230;')
            ->not->toContain(FAKE_LICENSE)
            ->not->toContain('client.example.org');
    });

    it('drops a JWT carried in the download path', function () {
        $out = UpgraderOutput::redactText(fakeJwtPathDownloadLine());

        expect($out)->toBe('Downloading update from https://vendor.example.net/[redacted]&#8230;')
            ->not->toContain('eyJ');
    });

    it('drops the signature and access key of a signed download URL', function () {
        $out = UpgraderOutput::redactText(fakeSignedDownloadLine());

        expect($out)->not->toContain(FAKE_AWS_KEY)
            ->not->toContain('ZmFrZXNpZ25hdHVyZTEyMw')
            ->toContain('https://downloads.example.org/[redacted]');
    });

    it('handles a download URL after the ellipsis entity has been decoded', function () {
        $decoded = html_entity_decode(fakeLicensedDownloadLine(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect(UpgraderOutput::redactText($decoded))
            ->toBe('Downloading update from https://updates.example.com/[redacted]…');
    });

    it('leaves a download line without a URL untouched', function () {
        expect(UpgraderOutput::redactText('Downloading update from 1&#8230;'))
            ->toBe('Downloading update from 1&#8230;');
    });

    it('keeps the path of other URLs but strips credentials, query string and fragment', function () {
        $out = UpgraderOutput::redactText('See https://admin:hunter2@example.com/wp-admin/update-core.php?_wpnonce=abc123&key=SECRETVALUE#top for details.');

        expect($out)->toBe('See https://example.com/wp-admin/update-core.php for details.');
    });

    it('masks token-like path segments in other URLs but keeps ordinary slugs', function () {
        $out = UpgraderOutput::redactText('Fetched https://example.com/dl/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b/plugin-name/readme.txt');

        expect($out)->toBe('Fetched https://example.com/dl/[redacted]/plugin-name/readme.txt');
    });

    it('masks secret-named key=value pairs, JWTs and bearer tokens outside URLs', function () {
        $out = UpgraderOutput::redactText('retrying with license_key=ABCD1234EFGH&amp;purchase_code=0000-1111 token '.FAKE_JWT.' header Bearer abcdef0123456789');

        expect($out)->not->toContain('ABCD1234EFGH')
            ->not->toContain('0000-1111')
            ->not->toContain('eyJ')
            ->not->toContain('abcdef0123456789')
            ->toContain('license_key=[redacted]')
            ->toContain('purchase_code=[redacted]')
            ->toContain('Bearer [redacted]');
    });

    it('leaves ordinary upgrader lines alone', function () {
        foreach ([
            'Unpacking the update&#8230;',
            'Could not copy file. sample-plugin/includes/class-admin.php',
            'Error: Could not copy file.',
            'Plugin updated successfully.',
            'The package could not be installed. PCLZIP_ERR_BAD_FORMAT (-10) : Unable to find End of Central Dir Record signature',
        ] as $line) {
            expect(UpgraderOutput::redactText($line))->toBe($line);
        }
    });
});

describe('UpgraderOutput::redact', function () {
    it('redacts every string in a nested update result, keeping keys and non-strings', function () {
        $result = [
            'ok' => true,
            'slug' => 'sample-plugin/sample-plugin.php',
            'before_version' => '1.0.0',
            'elapsed_ms' => 1200,
            'messages' => [fakeLicensedDownloadLine(), 'Unpacking the update&#8230;'],
            'nested' => ['url' => 'https://example.com/hook?token=SECRETVALUE'],
        ];

        $out = UpgraderOutput::redact($result);

        expect($out['ok'])->toBeTrue()
            ->and($out['elapsed_ms'])->toBe(1200)
            ->and($out['slug'])->toBe('sample-plugin/sample-plugin.php')
            ->and($out['messages'][0])->toBe('Downloading update from https://updates.example.com/[redacted]&#8230;')
            ->and($out['messages'][1])->toBe('Unpacking the update&#8230;')
            ->and($out['nested']['url'])->toBe('https://example.com/hook')
            ->and(json_encode($out))->not->toContain(FAKE_LICENSE)
            ->and(json_encode($out))->not->toContain('SECRETVALUE');
    });

    it('passes null through', function () {
        expect(UpgraderOutput::redact(null))->toBeNull();
    });
});

describe('UpgraderOutput::errorLines', function () {
    it('returns the last Error: line and the detail line before it, decoded', function () {
        $lines = UpgraderOutput::errorLines([
            fakeSignedDownloadLine(),
            'Unpacking the update&#8230;',
            'Could not copy file. sample-plugin/includes/class-admin.php',
            'Error: Could not copy file.',
        ]);

        expect($lines)->toBe([
            'Could not copy file. sample-plugin/includes/class-admin.php',
            'Error: Could not copy file.',
        ]);
    });

    it('never returns the download line, even when it directly precedes the error', function () {
        $lines = UpgraderOutput::errorLines([
            fakeLicensedDownloadLine(),
            'Error: Download failed.',
        ]);

        expect($lines)->toBe(['Error: Download failed.'])
            ->and(implode("\n", $lines))->not->toContain(FAKE_LICENSE);
    });

    it('picks the last Error: line when there are several', function () {
        $lines = UpgraderOutput::errorLines([
            'Error: First problem.',
            'Retrying&#8230;',
            'Update package not available.',
            'Error: Update package not available.',
        ]);

        expect($lines)->toBe(['Update package not available.', 'Error: Update package not available.']);
    });

    it('returns nothing when the upgrader logged no error', function () {
        expect(UpgraderOutput::errorLines(['Plugin is already up to date.']))->toBe([])
            ->and(UpgraderOutput::errorLines([]))->toBe([])
            ->and(UpgraderOutput::errorLines(null))->toBe([]);
    });

    it('skips non-string entries', function () {
        expect(UpgraderOutput::errorLines([['nested'], 'Download failed. Forbidden', 'Error: Download failed.']))
            ->toBe(['Download failed. Forbidden', 'Error: Download failed.']);
    });
});

describe('UpgraderOutput::classify', function () {
    it('maps upgrader errors to reason codes', function (array $messages, string $expected) {
        expect(UpgraderOutput::classify(UpgraderOutput::errorLines($messages)))->toBe($expected);
    })->with([
        'copy failed' => [[
            fakeSignedDownloadLine(),
            'Unpacking the update&#8230;',
            'Could not copy file. sample-plugin/includes/class-admin.php',
            'Error: Could not copy file.',
        ], UpgraderOutput::REASON_FS_PERMISSION],
        'upgrade dir not creatable' => [[
            fakeSignedDownloadLine(),
            'Unpacking the update&#8230;',
            'Could not create directory. /var/www/html/wp-content/upgrade/sample-plugin_2.0.0-AbC12d-x9Yz',
            'Error: Could not create directory.',
        ], UpgraderOutput::REASON_FS_PERMISSION],
        'licence refused' => [[
            fakeJwtPathDownloadLine(),
            'Download failed. Unauthorized',
            'Error: Download failed.',
        ], UpgraderOutput::REASON_DOWNLOAD_UNAUTHORIZED],
        'forbidden' => [[
            fakeLicensedDownloadLine(),
            'Download failed. Forbidden',
            'Error: Download failed.',
        ], UpgraderOutput::REASON_DOWNLOAD_UNAUTHORIZED],
        'not a zip' => [[
            fakeLicensedDownloadLine(),
            'Unpacking the update&#8230;',
            'The package could not be installed. PCLZIP_ERR_BAD_FORMAT (-10) : Unable to find End of Central Dir Record signature',
            'Error: The package could not be installed.',
        ], UpgraderOutput::REASON_BAD_PACKAGE],
        'no download URL' => [[
            'Downloading update from 1&#8230;',
            'Download failed. A valid URL was not provided.',
            'Error: Download failed.',
        ], UpgraderOutput::REASON_NO_PACKAGE],
        'no package' => [[
            'Update package not available.',
            'Error: Update package not available.',
        ], UpgraderOutput::REASON_NO_PACKAGE],
        'anything else' => [[
            fakeSignedDownloadLine(),
            'Download failed. cURL error 28: Operation timed out after 30001 milliseconds',
            'Error: Download failed.',
        ], UpgraderOutput::REASON_UPGRADER_ERROR],
    ]);
});
