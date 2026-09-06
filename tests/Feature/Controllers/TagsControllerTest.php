<?php

use App\Models\Server;
use App\Models\Tag;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects to login when hitting /settings/tags unauthenticated', function () {
        $response = $this->get(route('settings.tags.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('GET /settings/tags (index)', function () {
    it('renders existing tags with their server counts', function () {
        $tag = Tag::factory()->create(['name' => 'dedicated', 'sort_order' => 1]);
        $server = Server::factory()->create();
        $tag->servers()->attach($server);

        $response = $this->actingAs(User::factory()->create())->get(route('settings.tags.index'));

        $response->assertOk();
        $response->assertSee('dedicated');
        $response->assertSee('1 server');
    });
});

describe('POST /settings/tags (store)', function () {
    it('creates a tag and redirects with a status flash', function () {
        $response = $this->actingAs(User::factory()->create())->post(route('settings.tags.store'), [
            'name' => 'staging',
            'color' => '#16a34a',
            'description' => 'Staging servers',
            'sort_order' => 5,
        ]);

        $response->assertRedirect(route('settings.tags.index'));
        $response->assertSessionHas('status', 'Tag created.');

        $tag = Tag::where('name', 'staging')->first();
        expect($tag)->not->toBeNull()
            ->and($tag->color)->toBe('#16a34a')
            ->and($tag->slug)->toBe('staging');
    });

    it('rejects a duplicate tag name', function () {
        Tag::factory()->create(['name' => 'staging']);

        $response = $this->actingAs(User::factory()->create())->post(route('settings.tags.store'), [
            'name' => 'staging',
            'color' => '#16a34a',
        ]);

        $response->assertSessionHasErrors('name');
        expect(Tag::where('name', 'staging')->count())->toBe(1);
    });

    it('rejects an invalid color value', function () {
        $response = $this->actingAs(User::factory()->create())->post(route('settings.tags.store'), [
            'name' => 'staging',
            'color' => 'not-a-hex-color',
        ]);

        $response->assertSessionHasErrors('color');
        expect(Tag::where('name', 'staging')->exists())->toBeFalse();
    });

    it('requires a name', function () {
        $response = $this->actingAs(User::factory()->create())->post(route('settings.tags.store'), [
            'color' => '#16a34a',
        ]);

        $response->assertSessionHasErrors('name');
    });
});

describe('PATCH /settings/tags/{tag} (update)', function () {
    it('updates the tag and redirects with a status flash', function () {
        $tag = Tag::factory()->create(['name' => 'old-name', 'color' => '#000000']);

        $response = $this->actingAs(User::factory()->create())->patch(route('settings.tags.update', $tag), [
            'name' => 'new-name',
            'color' => '#ffffff',
        ]);

        $response->assertRedirect(route('settings.tags.index'));
        $response->assertSessionHas('status', 'Tag updated.');
        expect($tag->refresh())->name->toBe('new-name')->color->toBe('#ffffff');
    });

    it('allows keeping its own name unchanged (unique rule excludes itself)', function () {
        $tag = Tag::factory()->create(['name' => 'same-name', 'color' => '#000000']);

        $response = $this->actingAs(User::factory()->create())->patch(route('settings.tags.update', $tag), [
            'name' => 'same-name',
            'color' => '#111111',
        ]);

        $response->assertSessionDoesntHaveErrors();
        expect($tag->refresh()->color)->toBe('#111111');
    });

    it('rejects renaming to a name already used by another tag', function () {
        Tag::factory()->create(['name' => 'taken']);
        $tag = Tag::factory()->create(['name' => 'mine']);

        $response = $this->actingAs(User::factory()->create())->patch(route('settings.tags.update', $tag), [
            'name' => 'taken',
            'color' => '#000000',
        ]);

        $response->assertSessionHasErrors('name');
        expect($tag->refresh()->name)->toBe('mine');
    });

    it('404s for a nonexistent tag', function () {
        $response = $this->actingAs(User::factory()->create())->patch(route('settings.tags.update', ['tag' => 999999]), [
            'name' => 'x',
            'color' => '#000000',
        ]);

        $response->assertNotFound();
    });
});

describe('DELETE /settings/tags/{tag} (destroy)', function () {
    it('deletes the tag and redirects with a status flash', function () {
        $tag = Tag::factory()->create();

        $response = $this->actingAs(User::factory()->create())->delete(route('settings.tags.destroy', $tag));

        $response->assertRedirect(route('settings.tags.index'));
        $response->assertSessionHas('status', 'Tag deleted.');
        expect(Tag::find($tag->id))->toBeNull();
    });

    it('detaches the tag from any servers it was assigned to', function () {
        $tag = Tag::factory()->create();
        $server = Server::factory()->create();
        $tag->servers()->attach($server);

        $this->actingAs(User::factory()->create())->delete(route('settings.tags.destroy', $tag));

        expect($server->tags()->count())->toBe(0);
    });
});

describe('PATCH /servers/{server}/tags (syncServer)', function () {
    it('syncs the given tag ids onto the server and redirects back', function () {
        $server = Server::factory()->create();
        $tagA = Tag::factory()->create();
        $tagB = Tag::factory()->create();

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.tags.sync', $server), [
            'tags' => [$tagA->id, $tagB->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Tags updated.');
        expect($server->tags()->pluck('tags.id')->sort()->values()->all())->toBe([$tagA->id, $tagB->id]);
    });

    it('clears all tags when synced with an empty list', function () {
        $server = Server::factory()->create();
        $tag = Tag::factory()->create();
        $server->tags()->attach($tag);

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.tags.sync', $server), [
            'tags' => [],
        ]);

        $response->assertRedirect();
        expect($server->tags()->count())->toBe(0);
    });

    it('rejects a tag id that does not exist', function () {
        $server = Server::factory()->create();

        $response = $this->actingAs(User::factory()->create())->patch(route('servers.tags.sync', $server), [
            'tags' => [999999],
        ]);

        $response->assertSessionHasErrors('tags.0');
        expect($server->tags()->count())->toBe(0);
    });

    it('404s for a nonexistent server', function () {
        $response = $this->actingAs(User::factory()->create())->patch(route('servers.tags.sync', ['server' => 999999]), [
            'tags' => [],
        ]);

        $response->assertNotFound();
    });
});
