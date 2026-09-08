<?php

use App\Models\Site;
use App\Models\User;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ClientReportCompiler;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

it('requires authentication for template management', function () {
    $template = ClientReportTemplate::query()->first() ?: ClientReportTemplate::create([
        'name' => 'Test Template',
        'sections' => ['updates', 'uptime'],
        'is_default' => false,
    ]);

    $this->get(route('client-reports.templates.index'))->assertRedirect(route('login'));
    $this->get(route('client-reports.templates.create'))->assertRedirect(route('login'));
    $this->post(route('client-reports.templates.store'))->assertRedirect(route('login'));
    $this->get(route('client-reports.templates.edit', $template))->assertRedirect(route('login'));
    $this->put(route('client-reports.templates.update', $template))->assertRedirect(route('login'));
    $this->delete(route('client-reports.templates.destroy', $template))->assertRedirect(route('login'));
});

describe('Templates CRUD', function () {
    it('lists templates including the seeded default template', function () {
        $this->mockIssueCounterZero();

        $default = ClientReportTemplate::query()->where('is_default', true)->first();
        expect($default)->not->toBeNull();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('client-reports.templates.index'));

        $response->assertOk()
            ->assertSee($default->name)
            ->assertSee('Default');
    });

    it('renders the template create form', function () {
        $this->mockIssueCounterZero();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('client-reports.templates.create'));

        $response->assertOk()
            ->assertSee('Create Report Template')
            ->assertSee('Updates &amp; Upgrades', false)
            ->assertSee('Uptime &amp; Availability', false)
            ->assertSee('Security &amp; Firewall', false);
    });

    it('stores a new custom template with selected sections', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('client-reports.templates.store'), [
            'name' => 'Speed & Uptime Only',
            'sections' => ['uptime', 'performance'],
            'is_default' => 0,
        ]);

        $response->assertRedirect(route('client-reports.templates.index'))
            ->assertSessionHas('status', "Template 'Speed & Uptime Only' created successfully.");

        $template = ClientReportTemplate::where('name', 'Speed & Uptime Only')->firstOrFail();
        expect($template->sections)->toBe(['uptime', 'performance'])
            ->and($template->is_default)->toBeFalse();
    });

    it('clears previous default when setting a new default template', function () {
        $user = User::factory()->create();

        $oldDefault = ClientReportTemplate::query()->where('is_default', true)->firstOrFail();

        $response = $this->actingAs($user)->post(route('client-reports.templates.store'), [
            'name' => 'New Agency Default',
            'sections' => ['updates', 'uptime', 'security'],
            'is_default' => 1,
        ]);

        $response->assertRedirect(route('client-reports.templates.index'));

        $newDefault = ClientReportTemplate::where('name', 'New Agency Default')->firstOrFail();
        expect($newDefault->is_default)->toBeTrue();
        expect($oldDefault->fresh()->is_default)->toBeFalse();
    });

    it('validates that at least one valid section is selected', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('client-reports.templates.store'), [
            'name' => 'Empty Template',
            'sections' => [],
        ]);

        $response->assertSessionHasErrors(['sections']);
    });

    it('updates an existing template', function () {
        $user = User::factory()->create();
        $template = ClientReportTemplate::create([
            'name' => 'Old Name',
            'sections' => ['updates'],
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)->put(route('client-reports.templates.update', $template), [
            'name' => 'Updated Name',
            'sections' => ['updates', 'backups'],
            'is_default' => 0,
        ]);

        $response->assertRedirect(route('client-reports.templates.index'))
            ->assertSessionHas('status', "Template 'Updated Name' updated successfully.");

        $template->refresh();
        expect($template->name)->toBe('Updated Name')
            ->and($template->sections)->toBe(['updates', 'backups']);
    });

    it('blocks deleting the default template', function () {
        $user = User::factory()->create();
        $default = ClientReportTemplate::query()->where('is_default', true)->firstOrFail();

        $response = $this->actingAs($user)->from(route('client-reports.templates.index'))
            ->delete(route('client-reports.templates.destroy', $default));

        $response->assertRedirect(route('client-reports.templates.index'))
            ->assertSessionHas('status_error', 'Cannot delete the default template. Set another template as default first.');

        expect(ClientReportTemplate::where('id', $default->id)->exists())->toBeTrue();
    });

    it('deletes a custom non-default template and nulls template_id on linked reports', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $template = ClientReportTemplate::create([
            'name' => 'Temporary Tier',
            'sections' => ['updates', 'uptime'],
            'is_default' => false,
        ]);

        $report = ClientReport::create([
            'site_id' => $site->id,
            'template_id' => $template->id,
            'title' => 'Report with template',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'sections_data' => [],
            'status' => 'generated',
        ]);

        $response = $this->actingAs($user)
            ->delete(route('client-reports.templates.destroy', $template));

        $response->assertRedirect(route('client-reports.templates.index'))
            ->assertSessionHas('status', "Template 'Temporary Tier' deleted.");

        expect(ClientReportTemplate::where('id', $template->id)->exists())->toBeFalse();
        $report->refresh();
        expect($report->template_id)->toBeNull();
    });
});

describe('Compiler & Generator Template Filtering', function () {
    it('compiler filters out unrequested sections when $sections is provided', function () {
        $site = Site::factory()->create();
        $compiler = app(ClientReportCompiler::class);

        $data = $compiler->compile(
            site: $site,
            periodStart: now()->subMonth(),
            periodEnd: now(),
            sections: ['uptime', 'security']
        );

        expect($data)->toHaveKey('meta')
            ->and($data)->toHaveKey('branding')
            ->and($data)->toHaveKey('site')
            ->and($data)->toHaveKey('uptime')
            ->and($data)->toHaveKey('security')
            ->and($data)->not->toHaveKey('updates')
            ->and($data)->not->toHaveKey('performance')
            ->and($data)->not->toHaveKey('forms')
            ->and($data)->not->toHaveKey('traffic')
            ->and($data)->not->toHaveKey('backups');
    });

    it('generate endpoint respects selected template and records template_id', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $template = ClientReportTemplate::create([
            'name' => 'Uptime Only',
            'sections' => ['uptime'],
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)->post(route('client-reports.generate'), [
            'site_id' => $site->id,
            'template_id' => $template->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);

        $report = ClientReport::where('site_id', $site->id)->firstOrFail();
        $response->assertRedirect(route('client-reports.show', $report));

        expect($report->template_id)->toBe($template->id)
            ->and($report->sections_data)->toHaveKey('uptime')
            ->and($report->sections_data)->not->toHaveKey('updates')
            ->and($report->sections_data)->not->toHaveKey('security');
    });
});
