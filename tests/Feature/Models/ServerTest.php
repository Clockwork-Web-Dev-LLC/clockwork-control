<?php

use App\Models\Server;
use App\Models\Tag;
use App\Services\CloudProvider\CloudProviderRegistry;

describe('display_name accessor', function () {
    it('strips the configured suffix from name', function () {
        config(['clockwork.monitoring.display_name_strip_suffix' => '.example.com']);

        $server = Server::factory()->make(['name' => 'web36.example.com']);

        expect($server->display_name)->toBe('web36');
    });

    it('returns the full name unchanged when the suffix config is unset', function () {
        config(['clockwork.monitoring.display_name_strip_suffix' => null]);

        $server = Server::factory()->make(['name' => 'web36.example.com']);

        expect($server->display_name)->toBe('web36.example.com');
    });

    it('returns the full name unchanged when the suffix config is empty string', function () {
        config(['clockwork.monitoring.display_name_strip_suffix' => '']);

        $server = Server::factory()->make(['name' => 'web36.example.com']);

        expect($server->display_name)->toBe('web36.example.com');
    });
});

describe('provider_label accessor', function () {
    it('resolves the DigitalOcean label from the real CloudProviderRegistry', function () {
        $server = Server::factory()->make(['provider' => Server::PROVIDER_DIGITALOCEAN]);

        $expected = app(CloudProviderRegistry::class)->resolve(Server::PROVIDER_DIGITALOCEAN)->label();

        expect($server->provider_label)->toBe($expected)->not->toBeEmpty();
    });

    it('resolves the Hetzner label from the real CloudProviderRegistry', function () {
        $server = Server::factory()->make(['provider' => Server::PROVIDER_HETZNER]);

        $expected = app(CloudProviderRegistry::class)->resolve(Server::PROVIDER_HETZNER)->label();

        expect($server->provider_label)->toBe($expected)->not->toBeEmpty();
    });

    it('resolves the Azure label from the real CloudProviderRegistry', function () {
        $server = Server::factory()->make(['provider' => Server::PROVIDER_AZURE]);

        $expected = app(CloudProviderRegistry::class)->resolve(Server::PROVIDER_AZURE)->label();

        expect($server->provider_label)->toBe($expected)->not->toBeEmpty();
    });

    it('resolves the Vultr label from the real CloudProviderRegistry', function () {
        $server = Server::factory()->vultr()->make();

        $expected = app(CloudProviderRegistry::class)->resolve(Server::PROVIDER_VULTR)->label();

        expect($server->provider_label)->toBe($expected)->not->toBeEmpty();
    });

    it('resolves the Linode label from the real CloudProviderRegistry', function () {
        $server = Server::factory()->linode()->make();

        $expected = app(CloudProviderRegistry::class)->resolve(Server::PROVIDER_LINODE)->label();

        expect($server->provider_label)->toBe($expected)->not->toBeEmpty();
    });

    it('returns the raw provider string unchanged for an unrecognized provider, not the registry default', function () {
        $server = Server::factory()->make(['provider' => 'some-future-cloud']);

        // Deliberately distinct from CloudProviderRegistry::resolve()'s dispatch-level
        // default (NullCloudProvider, label "Unrecognized provider") — see the model's
        // own docblock above getProviderLabelAttribute(). The accessor must show the
        // server's own raw string, not whatever the registry would have resolved to.
        $registryLabel = app(CloudProviderRegistry::class)->resolve('some-future-cloud')->label();

        expect($server->provider_label)
            ->toBe('some-future-cloud')
            ->not->toBe($registryLabel);
    });
});

describe('scopeMonitored', function () {
    it('excludes a server with is_ignored true', function () {
        $ignored = Server::factory()->ignored()->create();
        $normal = Server::factory()->create();

        $ids = Server::monitored()->pluck('id');

        expect($ids)->not->toContain($ignored->id)
            ->and($ids)->toContain($normal->id);
    });

    it('excludes a server tagged staging even when is_ignored is false', function () {
        $staging = Server::factory()->create(['is_ignored' => false]);
        $tag = Tag::factory()->create(['slug' => 'staging']);
        $staging->tags()->attach($tag);

        $normal = Server::factory()->create(['is_ignored' => false]);

        $ids = Server::monitored()->pluck('id');

        expect($ids)->not->toContain($staging->id)
            ->and($ids)->toContain($normal->id);
    });

    it('includes a normal server that is neither ignored nor staging-tagged', function () {
        $normal = Server::factory()->create(['is_ignored' => false]);

        expect(Server::monitored()->pluck('id'))->toContain($normal->id);
    });
});

describe('isStaging', function () {
    it('is true when the server has a tag with slug staging', function () {
        $server = Server::factory()->create();
        $tag = Tag::factory()->create(['slug' => 'staging']);
        $server->tags()->attach($tag);
        $server->load('tags');

        expect($server->isStaging())->toBeTrue();
    });

    it('is false when the server has no staging tag', function () {
        $server = Server::factory()->create();
        $tag = Tag::factory()->create(['slug' => 'production']);
        $server->tags()->attach($tag);
        $server->load('tags');

        expect($server->isStaging())->toBeFalse();
    });

    it('is false when the server has no tags at all', function () {
        $server = Server::factory()->create();
        $server->load('tags');

        expect($server->isStaging())->toBeFalse();
    });
});

describe('encrypted casts', function () {
    it('round-trips ssh_private_key and ssh_password through encryption', function () {
        $server = Server::factory()->create([
            'ssh_private_key' => 'fake-test-ssh-private-key-content',
            'ssh_password' => 'super-secret-password',
        ]);

        $fresh = $server->fresh();

        expect($fresh->ssh_private_key)->toBe('fake-test-ssh-private-key-content')
            ->and($fresh->ssh_password)->toBe('super-secret-password');

        // The raw DB column must not contain the plaintext — proves it's actually encrypted at rest.
        $raw = DB::table('servers')->where('id', $server->id)->first();
        expect($raw->ssh_private_key)->not->toContain('fake-test-ssh-private-key-content')
            ->and($raw->ssh_password)->not->toBe('super-secret-password');
    });

    it('hides ssh_private_key and ssh_password from array and JSON serialization', function () {
        $server = Server::factory()->create([
            'ssh_private_key' => 'a-private-key',
            'ssh_password' => 'a-password',
        ]);

        expect($server->toArray())->not->toHaveKeys(['ssh_private_key', 'ssh_password']);

        $json = $server->toJson();
        expect($json)->not->toContain('a-private-key')
            ->and($json)->not->toContain('a-password')
            ->and($json)->not->toContain('ssh_private_key')
            ->and($json)->not->toContain('ssh_password');
    });
});

describe('tags relationship', function () {
    it('returns tags ordered by sort_order then name', function () {
        $server = Server::factory()->create();

        $zebra = Tag::factory()->create(['name' => 'zebra', 'sort_order' => 1]);
        $apple = Tag::factory()->create(['name' => 'apple', 'sort_order' => 1]);
        $first = Tag::factory()->create(['name' => 'should-be-first', 'sort_order' => 0]);

        $server->tags()->attach([$zebra->id, $apple->id, $first->id]);

        $ordered = $server->tags()->get()->pluck('name')->all();

        expect($ordered)->toBe(['should-be-first', 'apple', 'zebra']);
    });
});
