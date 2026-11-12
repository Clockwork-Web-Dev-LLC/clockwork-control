<?php

namespace Modules\Seo\Ranks;

use App\Models\Site;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;

/** Adding to and removing from a site's list of tracked keywords. */
class TrackedKeywords
{
    /** DataForSEO's limit on a keyword's length. */
    public const MAX_LENGTH = 700;

    /** Most keywords accepted in one go. */
    public const MAX_PER_ADD = 500;

    /** Search operators make a check cost five times as much, so they are refused. */
    private const OPERATORS = '/(^|\s)(site|inurl|intitle|intext|allinurl|allintitle|allintext|filetype|related|cache|define):/i';

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $devices
     * @return array{added: int, existing: int, rejected: list<array{keyword: string, reason: string}>}
     */
    public function add(Site $site, array $keywords, array $devices = ['desktop'], ?string $city = null, ?int $userId = null): array
    {
        $market = Market::forProfile(SiteProfile::query()->where('site_id', $site->id)->first());
        $city = filled($city) ? trim((string) $city) : null;
        $devices = array_values(array_intersect(TrackedKeyword::DEVICES, $devices)) ?: ['desktop'];
        $locationKey = TrackedKeyword::locationKey($market->locationCode, $city);

        $summary = ['added' => 0, 'existing' => 0, 'rejected' => []];
        $seen = [];

        foreach (array_slice($keywords, 0, self::MAX_PER_ADD) as $raw) {
            $keyword = KeywordMetricsService::normalize((string) $raw);
            if ($keyword === '' || isset($seen[$keyword])) {
                continue;
            }
            $seen[$keyword] = true;

            if (mb_strlen($keyword) > min(self::MAX_LENGTH, 191)) {
                $summary['rejected'][] = ['keyword' => mb_substr($keyword, 0, 60).'…', 'reason' => 'too long'];

                continue;
            }
            if (preg_match(self::OPERATORS, $keyword) === 1) {
                $summary['rejected'][] = ['keyword' => $keyword, 'reason' => 'search operators such as site: are not tracked'];

                continue;
            }

            foreach ($devices as $device) {
                $row = TrackedKeyword::query()->firstOrNew([
                    'site_id' => $site->id, 'keyword' => $keyword, 'device' => $device, 'location_key' => $locationKey,
                ]);

                if ($row->exists && $row->is_active) {
                    $summary['existing']++;

                    continue;
                }

                $row->fill([
                    'location_code' => $market->locationCode,
                    'language_code' => $market->languageCode,
                    'location_name' => $city,
                    'is_active' => true,
                    'added_by' => $row->exists ? $row->added_by : $userId,
                ])->save();
                $summary['added']++;
            }
        }

        if (count($keywords) > self::MAX_PER_ADD) {
            $summary['rejected'][] = ['keyword' => (count($keywords) - self::MAX_PER_ADD).' more', 'reason' => 'only '.self::MAX_PER_ADD.' can be added at once'];
        }

        return $summary;
    }

    /**
     * Competitor domains from free text: one per line or comma separated.
     *
     * @return list<string>
     */
    public static function parseDomains(?string $text, string $ownDomain, int $max = 10): array
    {
        $own = SerpParser::host($ownDomain);
        $domains = [];

        foreach (preg_split('/[\s,;]+/', (string) $text) ?: [] as $part) {
            $host = SerpParser::host($part);
            if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1 || $host === $own) {
                continue;
            }
            $domains[$host] = true;
        }

        return array_slice(array_keys($domains), 0, $max);
    }
}
