<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Modules\Feedback\FeedbackServiceProvider;
use Modules\Feedback\Models\FeedbackItem;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->mockIssueCounterZero();
    app(ModuleStateResolver::class)->flush();
});

test('unauthenticated users cannot view feedback backlog or pins', function () {
    $this->get(route('feedback.index'))->assertRedirect(route('login'));
    $this->getJson(route('feedback.pins', ['path' => '/monitoring']))->assertUnauthorized();
});

test('authenticated user can view feedback backlog dashboard', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'route_name' => 'monitoring.index',
        'controller_action' => 'App\Http\Controllers\MonitoringController@index',
        'selector' => '#monitoring-sites-card',
        'element_tag' => 'div',
        'element_text' => 'Sites table',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Pagination alignment issue',
        'content' => 'Pagination overlaps footer on small screens.',
    ]);

    $response = $this->actingAs($this->user)->get(route('feedback.index'));

    $response->assertOk();
    $response->assertSee('Feedback &amp; Backlog', false);
    $response->assertSee('Pagination alignment issue');
    $response->assertSee('Pagination overlaps footer on small screens.');
    $response->assertSee('/monitoring');
    $response->assertSee('Copy Prompt');
});

test('can create feedback item via API and auto-resolves route name', function () {
    $response = $this->actingAs($this->user)->postJson(route('feedback.store'), [
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'title' => 'Missing CSV export',
        'content' => 'Please add an export button next to re-probe all.',
        'type' => 'feature',
        'selector' => 'main div.card button',
        'element_tag' => 'button',
        'element_text' => 'Re-probe all sites',
        'x_pos' => 45.2,
        'y_pos' => 12.8,
        'viewport_width' => 1440,
        'viewport_height' => 900,
    ]);

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('item.title', 'Missing CSV export');

    $this->assertDatabaseHas('feedback_items', [
        'title' => 'Missing CSV export',
        'path' => '/monitoring',
        'type' => 'feature',
        'status' => 'open',
    ]);

    $item = FeedbackItem::where('title', 'Missing CSV export')->first();
    expect($item)->not->toBeNull();
    expect($item->route_name)->toBe('monitoring.index');
});

test('captures rich page context, blade file, nearest section, and formats in Claude prompt', function () {
    $response = $this->actingAs($this->user)->postJson(route('feedback.store'), [
        'url' => 'http://control.test/feedback',
        'path' => '/feedback',
        'route_name' => 'feedback.index',
        'controller_action' => 'Modules\Feedback\Http\Controllers\FeedbackController@index',
        'view_name' => 'modules/Feedback/resources/views/index.blade.php',
        'title' => 'Feedback & Backlog: Total Submissions',
        'content' => 'Make this stat card clickable to filter by all submissions.',
        'type' => 'tweak',
        'selector' => 'div.grid > a.card:nth-of-type(1) > div',
        'element_tag' => 'div',
        'element_text' => 'Total Submissions',
        'x_pos' => 20.5,
        'y_pos' => 15.0,
        'metadata' => [
            'page_title' => 'Feedback & Backlog · Clockwork Control',
            'nearest_heading' => 'Feedback & Backlog',
            'container' => 'Card Container',
            'hierarchy' => 'main > div.grid > a.card > div',
        ],
    ]);

    $response->assertOk();
    $item = FeedbackItem::where('title', 'Feedback & Backlog: Total Submissions')->first();
    expect($item)->not->toBeNull();
    expect($item->path)->toBe('/feedback');
    expect($item->route_name)->toBe('feedback.index');
    expect($item->view_name)->toBe('modules/Feedback/resources/views/index.blade.php');
    expect($item->metadata['nearest_heading'])->toBe('Feedback & Backlog');
    expect($item->metadata['container'])->toBe('Card Container');

    $prompt = $item->toClaudePrompt();
    expect($prompt)->toContain('- **Screen Path**: `/feedback`');
    expect($prompt)->toContain('- **Page Title**: Feedback & Backlog · Clockwork Control');
    expect($prompt)->toContain('- **Laravel Route**: `feedback.index`');
    expect($prompt)->toContain('- **Blade Template**: `modules/Feedback/resources/views/index.blade.php`');
    expect($prompt)->toContain('- **Nearest Section / Heading**: "Feedback & Backlog"');
    expect($prompt)->toContain('- **Container Context**: Card Container');
    expect($prompt)->toContain('- **Target Element Selector**: `div.grid > a.card:nth-of-type(1) > div`');
    expect($prompt)->toContain('- **Element Text Snippet**: "Total Submissions"');
});

