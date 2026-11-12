<?php

namespace Modules\Seo\Setup;

use App\Support\Settings;
use Illuminate\Support\Facades\Route;
use Modules\Core\ModuleStateResolver;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;

/**
 * What a new install still has to do before the SEO suite is useful, in
 * the order to do it. Shown on the Clients page until everything needed is
 * done. Reads local state only: it never calls Google or DataForSEO.
 */
class SetupChecklist
{
    public function __construct(
        private readonly GoogleOAuth $google,
        private readonly DataForSeoClient $dataForSeo,
        private readonly Settings $settings,
        private readonly ModuleStateResolver $modules,
    ) {}

    /**
     * @return list<array{key: string, title: string, detail: string, done: bool, optional: bool, admin: bool, url: ?string, action: string}>
     */
    public function steps(): array
    {
        $connection = GoogleConnection::current();
        $steps = [];

        $steps[] = [
            'key' => 'google_client',
            'title' => 'Add your Google OAuth client',
            'detail' => 'A client ID and secret from your own Google Cloud project, so Control can read Search Console and GA4. Free.',
            'done' => $this->google->isConfigured(),
            'optional' => false,
            'admin' => true,
            'url' => Route::has('settings.integrations.index') ? route('settings.integrations.index') : null,
            'action' => 'Open Integrations',
        ];

        $steps[] = [
            'key' => 'google_connection',
            'title' => 'Connect your agency Google account',
            'detail' => $connection?->isRevoked()
                ? 'The connection was revoked. Reconnect the same account to resume syncing.'
                : 'Sign in once as a shared account that can already see your clients\' Search Console and GA4 properties.',
            'done' => $connection !== null && ! $connection->isRevoked(),
            'optional' => false,
            'admin' => true,
            'url' => route('seo.settings'),
            'action' => 'Open SEO settings',
        ];

        $steps[] = [
            'key' => 'dataforseo',
            'title' => 'Add your DataForSEO API login',
            'detail' => 'Pay-as-you-go keyword data: volume, difficulty, CPC and intent. Skip it if you only want Search Console and GA4 numbers.',
            'done' => $this->dataForSeo->isConfigured(),
            'optional' => true,
            'admin' => true,
            'url' => Route::has('settings.integrations.index') ? route('settings.integrations.index') : null,
            'action' => 'Open Integrations',
        ];

        $steps[] = [
            'key' => 'first_client',
            'title' => 'Add your first client',
            'detail' => 'A domain is enough. Control finds its Search Console property and pulls 16 months of history.',
            'done' => SiteProfile::query()->exists(),
            'optional' => false,
            'admin' => false,
            'url' => route('seo.clients.create'),
            'action' => 'Add a client',
        ];

        if ($this->modules->isEnabled('mcp') && Route::has('settings.claude-access.index')) {
            $steps[] = [
                'key' => 'claude',
                'title' => 'Let Claude read the numbers',
                'detail' => 'Connect Claude to Control so it can pull report numbers and keyword data itself.',
                'done' => (bool) $this->settings->get('mcp.enabled', false) || filled(config('clockwork.mcp.local_user')),
                'optional' => true,
                'admin' => true,
                'url' => route('settings.claude-access.index'),
                'action' => 'Open Claude access',
            ];
        }

        return $steps;
    }

    /** True once every step that isn't optional is done. */
    public function complete(): bool
    {
        foreach ($this->steps() as $step) {
            if (! $step['optional'] && ! $step['done']) {
                return false;
            }
        }

        return true;
    }
}
