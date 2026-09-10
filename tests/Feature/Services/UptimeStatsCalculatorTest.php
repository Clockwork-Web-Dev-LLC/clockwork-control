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

    it('does not penalize uptime percentage for SLA exempt (Not Our Fault) down events', function () {
        $site = Site::factory()->create();
        $calc = new UptimeStatsCalculator;
        $now = now();

        // Site went down 15 hours ago due to client DNS and is currently down, but marked SLA exempt
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'is_sla_exempt' => true,
            'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
            'event_at' => $now->copy()->subHours(15),
        ]);

        $uptime24h = $calc->siteUptime($site, $now->copy()->subDay(), $now);

        // Not our fault: 100% rating preserved!
        expect($uptime24h)->toBe(100.0);
    });

    it('only penalizes legitimate downtime when mixed with Not Our Fault downtime', function () {
        $site = Site::factory()->create();
        $calc = new UptimeStatsCalculator;
        $now = now();

        // 1. Legit downtime 20h ago lasting 1h
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'is_sla_exempt' => false,
            'event_at' => $now->copy()->subHours(20),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'event_at' => $now->copy()->subHours(19),
        ]);

        // 2. Client DNS downtime 15h ago lasting 10h (SLA exempt)
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'is_sla_exempt' => true,
            'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
            'event_at' => $now->copy()->subHours(15),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'event_at' => $now->copy()->subHours(5),
        ]);

        $uptime24h = $calc->siteUptime($site, $now->copy()->subDay(), $now);

        // 23h up out of 24h = 95.83%
        expect($uptime24h)->toBe(95.83);
    });

    it('preserves 100% bulk uptime across multiple sites with SLA exempt outages', function () {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $calc = new UptimeStatsCalculator;
        $now = now();

        // Site A is down due to client DNS (SLA exempt)
        SiteUptimeEvent::create([
            'site_id' => $siteA->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'is_sla_exempt' => true,
            'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
            'event_at' => $now->copy()->subHours(12),
        ]);

        // Site B is 100% up
        SiteUptimeEvent::create([
            'site_id' => $siteB->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'event_at' => $now->copy()->subDays(2),
        ]);

        $bulk = $calc->bulkUptime(collect([$siteA, $siteB]), $now->copy()->subDay(), $now);

        expect($bulk[$siteA->id])->toBe(100.0)
            ->and($bulk[$siteB->id])->toBe(100.0);
    });
});
