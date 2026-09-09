<?php

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Services\Uptime\UptimeStatsCalculator;

describe('UptimeStatsCalculator', function () {
    it('does not penalize uptime percentage for scheduled maintenance windows', function () {
        $site = Site::factory()->create();
        $calc = new UptimeStatsCalculator;
        $now = now();

        // Site entered maintenance 2 hours ago and exited 1 hour ago
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_MAINTENANCE,
            'event_at' => $now->copy()->subHours(2),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'event_at' => $now->copy()->subHours(1),
        ]);

        $uptime24h = $calc->siteUptime($site, $now->copy()->subDay(), $now);

        // Maintenance should not be counted as downtime
        expect($uptime24h)->toBe(100.0);
    });

    it('penalizes uptime percentage for down events', function () {
        $site = Site::factory()->create();
        $calc = new UptimeStatsCalculator;
        $now = now();

        // Site went down 2 hours ago and recovered 1 hour ago (1 hour downtime out of 24h = ~95.83%)
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => $now->copy()->subHours(2),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'event_at' => $now->copy()->subHours(1),
        ]);

        $uptime24h = $calc->siteUptime($site, $now->copy()->subDay(), $now);

        expect($uptime24h)->toBeLessThan(100.0);
    });
});
