<?php

use App\Models\Site;
use App\Models\SiteWorkLog;
use App\Models\User;
use Modules\ClientReports\Services\ClientReportCompiler;

describe('Site work logs', function () {
    it('stores a work log on the site', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $this->actingAs($user)
            ->post(route('sites.work-logs.store', $site), [
                'worked_on' => '2026-09-01',
                'hours' => '1.50',
                'description' => 'Fixed Stripe checkout webhook',
            ])
            ->assertRedirect();

        $log = SiteWorkLog::query()->where('site_id', $site->id)->first();
        expect($log)->not->toBeNull()
            ->and((float) $log->hours)->toBe(1.5)
            ->and($log->user_id)->toBe($user->id)
            ->and($log->description)->toContain('Stripe');
    });

    it('compiles work logs into the client report period', function () {
        $site = Site::factory()->create();
        SiteWorkLog::factory()->create([
            'site_id' => $site->id,
            'worked_on' => '2026-09-02',
            'hours' => '2.00',
            'description' => 'Theme header fix',
        ]);
        SiteWorkLog::factory()->create([
            'site_id' => $site->id,
            'worked_on' => '2026-08-01',
            'hours' => '5.00',
            'description' => 'Outside the window',
        ]);

        $data = app(ClientReportCompiler::class)->compile(
            $site,
            now()->setDate(2026, 9, 1),
            now()->setDate(2026, 9, 30),
            sections: ['work_log'],
        );

        expect($data['work_log']['total_hours'])->toBe(2.0)
            ->and($data['work_log']['entries'])->toHaveCount(1)
            ->and($data['work_log']['entries'][0]['description'])->toBe('Theme header fix');
    });
});
