<?php

namespace App\Support;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\File;

class EnvCredentialManager
{
    /**
     * @param  ?string  $envPath  Overrides the root .env path — tests inject a temp
     *                            file here so the suite never touches the real .env
     *                            (which holds this app's actual live secrets).
     */
    public function __construct(
        private readonly ?string $envPath = null,
    ) {}

    protected function envPath(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    /**
     * Complete mapping of service credential fields to their canonical .env variable names.
     *
     * @var array<string, array<string, array{env_var: string, label: string, secret: bool, guide: string, url: string, config_path?: string}>>
     */
    public const DEFINITIONS = [
        'digitalocean' => [
            'token' => [
                'env_var' => 'CLOCKWORK_DIGITALOCEAN_TOKEN',
                'label' => 'Personal Access Token',
                'secret' => true,
                'guide' => 'Personal Access Token with Read & Write scope from cloud.digitalocean.com/account/api/tokens',
                'url' => 'https://cloud.digitalocean.com/account/api/tokens',
                'config_path' => 'clockwork.digitalocean.token',
            ],
        ],

        'hetzner' => [
            'token' => [
                'env_var' => 'CLOCKWORK_HETZNER_TOKEN',
                'label' => 'API Token',
                'secret' => true,
                'guide' => 'Hetzner Cloud API Token with Read & Write permissions from console.hetzner.cloud',
                'url' => 'https://console.hetzner.cloud/projects',
                'config_path' => 'clockwork.hetzner.token',
            ],
        ],

        'linode' => [
            'token' => [
                'env_var' => 'CLOCKWORK_LINODE_TOKEN',
                'label' => 'Personal Access Token',
                'secret' => true,
                'guide' => 'Personal Access Token with Linodes scope from cloud.linode.com/profile/tokens',
                'url' => 'https://cloud.linode.com/profile/tokens',
                'config_path' => 'clockwork.linode.token',
            ],
        ],

        'vultr' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_VULTR_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'Personal API Key from my.vultr.com/settings/#settingsapi',
                'url' => 'https://my.vultr.com/settings/#settingsapi',
                'config_path' => 'clockwork.vultr.api_key',
            ],
        ],

        'azure' => [
            'tenant_id' => [
                'env_var' => 'CLOCKWORK_AZURE_TENANT_ID',
                'label' => 'Tenant ID',
                'secret' => false,
                'guide' => 'Directory (tenant) ID from Azure App Registration',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'clockwork.azure.tenant_id',
            ],
            'client_id' => [
                'env_var' => 'CLOCKWORK_AZURE_CLIENT_ID',
                'label' => 'Client ID',
                'secret' => false,
                'guide' => 'Application (client) ID from Azure App Registration',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'clockwork.azure.client_id',
            ],
            'client_secret' => [
                'env_var' => 'CLOCKWORK_AZURE_CLIENT_SECRET',
                'label' => 'Client Secret',
                'secret' => true,
                'guide' => 'Client Secret value from Certificates & secrets',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'clockwork.azure.client_secret',
            ],
            'subscription_id' => [
                'env_var' => 'CLOCKWORK_AZURE_SUBSCRIPTION_ID',
                'label' => 'Subscription ID',
                'secret' => false,
                'guide' => 'Target Azure Subscription ID for VM metrics',
                'url' => 'https://portal.azure.com/#view/Microsoft_Azure_Billing/SubscriptionsBlade',
                'config_path' => 'clockwork.azure.subscription_id',
            ],
        ],

        'spinupwp' => [
            'token' => [
                'env_var' => 'CLOCKWORK_SPINUPWP_TOKEN',
                'label' => 'API Token',
                'secret' => true,
                'guide' => 'SpinupWP API Token from app.spinupwp.com/settings/api-tokens',
                'url' => 'https://app.spinupwp.com/settings/api-tokens',
                'config_path' => 'clockwork.spinupwp.token',
            ],
        ],

        'pressable' => [
            'client_id' => [
                'env_var' => 'CLOCKWORK_PRESSABLE_CLIENT_ID',
                'label' => 'Client ID',
                'secret' => false,
                'guide' => 'OAuth Client ID from my.pressable.com/api',
                'url' => 'https://my.pressable.com/api',
                'config_path' => 'clockwork.pressable.client_id',
            ],
            'client_secret' => [
                'env_var' => 'CLOCKWORK_PRESSABLE_CLIENT_SECRET',
                'label' => 'Client Secret',
                'secret' => true,
                'guide' => 'OAuth Client Secret from my.pressable.com/api',
                'url' => 'https://my.pressable.com/api',
                'config_path' => 'clockwork.pressable.client_secret',
            ],
        ],

