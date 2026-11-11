<?php

use App\Models\IntegrationCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Core\ModuleRegistry;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Mcp\Tools\ControlWhoAmI;
use Modules\Seo\Models\GoogleConnection;
use Tests\Fixtures\SeoStoredData;

/**
 * Seeds recognisable secrets everywhere a tool could plausibly reach, calls
 * every registered tool, and checks none of them comes back.
 */
it('never returns a credential from any tool', function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));

    $server = Server::factory()->create(['ssh_private_key' => 'CANARY-ssh-private-key']);
    $profile = SeoStoredData::profile('acme.com', ['last_sync_error' => 'Search Console: quota']);
    Site::query()->whereKey($profile->site_id)->update([
        'server_id' => $server->id,
        'db_password' => 'CANARY-db-password',
        'db_user' => 'CANARY-db-user',
        'companion_secret' => 'CANARY-companion-secret',
    ]);
    IntegrationCredential::query()->create(['integration' => 'seo', 'key' => 'google_client_secret', 'value' => 'CANARY-client-secret']);
    GoogleConnection::query()->create([
        'account_email' => 'systems@example.com', 'refresh_token' => 'CANARY-refresh-token',
        'status' => 'error', 'last_error' => 'temporarily unavailable',
    ]);
    SeoStoredData::gscMonth($profile->site_id, '2026-10', 100, 2000, 8.0, nonbrandedClicks: 40);
    SeoStoredData::ga4Month($profile->site_id, '2026-10');
    SeoStoredData::row($profile->site_id, '2026-10', 'query', null, 'acme plumbing', 40, 200, branded: true);
    SeoStoredData::row($profile->site_id, '2026-10', 'page', 'https://www.acme.com/', null, 40, 200);

    $user = User::factory()->admin()->create(['password' => bcrypt('CANARY-user-password'), 'remember_token' => 'CANARY-remember']);

    $arguments = [
        'control_whoami' => [],
        'seo_list_sites' => [],
        'seo_month_numbers' => ['site' => 'acme.com', 'month' => '2026-10', 'include' => ['top_pages', 'top_queries', 'trend', 'channels']],
        'seo_daily_series' => ['site' => 'acme.com', 'series' => ['clicks', 'impressions', 'ctr', 'position', 'organic_sessions', 'ai_assistant_sessions'], 'yoy' => true],
        'seo_search_rows' => ['site' => 'acme.com', 'dimension' => 'page_query', 'end_month' => '2026-10', 'compare' => 'yoy'],
        'seo_ai_impressions_record' => ['site' => 'acme.com', 'month' => '2026-10', 'status' => 'entered', 'ai_impressions' => 10],
        'seo_site_profile_update' => ['site' => 'acme.com', 'brand_terms' => ['acme', 'apex'], 'confirm_purge' => true],
    ];

    $tools = [ControlWhoAmI::class, ...app(ModuleRegistry::class)->mcpTools()];
    $called = [];

    foreach ($tools as $class) {
        $name = app($class)->name();
        // A new tool must be added here before it ships.
        expect($arguments)->toHaveKey($name);

        $response = ClockworkServer::actingAs($user)->tool($class, $arguments[$name])->assertOk();
        $json = json_encode((fn () => $this->response->toArray())->call($response));

        expect($json)->not->toContain('CANARY')
            ->and($json)->not->toContain('ssh_private_key')
            ->and($json)->not->toContain('db_password');
        $called[] = $name;
    }

    expect($called)->toHaveCount(7);
});
