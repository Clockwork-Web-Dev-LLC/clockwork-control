<?php

use App\Jobs\AbstractRunUpdate;
use App\Jobs\PurgeSiteCacheJob;
use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Cache\SiteCachePurger;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Pressable\PressableClient;

describe('SiteCachePurger', function () {
    it('purges Pressable edge and object cache', function () {
        $site = Site::factory()->pressable()->create();

        $pressable = Mockery::mock(PressableClient::class);
        $pressable->shouldReceive('isViewOnly')->andReturn(false);
        $pressable->shouldReceive('purgeEdgeCache')->once()->with($site->pressable_site_id);
        $pressable->shouldReceive('flushObjectCache')->once()->with($site->pressable_site_id);
        app()->instance(PressableClient::class, $pressable);

        $result = app(SiteCachePurger::class)->purge($site);

        expect($result['layers']['pressable']['ok'])->toBeTrue();
    });

    it('purges Cloudflare homepage URLs when proxied and write-configured', function () {
        $site = Site::factory()->create([
            'domain' => 'shop.example.test',
            'cloudflare_state' => Site::CF_PROXIED,
        ]);

        $cf = Mockery::mock(CloudflareClient::class);
        $cf->shouldReceive('isWriteConfigured')->andReturn(true);
        $cf->shouldReceive('zoneByName')->andReturn(['id' => 'zone-1']);
        $cf->shouldReceive('purgeCacheFiles')->once()->with('zone-1', Mockery::on(function (array $files) {
            return in_array('https://shop.example.test/', $files, true);
        }));
        app()->instance(CloudflareClient::class, $cf);

        $result = app(SiteCachePurger::class)->purge($site);

        expect($result['layers']['cloudflare']['ok'])->toBeTrue();
    });
});

describe('post-update cache purge timing', function () {
    it('dispatches one purge when the last live job for the site completes successfully', function () {
        Queue::fake();

        $site = Site::factory()->create(['companion_installed' => true]);
        $batch = (string) Str::uuid();

        $done = PluginUpdateJob::factory()->create([
            'site_id' => $site->id,
            'batch_id' => $batch,
            'status' => PluginUpdateJob::STATUS_COMPLETE,
        ]);
        $pending = PluginUpdateJob::factory()->create([
            'site_id' => $site->id,
            'batch_id' => $batch,
            'status' => PluginUpdateJob::STATUS_PENDING,
        ]);

        $job = new class($pending->id) extends AbstractRunUpdate
        {
            protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array
            {
                return ['ok' => true, 'before_version' => '1.0.0', 'after_version' => '1.0.1', 'upgrade_completed' => true];
            }

            protected function actionLogType(): string
            {
                return ActionLog::TYPE_PLUGIN_UPDATE;
            }

            protected function actionLogTarget(PluginUpdateJob $row): ?string
            {
                return 'demo/demo.php';
            }

            protected function actionLogSummary(PluginUpdateJob $row, array $result): string
            {
                return 'updated';
            }
        };

        Http::fake();
        $job->handle(app(ActionLogger::class));

        Queue::assertPushed(PurgeSiteCacheJob::class, 1);
        expect($done->refresh()->status)->toBe(PluginUpdateJob::STATUS_COMPLETE);
    });
});