        'wpengine' => [
            'api_user_id' => [
                'env_var' => 'CLOCKWORK_WPENGINE_API_USER_ID',
                'label' => 'API User ID',
                'secret' => false,
                'guide' => 'WP Engine API User ID from User Portal (my.wpengine.com)',
                'url' => 'https://my.wpengine.com/api_access',
                'config_path' => 'clockwork.wpengine.api_user_id',
            ],
            'api_password' => [
                'env_var' => 'CLOCKWORK_WPENGINE_API_PASSWORD',
                'label' => 'API Password',
                'secret' => true,
                'guide' => 'WP Engine API Password / Access Token',
                'url' => 'https://my.wpengine.com/api_access',
                'config_path' => 'clockwork.wpengine.api_password',
            ],
            'ssh_private_key' => [
                'env_var' => 'CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY',
                'label' => 'SSH Private Key',
                'secret' => true,
                'guide' => 'Optional SSH private key for WP-CLI execution on WP Engine environments',
                'url' => 'https://my.wpengine.com/api_access',
                'config_path' => 'clockwork.wpengine.ssh_private_key',
            ],
        ],

        'kinsta' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_KINSTA_API_KEY',
                'label' => 'Company API Key',
                'secret' => true,
                'guide' => 'Company API Key from MyKinsta API Settings',
                'url' => 'https://my.kinsta.com/company/api-keys',
                'config_path' => 'clockwork.kinsta.api_key',
            ],
            'ssh_password' => [
                'env_var' => 'CLOCKWORK_KINSTA_SSH_PASSWORD',
                'label' => 'SSH Password',
                'secret' => true,
                'guide' => 'Default SSH password for Kinsta site environments',
                'url' => 'https://my.kinsta.com',
                'config_path' => 'clockwork.kinsta.ssh_password',
            ],
        ],

        'cloudways' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_CLOUDWAYS_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'API Key from Cloudways platform account settings',
                'url' => 'https://platform.cloudways.com/api',
                'config_path' => 'clockwork.cloudways.api_key',
            ],
            'email' => [
                'env_var' => 'CLOCKWORK_CLOUDWAYS_EMAIL',
                'label' => 'Account Email',
                'secret' => false,
                'guide' => 'Email address associated with your Cloudways account',
                'url' => '',
                'config_path' => 'clockwork.cloudways.email',
            ],
        ],

        'gridpane' => [
            'api_key' => [
                'env_var' => 'GRIDPANE_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'Personal API Key from my.gridpane.com/oauth/api',
                'url' => 'https://my.gridpane.com/settings',
                'config_path' => 'clockwork.gridpane.api_key',
            ],
        ],

        'cloudflare' => [
            'api_token' => [
                'env_var' => 'CLOCKWORK_CLOUDFLARE_API_TOKEN',
                'label' => 'API Token (read)',
                'secret' => true,
                'guide' => 'Cloudflare API token with Zone.Read and DNS.Read permissions',
                'url' => 'https://dash.cloudflare.com/profile/api-tokens',
                'config_path' => 'clockwork.cloudflare.api_token',
            ],
            'write_token' => [
                'env_var' => 'CLOCKWORK_CLOUDFLARE_WRITE_TOKEN',
                'label' => 'Write Token (optional)',
                'secret' => true,
                'guide' => 'Zone.DNS:Edit token, used for site migration cutovers',
                'url' => 'https://dash.cloudflare.com/profile/api-tokens',
                'config_path' => 'clockwork.cloudflare.write_token',
            ],
        ],

        'gtmetrix' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_GTMETRIX_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'GTmetrix REST API v2 key from gtmetrix.com/dashboard/api',
                'url' => 'https://gtmetrix.com/dashboard/api/',
                'config_path' => 'clockwork.gtmetrix.api_key',
            ],
        ],

        'psi' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_PSI_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'Google Cloud API Key with PageSpeed Insights API enabled',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'clockwork.psi.api_key',
            ],
        ],

        'pagespeed' => [
            'api_key' => [
                'env_var' => 'CLOCKWORK_PSI_API_KEY',
                'label' => 'API Key',
                'secret' => true,
                'guide' => 'Google Cloud API Key with PageSpeed Insights API enabled',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'clockwork.psi.api_key',
            ],
        ],

        'security_scans' => [
            'google_web_risk_key' => [
                'env_var' => 'CLOCKWORK_GOOGLE_WEB_RISK_KEY',
                'label' => 'Google Web Risk Key',
                'secret' => true,
                'guide' => 'Google Cloud API key with Web Risk API enabled (commercial standard)',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'clockwork.security_scans.google_web_risk_key',
            ],
            'google_safe_browsing_key' => [
                'env_var' => 'CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY',
                'label' => 'Google Safe Browsing Key (legacy v4)',
                'secret' => true,
                'guide' => 'Legacy non-commercial Safe Browsing v4 fallback. Prefer CLOCKWORK_GOOGLE_WEB_RISK_KEY.',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'clockwork.security_scans.google_safe_browsing_key',
            ],
            'urlhaus_auth_key' => [
                'env_var' => 'CLOCKWORK_URLHAUS_AUTH_KEY',
                'label' => 'URLHaus Auth Key',
                'secret' => true,
                'guide' => 'Free abuse.ch URLHaus authentication key',
                'url' => 'https://auth.abuse.ch/',
                'config_path' => 'clockwork.security_scans.urlhaus_auth_key',
            ],
        ],

        'twilio' => [
            'account_sid' => [
                'env_var' => 'TWILIO_ACCOUNT_SID',
                'label' => 'Account SID',
                'secret' => false,
                'guide' => 'Twilio Account SID from console.twilio.com',
                'url' => 'https://console.twilio.com/',
                'config_path' => 'clockwork.twilio.account_sid',
            ],
            'auth_token' => [
                'env_var' => 'TWILIO_AUTH_TOKEN',
                'label' => 'Auth Token',
                'secret' => true,
                'guide' => 'Twilio Auth Token from console.twilio.com',
                'url' => 'https://console.twilio.com/',
                'config_path' => 'clockwork.twilio.auth_token',
            ],
            'from_number' => [
                'env_var' => 'TWILIO_FROM_NUMBER',
                'label' => 'From Number',
                'secret' => false,
                'guide' => 'E.164 formatted SMS-capable Twilio phone number (+1234567890)',
                'url' => 'https://console.twilio.com/',
                'config_path' => 'clockwork.twilio.from_number',
            ],
        ],

        'slack' => [
            'webhook_url' => [
                'env_var' => 'CLOCKWORK_SLACK_WEBHOOK_URL',
                'label' => 'Incoming Webhook URL',
                'secret' => true,
                'guide' => 'Incoming Webhook URL from your Slack app settings',
                'url' => 'https://api.slack.com/apps',
                'config_path' => 'clockwork.slack.webhook_url',
            ],
            'channel' => [
                'env_var' => 'CLOCKWORK_SLACK_CHANNEL',
                'label' => 'Default Channel (optional)',
                'secret' => false,
                'guide' => 'Optional Slack channel override (e.g. #monitoring-alerts)',
                'url' => '',
                'config_path' => 'clockwork.slack.channel',
            ],
        ],

        'mattermost' => [
            'webhook_url' => [
                'env_var' => 'CLOCKWORK_MATTERMOST_WEBHOOK_URL',
                'label' => 'Incoming Webhook URL',
                'secret' => true,
                'guide' => 'Incoming Webhook URL from Mattermost Integrations',
                'url' => 'https://mattermost.com/docs/guides/administration/integrations/incoming-webhooks/',
                'config_path' => 'clockwork.mattermost.webhook_url',
            ],
            'channel' => [
                'env_var' => 'CLOCKWORK_MATTERMOST_CHANNEL',
                'label' => 'Default Channel (optional)',
                'secret' => false,
                'guide' => 'Optional Mattermost channel name (e.g. alerts)',
                'url' => '',
                'config_path' => 'clockwork.mattermost.channel',
            ],
        ],

        'backup-relay' => [
            'bucket' => [
                'env_var' => 'S3_BACKUP_RELAY_BUCKET',
                'label' => 'S3 Bucket',
                'secret' => false,
                'guide' => 'Amazon S3 bucket name configured for offsite backup archive storage',
                'url' => 'https://s3.console.aws.amazon.com/s3/home',
                'config_path' => 'filesystems.disks.s3-backup-relay.bucket',
            ],
            'region' => [
                'env_var' => 'S3_BACKUP_RELAY_REGION',
                'label' => 'S3 Region',
                'secret' => false,
                'guide' => 'AWS Region for the S3 backup bucket (e.g. us-east-1, us-east-2)',
                'url' => 'https://docs.aws.amazon.com/general/latest/gr/s3.html',
                'config_path' => 'filesystems.disks.s3-backup-relay.region',
            ],
            'mode' => [
                'env_var' => 'CLOCKWORK_BACKUP_RELAY_MODE',
                'label' => 'Relay Mode',
                'secret' => false,
                'guide' => 'Relay execution mode: in_repo (local cron) or external_agent (remote runner)',
                'url' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/glacier-instant-retrieval-storage-class.html',
                'config_path' => 'clockwork.backup_relay.mode',
            ],
            'prefix' => [
                'env_var' => 'CLOCKWORK_BACKUP_RELAY_S3_PREFIX',
                'label' => 'S3 Prefix',
                'secret' => false,
                'guide' => 'Root S3 path prefix for backup manifests (default: _control/backup-relay)',
                'url' => 'https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-prefixes.html',
                'config_path' => 'clockwork.backup_relay.s3_prefix',
            ],
        ],

        'bill-com' => [
            'username' => [
                'env_var' => 'CLOCKWORK_BILL_COM_USERNAME',
                'label' => 'Username',
                'secret' => false,
                'guide' => 'Bill.com API account username / email',
                'url' => 'https://developer.bill.com/',
                'config_path' => 'clockwork.bill_com.username',
            ],
            'password' => [
                'env_var' => 'CLOCKWORK_BILL_COM_PASSWORD',
                'label' => 'Password',
                'secret' => true,
                'guide' => 'Bill.com account password',
                'url' => 'https://developer.bill.com/',
                'config_path' => 'clockwork.bill_com.password',
            ],
            'org_id' => [
                'env_var' => 'CLOCKWORK_BILL_COM_ORG_ID',
                'label' => 'Organization ID',
                'secret' => false,
                'guide' => 'Bill.com Organization ID',
                'url' => 'https://developer.bill.com/',
                'config_path' => 'clockwork.bill_com.org_id',
            ],
            'dev_key' => [
                'env_var' => 'CLOCKWORK_BILL_COM_DEV_KEY',
                'label' => 'Developer Key',
                'secret' => true,
                'guide' => 'Developer Key from developer.bill.com',
                'url' => 'https://developer.bill.com/',
                'config_path' => 'clockwork.bill_com.dev_key',
            ],
        ],

        'do_spaces' => [
            'key' => [
                'env_var' => 'CLOCKWORK_DO_SPACES_KEY',
                'label' => 'Spaces Access Key',
                'secret' => false,
                'guide' => 'DigitalOcean Spaces access key (from API -> Spaces access keys)',
                'url' => 'https://cloud.digitalocean.com/account/api/spaces',
                'config_path' => 'clockwork.do_spaces.key',
            ],
            'secret' => [
                'env_var' => 'CLOCKWORK_DO_SPACES_SECRET',
                'label' => 'Spaces Secret Key',
                'secret' => true,
                'guide' => 'DigitalOcean Spaces secret key',
                'url' => 'https://cloud.digitalocean.com/account/api/spaces',
                'config_path' => 'clockwork.do_spaces.secret',
            ],
        ],

        'ssh' => [
            'default_key_path' => [
                'env_var' => 'CLOCKWORK_SSH_KEY_PATH',
                'label' => 'Default SSH Key Path',
                'secret' => false,
                'guide' => 'Path to private key on the server (e.g. /home/deploy/.ssh/id_ed25519)',
                'url' => '',
                'config_path' => 'clockwork.ssh.key_path',
            ],
            'default_key_passphrase' => [
                'env_var' => 'CLOCKWORK_SSH_KEY_PASSPHRASE',
                'label' => 'Default Passphrase',
                'secret' => true,
                'guide' => 'Optional passphrase protecting the private key',
                'url' => '',
                'config_path' => 'clockwork.ssh.key_passphrase',
            ],
        ],

        'auth_google' => [
            'client_id' => [
                'env_var' => 'GOOGLE_CLIENT_ID',
                'label' => 'Google Client ID',
                'secret' => false,
                'guide' => 'OAuth 2.0 Web Client ID from Google Cloud Console',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'services.google.client_id',
            ],
            'client_secret' => [
                'env_var' => 'GOOGLE_CLIENT_SECRET',
                'label' => 'Google Client Secret',
                'secret' => true,
                'guide' => 'OAuth 2.0 Web Client Secret from Google Cloud Console',
                'url' => 'https://console.cloud.google.com/apis/credentials',
                'config_path' => 'services.google.client_secret',
            ],
            'hosted_domain' => [
                'env_var' => 'GOOGLE_HD',
                'label' => 'Hosted Domain (optional)',
                'secret' => false,
                'guide' => 'Optional Google Workspace domain restriction (e.g. your-agency.com)',
                'url' => '',
                'config_path' => 'services.google.hosted_domain',
            ],
        ],

        'auth_github' => [
            'client_id' => [
                'env_var' => 'GITHUB_CLIENT_ID',
                'label' => 'GitHub Client ID',
                'secret' => false,
                'guide' => 'OAuth App Client ID from github.com/settings/developers',
                'url' => 'https://github.com/settings/developers',
                'config_path' => 'services.github.client_id',
            ],
            'client_secret' => [
                'env_var' => 'GITHUB_CLIENT_SECRET',
                'label' => 'GitHub Client Secret',
                'secret' => true,
                'guide' => 'OAuth App Client Secret from github.com/settings/developers',
                'url' => 'https://github.com/settings/developers',
                'config_path' => 'services.github.client_secret',
            ],
        ],

        'auth_microsoft' => [
            'client_id' => [
                'env_var' => 'MICROSOFT_CLIENT_ID',
                'label' => 'Microsoft Client ID',
                'secret' => false,
                'guide' => 'Application (client) ID from Microsoft Entra App Registration',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'services.microsoft.client_id',
            ],
            'client_secret' => [
                'env_var' => 'MICROSOFT_CLIENT_SECRET',
                'label' => 'Microsoft Client Secret',
                'secret' => true,
                'guide' => 'Client Secret value from Certificates & secrets',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'services.microsoft.client_secret',
            ],
            'tenant_id' => [
                'env_var' => 'MICROSOFT_TENANT_ID',
                'label' => 'Directory (tenant) ID (or "common")',
                'secret' => false,
                'guide' => 'Directory (tenant) ID from Microsoft Entra App Registration, or "common"',
                'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
                'config_path' => 'services.microsoft.tenant_id',
            ],
        ],
    ];

    /**
     * Resolve definitions for a specific service ID with alias normalization.
     *
     * @return array<string, array{env_var: string, label: string, secret: bool, guide: string, url: string, config_path?: string}>
     */
    public function getDefinitions(string $serviceId): array
    {
        $id = strtolower(trim($serviceId));

        $aliases = [
            'billcom' => 'bill-com',
            'bill_com' => 'bill-com',
            'pagespeed' => 'psi',
            'security-scans' => 'security_scans',
            'do-spaces' => 'do_spaces',
            'auth-google' => 'auth_google',
            'google' => 'auth_google',
            'auth-github' => 'auth_github',
            'github' => 'auth_github',
            'auth-microsoft' => 'auth_microsoft',
            'microsoft' => 'auth_microsoft',
            'contact-forms' => 'contact_forms',
            'contact_forms' => 'contact_forms',
            'client-slack' => 'client_slack',
            'client_slack' => 'client_slack',
            'backup_relay' => 'backup-relay',
        ];

        if (isset($aliases[$id])) {
            $id = $aliases[$id];
        }

        return self::DEFINITIONS[$id] ?? [];
    }

    /**
     * Get field statuses and values for a service.
     *
     * @return list<array{field: string, label: string, env_var: string, configured: bool, preview: ?string, secret: bool, guide: string, url: string}>
     */
    public function getFieldsForService(string $serviceId): array
    {
        $defs = $this->getDefinitions($serviceId);
        $result = [];

        foreach ($defs as $fieldKey => $meta) {
            $envVar = $meta['env_var'];
            $val = $this->getEnvValue($envVar);
            if (($val === null || $val === '') && ! empty($meta['config_path'])) {
                $configVal = config($meta['config_path']);
                if ($configVal !== null && $configVal !== '') {
                    $val = (string) $configVal;
                }
            }
            $configured = ($val !== null && $val !== '');

            $preview = null;
            if ($configured) {
                if ($meta['secret']) {
                    $preview = strlen($val) > 8 ? '••••••••'.substr($val, -4) : '••••••••';
                } else {
                    $preview = $val;
                }
            }

            $result[] = [
                'field' => $fieldKey,
                'label' => $meta['label'],
                'env_var' => $envVar,
                'configured' => $configured,
                'preview' => $preview,
                'secret' => $meta['secret'],
                'guide' => $meta['guide'],
                'url' => $meta['url'],
                'url_label' => $meta['url_label'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Read value of an environment variable.
     */
    public function getEnvValue(string $envKey): ?string
    {
        $val = getenv($envKey);
        if ($val === false || $val === '') {
            $val = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? null;
        }

        return is_string($val) && $val !== '' ? $val : null;
    }

    /**
     * Save an API credential directly to the .env file.
     */
    public function save(string $serviceId, string $field, string $value): bool
    {
        $defs = $this->getDefinitions($serviceId);
        if (! isset($defs[$field])) {
            return false;
        }

        $meta = $defs[$field];
        $envKey = $meta['env_var'];
        // Strip embedded newlines/CRs, not just leading/trailing whitespace —
        // an unstripped one would let a pasted credential value inject an
        // extra line into .env (e.g. a crafted "token\nMAIL_HOST=evil"),
        // silently adding or overwriting an unrelated variable.
        $cleanVal = trim(str_replace(["\r", "\n"], '', $value));

        $written = $this->writeEnv($envKey, $cleanVal);

        if ($written) {
            // Update runtime memory so running process immediately sees the change
            putenv("{$envKey}={$cleanVal}");
            $_ENV[$envKey] = $cleanVal;
            $_SERVER[$envKey] = $cleanVal;

            if (isset($meta['config_path'])) {
                config([$meta['config_path'] => $cleanVal]);
            }

            // Also clear any lingering database entry so .env is the single source of truth
            $normService = str_replace('-', '_', $serviceId);
            IntegrationCredential::query()
                ->whereIn('integration', [$serviceId, $normService])
                ->where('key', $field)
                ->delete();
        }

        return $written;
    }

    /**
     * Remove an API credential from the .env file.
     */
    public function remove(string $serviceId, string $field): bool
    {
        $defs = $this->getDefinitions($serviceId);
        if (! isset($defs[$field])) {
            return false;
        }

        $meta = $defs[$field];
        $envKey = $meta['env_var'];

        $removed = $this->removeEnv($envKey);

        if ($removed) {
            putenv("{$envKey}=");
            unset($_ENV[$envKey], $_SERVER[$envKey]);

            if (isset($meta['config_path'])) {
                config([$meta['config_path'] => null]);
            }

            $normService = str_replace('-', '_', $serviceId);
            IntegrationCredential::query()
                ->whereIn('integration', [$serviceId, $normService])
                ->where('key', $field)
                ->delete();
        }

        return $removed;
    }

    /**
     * Atomically write a KEY=value to the root .env file.
     */
    public function writeEnv(string $key, ?string $value): bool
    {
        $path = $this->envPath();
        if (! file_exists($path)) {
            $examplePath = base_path('.env.example');
            if (file_exists($examplePath)) {
                copy($examplePath, $path);
            } else {
                file_put_contents($path, '', LOCK_EX);
            }
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        // Format value safely with quotes if spaces or special characters exist
        $formattedValue = $this->formatEnvValue($value ?? '');
        $newLine = "{$key}={$formattedValue}";

        // Matches lines like:
        // CLOCKWORK_TOKEN=...
        // # CLOCKWORK_TOKEN=...
        $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $newContents = preg_replace($pattern, $newLine, $contents);
        } else {
            $newContents = rtrim($contents).PHP_EOL.$newLine.PHP_EOL;
        }

        return file_put_contents($path, $newContents, LOCK_EX) !== false;
    }

    /**
     * Clear a key's value in the root .env file.
     */
    public function removeEnv(string $key): bool
    {
        $path = $this->envPath();
        if (! file_exists($path)) {
            return true;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            $newContents = preg_replace($pattern, "{$key}=", $contents);

            return file_put_contents($path, $newContents, LOCK_EX) !== false;
        }

        return true;
    }

    protected function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|"|\'|#|\$|\\\\/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
        }

        return $value;
    }
}
