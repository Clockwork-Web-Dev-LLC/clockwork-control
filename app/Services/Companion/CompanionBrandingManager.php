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
