<?php

namespace Modules\Seo\Google;

use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;

/**
 * Copies the Search Console properties the connection can see into
 * seo_google_properties, so the profile page can offer them without calling
 * Google. Properties that disappear keep their old last_seen_at, which the
 * profile page flags. GA4 properties come from the Admin API in Phase 1b.
 */
class PropertyDiscovery
{
    public function __construct(private readonly SearchConsoleClient $searchConsole) {}

    /** @return GoogleApiResult|int the error, or how many properties were seen */
    public function refresh(GoogleConnection $connection): GoogleApiResult|int
    {
        $result = $this->searchConsole->listSites($connection);
        if (! $result->ok) {
            return $result;
        }

        $now = now();
        $seen = 0;

        foreach ((array) ($result->json['siteEntry'] ?? []) as $entry) {
            $siteUrl = is_array($entry) ? ($entry['siteUrl'] ?? null) : null;
            if (! is_string($siteUrl) || $siteUrl === '') {
                continue;
            }

            GoogleProperty::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'kind' => GoogleProperty::KIND_GSC, 'property' => $siteUrl],
                [
                    'display_name' => $siteUrl,
                    'permission_level' => is_string($entry['permissionLevel'] ?? null) ? $entry['permissionLevel'] : null,
                    'last_seen_at' => $now,
                ],
            );
            $seen++;
        }

        return $seen;
    }
}
