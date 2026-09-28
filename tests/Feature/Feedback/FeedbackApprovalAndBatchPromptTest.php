<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Modules\Feedback\Models\FeedbackComment;
use Modules\Feedback\Models\FeedbackItem;

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'Aaron Reimann',
        'email' => 'aaron@example.com',
    ]);
});

test('can mark a feedback item as approved', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Pagination missing',
        'content' => 'Please add pagination with 50 per page default.',
    ]);

    expect($item->status)->toBe(FeedbackItem::STATUS_OPEN);
    expect($item->isApproved())->toBeFalse();

    $response = $this->actingAs($this->user)->postJson(route('feedback.approve', $item));
    $response->assertOk();
    $response->assertJson(['ok' => true, 'status' => FeedbackItem::STATUS_APPROVED]);

    expect($item->fresh()->status)->toBe(FeedbackItem::STATUS_APPROVED);
    expect($item->fresh()->isApproved())->toBeTrue();
});

test('approved items appear in approved scope and stats', function () {
    $item1 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'Approved bug',
        'content' => 'Bug details',
    ]);

    $item2 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/settings',
        'path' => '/settings',
        'type' => FeedbackItem::TYPE_FEATURE,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Open feature',
        'content' => 'Feature details',
    ]);

    expect(FeedbackItem::approved()->count())->toBe(1);
    expect(FeedbackItem::active()->count())->toBe(2);

    $response = $this->actingAs($this->user)->get(route('feedback.index', ['status' => 'approved']));
    $response->assertOk();
    $response->assertSee('Approved bug');
    $response->assertDontSee('Open feature');
});

test('can generate compiled batch prompt for approved items', function () {
    $item1 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'route_name' => 'monitoring.index',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'Pagination missing',
        'content' => 'Add pagination drawer',
    ]);

    $item2 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/settings',
        'path' => '/settings',
        'route_name' => 'settings.index',
        'type' => FeedbackItem::TYPE_FEATURE,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'Quick shortcut',
        'content' => 'Add shortcut key',
    ]);

    FeedbackComment::create([
        'feedback_item_id' => $item1->id,
        'user_id' => $this->user->id,
        'content' => 'We agreed on 50 items per page.',
    ]);

    $response = $this->actingAs($this->user)->getJson(route('feedback.prompt.batch'));
    $response->assertOk();
    $response->assertJson(['ok' => true, 'count' => 2]);

    $prompt = $response->json('prompt');
    expect($prompt)->toContain('# Task: Implement Approved Feedback & Feature Requests');
    expect($prompt)->toContain('Pagination missing');
    expect($prompt)->toContain('Quick shortcut');
    expect($prompt)->toContain('We agreed on 50 items per page.');

    // Also supports status=all to bundle all items
    $openItem = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/security',
        'path' => '/security',
        'type' => FeedbackItem::TYPE_TWEAK,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Open Tweak',
        'content' => 'Open tweak details',
    ]);

    $allRes = $this->actingAs($this->user)->getJson(route('feedback.prompt.batch', ['status' => 'all']));
    $allRes->assertOk();
    $allRes->assertJson(['ok' => true, 'count' => 3]);
    expect($allRes->json('prompt'))->toContain('Open Tweak');
});

test('can download markdown prompt file for approved batch or single item', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'Downloadable Bug',
        'content' => 'Download test',
    ]);

    // Batch download
    $batchRes = $this->actingAs($this->user)->get(route('feedback.prompt.download', ['status' => 'approved']));
    $batchRes->assertOk();
    $batchRes->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    expect($batchRes->streamedContent())->toContain('Downloadable Bug');

    // Single item download
    $singleRes = $this->actingAs($this->user)->get(route('feedback.prompt.download', ['id' => $item->id]));
    $singleRes->assertOk();
    $singleRes->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    expect($singleRes->streamedContent())->toContain('Downloadable Bug');
});

test('can mark approved items as in progress', function () {
    $item1 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'Bug 1',
        'content' => 'Content 1',
    ]);

    $item2 = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_TWEAK,
        'status' => FeedbackItem::STATUS_OPEN,
        'title' => 'Tweak 1',
        'content' => 'Content 2',
    ]);

    $response = $this->actingAs($this->user)->postJson(route('feedback.prompt.mark-in-progress'));
    $response->assertOk();
    $response->assertJson(['ok' => true, 'count' => 1]);

    expect($item1->fresh()->status)->toBe(FeedbackItem::STATUS_IN_PROGRESS);
    expect($item2->fresh()->status)->toBe(FeedbackItem::STATUS_OPEN);
});

test('artisan clockwork:feedback-prompt creates prompt file in storage and updates status', function () {
    $item = FeedbackItem::create([
        'user_id' => $this->user->id,
        'url' => 'http://control.test/monitoring',
        'path' => '/monitoring',
        'type' => FeedbackItem::TYPE_BUG,
        'status' => FeedbackItem::STATUS_APPROVED,
        'title' => 'CLI Batch Task',
        'content' => 'Testing artisan prompt generation',
    ]);

    $testOutputFile = storage_path('app/prompts/test-artisan-prompt.md');
    if (File::exists($testOutputFile)) {
        File::delete($testOutputFile);
    }

    $this->artisan('clockwork:feedback-prompt', [
        '--status' => 'approved',
        '--output' => $testOutputFile,
        '--mark-in-progress' => true,
    ])
        ->expectsOutputToContain('Prompt successfully generated')
        ->assertSuccessful();

    expect(File::exists($testOutputFile))->toBeTrue();
    expect(File::get($testOutputFile))->toContain('CLI Batch Task');
    expect($item->fresh()->status)->toBe(FeedbackItem::STATUS_IN_PROGRESS);

    // Clean up
    if (File::exists($testOutputFile)) {
        File::delete($testOutputFile);
    }
});
