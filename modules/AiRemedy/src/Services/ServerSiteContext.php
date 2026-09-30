<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use App\Models\Site;

/**
 * The WordPress sites Clockwork knows on a server (domain, SSH user, docroot),
 * used two ways:
 *  - handed to the LLM so it proposes real paths instead of guessing, and
 *  - to check proposed/edited site commands (`wp … --path=`, `.maintenance`
 *    removal) actually target a site on that server, as the right user.
 */
class ServerSiteContext
{
    /** @var array<int, list<array{domain: string, site_user: ?string, wp_path: string}>> */
    protected array $cache = [];

    /**
     * @return list<array{domain: string, site_user: ?string, wp_path: string}>
     */
    public function sites(Server $server): array
    {
        return $this->cache[$server->id] ??= $server->sites()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get(['id', 'domain', 'site_user', 'wp_path'])
            ->map(fn (Site $site) => [
                'domain' => (string) $site->domain,
                'site_user' => $site->site_user ?: null,
                // SpinupWP layout; same fallback the rest of Control uses.
                'wp_path' => $this->normalize($site->wp_path ?: '/sites/'.$site->domain.'/files'),
            ])
            ->values()
            ->all();
    }

    /**
     * Why this command doesn't match a known site on the server, or null when
     * it's fine (or isn't a site-targeted command at all).
     */
    public function problem(string $command, Server $server): ?string
    {
        $command = trim($command);
        $sites = $this->sites($server);

        $isWp = (bool) preg_match('/^(sudo\s+-u\s+\S+\s+)?wp\s/i', $command);
        $maintenance = preg_match('/^rm\s+(-f\s+)?(\S+)\/\.maintenance$/i', $command, $mm) === 1;

        if (! $isWp && ! $maintenance) {
            return null;
        }

        $path = $maintenance ? $mm[2] : $this->pathArg($command);
        if ($path === null) {
            return 'wp-cli commands need --path=<site path> so they run against the right site.';
        }

        $site = collect($sites)->firstWhere('wp_path', $this->normalize($path));
        if ($site === null) {
            return "{$path} isn't a WordPress site Clockwork knows on this server.";
        }

        if ($isWp && preg_match('/^sudo\s+-u\s+(\S+)\s/i', $command, $um) && $site['site_user'] && $um[1] !== $site['site_user']) {
            return "{$site['domain']} runs as {$site['site_user']}, not {$um[1]}.";
        }

        return null;
    }

    /**
     * A corrected version of a mistargeted site command, when the intended site
     * is unambiguous (matched by the `sudo -u` user or a domain in the path).
     */
    public function suggestion(string $command, Server $server): ?string
    {
        $command = trim($command);
        if ($this->problem($command, $server) === null) {
            return null;
        }

        $sites = collect($this->sites($server));
        $site = null;

        if (preg_match('/^sudo\s+-u\s+(\S+)\s/i', $command, $um)) {
            $byUser = $sites->where('site_user', $um[1]);
            $site = $byUser->count() === 1 ? $byUser->first() : null;
        }

        if ($site === null) {
            $path = $this->pathArg($command) ?? (preg_match('/^rm\s+(-f\s+)?(\S+)\/\.maintenance$/i', $command, $mm) ? $mm[2] : '');
            $byDomain = $sites->filter(fn ($s) => $this->pathMentions($path.'/', $s['domain']));
            $site = $byDomain->count() === 1 ? $byDomain->first() : null;
        }

        if ($site === null) {
            return null;
        }

        if (preg_match('/^rm\s+(-f\s+)?\S+\/\.maintenance$/i', $command, $mm)) {
            return 'rm '.($mm[1] ?? '').$site['wp_path'].'/.maintenance';
        }

        $fixed = preg_replace('/\s--path=[\'"]?[^\s\'"]+[\'"]?/', ' --path='.$site['wp_path'], $command, 1, $count);
        if ($count === 0) {
            $fixed = $command.' --path='.$site['wp_path'];
        }
        if ($site['site_user']) {
            $fixed = preg_match('/^sudo\s+-u\s+\S+\s/i', (string) $fixed)
                ? preg_replace('/^sudo\s+-u\s+\S+\s/i', 'sudo -u '.$site['site_user'].' ', (string) $fixed)
                : 'sudo -u '.$site['site_user'].' '.$fixed;
        }

        return $this->problem((string) $fixed, $server) === null ? (string) $fixed : null;
    }

    /**
     * Whether a guessed path names this site: its domain, the domain without
     * `www.`, or just its first label (`/var/www/example/htdocs` → example.org).
     */
    protected function pathMentions(string $path, string $domain): bool
    {
        $bare = (string) preg_replace('/^www\./', '', $domain);
        $label = explode('.', $bare)[0];

        foreach (array_unique([$domain, $bare, $label]) as $needle) {
            if ($needle !== '' && str_contains($path, '/'.$needle.'/')) {
                return true;
            }
        }

        return false;
    }

    protected function pathArg(string $command): ?string
    {
        return preg_match('/\s--path=[\'"]?([^\s\'"]+)[\'"]?/', $command, $m) ? $m[1] : null;
    }

    protected function normalize(string $path): string
    {
        return rtrim($path, '/') ?: '/';
    }
}
