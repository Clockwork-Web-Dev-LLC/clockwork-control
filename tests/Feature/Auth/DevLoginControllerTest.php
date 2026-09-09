<?php

use App\Http\Controllers\Auth\DevLoginController;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('DevLoginController', function () {
    it('404s when APP_ENV is not local', function () {
        $this->get(route('dev-login'))->assertNotFound();
    });

    it('404s in local env when no active user exists', function () {
        $this->app['env'] = 'local';
        User::query()->update(['revoked_at' => now()]);
        User::factory()->create(['revoked_at' => now()]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ])->get(route('dev-login'))->assertNotFound();
    });

    it('logs in the first active user on loopback when APP_ENV is local', function () {
        $this->app['env'] = 'local';
        $this->mockIssueCounterZero();

        $user = User::factory()->create(['email' => 'dev@example.com']);
        User::factory()->create(['revoked_at' => now()]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ])->get(route('dev-login'));

        $response->assertRedirect(route('settings.companion.index'));
        $this->assertAuthenticatedAs($user);
    });

    it('does not treat a public host as loopback', function () {
        $request = Request::create('http://clockwork.example/dev-login', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_HOST' => 'clockwork.example',
        ]);

        expect(DevLoginController::isLoopbackRequest($request))->toBeFalse();
    });

    it('accepts localhost on 127.0.0.1', function () {
        $request = Request::create('http://localhost/dev-login', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ]);

        expect(DevLoginController::isLoopbackRequest($request))->toBeTrue();
    });

    it('rejects proxied requests carrying X-Forwarded-For', function () {
        $request = Request::create('http://localhost/dev-login', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.100',
        ]);

        expect(DevLoginController::isLoopbackRequest($request))->toBeFalse();
    });

    it('rejects proxied requests carrying X-Forwarded-Host', function () {
        $request = Request::create('http://localhost/dev-login', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_FORWARDED_HOST' => 'lan.example.com',
        ]);

        expect(DevLoginController::isLoopbackRequest($request))->toBeFalse();
    });
});
