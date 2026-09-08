<?php

namespace App\Services\Companion;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CompanionBrandingManager
{
    public const LOGO_DISK = 'public';

    public const DEFAULT_COMPANY_NAME = 'Clockwork Web Dev';

    public const DEFAULT_COMPANY_URL = 'https://clockworkcontrol.com';

    public const DEFAULT_SUPPORT_EMAIL = 'support@clockworkcontrol.com';

    public const DEFAULT_SUPPORT_URL = 'https://clockworkcontrol.com/docs';

    public const DEFAULT_PLUGIN_NAME = 'Clockwork Companion';

    public const DEFAULT_PLUGIN_DESCRIPTION = 'Monitors WordPress health, synthetic form testing, security scans, and maintenance telemetry for Clockwork Control.';

    public const DEFAULT_MENU_TITLE = 'Clockwork';

    public const DEFAULT_MENU_ICON = 'dashicons-clock';

    public const DEFAULT_REPORTS_PRIMARY_COLOR = '#2D2062';

    public const DEFAULT_REPORTS_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_EMAIL_HEADER_BG = '#2D2062';

    public const DEFAULT_EMAIL_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_EMAIL_BADGE_TEXT = 'Security Alert';

    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Get the active branding settings merged with defaults.
     *
     * @return array{
     *     enabled: bool,
     *     company_name: string,
     *     company_url: string,
     *     support_email: string,
     *     support_url: string,
     *     plugin_name: string,
     *     plugin_description: string,
     *     menu_title: string,
     *     menu_icon: string,
     *     logo_url: string,
     *     hide_plugin_row: bool,
     *     hide_help_links: bool,
     *     footer_text: string,
     *     is_custom: bool,
     * }
     */
    public function get(): array
    {
        $enabled = (bool) $this->settings->get('companion.branding.enabled', false);
        $companyName = (string) $this->settings->get('companion.branding.company_name', self::DEFAULT_COMPANY_NAME);
        $companyUrl = (string) $this->settings->get('companion.branding.company_url', self::DEFAULT_COMPANY_URL);
        $supportEmail = (string) $this->settings->get('companion.branding.support_email', self::DEFAULT_SUPPORT_EMAIL);
        $supportUrl = (string) $this->settings->get('companion.branding.support_url', self::DEFAULT_SUPPORT_URL);
        $pluginName = (string) $this->settings->get('companion.branding.plugin_name', self::DEFAULT_PLUGIN_NAME);
        $pluginDesc = (string) $this->settings->get('companion.branding.plugin_description', self::DEFAULT_PLUGIN_DESCRIPTION);
        $menuTitle = (string) $this->settings->get('companion.branding.menu_title', self::DEFAULT_MENU_TITLE);
        $menuIcon = (string) $this->settings->get('companion.branding.menu_icon', self::DEFAULT_MENU_ICON);
        $logoUrl = (string) $this->settings->get('companion.branding.logo_url', '');
        $hidePluginRow = (bool) $this->settings->get('companion.branding.hide_plugin_row', false);
        $hideHelpLinks = (bool) $this->settings->get('companion.branding.hide_help_links', true);
        $footerText = (string) $this->settings->get('companion.branding.footer_text', '');

        $isCustom = $enabled || $this->settings->get('companion.branding.company_name') !== null;

        return [
            'enabled' => $enabled,
            'company_name' => $companyName !== '' ? $companyName : self::DEFAULT_COMPANY_NAME,
            'company_url' => $companyUrl !== '' ? $companyUrl : self::DEFAULT_COMPANY_URL,
            'support_email' => $supportEmail !== '' ? $supportEmail : self::DEFAULT_SUPPORT_EMAIL,
            'support_url' => $supportUrl,
            'plugin_name' => $pluginName !== '' ? $pluginName : self::DEFAULT_PLUGIN_NAME,
            'plugin_description' => $pluginDesc !== '' ? $pluginDesc : self::DEFAULT_PLUGIN_DESCRIPTION,
            'menu_title' => $menuTitle !== '' ? $menuTitle : self::DEFAULT_MENU_TITLE,
            'menu_icon' => $menuIcon !== '' ? $menuIcon : self::DEFAULT_MENU_ICON,
            'logo_url' => $logoUrl,
            'hide_plugin_row' => $hidePluginRow,
            'hide_help_links' => $hideHelpLinks,
            'footer_text' => $footerText,
            'is_custom' => $isCustom,
        ];
    }

