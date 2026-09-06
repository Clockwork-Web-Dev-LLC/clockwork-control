<?php

namespace Tests\Unit\Models;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteDomainExpirationTest extends TestCase
{
    public function test_state_is_none_when_no_expiration_date(): void
    {
        $site = new Site;
        $site->domain_expires_at = null;

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_NONE, $site->domainExpirationState());
        $this->assertSame('No domain data', $site->domainExpirationStateLabel());
    }

    public function test_state_is_green_when_more_than_30_days_left(): void
    {
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->addDays(45);

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_GREEN, $site->domainExpirationState());
        $this->assertSame('Domain OK', $site->domainExpirationStateLabel());
    }

    public function test_state_is_yellow_when_between_8_and_30_days_left(): void
    {
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->addDays(20);

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_YELLOW, $site->domainExpirationState());
        $this->assertSame('Domain renewal', $site->domainExpirationStateLabel());
    }

    public function test_state_is_red_when_7_or_fewer_days_left(): void
    {
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->addDays(5);

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());
        $this->assertSame('Domain expiring', $site->domainExpirationStateLabel());
    }

    public function test_state_is_red_when_expired(): void
    {
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->subDay();

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());
        $this->assertSame('Domain expiring', $site->domainExpirationStateLabel());
    }

    public function test_state_is_red_on_redemption_or_pending_delete_status(): void
    {
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->addDays(120); // normally green
        $site->domain_rdap_status = 'redemptionPeriod';

        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());

        $site->domain_rdap_status = 'pendingDelete';
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());
    }

    public function test_state_is_red_on_the_iana_rdap_status_registry_format(): void
    {
        // Real registries return this in lowercase, space-separated form
        // (the actual IANA RDAP JSON status-registry values), not the
        // legacy EPP camelCase form covered above — both must be caught.
        $site = new Site;
        $site->domain_expires_at = Carbon::now()->addDays(120); // normally green

        $site->domain_rdap_status = 'redemption period';
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());

        $site->domain_rdap_status = 'pending delete';
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());

        $site->domain_rdap_status = 'PENDING DELETE';
        $this->assertSame(Site::DOMAIN_EXPIRATION_STATE_RED, $site->domainExpirationState());
    }
}
