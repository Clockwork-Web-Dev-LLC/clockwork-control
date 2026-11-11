<?php

use App\Support\SsrfGuard;

afterEach(function () {
    SsrfGuard::stopFaking();
});

it('allows a normal public IP literal', function () {
    SsrfGuard::assertPublic('https://8.8.8.8/');
})->throwsNoExceptions();

it('rejects a loopback IP literal', function () {
    SsrfGuard::assertPublic('https://127.0.0.1/');
})->throws(RuntimeException::class);

it('rejects a link-local IP literal (cloud metadata range)', function () {
    SsrfGuard::assertPublic('https://169.254.169.254/');
})->throws(RuntimeException::class);

it('rejects an RFC1918 private IP literal', function () {
    SsrfGuard::assertPublic('https://10.0.0.5/');
})->throws(RuntimeException::class);

it('rejects an IPv6 loopback literal', function () {
    SsrfGuard::assertPublic('https://[::1]/');
})->throws(RuntimeException::class);

it('rejects an IPv6 loopback literal even when DNS faking is active', function () {
    SsrfGuard::fake();

    SsrfGuard::assertPublic('https://[::1]/');
})->throws(RuntimeException::class);

it('rejects CGNAT and NAT64 metadata literals', function (string $url) {
    SsrfGuard::fake();

    SsrfGuard::assertPublic($url);
})->with([
    'https://100.64.0.1/',
    'https://[64:ff9b::a9fe:a9fe]/',
    'https://[::ffff:169.254.169.254]/',
])->throws(RuntimeException::class);

it('rejects non-HTTP schemes', function () {
    SsrfGuard::assertPublic('file:///etc/passwd');
})->throws(InvalidArgumentException::class);

it('rejects a URL with no host', function () {
    SsrfGuard::assertPublic('not-a-url');
})->throws(InvalidArgumentException::class);

it('rejects a hostname that resolves to a private IP when faked', function () {
    SsrfGuard::fake(['evil.example' => ['10.0.0.5']]);

    SsrfGuard::assertPublic('https://evil.example/');
})->throws(RuntimeException::class);

it('allows a hostname that resolves to a public IP when faked', function () {
    SsrfGuard::fake(['safe.example' => ['93.184.216.34']]);

    SsrfGuard::assertPublic('https://safe.example/');
})->throwsNoExceptions();

it('defaults unmapped hostnames to a safe public IP while faking', function () {
    SsrfGuard::fake();

    SsrfGuard::assertPublic('https://anything-at-all.example/');
})->throwsNoExceptions();

it('permits private IP literals when allowPrivateHosts is enabled', function () {
    SsrfGuard::allowPrivateHosts(true);

    SsrfGuard::assertPublic('https://192.168.1.1/');
    SsrfGuard::assertPublic('https://10.0.0.1/');
})->throwsNoExceptions();