    /**
     * Save operator branding configuration to Settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        $payload = [
            'companion.branding.enabled' => ! empty($data['enabled']),
            'companion.branding.company_name' => trim((string) ($data['company_name'] ?? self::DEFAULT_COMPANY_NAME)),
            'companion.branding.company_url' => trim((string) ($data['company_url'] ?? self::DEFAULT_COMPANY_URL)),
            'companion.branding.support_email' => trim((string) ($data['support_email'] ?? self::DEFAULT_SUPPORT_EMAIL)),
            'companion.branding.support_url' => trim((string) ($data['support_url'] ?? '')),
            'companion.branding.plugin_name' => trim((string) ($data['plugin_name'] ?? self::DEFAULT_PLUGIN_NAME)),
            'companion.branding.plugin_description' => trim((string) ($data['plugin_description'] ?? self::DEFAULT_PLUGIN_DESCRIPTION)),
            'companion.branding.menu_title' => trim((string) ($data['menu_title'] ?? self::DEFAULT_MENU_TITLE)),
            'companion.branding.menu_icon' => trim((string) ($data['menu_icon'] ?? self::DEFAULT_MENU_ICON)),
            'companion.branding.hide_plugin_row' => ! empty($data['hide_plugin_row']),
            'companion.branding.hide_help_links' => ! empty($data['hide_help_links']),
            'companion.branding.footer_text' => trim((string) ($data['footer_text'] ?? '')),
        ];

        if (array_key_exists('logo_url', $data)) {
            // Setting a raw logo_url directly (bypassing uploadLogo()) always
            // refers to something other than our own locally-stored file —
            // drop the stale local file (if any) so it doesn't linger as an
            // orphan, and clear logo_path so a later uploadLogo() call never
            // mistakenly deletes a file this save() call didn't create.
            $this->deleteStoredLogoFile();
            $payload['companion.branding.logo_url'] = trim((string) $data['logo_url']);
            $payload['companion.branding.logo_path'] = null;
        }

        $this->settings->putMany($payload);
    }

    /**
     * Store an uploaded logo file, deleting any previously uploaded logo
     * file first so re-uploads don't accumulate orphaned files in storage.
     */
    public function uploadLogo(UploadedFile $file): string
    {
        $this->deleteStoredLogoFile();

        $extension = $file->getClientOriginalExtension() ?: 'png';
        $filename = 'companion-logo-'.Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs('branding', $filename, self::LOGO_DISK);
        $url = Storage::disk(self::LOGO_DISK)->url($path);

        $this->settings->putMany([
            'companion.branding.logo_url' => $url,
            'companion.branding.logo_path' => $path,
        ]);

        return $url;
    }

