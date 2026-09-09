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
        '/install/done',
    ]);

    it('serves /install/done after a successful install while just_installed is set', function () {
        EnforceInstallerGate::fake(true);

        $response = $this->withSession(['install.just_installed' => true])
            ->get(route('install.done'));

        $response->assertOk()
            ->assertSee('Clockwork Control is Installed!');
    });

    it('does not redirect standard routes when installed', function () {
        EnforceInstallerGate::fake(true);

        $response = $this->get(route('login'));

        $response->assertOk();
    });
});
