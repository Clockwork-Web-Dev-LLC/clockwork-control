<?php

use App\Models\Server;
use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\UnregisteredCloudProviderCheck;

/**
 * Coverage for UnregisteredCloudProviderCheck (App\Services\Diagnostics\
 * DiagnosticCheck). Unlike the other checks in this suite, this one isn't an
 * external-API probe — it's a DB-driven check over this app's own servers
 * table, verifying every servers.provider value in use is claimed by a
 * registered CloudProvider module (see CloudProviderRegistry, which as of
 * Phase 4 falls back to NullCloudProvider instead of silently defaulting
 * unrecognized providers to DigitalOcean).
 *
 * The app registers 4 real CloudProvider modules in bootstrap/providers.php
 * (Azure, Cloudways, DigitalOcean, Hetzner) — see ModuleRegistry::
 * cloudProviders(). Those 4 ids are exercised here as the "recognized" set;
 * anything else (e.g. a stale/typo'd provider string) should surface as a
 * failing server.provider value.
 *
 * This check has no "unconfigured" state (no external credentials to be
 * missing) — with zero servers or only recognized-provider servers, it's ok;
 * with any unrecognized provider value present, it fails. It never returns
 * STATUS_SKIPPED.
 */
describe('UnregisteredCloudProviderCheck', function () {
    it('returns ok when there are no servers at all', function () {
        $result = app(UnregisteredCloudProviderCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('registered');
    });

    it('returns ok when every server uses a recognized provider', function () {
        Server::factory()->digitalOcean()->create();
        Server::factory()->hetzner()->create();
        Server::factory()->azure()->create();
        Server::factory()->cloudways()->create();
        Server::factory()->vultr()->create();
        Server::factory()->linode()->create();

        $result = app(UnregisteredCloudProviderCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('registered');
    });

    it('returns fail naming the unrecognized provider value when one server uses it', function () {
        Server::factory()->digitalOcean()->create();
        Server::factory()->create(['provider' => 'rackspace']);

        $result = app(UnregisteredCloudProviderCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('1 unrecognized provider value(s) in use')
            ->and($result->detail)->toBe('rackspace');
    });

    it('returns fail listing every distinct unrecognized provider value, without duplicates', function () {
        Server::factory()->digitalOcean()->create();
        Server::factory()->create(['provider' => 'rackspace']);
        Server::factory()->create(['provider' => 'rackspace']);
        Server::factory()->create(['provider' => 'upcloud']);

        $result = app(UnregisteredCloudProviderCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('2 unrecognized provider value(s) in use')
            ->and($result->detail)->toContain('rackspace')
            ->and($result->detail)->toContain('upcloud');

        // distinct() means "rackspace" appears once in the detail list even
        // though two servers use it.
        expect(substr_count($result->detail, 'rackspace'))->toBe(1);
    });
});