    /**
     * Delete the currently stored logo file (if any) from disk. Does not
     * touch the logo_url/logo_path settings themselves — callers clear
     * those separately once they know what (if anything) replaces them.
     */
    protected function deleteStoredLogoFile(): void
    {
        $path = (string) $this->settings->get('companion.branding.logo_path', '');

        if ($path !== '') {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }

    /**
     * Reset branding back to Clockwork Control defaults.
     */
    public function reset(): void
    {
        $this->deleteStoredLogoFile();

        $this->settings->putMany([
            'companion.branding.enabled' => false,
            'companion.branding.company_name' => null,
            'companion.branding.company_url' => null,
            'companion.branding.support_email' => null,
            'companion.branding.support_url' => null,
            'companion.branding.plugin_name' => null,
            'companion.branding.plugin_description' => null,
            'companion.branding.menu_title' => null,
            'companion.branding.menu_icon' => null,
            'companion.branding.logo_url' => null,
            'companion.branding.logo_path' => null,
            'companion.branding.hide_plugin_row' => null,
            'companion.branding.hide_help_links' => null,
            'companion.branding.footer_text' => null,
        ]);
    }

    /**
     * Get Client Reports branding configuration merged with shared agency defaults.
     *
     * @return array{
     *     enabled: bool,
     *     company_name: string,
     *     company_url: string,
     *     support_email: string,
     *     support_url: string,
     *     logo_url: string,
     *     primary_color: string,
     *     accent_color: string,
     *     footer_text: string,
     *     is_custom: bool,
     * }
     */
    public function getReportsBranding(): array
    {
        $shared = $this->get();
        $enabled = (bool) $this->settings->get('reports.branding.enabled', false);
        $companyName = (string) $this->settings->get('reports.branding.company_name', '');
        $supportEmail = (string) $this->settings->get('reports.branding.support_email', '');
        $supportUrl = (string) $this->settings->get('reports.branding.support_url', '');
        $logoUrl = (string) $this->settings->get('reports.branding.logo_url', '');
        $primaryColor = (string) $this->settings->get('reports.branding.primary_color', self::DEFAULT_REPORTS_PRIMARY_COLOR);
        $accentColor = (string) $this->settings->get('reports.branding.accent_color', self::DEFAULT_REPORTS_ACCENT_COLOR);
        $footerText = (string) $this->settings->get('reports.branding.footer_text', '');

        $isCustom = $enabled || $companyName !== '' || $primaryColor !== self::DEFAULT_REPORTS_PRIMARY_COLOR;

        return [
            'enabled' => $enabled,
            'company_name' => $companyName !== '' ? $companyName : $shared['company_name'],
            'company_url' => $shared['company_url'],
            'support_email' => $supportEmail !== '' ? $supportEmail : $shared['support_email'],
            'support_url' => $supportUrl !== '' ? $supportUrl : $shared['support_url'],
            'logo_url' => $logoUrl !== '' ? $logoUrl : $shared['logo_url'],
            'primary_color' => $primaryColor !== '' ? $primaryColor : self::DEFAULT_REPORTS_PRIMARY_COLOR,
            'accent_color' => $accentColor !== '' ? $accentColor : self::DEFAULT_REPORTS_ACCENT_COLOR,
            'footer_text' => $footerText,
            'is_custom' => $isCustom,
        ];
    }

    /**
     * Save Client Reports branding settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveReportsBranding(array $data): void
    {
        $payload = [
            'reports.branding.enabled' => ! empty($data['enabled']),
            'reports.branding.company_name' => trim((string) ($data['company_name'] ?? '')),
            'reports.branding.support_email' => trim((string) ($data['support_email'] ?? '')),
            'reports.branding.support_url' => trim((string) ($data['support_url'] ?? '')),
            'reports.branding.primary_color' => trim((string) ($data['primary_color'] ?? self::DEFAULT_REPORTS_PRIMARY_COLOR)),
            'reports.branding.accent_color' => trim((string) ($data['accent_color'] ?? self::DEFAULT_REPORTS_ACCENT_COLOR)),
            'reports.branding.footer_text' => trim((string) ($data['footer_text'] ?? '')),
        ];

        if (array_key_exists('logo_url', $data)) {
            $payload['reports.branding.logo_url'] = trim((string) $data['logo_url']);
        }

        $this->settings->putMany($payload);
    }

    /**
     * Reset Client Reports branding back to shared defaults.
     */
    public function resetReports(): void
    {
        $this->settings->putMany([
            'reports.branding.enabled' => false,
            'reports.branding.company_name' => null,
            'reports.branding.support_email' => null,
            'reports.branding.support_url' => null,
            'reports.branding.logo_url' => null,
            'reports.branding.primary_color' => null,
            'reports.branding.accent_color' => null,
            'reports.branding.footer_text' => null,
        ]);
    }

    /**
     * Get Plugin Notification Email branding merged with shared agency defaults.
     *
     * @return array{
     *     enabled: bool,
     *     company_name: string,
     *     company_url: string,
     *     sender_name: string,
     *     reply_to: string,
     *     support_email: string,
     *     logo_url: string,
     *     header_bg: string,
     *     accent_color: string,
     *     badge_text: string,
     *     footer_text: string,
     *     use_logo: bool,
     *     is_custom: bool,
     * }
     */
    public function getEmailBranding(): array
    {
        $shared = $this->get();
        $enabled = (bool) $this->settings->get('email.branding.enabled', false);
        $companyName = (string) $this->settings->get('email.branding.company_name', '');
        $senderName = (string) $this->settings->get('email.branding.sender_name', '');
        $replyTo = (string) $this->settings->get('email.branding.reply_to', '');
        $headerBg = (string) $this->settings->get('email.branding.header_bg', self::DEFAULT_EMAIL_HEADER_BG);
        $accentColor = (string) $this->settings->get('email.branding.accent_color', self::DEFAULT_EMAIL_ACCENT_COLOR);
        $badgeText = (string) $this->settings->get('email.branding.badge_text', self::DEFAULT_EMAIL_BADGE_TEXT);
        $footerText = (string) $this->settings->get('email.branding.footer_text', '');
        $useLogo = (bool) $this->settings->get('email.branding.use_logo', true);

        $isCustom = $enabled || $companyName !== '' || $headerBg !== self::DEFAULT_EMAIL_HEADER_BG;

        return [
            'enabled' => $enabled,
            'company_name' => $companyName !== '' ? $companyName : $shared['company_name'],
            'company_url' => $shared['company_url'],
            'sender_name' => $senderName !== '' ? $senderName : $shared['company_name'],
            'reply_to' => $replyTo !== '' ? $replyTo : $shared['support_email'],
            'support_email' => $shared['support_email'],
            'logo_url' => $shared['logo_url'],
            'header_bg' => $headerBg !== '' ? $headerBg : self::DEFAULT_EMAIL_HEADER_BG,
            'accent_color' => $accentColor !== '' ? $accentColor : self::DEFAULT_EMAIL_ACCENT_COLOR,
            'badge_text' => $badgeText !== '' ? $badgeText : self::DEFAULT_EMAIL_BADGE_TEXT,
            'footer_text' => $footerText,
            'use_logo' => $useLogo,
            'is_custom' => $isCustom,
        ];
    }

    /**
     * Save Plugin Notification Email branding settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveEmailBranding(array $data): void
    {
        $payload = [
            'email.branding.enabled' => ! empty($data['enabled']),
            'email.branding.company_name' => trim((string) ($data['company_name'] ?? '')),
            'email.branding.sender_name' => trim((string) ($data['sender_name'] ?? '')),
            'email.branding.reply_to' => trim((string) ($data['reply_to'] ?? '')),
            'email.branding.header_bg' => trim((string) ($data['header_bg'] ?? self::DEFAULT_EMAIL_HEADER_BG)),
            'email.branding.accent_color' => trim((string) ($data['accent_color'] ?? self::DEFAULT_EMAIL_ACCENT_COLOR)),
            'email.branding.badge_text' => trim((string) ($data['badge_text'] ?? self::DEFAULT_EMAIL_BADGE_TEXT)),
            'email.branding.footer_text' => trim((string) ($data['footer_text'] ?? '')),
            'email.branding.use_logo' => ! empty($data['use_logo']),
        ];

        $this->settings->putMany($payload);
    }

    /**
     * Reset Plugin Notification Email branding back to defaults.
     */
    public function resetEmail(): void
    {
        $this->settings->putMany([
            'email.branding.enabled' => false,
            'email.branding.company_name' => null,
            'email.branding.sender_name' => null,
            'email.branding.reply_to' => null,
            'email.branding.header_bg' => null,
            'email.branding.accent_color' => null,
            'email.branding.badge_text' => null,
            'email.branding.footer_text' => null,
            'email.branding.use_logo' => null,
        ]);
    }

    /**
     * Export payload ready for wire transmission to remote WordPress sites.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->get();

        return [
            'enabled' => $data['enabled'],
            'company_name' => $data['company_name'],
            'company_url' => $data['company_url'],
            'support_email' => $data['support_email'],
            'support_url' => $data['support_url'],
            'plugin_name' => $data['plugin_name'],
            'plugin_description' => $data['plugin_description'],
            'menu_title' => $data['menu_title'],
            'menu_icon' => $data['menu_icon'],
            'logo_url' => $data['logo_url'],
            'hide_plugin_row' => $data['hide_plugin_row'],
            'hide_help_links' => $data['hide_help_links'],
            'footer_text' => $data['footer_text'],
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Push current branding to a single site over HMAC REST.
     */
    public function syncSite(Site $site): bool
    {
        if (! $site->companion_installed || empty($site->companion_secret)) {
            return false;
        }

        try {
            $client = new ClockworkCompanionClient($site);
            $res = $client->pushBranding($this->payload());

            return ! empty($res['ok']);
        } catch (Throwable $e) {
            Log::warning('companion.branding_sync_failed', [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Push current branding to all connected sites across the fleet.
     *
     * @return array{total: int, successful: int, failed: int, errors: array<string, string>}
     */
    public function syncFleet(): array
    {
        $sites = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false)
            ->get();

        $total = $sites->count();
        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ($sites as $site) {
            if ($this->syncSite($site)) {
                $successful++;
            } else {
                $failed++;
                $errors[$site->domain] = 'Connection or endpoint failure';
            }
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
