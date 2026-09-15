<?php

use App\Models\Server;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Support\IssueCategoryConfig;
use App\Support\IssueCounter;
use App\Support\Settings;

describe('IssueCategoryConfig', function () {
    it('provides defaults for all 23 fleet issue categories', function () {
        $config = app(IssueCategoryConfig::class);
        $defaults = $config->defaults();

        expect($defaults)->toBeArray()
            ->toHaveCount(23)
            ->toHaveKeys([
                'down_sites',
                'scheduler_stale',
                'malware',
                'companion_malware',
                'tampering',
                'health',
                'forms_failing',
                'stuck_maintenance',
                'seo-indexability',
                'ssl',
                'hot',
                'domain-expiration',
                'reboot',
                'patches',
                'cf',
                'no_ssh',
                'no_jail',
                'no_db',
                'no_companion',
                'orphans',
                'plugins_outdated',
                'plugins_closed',
                'wp_admins',
            ]);

        // Verify sensible emergency defaults vs routine defaults
        expect($defaults['down_sites'])->toBe(IssueCategoryConfig::LEVEL_PRESSING);
        expect($defaults['scheduler_stale'])->toBe(IssueCategoryConfig::LEVEL_PRESSING);
        expect($defaults['malware'])->toBe(IssueCategoryConfig::LEVEL_PRESSING);
        expect($defaults['health'])->toBe(IssueCategoryConfig::LEVEL_PRESSING);

        expect($defaults['plugins_outdated'])->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($defaults['plugins_closed'])->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($defaults['wp_admins'])->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($defaults['reboot'])->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
    });

    it('retrieves effective levels with normalization', function () {
        $config = app(IssueCategoryConfig::class);

        expect($config->getLevel('domain-expiration'))->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($config->getLevel('domain_expiration'))->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);

        expect($config->getLevel('seo-indexability'))->toBe(IssueCategoryConfig::LEVEL_PRESSING);
        expect($config->getLevel('seo_indexability'))->toBe(IssueCategoryConfig::LEVEL_PRESSING);

        expect($config->isPressing('down_sites'))->toBeTrue();
        expect($config->isNotPressing('plugins_outdated'))->toBeTrue();
        expect($config->isOff('plugins_outdated'))->toBeFalse();
    });

    it('persists and updates individual category levels', function () {
        $config = app(IssueCategoryConfig::class);

        $config->setLevel('plugins_outdated', IssueCategoryConfig::LEVEL_OFF);
        expect($config->getLevel('plugins_outdated'))->toBe(IssueCategoryConfig::LEVEL_OFF);
        expect($config->isOff('plugins_outdated'))->toBeTrue();

        $config->setLevel('plugins_outdated', IssueCategoryConfig::LEVEL_PRESSING);
        expect($config->getLevel('plugins_outdated'))->toBe(IssueCategoryConfig::LEVEL_PRESSING);
        expect($config->isPressing('plugins_outdated'))->toBeTrue();
    });

    it('throws exception on invalid level in setLevel', function () {
        $config = app(IssueCategoryConfig::class);
        $config->setLevel('plugins_outdated', 'bogus_level');
    })->throws(InvalidArgumentException::class);

    it('supports bulk saving and resetting to defaults', function () {
        $config = app(IssueCategoryConfig::class);

        $config->saveLevels([
            'plugins_outdated' => IssueCategoryConfig::LEVEL_OFF,
            'plugins_closed' => IssueCategoryConfig::LEVEL_OFF,
            'hot' => IssueCategoryConfig::LEVEL_PRESSING,
        ]);

        expect($config->isOff('plugins_outdated'))->toBeTrue();
        expect($config->isOff('plugins_closed'))->toBeTrue();
        expect($config->isPressing('hot'))->toBeTrue();

        $config->resetToDefaults();

        expect($config->getLevel('plugins_outdated'))->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($config->getLevel('plugins_closed'))->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
        expect($config->getLevel('hot'))->toBe(IssueCategoryConfig::LEVEL_NOT_PRESSING);
    });

    it('integrates with IssueCounter to calculate pressing vs all totals', function () {
        $counter = app(IssueCounter::class);
        $config = app(IssueCategoryConfig::class);

        // Reset config
        $config->resetToDefaults();

        // Stale scheduler is PRESSING by default
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(12)->toIso8601String()
        );

        // Reboot required is NOT_PRESSING by default
        Server::factory()->create([
            'is_ignored' => false,
            'reboot_required' => true,
        ]);

        $pressingTotal = $counter->total(); // default: pressing
        $allTotal = $counter->total('all'); // all enabled (pressing + not_pressing)

        expect($pressingTotal)->toBeGreaterThanOrEqual(1);
        expect($allTotal)->toBeGreaterThan($pressingTotal);

        // Now turn OFF reboot category completely
        $config->setLevel('reboot', IssueCategoryConfig::LEVEL_OFF);
        $allAfterMute = $counter->total('all');
        expect($allAfterMute)->toBeLessThan($allTotal);

        // Now turn OFF scheduler_stale
        $config->setLevel('scheduler_stale', IssueCategoryConfig::LEVEL_OFF);
        $pressingAfterMute = $counter->total();
        expect($pressingAfterMute)->toBe($pressingTotal - 1);
    });
});
