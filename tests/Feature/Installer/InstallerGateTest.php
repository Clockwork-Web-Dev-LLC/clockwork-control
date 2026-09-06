<?php

use App\Http\Middleware\EnforceInstallerGate;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('EnforceInstallerGate', function () {
    afterEach(function () {
        EnforceInstallerGate::fake(null);
    });

    it('redirects uninstalled application requests to /install', function () {
        EnforceInstallerGate::fake(false);

        $response = $this->get('/login');

        $response->assertRedirect(route('install.welcome'));
    });

    it('permits installer routes when uninstalled', function (string $route) {
        EnforceInstallerGate::fake(false);

        $response = $this->get(route($route));

        $response->assertOk();
    })->with([
        'install.welcome',
        'install.database',
        'install.app',
        'install.mail',
        'install.google',
        'install.admin',
        'install.hosting',
    ]);

    it('returns hard 404 on /install and subroutes when installed', function (string $uri) {
        EnforceInstallerGate::fake(true);

        $response = $this->get($uri);

        $response->assertNotFound();
    })->with([
        '/install',
        '/install/database',
        '/install/app',
        '/install/mail',
        '/install/google',
        '/install/admin',
        '/install/hosting',
        '/install/review',
    ]);

    it('does not redirect standard routes when installed', function () {
        EnforceInstallerGate::fake(true);

        $response = $this->get(route('login'));

        $response->assertOk();
    });
});
