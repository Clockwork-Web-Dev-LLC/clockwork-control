<?php

use App\Services\Updates\ThemeIdentityGuard;
use Illuminate\Support\Facades\Http;

/*
| ThemeIdentityGuard: does an installed theme (one Companion snapshot
| themes.items entry) look like the theme an update would install? The
| nightly loop's handling of a refusal is covered in
| tests/Feature/Console/RunNightlyPluginUpdatesTest.php.
*/

function guardTheme(array $overrides = []): array
{
    return array_merge([
        'slug' => 'example-theme',
        'name' => 'Example Theme',
        'version' => '1.0.0',
        'new_version' => '1.1.0',
        'update_available' => true,
    ], $overrides);
}

describe('ThemeIdentityGuard', function () {
    it('accepts a theme whose name matches the wordpress.org listing for its folder', function () {
        Http::fake(['api.wordpress.org/themes/*' => Http::response(['name' => 'Example', 'slug' => 'example-theme', 'version' => '1.1.0'])]);

        // "Example Theme" vs "Example": a trailing "Theme" is not a difference.
        expect((new ThemeIdentityGuard)->mismatchReason(guardTheme()))->toBeNull();
    });

    it('refuses a theme whose name differs from the wordpress.org listing for its folder', function () {
        Http::fake(['api.wordpress.org/themes/*' => Http::response(['name' => 'Studio', 'slug' => 'studio', 'version' => '2.3.0'])]);

        $reason = (new ThemeIdentityGuard)->mismatchReason(guardTheme([
            'slug' => 'studio', 'name' => 'Studio Child Theme', 'version' => '1.0.0', 'new_version' => '2.3.0',
        ]));

        expect($reason)->toContain('"Studio Child Theme"')
            ->and($reason)->toContain('"Studio"')
            ->and($reason)->toContain('latest 2.3.0')
            ->and($reason)->toContain('Updating to 2.3.0');
    });

    it('lets a matching wordpress.org listing outrank the child-theme name check', function () {
        Http::fake(['api.wordpress.org/themes/*' => Http::response(['name' => 'Example Child', 'slug' => 'example-child', 'version' => '2.0'])]);

        expect((new ThemeIdentityGuard)->mismatchReason(guardTheme(['slug' => 'example-child', 'name' => 'Example Child'])))->toBeNull();
    });

    it('compares names without HTML entities, case or punctuation', function () {
        expect(ThemeIdentityGuard::sameName('Dine &amp; Drink', 'Dine & Drink'))->toBeTrue()
            ->and(ThemeIdentityGuard::sameName('Twenty Twenty-Four', 'twenty twenty four'))->toBeTrue()
            ->and(ThemeIdentityGuard::sameName('Example Child Theme', 'Example'))->toBeFalse()
            ->and(ThemeIdentityGuard::sameName('', ''))->toBeFalse();
    });

    describe('when wordpress.org does not list the folder', function () {
        beforeEach(function () {
            Http::fake(['api.wordpress.org/themes/*' => Http::response(['error' => 'Theme not found'], 404)]);
        });

        it('accepts a premium theme whatever its name', function () {
            expect((new ThemeIdentityGuard)->mismatchReason(guardTheme(['slug' => 'example-builder', 'name' => 'Example Page Builder'])))->toBeNull();
        });

        it('refuses a theme named as a child theme', function () {
            expect((new ThemeIdentityGuard)->mismatchReason(guardTheme(['name' => 'Example Child'])))->toContain('child theme');
        });

        it('refuses a theme whose snapshot names a parent template', function () {
            $reason = (new ThemeIdentityGuard)->mismatchReason(guardTheme(['template' => 'example-parent']));

            expect($reason)->toContain('child theme of "example-parent"');
        });

        it('does not take a parent template equal to its own folder as a child theme', function () {
            expect((new ThemeIdentityGuard)->mismatchReason(guardTheme(['template' => 'example-theme'])))->toBeNull();
        });
    });

    it('falls back to the snapshot checks when the directory cannot be reached', function () {
        Http::fake(['api.wordpress.org/themes/*' => Http::response('', 503)]);
        $guard = new ThemeIdentityGuard;

        expect($guard->mismatchReason(guardTheme()))->toBeNull()
            ->and($guard->mismatchReason(guardTheme(['slug' => 'other-child', 'name' => 'Other Child'])))->toContain('child theme');
    });

    it('asks the directory once per folder name', function () {
        Http::fake(['api.wordpress.org/themes/*' => Http::response(['name' => 'Example', 'version' => '1.1.0'])]);
        $guard = new ThemeIdentityGuard;

        $guard->mismatchReason(guardTheme());
        $guard->mismatchReason(guardTheme(['version' => '0.9']));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'request[slug]=example-theme')
            && str_contains($request->url(), 'action=theme_information'));
    });
});
