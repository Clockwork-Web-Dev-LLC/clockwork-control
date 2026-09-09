<?php

use App\Services\Process\BackgroundArtisan;
use App\Services\Process\DetachedShell;
use Illuminate\Support\Facades\Cache;

describe('BackgroundArtisan', function () {
    beforeEach(function () {
        Cache::flush();
    });

    it('locks, launches a detached nohup artisan command, and reports started', function () {
        $this->mock(DetachedShell::class, function ($mock) {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function (string $cmd) {
                    return str_contains($cmd, 'nohup')
                        && str_contains($cmd, 'artisan clockwork:check-site-uptime')
                        && str_contains($cmd, 'artisan cache:forget')
                        && str_contains($cmd, '< /dev/null')
                        && str_contains($cmd, '> /dev/null 2>&1');
                });
        });

        $result = app(BackgroundArtisan::class)->start(
            'test.uptime',
            ['clockwork:check-site-uptime'],
            60,
            'test-uptime-bg',
        );

        expect($result->started())->toBeTrue()
            ->and(Cache::has('test.uptime'))->toBeTrue();
    });

    it('chains multiple artisan commands with &&', function () {
        $this->mock(DetachedShell::class, function ($mock) {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(fn (string $cmd) => str_contains($cmd, 'clockwork:import-spinupwp')
                    && str_contains($cmd, '&&')
                    && str_contains($cmd, 'clockwork:poll-servers'));
        });

        expect(app(BackgroundArtisan::class)->start(
            'test.import',
            ['clockwork:import-spinupwp', 'clockwork:poll-servers'],
        )->started())->toBeTrue();
    });

    it('does not launch a second job while the lock is held', function () {
        Cache::put('test.lock', now()->toIso8601String(), 60);

        $this->mock(DetachedShell::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        expect(app(BackgroundArtisan::class)->start('test.lock', ['clockwork:poll-servers'])->alreadyRunning())->toBeTrue();
    });

    it('rejects an empty command list', function () {
        $this->mock(DetachedShell::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        $result = app(BackgroundArtisan::class)->start('test.empty', []);

        expect($result->failed())->toBeTrue()
            ->and(Cache::has('test.empty'))->toBeFalse();
    });
});