test('auto-resolves module blade view when client does not pass view_name', function () {
    $response = $this->actingAs($this->user)->postJson(route('feedback.store'), [
        'url' => 'http://control.test/feedback',
        'path' => '/feedback',
        'title' => 'Stats Card Tweak',
        'content' => 'Check alignment',
        'type' => 'tweak',
    ]);

    $response->assertOk();
    $item = FeedbackItem::where('title', 'Stats Card Tweak')->first();
    expect($item)->not->toBeNull();
    expect($item->route_name)->toBe('feedback.index');
    expect($item->view_name)->toBe('modules/Feedback/resources/views/index.blade.php');
});

test('pins endpoint returns items for active path with formatted properties', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'route_name' => 'monitoring.index',
        'selector' => '#test-btn',
        'element_tag' => 'button',
        'element_text' => 'Click me',
        'type' => FeedbackItem::TYPE_TWEAK,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Button color change',
        'content' => 'Make this button darker.',
    ]);

    $response = $this->actingAs($this->user)->getJson(route('feedback.pins', ['path' => '/monitoring']));

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('count', 1);
    $response->assertJsonPath('pins.0.title', 'Button color change');
    $response->assertJsonPath('pins.0.number', 1);
    $response->assertJsonPath('pins.0.author.name', $this->user->name);
});

test('threaded discussions allow teammates to reply back and forth', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'route_name' => 'monitoring.index',
        'type' => FeedbackItem::TYPE_FEATURE,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Export format',
        'content' => 'Can we export data here?',
    ]);

    $colleague = User::factory()->create(['name' => 'Sarah Developer']);

    // Colleague replies
    $response1 = $this->actingAs($colleague)->postJson(route('feedback.comments.store', $item), [
        'content' => 'Should it be CSV or JSON?',
    ]);
    $response1->assertOk();
    $response1->assertJsonPath('ok', true);
    $response1->assertJsonPath('comment.content', 'Should it be CSV or JSON?');

    // Aaron replies back
    $response2 = $this->actingAs($this->user)->postJson(route('feedback.comments.store', $item), [
        'content' => 'CSV format with timestamp in filename.',
    ]);
    $response2->assertOk();

    $this->assertCount(2, $item->fresh()->comments);

    // Verify Claude prompt contains entire conversation transcript
    $prompt = $item->fresh(['comments.user'])->toClaudePrompt();
    expect($prompt)->toContain('### Discussion Thread');
    expect($prompt)->toContain('Sarah Developer');
    expect($prompt)->toContain('Should it be CSV or JSON?');
    expect($prompt)->toContain($this->user->name);
    expect($prompt)->toContain('CSV format with timestamp in filename.');
});

test('can update feedback status and delete item', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Temporary bug',
        'content' => 'Test content',
    ]);

    // Update status to in_progress
    $response = $this->actingAs($this->user)->patchJson(route('feedback.update', $item), [
        'status' => FeedbackItem::STATUS_IN_PROGRESS,
    ]);
    $response->assertOk();
    expect($item->fresh()->status)->toBe(FeedbackItem::STATUS_IN_PROGRESS);

    // Delete item
    $del = $this->actingAs($this->user)->deleteJson(route('feedback.destroy', $item));
    $del->assertOk();
    $this->assertDatabaseMissing('feedback_items', ['id' => $item->id]);
});

test('feedback module is toggleable in settings and respects enabled state', function () {
    $resolver = app(ModuleStateResolver::class);
    $provider = new FeedbackServiceProvider(app());

    // By default, bundled module is enabled (fail-open)
    expect($provider->enabled())->toBeTrue();
    expect($provider->navItems())->toHaveCount(1);
    expect($provider->navItems()[0]->label)->toBe('Feedback');

    // Live layout renders overlay when enabled
    $response = $this->actingAs($this->user)->get(route('monitoring.index'));
    $response->assertOk();
    $response->assertSee('id="cw-feedback-overlay-root"', false);

    // Explicitly disable module in installed_modules
    InstalledModule::updateOrCreate(
        ['module_id' => 'feedback'],
        [
            'name' => 'Feedback',
            'source' => 'bundled',
            'enabled' => false,
            'status' => 'active',
        ]
    );
    $resolver->flush();

    expect($resolver->isEnabled('feedback'))->toBeFalse();
    expect($provider->enabled())->toBeFalse();
    expect($provider->navItems())->toBeEmpty();

    // When disabled, live layout does NOT render overlay (zero overhead)
    $responseDisabled = $this->actingAs($this->user)->get(route('monitoring.index'));
    $responseDisabled->assertOk();
    $responseDisabled->assertDontSee('id="cw-feedback-overlay-root"', false);
});
