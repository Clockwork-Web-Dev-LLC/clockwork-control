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

    public const DEFAULT_BRAND_TEXT = 'Companion';

    public const DEFAULT_LOGO_URL = 'https://clockworkwd.com/wp-content/mu-plugins/clockwork-companion/assets/clockwork-logo.png';

    public const DEFAULT_PRIMARY_COLOR = '#2D2062';

    public const DEFAULT_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_MASTER_PRIMARY_COLOR = '#2D2062';

    public const DEFAULT_MASTER_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_REPORTS_PRIMARY_COLOR = '#2D2062';

    public const DEFAULT_REPORTS_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_EMAIL_HEADER_BG = '#2D2062';

    public const DEFAULT_EMAIL_ACCENT_COLOR = '#7EFF83';

    public const DEFAULT_EMAIL_BADGE_TEXT = 'Security Alert';

    public const HEX_COLOR_REGEX = '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/';

    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Laravel validation rules for a brand palette hex color.
     *
     * @return list<string>
     */
    public static function hexColorRules(): array
    {
        return ['nullable', 'string', 'max:7', 'regex:'.self::HEX_COLOR_REGEX];
    }

    /**
     * Accept #RGB / #RRGGBB (any case); otherwise return $default.
     */
    public static function normalizeHexColor(mixed $value, string $default): string
    {
        $color = trim((string) $value);

        return preg_match(self::HEX_COLOR_REGEX, $color) === 1 ? $color : $default;
    }

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
     *     brand_text: string,
     *     logo_url: string,
     *     primary_color: string,
     *     accent_color: string,
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
        $brandText = (string) $this->settings->get('companion.branding.brand_text', self::DEFAULT_BRAND_TEXT);
        $logoUrl = (string) $this->settings->get('companion.branding.logo_url', '');
        $primaryColor = self::normalizeHexColor(
            $this->settings->get('companion.branding.primary_color', self::DEFAULT_PRIMARY_COLOR),
            self::DEFAULT_PRIMARY_COLOR,
        );
        $accentColor = self::normalizeHexColor(
            $this->settings->get('companion.branding.accent_color', self::DEFAULT_ACCENT_COLOR),
            self::DEFAULT_ACCENT_COLOR,
        );
        $hidePluginRow = (bool) $this->settings->get('companion.branding.hide_plugin_row', false);
        $hideHelpLinks = (bool) $this->settings->get('companion.branding.hide_help_links', false);
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
            'brand_text' => $brandText !== '' ? $brandText : self::DEFAULT_BRAND_TEXT,
            'logo_url' => $logoUrl,
            'primary_color' => $primaryColor,
            'accent_color' => $accentColor,
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

        if (array_key_exists('brand_text', $data)) {
            $payload['companion.branding.brand_text'] = trim((string) $data['brand_text']);
        }

        if (array_key_exists('primary_color', $data)) {
            $payload['companion.branding.primary_color'] = self::normalizeHexColor(
                $data['primary_color'],
                self::DEFAULT_PRIMARY_COLOR,
            );
        }

        if (array_key_exists('accent_color', $data)) {
            $payload['companion.branding.accent_color'] = self::normalizeHexColor(
                $data['accent_color'],
                self::DEFAULT_ACCENT_COLOR,
            );
        }

        if (array_key_exists('logo_url', $data)) {
            $incoming = trim((string) $data['logo_url']);
            $current = (string) $this->settings->get('companion.branding.logo_url', '');

            // The Companion form always posts logo_url. Re-saving the same
            // uploaded URL must not delete the file from disk.
            if ($incoming !== $current) {
                $this->deleteStoredLogoFile();
                $payload['companion.branding.logo_url'] = $incoming;
                $payload['companion.branding.logo_path'] = null;
            }
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
            'companion.branding.brand_text' => null,
            'companion.branding.logo_url' => null,
            'companion.branding.logo_path' => null,
            'companion.branding.hide_plugin_row' => null,
            'companion.branding.hide_help_links' => null,
            'companion.branding.footer_text' => null,
            'companion.branding.primary_color' => null,
            'companion.branding.accent_color' => null,
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
        $primaryColor = self::normalizeHexColor(
            $this->settings->get('reports.branding.primary_color', self::DEFAULT_REPORTS_PRIMARY_COLOR),
            self::DEFAULT_REPORTS_PRIMARY_COLOR,
        );
        $accentColor = self::normalizeHexColor(
            $this->settings->get('reports.branding.accent_color', self::DEFAULT_REPORTS_ACCENT_COLOR),
            self::DEFAULT_REPORTS_ACCENT_COLOR,
        );
        $footerText = (string) $this->settings->get('reports.branding.footer_text', '');

        $isCustom = $enabled || $companyName !== '' || $primaryColor !== self::DEFAULT_REPORTS_PRIMARY_COLOR;

        return [
            'enabled' => $enabled,
            'company_name' => $companyName !== '' ? $companyName : $shared['company_name'],
            'company_url' => $shared['company_url'],
            'support_email' => $supportEmail !== '' ? $supportEmail : $shared['support_email'],
            'support_url' => $supportUrl !== '' ? $supportUrl : $shared['support_url'],
            'logo_url' => $logoUrl !== '' ? $logoUrl : $shared['logo_url'],
            'primary_color' => $primaryColor,
            'accent_color' => $accentColor,
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
            'reports.branding.primary_color' => self::normalizeHexColor($data['primary_color'] ?? null, self::DEFAULT_REPORTS_PRIMARY_COLOR),
            'reports.branding.accent_color' => self::normalizeHexColor($data['accent_color'] ?? null, self::DEFAULT_REPORTS_ACCENT_COLOR),
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
        $headerBg = self::normalizeHexColor(
            $this->settings->get('email.branding.header_bg', self::DEFAULT_EMAIL_HEADER_BG),
            self::DEFAULT_EMAIL_HEADER_BG,
        );
        $accentColor = self::normalizeHexColor(
            $this->settings->get('email.branding.accent_color', self::DEFAULT_EMAIL_ACCENT_COLOR),
            self::DEFAULT_EMAIL_ACCENT_COLOR,
        );
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
            'header_bg' => $headerBg,
            'accent_color' => $accentColor,
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
            'email.branding.header_bg' => self::normalizeHexColor($data['header_bg'] ?? null, self::DEFAULT_EMAIL_HEADER_BG),
            'email.branding.accent_color' => self::normalizeHexColor($data['accent_color'] ?? null, self::DEFAULT_EMAIL_ACCENT_COLOR),
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
            'brand_text' => $data['brand_text'],
            'logo_url' => $data['logo_url'],
            'hide_plugin_row' => $data['hide_plugin_row'],
            'hide_help_links' => $data['hide_help_links'],
            'footer_text' => $data['footer_text'],
            'primary_color' => $data['primary_color'],
            'primary_dark_color' => $data['primary_color'],
            'accent_color' => $data['accent_color'],
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

    /**
     * Get the master agency color palette.
     *
     * @return array{primary_color: string, accent_color: string}
     */
    public function getMasterPalette(): array
    {
        return [
            'primary_color' => self::normalizeHexColor(
                $this->settings->get('master.branding.primary_color', self::DEFAULT_MASTER_PRIMARY_COLOR),
                self::DEFAULT_MASTER_PRIMARY_COLOR,
            ),
            'accent_color' => self::normalizeHexColor(
                $this->settings->get('master.branding.accent_color', self::DEFAULT_MASTER_ACCENT_COLOR),
                self::DEFAULT_MASTER_ACCENT_COLOR,
            ),
        ];
    }

    /**
     * Save the master agency color palette, optionally cascading it to all 3 hubs.
     *
     * @param  array{primary_color?: string, accent_color?: string}  $data
     */
    public function saveMasterPalette(array $data, bool $applyToAll = false): void
    {
        $primary = self::normalizeHexColor($data['primary_color'] ?? null, self::DEFAULT_MASTER_PRIMARY_COLOR);
        $accent = self::normalizeHexColor($data['accent_color'] ?? null, self::DEFAULT_MASTER_ACCENT_COLOR);

        $payload = [
            'master.branding.primary_color' => $primary,
            'master.branding.accent_color' => $accent,
        ];

        if ($applyToAll) {
            $payload['companion.branding.primary_color'] = $primary;
            $payload['companion.branding.accent_color'] = $accent;
            $payload['reports.branding.primary_color'] = $primary;
            $payload['reports.branding.accent_color'] = $accent;
            $payload['email.branding.header_bg'] = $primary;
            $payload['email.branding.accent_color'] = $accent;
        }

        $this->settings->putMany($payload);
    }

    /**
     * Derive a harmonious pastel soft color (e.g. for badges, chips, card highlights)
     * by blending the primary dark hex with 85% white.
     */
    public static function deriveSoftColor(string $hex): string
    {
        $hex = ltrim(self::normalizeHexColor($hex, self::DEFAULT_PRIMARY_COLOR), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return '#E0DEE7';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $softR = (int) round($r * 0.15 + 255 * 0.85);
        $softG = (int) round($g * 0.15 + 255 * 0.85);
        $softB = (int) round($b * 0.15 + 255 * 0.85);

        return sprintf('#%02X%02X%02X', $softR, $softG, $softB);
    }

    /**
     * Derive a vibrant medium interactive tone from a dark primary hex color
     * to ensure two-tone contrast between header and active tabs/buttons.
     */
    public static function deriveMediumTone(string $hex): string
    {
        $hex = ltrim(self::normalizeHexColor($hex, self::DEFAULT_PRIMARY_COLOR), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) {
            return '#6953C4';
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            $targetL = min(max($l + 0.30, 0.45), 0.65);
            $v = (int) round($targetL * 255);

            return sprintf('#%02X%02X%02X', $v, $v, $v);
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };
        $h /= 6;

        $targetL = min(max($l + 0.28, 0.48), 0.65);
        $targetS = max($s, 0.50);

        $q = $targetL < 0.5 ? $targetL * (1 + $targetS) : $targetL + $targetS - $targetL * $targetS;
        $p = 2 * $targetL - $q;

        $hue2rgb = function ($p, $q, $t) {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };

        $medR = (int) round($hue2rgb($p, $q, $h + 1 / 3) * 255);
        $medG = (int) round($hue2rgb($p, $q, $h) * 255);
        $medB = (int) round($hue2rgb($p, $q, $h - 1 / 3) * 255);

        return sprintf('#%02X%02X%02X', $medR, $medG, $medB);
    }
}
