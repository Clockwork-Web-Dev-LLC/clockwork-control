<?php

namespace Modules\Seo\Mcp\Support;

use App\Models\Site;
use Modules\Seo\Models\SiteProfile;

/**
 * Turns a tool's "site" input (a Control site id or a domain, with or
 * without www.) into the site's SEO profile.
 */
class SiteFinder
{
    /**
     * @return SiteProfile|string the profile, or a message that says how to fix the lookup
     */
    public static function profile(mixed $input): SiteProfile|string
    {
        $site = self::site($input);
        if ($site === null) {
            return "No site matches \"{$input}\". Use seo_list_sites to find the right id or domain.";
        }

        $profile = SiteProfile::query()->with('site')->where('site_id', $site->id)->first();
        if ($profile === null) {
            return "{$site->domain} has no SEO profile yet. Set one up on /seo/sites/{$site->id}/profile.";
        }

        return $profile;
    }

    public static function site(mixed $input): ?Site
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        if (ctype_digit($input)) {
            return Site::query()->find((int) $input);
        }

        $domain = self::normalizeDomain($input);

        return Site::query()
            ->whereIn('domain', [$domain, 'www.'.$domain])
            ->orderBy('is_inactive')
            ->orderBy('id')
            ->first();
    }

    public static function normalizeDomain(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value, 2)[0];

        return preg_replace('/^www\./', '', $value) ?? $value;
    }
}
