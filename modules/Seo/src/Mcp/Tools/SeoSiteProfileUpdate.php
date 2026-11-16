<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;
use Modules\Seo\Services\QueryFilters;
use Modules\Seo\Services\SearchRows;
use Modules\Seo\Services\SiteProfileService;
use Modules\Seo\Support\GscDates;

/**
 * What it will not touch, on purpose: the Search Console and GA4
 * properties (changing one deletes stored history) and report notes (the
 * report follows them as instructions, so text Claude read somewhere must
 * never be able to plant one). Both are edited on the profile page.
 */
#[Name('seo_site_profile_update')]
#[Title('Update a site\'s SEO profile')]
#[Description('Change a site\'s brand terms, GA4 key events or its year-over-year flag. A brand terms change re-pulls non-branded daily totals for the last 16 months, so it only applies with confirm_purge set to true; without it you get a preview and nothing changes. Use dry_run to preview any change. The Search Console and GA4 properties and the report notes can only be changed in Control, on the site\'s profile page.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class SeoSiteProfileUpdate extends SeoTool
{
    public const NAME = 'seo_site_profile_update';

    private const MAX_BRAND_TERMS = 50;

    private const MAX_KEY_EVENTS = 10;

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'brand_terms' => $schema->array()->max(self::MAX_BRAND_TERMS)->items($schema->string())
                ->description('The full new list. A query containing any term counts as branded. An empty list clears them.'),
            'confirm_purge' => $schema->boolean()->description('Needed to apply a brand_terms change. Default false.'),
            'key_events' => $schema->array()->max(self::MAX_KEY_EVENTS)->items($schema->string())
                ->description('GA4 event names the monthly report counts. An empty list goes back to the default three.'),
            'compare_yoy' => $schema->boolean()->description('True for a seasonal client whose reports compare year over year.'),
            'dry_run' => $schema->boolean()->description('Show what would change without saving. Default false.'),
        ];
    }

    public function handle(Request $request, SiteProfileService $profiles, SearchRows $searchRows, AuditLog $audit): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        foreach (['gsc_site_url', 'ga4_property_id', 'ga4_custom_primary_channel', 'report_notes'] as $locked) {
            if ($request->has($locked)) {
                return Response::error("{$locked} can't be changed from here. Edit it in Control on the site's SEO profile page.");
            }
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $attributes = [];
        $changes = [];
        $brand = null;

        if ($request->has('brand_terms')) {
            $terms = $this->stringList($request->get('brand_terms'), self::MAX_BRAND_TERMS, 100, 'brand_terms', allowCommas: false);
            if (is_string($terms)) {
                return Response::error($terms);
            }

            $joined = implode(', ', $terms);
            try {
                BrandRegex::compile($joined);
            } catch (InvalidArgumentException $e) {
                return Response::error($e->getMessage());
            }

            $newTerms = BrandRegex::parseTerms($joined);
            $oldTerms = BrandRegex::parseTerms($profile->brand_terms);

            // Matching ignores case, so a change of case alone is no change
            // and must not trigger a 16-month re-pull.
            if (array_map('mb_strtolower', $newTerms) !== array_map('mb_strtolower', $oldTerms)) {
                $attributes['brand_terms'] = $joined;
                $changes['brand_terms'] = ['before' => $oldTerms, 'after' => $newTerms];
                $brand = $this->brandPreview($profile, $profiles, $searchRows, $joined);
            }
        }

        if ($request->has('key_events')) {
            $events = $this->stringList($request->get('key_events'), self::MAX_KEY_EVENTS, 100, 'key_events', allowCommas: false);
            if (is_string($events)) {
                return Response::error($events);
            }
            foreach ($events as $event) {
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $event) !== 1) {
                    return Response::error("\"{$event}\" is not a GA4 event name: letters, numbers and underscores, starting with a letter, 40 characters at most.");
                }
            }

            $before = $profile->keyEvents();
            $after = $events === [] ? SiteProfile::DEFAULT_KEY_EVENTS : $events;
            if ($before !== $after) {
                $attributes['key_events'] = $events === [] ? null : $events;
                $changes['key_events'] = ['before' => $before, 'after' => $after];
            }
        }

        if ($request->has('compare_yoy')) {
            if (! is_bool($request->get('compare_yoy'))) {
                return Response::error('compare_yoy must be true or false.');
            }
            if ((bool) $profile->compare_yoy !== $request->get('compare_yoy')) {
                $attributes['compare_yoy'] = $request->get('compare_yoy');
                $changes['compare_yoy'] = ['before' => (bool) $profile->compare_yoy, 'after' => $request->get('compare_yoy')];
            }
        }

        $base = [
            'site' => ResponseEnvelope::site($profile),
            'changes' => $changes,
            'brand' => $brand,
        ];

        if ($changes === []) {
            return Response::structured(['summary' => "Nothing to change for {$profile->site->domain}: the profile already has these values.", 'applied' => false] + $base);
        }

        if ($request->get('dry_run', false)) {
            return Response::structured(['summary' => 'Dry run for '.$profile->site->domain.': would change '.implode(', ', array_keys($changes)).'. Nothing was saved.', 'applied' => false] + $base);
        }

        // All or nothing: an unconfirmed brand change holds back the other
        // fields too, so one call never half-applies.
        if ($brand !== null && ! $request->get('confirm_purge', false)) {
            return Response::structured([
                'summary' => "Nothing was saved. Changing brand terms for {$profile->site->domain} re-pulls {$brand['days_repulled']} days of non-branded totals"
                    .($brand['days_kept_on_old_terms'] > 0 ? " and leaves {$brand['days_kept_on_old_terms']} older days on the old terms" : '')
                    .'. Show the person this preview, then call again with confirm_purge set to true.',
                'applied' => false,
                'needs' => 'confirm_purge',
            ] + $base);
        }

        $profiles->save($profile, $attributes, $user->id);

        $audit->record(
            AuditLog::PROFILE_UPDATED,
            $user,
            self::NAME,
            $profile->site->domain,
            "Updated the SEO profile for {$profile->site->domain}: ".implode(', ', array_keys($changes)).'.',
            $changes,
        );

        return Response::structured([
            'summary' => "Saved for {$profile->site->domain}: ".implode(', ', array_keys($changes)).'.'
                .($brand !== null ? ' A re-pull of non-branded totals is queued.' : ''),
            'applied' => true,
            'resync_queued' => $brand !== null,
        ] + $base);
    }

    /**
     * What a brand terms change would do: which of last month's top
     * queries the new terms catch, and how many days get re-pulled.
     *
     * @return array<string, mixed>
     */
    private function brandPreview(SiteProfile $profile, SiteProfileService $profiles, SearchRows $searchRows, string $terms): array
    {
        $impact = $profiles->brandChangeImpact($profile);

        $top = array_slice(array_filter(
            array_column($searchRows->top($profile->site_id, 'query', $this->lastCompleteMonth(), 25), 'query'),
            fn ($q) => ! QueryFilters::isJunk((string) $q),
        ), 0, 25);

        $storedNonbrandedDays = DB::table('seo_gsc_daily')
            ->where('site_id', $profile->site_id)
            ->where('segment', 'nonbranded')
            ->where('date', '>=', $impact['repull_from'])
            ->count();

        return [
            'top_queries_checked' => count($top),
            'queries_counted_as_branded' => array_values(array_filter($top, fn ($q) => BrandRegex::matches((string) $q, $terms))),
            'repull_from' => $impact['repull_from'],
            'days_repulled' => max($storedNonbrandedDays, (int) GscDates::windowStart()->diffInDays(GscDates::yesterday()) + 1),
            'days_kept_on_old_terms' => $impact['kept_older_days'],
        ];
    }

    /**
     * @return list<string>|string the cleaned list, or what is wrong with it
     */
    private function stringList(mixed $input, int $maxItems, int $maxLength, string $field, bool $allowCommas): array|string
    {
        if (! is_array($input) || ! array_is_list($input)) {
            return "{$field} must be a list of text values.";
        }
        if (count($input) > $maxItems) {
            return "{$field} takes {$maxItems} values at most.";
        }

        $out = [];
        foreach ($input as $value) {
            if (! is_string($value)) {
                return "{$field} must be a list of text values.";
            }
            $value = trim((string) ResponseEnvelope::clip($value, $maxLength + 1));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $maxLength) {
                return "Each value in {$field} can be {$maxLength} characters at most.";
            }
            if (! $allowCommas && str_contains($value, ',')) {
                return "A value in {$field} can't contain a comma: pass each one as its own list item.";
            }
            $out[] = $value;
        }

        return $out;
    }
}
