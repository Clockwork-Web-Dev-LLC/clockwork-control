<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeEvent;

describe('Site current-outage SLA exemption', function () {
    it('is false when the site is not down', function () {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => Server::factory()->create()->id,
            'uptime_state' => 'up',
            'uptime_sla_exempt' => true,
        ]);

        expect($site->isCurrentOutageSlaExempt())->toBeFalse();
    });

    it('is true from standing site policy while down', function () {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => Server::factory()->create()->id,
            'uptime_state' => 'down',
            'uptime_sla_exempt' => true,
        ]);

        expect($site->isCurrentOutageSlaExempt())->toBeTrue();
    });

    it('is true from the latest down event without standing site policy', function () {
        $site = Site::factory()->spinupwp()->create([
            'server_id' => Server::factory()->create()->id,
            'uptime_state' => 'down',
            'uptime_sla_exempt' => false,
        ]);

        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subHour(),
            'is_sla_exempt' => true,
            'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
        ]);

        expect($site->isCurrentOutageSlaExempt())->toBeTrue()
            ->and($site->latestDownEvent()?->exemption_reason)->toBe(SiteUptimeEvent::REASON_CLIENT_DNS);
    });
});
