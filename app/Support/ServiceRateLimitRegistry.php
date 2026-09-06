<?php

namespace App\Support;

class ServiceRateLimitRegistry
{
    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Complete dictionary of official rate limits, response headers, docs URLs,
     * fleet polling costs, and default tunables for every supported service.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'digitalocean' => [
                'id' => 'digitalocean',
                'name' => 'DigitalOcean',
                'category' => 'Cloud VPS',
                'docs_url' => 'https://docs.digitalocean.com/reference/api/api-reference/',
                'rate_limit_docs_url' => 'https://docs.digitalocean.com/reference/api/api-reference/#section/Rate-Limits',
                'official_limits' => [
                    'standard' => '5,000 requests / hour',
                    'window' => 'Rolling 1-hour window per Personal Access Token across all API v2 endpoints.',
                    'headers' => ['RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Evaluated on an hourly basis. Bursts without spacing can trigger network security rate-limiting.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '4–5 API calls per droplet per monitoring cycle (CPU, Memory, Disk, Load).',
                    'fleet_projection' => '20 droplets polled every 5 min = ~1,200 req/hr (24% of quota). 50 droplets = ~3,000 req/hr (60% of quota). 80+ droplets requires pacing.',
                    'recommendation' => 'Set inter-request delay to 50–100ms when polling 25+ droplets to prevent burst throttles.',
                ],
                'defaults' => [
                    'rate_limit' => 5000,
                    'rate_limit_unit' => 'requests / hour',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 50,
                    'retry_attempts' => 2,
                ],
            ],

            'hetzner' => [
                'id' => 'hetzner',
                'name' => 'Hetzner Cloud',
                'category' => 'Cloud VPS',
                'docs_url' => 'https://docs.hetzner.cloud/',
                'rate_limit_docs_url' => 'https://docs.hetzner.cloud/#rate-limiting',
                'official_limits' => [
                    'standard' => '3,600 requests / hour (~60 req/min)',
                    'window' => 'Hourly rolling window with per-second burst dampening per API project token.',
                    'headers' => ['RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Strict 429 response when the project token exceeds 3,600 calls/hr.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1–2 API calls per server for telemetry metrics and power status.',
                    'fleet_projection' => '20 servers = ~240–480 req/hr (well within 3,600 cap).',
                    'recommendation' => 'Keep timeout at 15s and concurrency at 2 for stable polling.',
                ],
                'defaults' => [
                    'rate_limit' => 3600,
                    'rate_limit_unit' => 'requests / hour',
                    'timeout' => 15,
                    'concurrency' => 2,
                    'delay_ms' => 100,
                    'retry_attempts' => 2,
                ],
            ],

            'linode' => [
                'id' => 'linode',
                'name' => 'Linode (Akamai)',
                'category' => 'Cloud VPS',
                'docs_url' => 'https://techdocs.akamai.com/linode-api/reference/api-rate-limits',
                'rate_limit_docs_url' => 'https://techdocs.akamai.com/linode-api/reference/api-rate-limits',
                'official_limits' => [
                    'standard' => '800 requests / minute',
                    'window' => 'Per-minute rolling bucket per user/token across API v4.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests (or 400 with rate limit body)',
                    'burst_notes' => 'Very generous limits for read queries; write endpoints (rebuilds/reboots) have separate internal limits.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1 API call per instance for stats history.',
                    'fleet_projection' => 'Even a 100-instance fleet consumes < 15% of the 800 req/min limit.',
                    'recommendation' => 'Pacing delay can stay low (25ms) with high concurrency (4).',
                ],
                'defaults' => [
                    'rate_limit' => 800,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 4,
                    'delay_ms' => 25,
                    'retry_attempts' => 2,
                ],
            ],

            'vultr' => [
                'id' => 'vultr',
                'name' => 'Vultr',
                'category' => 'Cloud VPS',
                'docs_url' => 'https://www.vultr.com/api/',
                'rate_limit_docs_url' => 'https://www.vultr.com/api/#section/Rate-Limits',
                'official_limits' => [
                    'standard' => '30 requests / second (~1,800 req/min)',
                    'window' => 'Per-second rate throttling per API Key.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Allows strong burst bursts up to 30 req/sec before throttling occurs.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1–2 API calls per VPS instance for telemetry and bandwidth.',
                    'fleet_projection' => 'Safe for high concurrency up to 5 parallel workers.',
                    'recommendation' => 'Keep concurrency at 3–4 with 50ms delay between instances.',
                ],
                'defaults' => [
                    'rate_limit' => 1800,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 50,
                    'retry_attempts' => 2,
                ],
            ],

            'azure' => [
                'id' => 'azure',
                'name' => 'Azure (Microsoft Cloud)',
                'category' => 'Cloud VPS',
                'docs_url' => 'https://learn.microsoft.com/en-us/azure/azure-resource-manager/management/request-limits-and-throttling',
                'rate_limit_docs_url' => 'https://learn.microsoft.com/en-us/azure/azure-resource-manager/management/request-limits-and-throttling',
                'official_limits' => [
                    'standard' => '12,000 read requests / hour',
                    'window' => 'Per subscription per region. Azure Monitor metrics API limits to 12,000 req/min.',
                    'headers' => ['x-ms-ratelimit-remaining-subscription-reads', 'Retry-After'],
                    'exceeded_code' => '429 Too Many Requests with Retry-After header',
                    'burst_notes' => 'Exceeding limit returns Retry-After duration in seconds.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '2 calls per VM (Resource Manager + Azure Monitor metrics).',
                    'fleet_projection' => 'Batch queries should be throttled to prevent resource group contention.',
                    'recommendation' => 'Set delay to 100ms and respect Retry-After header.',
                ],
                'defaults' => [
                    'rate_limit' => 12000,
                    'rate_limit_unit' => 'requests / hour',
                    'timeout' => 15,
                    'concurrency' => 2,
                    'delay_ms' => 100,
                    'retry_attempts' => 3,
                ],
            ],

            'spinupwp' => [
                'id' => 'spinupwp',
                'name' => 'SpinupWP',
                'category' => 'Control Panel',
                'docs_url' => 'https://spinupwp.com/doc/api/',
                'rate_limit_docs_url' => 'https://spinupwp.com/doc/api/#rate-limits',
                'official_limits' => [
                    'standard' => '60 requests / minute (1 req/sec)',
                    'window' => 'Per-minute rate limit per API token.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Designed for 1 req/sec average with short burst allowance.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Syncing sites, databases, and servers traverses paginated endpoints.',
                    'fleet_projection' => 'Full inventory sync across 50+ sites can take 30–60 seconds.',
                    'recommendation' => 'Add 150–250ms sleep between paginated page fetches to avoid 429 lockouts.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 2,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'pressable' => [
                'id' => 'pressable',
                'name' => 'Pressable',
                'category' => 'Managed Host',
                'docs_url' => 'https://my.pressable.com/api',
                'rate_limit_docs_url' => 'https://my.pressable.com/api',
                'official_limits' => [
                    'standard' => '100 requests / minute',
                    'window' => 'Rolling per-minute quota per OAuth client.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Backup endpoints (/backups/fs, /backups/db) query heavy storage databases.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Separate queries for filesystem and database backup history.',
                    'fleet_projection' => 'Syncing backup history across 40 sites makes ~80 API calls.',
                    'recommendation' => 'Throttle backup depth sync with 100ms inter-request delay.',
                ],
                'defaults' => [
                    'rate_limit' => 100,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 2,
                    'delay_ms' => 100,
                    'retry_attempts' => 2,
                ],
            ],

            'wpengine' => [
                'id' => 'wpengine',
                'name' => 'WP Engine',
                'category' => 'Managed Host',
                'docs_url' => 'https://wpengineapi.com/',
                'rate_limit_docs_url' => 'https://wpengineapi.com/#rate-limiting',
                'official_limits' => [
                    'standard' => '600 requests / minute (10 req/sec)',
                    'window' => 'Per user token across the WP Engine Developer API.',
                    'headers' => ['X-RateLimit-Remaining', 'Retry-After'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'High capacity designed for large agency multi-account deployments.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Inventory sync pulls installs, domains, and PHP versions in bulk.',
                    'fleet_projection' => 'Fast sync even for fleets with 100+ installs.',
                    'recommendation' => 'Concurrency 3, 50ms delay.',
                ],
                'defaults' => [
                    'rate_limit' => 600,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 50,
                    'retry_attempts' => 2,
                ],
            ],

            'kinsta' => [
                'id' => 'kinsta',
                'name' => 'Kinsta',
                'category' => 'Managed Host',
                'docs_url' => 'https://kinsta.com/docs/kinsta-api/',
                'rate_limit_docs_url' => 'https://kinsta.com/docs/kinsta-api/#rate-limits',
                'official_limits' => [
                    'standard' => '60 requests / minute (1 req/sec)',
                    'window' => 'Enforced per Company API Key.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Strict 1 request per second ceiling.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Site lists and environment details must be fetched sequentially.',
                    'fleet_projection' => 'Pacing prevents bursting into 429 penalties.',
                    'recommendation' => 'Maintain 300ms delay between consecutive calls.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 1,
                    'delay_ms' => 300,
                    'retry_attempts' => 2,
                ],
            ],

            'cloudways' => [
                'id' => 'cloudways',
                'name' => 'Cloudways',
                'category' => 'Control Panel',
                'docs_url' => 'https://developers.cloudways.com/docs/',
                'rate_limit_docs_url' => 'https://developers.cloudways.com/docs/',
                'official_limits' => [
                    'standard' => '30–100 requests / minute',
                    'window' => 'Per API key. Bearer token expires every 60 minutes.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Server power/resize actions are tightly throttled; reads allow up to 100/min.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Server list and application list sync.',
                    'fleet_projection' => 'Automated token refreshing on 401 response.',
                    'recommendation' => 'Concurrency 2, 200ms delay.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 2,
                    'delay_ms' => 200,
                    'retry_attempts' => 2,
                ],
            ],

            'gridpane' => [
                'id' => 'gridpane',
                'name' => 'GridPane',
                'category' => 'Control Panel',
                'docs_url' => 'https://gridpane.com/kb/',
                'rate_limit_docs_url' => 'https://gridpane.com/kb/',
                'official_limits' => [
                    'standard' => '60–120 requests / minute',
                    'window' => 'Recommended 1–2 requests per second.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'GridPane relies on asynchronous queued jobs for heavy operations.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Server and site sync calls.',
                    'fleet_projection' => 'Smooth inventory read.',
                    'recommendation' => 'Concurrency 1, 250ms delay.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 1,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'cloudflare' => [
                'id' => 'cloudflare',
                'name' => 'Cloudflare',
                'category' => 'DNS & Security',
                'docs_url' => 'https://developers.cloudflare.com/fundamentals/api/reference/limits/',
                'rate_limit_docs_url' => 'https://developers.cloudflare.com/fundamentals/api/reference/limits/',
                'official_limits' => [
                    'standard' => '1,200 requests per 5 minutes (240 req/min)',
                    'window' => 'Rolling 5-minute bucket per API token.',
                    'headers' => ['cf-ray', 'cf-cache-status'],
                    'exceeded_code' => '429 Too Many Requests (HTTP code 429)',
                    'burst_notes' => 'Cloudflare uses Leaky Bucket algorithm.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'DNS queries, SSL verification, and edge cache purges.',
                    'fleet_projection' => 'Diagnostics check runs 1–2 requests per domain.',
                    'recommendation' => 'Concurrency 4, 25ms delay.',
                ],
                'defaults' => [
                    'rate_limit' => 240,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 4,
                    'delay_ms' => 25,
                    'retry_attempts' => 2,
                ],
            ],

            'psi' => [
                'id' => 'psi',
                'name' => 'Google PageSpeed Insights',
                'category' => 'Performance',
                'docs_url' => 'https://developers.google.com/speed/docs/insights/v5/get-started',
                'rate_limit_docs_url' => 'https://developers.google.com/speed/docs/insights/v5/get-started#rate-limits',
                'official_limits' => [
                    'standard' => '25,000 queries / day (240 queries / min)',
                    'window' => 'Daily quota per Google Cloud API Key with 4 QPS burst.',
                    'headers' => ['x-goog-quota-user'],
                    'exceeded_code' => '429 RESOURCE_EXHAUSTED',
                    'burst_notes' => 'Without an API key, throttled to 1 QPS and very low daily limits.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Each synthetic audit exercises both mobile and desktop profiles.',
                    'fleet_projection' => 'Testing 100 sites daily uses 200 queries (0.8% of daily 25k quota).',
                    'recommendation' => 'Set timeout to 90s because Google Lighthouse takes 15–45s to execute remotely.',
                ],
                'defaults' => [
                    'rate_limit' => 240,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 90,
                    'concurrency' => 1,
                    'delay_ms' => 1000,
                    'retry_attempts' => 1,
                ],
            ],

            'gtmetrix' => [
                'id' => 'gtmetrix',
                'name' => 'GTmetrix',
                'category' => 'Performance',
                'docs_url' => 'https://gtmetrix.com/api/docs/2.0/',
                'rate_limit_docs_url' => 'https://gtmetrix.com/api/docs/2.0/#api-rate-limits',
                'official_limits' => [
                    'standard' => 'Credit-based monthly budget + 1–5 concurrent test slots',
                    'window' => 'Account tier determines concurrent slot availability and monthly API credits.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
                    'exceeded_code' => '429 (Concurrent slots full) or 402 (Credits exhausted)',
                    'burst_notes' => 'Tests queue if all browser execution slots are active.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1 test credit per audit.',
                    'fleet_projection' => 'Sequential execution recommended to avoid slot saturation.',
                    'recommendation' => 'Concurrency 1, timeout 120s.',
                ],
                'defaults' => [
                    'rate_limit' => 10,
                    'rate_limit_unit' => 'concurrent test slots',
                    'timeout' => 120,
                    'concurrency' => 1,
                    'delay_ms' => 2000,
                    'retry_attempts' => 1,
                ],
            ],

            'sucuri' => [
                'id' => 'sucuri',
                'name' => 'Sucuri SiteCheck',
                'category' => 'Security',
                'docs_url' => 'https://sitecheck.sucuri.net/',
                'rate_limit_docs_url' => 'https://sitecheck.sucuri.net/',
                'official_limits' => [
                    'standard' => '~30 requests / minute per IP',
                    'window' => 'Public unauthenticated scanner rate-limiting.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '403 Forbidden or 429 Too Many Requests',
                    'burst_notes' => 'IP-based rate limiter blocks bursts.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1 HTTP scan request per monitored website.',
                    'fleet_projection' => 'Artisan scan loop needs sequential delay.',
                    'recommendation' => 'Artisan loop sleeps 250ms between site audits; timeout 30s.',
                ],
                'defaults' => [
                    'rate_limit' => 30,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 30,
                    'concurrency' => 1,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'twilio' => [
                'id' => 'twilio',
                'name' => 'Twilio',
                'category' => 'Notifications',
                'docs_url' => 'https://www.twilio.com/docs/messaging/guidance/rate-limits-and-message-queuing',
                'rate_limit_docs_url' => 'https://www.twilio.com/docs/messaging/guidance/rate-limits-and-message-queuing',
                'official_limits' => [
                    'standard' => '1 message segment / second (1 MPS)',
                    'window' => 'Standard US 10DLC throughput. Registered brand campaigns support higher throughput.',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
                    'exceeded_code' => '429 Error 20429 (Too Many Requests)',
                    'burst_notes' => 'Messages sent faster than throughput are queued in Twilio buffers (up to 4 hours).',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Emergency SMS alerts dispatched to on-call engineer.',
                    'fleet_projection' => 'Burst alerts (multiple sites failing simultaneously) are paced.',
                    'recommendation' => 'Concurrency 1, delay 500ms.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'messages / minute',
                    'timeout' => 15,
                    'concurrency' => 1,
                    'delay_ms' => 500,
                    'retry_attempts' => 2,
                ],
            ],

            'slack' => [
                'id' => 'slack',
                'name' => 'Slack',
                'category' => 'Notifications',
                'docs_url' => 'https://api.slack.com/apis/rate-limits',
                'rate_limit_docs_url' => 'https://api.slack.com/apis/rate-limits',
                'official_limits' => [
                    'standard' => '1 message / second',
                    'window' => 'Per incoming webhook URL with short burst allowance.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '429 with Retry-After header',
                    'burst_notes' => 'Designed for real-time chat broadcasts.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Notification broadcasts on incident open/close.',
                    'fleet_projection' => 'Paced notifications to prevent 429 webhook lockouts.',
                    'recommendation' => 'Concurrency 1, timeout 10s.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'messages / minute',
                    'timeout' => 10,
                    'concurrency' => 1,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'mattermost' => [
                'id' => 'mattermost',
                'name' => 'Mattermost',
                'category' => 'Notifications',
                'docs_url' => 'https://mattermost.com/',
                'rate_limit_docs_url' => 'https://mattermost.com/',
                'official_limits' => [
                    'standard' => '60–100 requests / minute',
                    'window' => 'Configured by Mattermost server admin (RateLimitingSettings).',
                    'headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Self-hosted limits determined by your Mattermost instance config.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Webhook incident notifications.',
                    'fleet_projection' => 'Fast internal dispatch.',
                    'recommendation' => 'Concurrency 1, timeout 10s.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'messages / minute',
                    'timeout' => 10,
                    'concurrency' => 1,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'bill-com' => [
                'id' => 'bill-com',
                'name' => 'Bill.com',
                'category' => 'Agency Billing',
                'docs_url' => 'https://developer.bill.com/',
                'rate_limit_docs_url' => 'https://developer.bill.com/',
                'official_limits' => [
                    'standard' => '100 requests / minute',
                    'window' => 'Per dev key and active session.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => 'HTTP 429 / API error payload',
                    'burst_notes' => 'Session tokens expire after inactivity.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Invoice and customer sync for care plans.',
                    'fleet_projection' => 'Runs in periodic billing sync jobs.',
                    'recommendation' => 'Concurrency 2, timeout 30s.',
                ],
                'defaults' => [
                    'rate_limit' => 100,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 30,
                    'concurrency' => 2,
                    'delay_ms' => 200,
                    'retry_attempts' => 2,
                ],
            ],

            'auth_google' => [
                'id' => 'auth_google',
                'name' => 'Google',
                'category' => 'Authentication',
                'docs_url' => 'https://developers.google.com/identity/protocols/oauth2',
                'rate_limit_docs_url' => 'https://developers.google.com/identity/protocols/oauth2#usage-limits',
                'official_limits' => [
                    'standard' => 'Google Identity OAuth standard quotas',
                    'window' => 'Per-project rate limits in Google Cloud Console.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '429 / 403 rateLimitExceeded',
                    'burst_notes' => 'Standard OAuth 2.0 endpoints have high rate limits for user authentication.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1–2 API calls during operator Google OAuth sign-in flow.',
                    'fleet_projection' => 'Operator authentication consumes negligible quota.',
                    'recommendation' => 'Keep timeout at 15s with concurrency at 3.',
                ],
                'defaults' => [
                    'rate_limit' => 1000,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 0,
                    'retry_attempts' => 2,
                ],
            ],

            'auth_github' => [
                'id' => 'auth_github',
                'name' => 'GitHub',
                'category' => 'Authentication',
                'docs_url' => 'https://docs.github.com/en/apps/oauth-apps',
                'rate_limit_docs_url' => 'https://docs.github.com/en/rest/using-the-rest-api/rate-limits-for-the-rest-api',
                'official_limits' => [
                    'standard' => '5,000 requests / hour',
                    'window' => 'Rolling 60-minute window per authenticated OAuth App token.',
                    'headers' => ['x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset'],
                    'exceeded_code' => '403 rate limit exceeded / 429 Too Many Requests',
                    'burst_notes' => 'GitHub OAuth and REST API rate limit is 5,000 req/hr for authorized users and 60 req/hr for unauthenticated calls.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1–2 calls during operator OAuth sign-in and profile fetch.',
                    'fleet_projection' => 'Sign-in events are operator-driven and lightweight. Far below the 5,000 req/hr ceiling.',
                    'recommendation' => 'Default timeout of 15s is recommended for fast token exchange.',
                ],
                'defaults' => [
                    'rate_limit' => 5000,
                    'rate_limit_unit' => 'requests / hour',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 0,
                    'retry_attempts' => 2,
                ],
            ],

            'auth_microsoft' => [
                'id' => 'auth_microsoft',
                'name' => 'Microsoft',
                'category' => 'Authentication',
                'docs_url' => 'https://learn.microsoft.com/en-us/entra/identity-platform/',
                'rate_limit_docs_url' => 'https://learn.microsoft.com/en-us/graph/throttling-limits',
                'official_limits' => [
                    'standard' => 'Microsoft Graph tier limits (~10,000 req / 10 min)',
                    'window' => 'Rolling per-tenant and per-app throttling on Microsoft Graph API.',
                    'headers' => ['Retry-After', 'RateLimit-Limit', 'RateLimit-Remaining'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Throttling occurs when too many requests are sent to Microsoft identity or Graph endpoints in a short window.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1–2 API calls during operator OAuth redirect/token exchange and /me profile query.',
                    'fleet_projection' => 'Operator login traffic is negligible against enterprise Microsoft Graph quotas.',
                    'recommendation' => 'Keep timeout at 15s with 2 retries for token exchange reliability.',
                ],
                'defaults' => [
                    'rate_limit' => 2000,
                    'rate_limit_unit' => 'requests / minute',
                    'timeout' => 15,
                    'concurrency' => 3,
                    'delay_ms' => 0,
                    'retry_attempts' => 2,
                ],
            ],

            'contact-forms' => [
                'id' => 'contact-forms',
                'name' => 'Contact Form Testing',
                'category' => 'Maintenance & QA',
                'docs_url' => 'https://clockworkcontrol.com/docs/features/contact-forms',
                'rate_limit_docs_url' => 'https://clockworkcontrol.com/docs/features/contact-forms',
                'official_limits' => [
                    'standard' => 'Synthetic local test suite',
                    'window' => 'Executed by scheduled Artisan worker or on-demand manual trigger.',
                    'headers' => [],
                    'exceeded_code' => 'N/A',
                    'burst_notes' => 'Tests are scheduled across morning hours to minimize concurrent site load.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Submits synthetic test forms to WordPress sites with companion verification.',
                    'fleet_projection' => 'Runs daily for care plan sites with contact form monitoring enabled.',
                    'recommendation' => 'Concurrency 2, delay 500ms between submissions.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'tests / minute',
                    'timeout' => 30,
                    'concurrency' => 2,
                    'delay_ms' => 500,
                    'retry_attempts' => 2,
                ],
            ],

            'client_slack' => [
                'id' => 'client_slack',
                'name' => 'Client Slack Notifications',
                'category' => 'Notifications',
                'docs_url' => 'https://api.slack.com/messaging/webhooks',
                'rate_limit_docs_url' => 'https://api.slack.com/docs/rate-limits',
                'official_limits' => [
                    'standard' => '1 message / second per incoming webhook',
                    'window' => 'Per client-configured site webhook URL.',
                    'headers' => ['Retry-After'],
                    'exceeded_code' => '429 Too Many Requests',
                    'burst_notes' => 'Per-site client Slack webhooks are configured directly in WordPress companion plugin.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => 'Dispatches client-facing form failure and site alerts to client Slack channels.',
                    'fleet_projection' => 'Low volume; event-driven alerts per client site.',
                    'recommendation' => 'Timeout 10s, concurrency 1.',
                ],
                'defaults' => [
                    'rate_limit' => 60,
                    'rate_limit_unit' => 'messages / minute',
                    'timeout' => 10,
                    'concurrency' => 1,
                    'delay_ms' => 250,
                    'retry_attempts' => 2,
                ],
            ],

            'backup-relay' => [
                'id' => 'backup-relay',
                'name' => 'Backup Relay',
                'category' => 'Maintenance',
                'docs_url' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/glacier-instant-retrieval-storage-class.html',
                'rate_limit_docs_url' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/optimizing-performance.html',
                'official_limits' => [
                    'standard' => '3,500 PUT/POST/DELETE, 5,500 GET/HEAD requests / second per prefix',
                    'window' => 'Continuous throughput per partitioned prefix in Amazon S3.',
                    'headers' => ['x-amz-request-id', 'x-amz-id-2'],
                    'exceeded_code' => '503 Slow Down',
                    'burst_notes' => 'Amazon S3 automatically scales partition capacity up to 3,500 PUT and 5,500 GET requests per second per prefix.',
                ],
                'fleet_impact' => [
                    'calls_per_server' => '1 backup archive stream per site per scheduled archival cycle.',
                    'fleet_projection' => '42 sites archived weekly = ~42 multi-part uploads per week (negligible AWS request quota cost).',
                    'recommendation' => 'Set frequency to 1 a week with 90-day Glacier Instant Retrieval retention for optimal cost and compliance.',
                ],
                'defaults' => [
                    'rate_limit' => 3500,
                    'rate_limit_unit' => 'requests / second',
                    'timeout' => 300,
                    'concurrency' => 2,
                    'delay_ms' => 100,
                    'retry_attempts' => 3,
                ],
            ],
        ];
    }

    /**
     * Retrieve definition for a specific service.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $serviceId): ?array
    {
        $aliases = [
            'do_spaces' => 'digitalocean',
            'do-spaces' => 'digitalocean',
            'security_scans' => 'sucuri',
            'security-scans' => 'sucuri',
            'billcom' => 'bill-com',
            'bill_com' => 'bill-com',
            'auth-github' => 'auth_github',
            'github' => 'auth_github',
            'auth-microsoft' => 'auth_microsoft',
            'microsoft' => 'auth_microsoft',
            'auth-google' => 'auth_google',
            'google' => 'auth_google',
            'contact_forms' => 'contact-forms',
            'contactforms' => 'contact-forms',
            'client-slack' => 'client_slack',
            'clientslack' => 'client_slack',
            'backup_relay' => 'backup-relay',
            'backuprelay' => 'backup-relay',
        ];

        $cleanId = strtolower(trim($serviceId));
        if (isset($aliases[$cleanId])) {
            $cleanId = $aliases[$cleanId];
        }

        $all = $this->all();

        if (isset($all[$cleanId])) {
            return $all[$cleanId];
        }

        $normalized = str_replace('_', '-', $cleanId);
        if (isset($all[$normalized])) {
            return $all[$normalized];
        }

        // Check alternate aliases (e.g. bill_com -> bill-com)
        $altKey = str_replace('-', '_', $normalized);
        if (isset($all[$altKey])) {
            return $all[$altKey];
        }

        return null;
    }

    /**
     * Get active operator tunables merged with vendor recommended defaults.
     *
     * @return array{rate_limit: int, rate_limit_unit: string, timeout: int, concurrency: int, delay_ms: int, retry_attempts: int, is_custom: bool}
     */
    public function getTunables(string $serviceId): array
    {
        $service = $this->get($serviceId);
        $defaults = $service['defaults'] ?? [
            'rate_limit' => 100,
            'rate_limit_unit' => 'requests / minute',
            'timeout' => 15,
            'concurrency' => 2,
            'delay_ms' => 100,
            'retry_attempts' => 2,
        ];

        $key = 'services.'.($service['id'] ?? $serviceId);
        $savedTimeout = $this->settings->get("{$key}.timeout");
        $savedRateLimit = $this->settings->get("{$key}.rate_limit");
        $savedConcurrency = $this->settings->get("{$key}.concurrency");
        $savedDelay = $this->settings->get("{$key}.delay_ms");
        $savedRetries = $this->settings->get("{$key}.retry_attempts");

        $isCustom = ($savedTimeout !== null || $savedRateLimit !== null || $savedConcurrency !== null || $savedDelay !== null || $savedRetries !== null);

        return [
            'rate_limit' => (int) ($savedRateLimit ?? $defaults['rate_limit']),
            'rate_limit_unit' => (string) $defaults['rate_limit_unit'],
            'timeout' => (int) ($savedTimeout ?? $defaults['timeout']),
            'concurrency' => (int) ($savedConcurrency ?? $defaults['concurrency']),
            'delay_ms' => (int) ($savedDelay ?? $defaults['delay_ms']),
            'retry_attempts' => (int) ($savedRetries ?? $defaults['retry_attempts']),
            'is_custom' => $isCustom,
        ];
    }

    /**
     * Save operator custom tunables to AppSettings.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveTunables(string $serviceId, array $data): void
    {
        $service = $this->get($serviceId);
        $id = $service['id'] ?? $serviceId;
        $key = "services.{$id}";

        if (isset($data['timeout'])) {
            $this->settings->put("{$key}.timeout", max(1, min(300, (int) $data['timeout'])));
        }
        if (isset($data['rate_limit'])) {
            $this->settings->put("{$key}.rate_limit", max(1, (int) $data['rate_limit']));
        }
        if (isset($data['concurrency'])) {
            $this->settings->put("{$key}.concurrency", max(1, min(10, (int) $data['concurrency'])));
        }
        if (isset($data['delay_ms'])) {
            $this->settings->put("{$key}.delay_ms", max(0, min(5000, (int) $data['delay_ms'])));
        }
        if (isset($data['retry_attempts'])) {
            $this->settings->put("{$key}.retry_attempts", max(0, min(5, (int) $data['retry_attempts'])));
        }
    }

    /**
     * Reset custom operator overrides back to recommended vendor defaults.
     */
    public function resetToDefaults(string $serviceId): void
    {
        $service = $this->get($serviceId);
        $id = $service['id'] ?? $serviceId;
        $key = "services.{$id}";

        $this->settings->put("{$key}.timeout", null);
        $this->settings->put("{$key}.rate_limit", null);
        $this->settings->put("{$key}.concurrency", null);
        $this->settings->put("{$key}.delay_ms", null);
        $this->settings->put("{$key}.retry_attempts", null);
    }
}
