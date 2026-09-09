<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('CodeSnippetsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('rejects execute when more than 15 sites are selected', function () {
        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('snippets.execute'), [
                'site_ids' => range(1, 16),
                'code' => 'return 1;',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('site_ids');
    });
});
