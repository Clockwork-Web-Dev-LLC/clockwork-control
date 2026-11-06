<?php

use App\Support\Mail\BrandLogo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| The white-label logo in client emails. A logo uploaded in Settings lives on
| Control's public disk, whose URL is relative ("/storage/…") or on Control's
| own private host: it has to be embedded from disk, never hot-linked.
*/

beforeEach(function () {
    Storage::fake('public');
    config(['app.url' => 'https://control.example.test']);
});

it('embeds an uploaded logo from the public disk, whatever its URL looks like', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Storage::disk('public')->put('branding/companion-logo-1.png', $png);
    Http::fake();

    expect(BrandLogo::bytes(Storage::disk('public')->url('branding/companion-logo-1.png')))->toBe($png)
        ->and(BrandLogo::bytes('/storage/branding/companion-logo-1.png'))->toBe($png)
        ->and(BrandLogo::mimeType($png))->toBe('image/png');
    Http::assertNothingSent();
});

it('never hot-links a relative URL or one on Control\'s own host', function () {
    expect(BrandLogo::linkable('/storage/branding/missing.png'))->toBeFalse()
        ->and(BrandLogo::linkable('https://control.example.test/images/logo.png'))->toBeFalse()
        ->and(BrandLogo::linkable('https://cdn.agency.example/logo.png'))->toBeTrue();
});
