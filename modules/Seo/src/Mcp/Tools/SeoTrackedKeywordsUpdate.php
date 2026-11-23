<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\RankSettings;
use Modules\Seo\Ranks\TrackedKeywords;

#[Name('seo_tracked_keywords_update')]
#[Title('Add, pause or resume tracked keywords')]
#[Description('Change which keywords are rank tracked for one site. action "add" starts tracking new keywords, which is a recurring cost: every keyword on every device is checked on schedule until someone pauses it, so tell the person the monthly cost in the answer and only add what they asked for. "pause" stops checks and keeps the history. "resume" restarts them. It cannot delete keywords or history; that is done in Control.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class SeoTrackedKeywordsUpdate extends SeoTool
{
    public const NAME = 'seo_tracked_keywords_update';

    private const MAX = 200;

    /** Adding keywords commits recurring spend, so Claude asks the person first. */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'action' => $schema->string()->enum(['add', 'pause', 'resume'])->required(),
            'keywords' => $schema->array()->min(1)->max(self::MAX)->items($schema->string())->description('Up to 200 keywords. For pause and resume, the keyword text as tracked.')->required(),
            'devices' => $schema->array()->items($schema->string()->enum(TrackedKeyword::DEVICES))->description('For add: default desktop only. Each device doubles the cost. For pause and resume: default both.'),
            'city' => $schema->string()->max(191)->description('For add: track in one city, written "City,Region,Country" as Google Ads names it. Default: the whole country from the site profile.'),
        ];
    }

    public function handle(Request $request, TrackedKeywords $tracked, RankSettings $settings, AuditLog $audit): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $action = (string) $request->get('action');
        if (! in_array($action, ['add', 'pause', 'resume'], true)) {
            return Response::error('action must be add, pause or resume.');
        }

        $keywords = $request->get('keywords');
        if (! is_array($keywords) || $keywords === [] || count($keywords) > self::MAX || array_filter($keywords, fn ($k) => ! is_string($k)) !== []) {
            return Response::error('keywords must be a list of 1 to '.self::MAX.' keywords.');
        }

        $devices = $request->get('devices');
        if ($devices !== null && (! is_array($devices) || $devices === [] || array_diff($devices, TrackedKeyword::DEVICES) !== [])) {
            return Response::error('devices must be desktop, mobile or both.');
        }

        $city = $request->get('city');
        if ($city !== null && (! is_string($city) || mb_strlen($city) > 191)) {
            return Response::error('city must be a place name such as "Austin,Texas,United States".');
        }

        $domain = (string) $profile->site->domain;
        $activeBefore = TrackedKeyword::query()->where('site_id', $profile->site_id)->where('is_active', true)->count();
        $perKeyword = $settings->monthlyEstimate(1000) / 1000;
        $rejected = [];
        $changed = 0;

        if ($action === 'add') {
            $result = $tracked->add($profile->site, $keywords, $devices ?? ['desktop'], $city, $user->id);
            $changed = $result['added'];
            $rejected = $result['rejected'];
            $already = $result['existing'];
        } else {
            $texts = array_values(array_unique(array_filter(array_map(fn (string $k) => KeywordMetricsService::normalize($k), $keywords))));
            $rows = TrackedKeyword::query()
                ->where('site_id', $profile->site_id)
                ->whereIn('keyword', $texts)
                ->when($devices !== null, fn ($q) => $q->whereIn('device', $devices))
                ->get();

            $target = $action === 'resume';
            $already = $rows->where('is_active', $target)->count();
            $toChange = $rows->where('is_active', ! $target);
            $changed = $toChange->count();
            TrackedKeyword::query()->whereIn('id', $toChange->pluck('id'))->update(['is_active' => $target]);

            foreach (array_diff($texts, $rows->pluck('keyword')->all()) as $missing) {
                $rejected[] = ['keyword' => $missing, 'reason' => 'not tracked for this site'];
            }
        }

        $activeAfter = TrackedKeyword::query()->where('site_id', $profile->site_id)->where('is_active', true)->count();

        if ($changed > 0) {
            $audit->record(
                AuditLog::TRACKED_KEYWORDS_UPDATED,
                $user,
                self::NAME,
                $domain,
                ucfirst($action).($action === 'add' ? 'ed' : 'd')." {$changed} tracked keyword rows for {$domain}.",
                ['tracked_keywords' => [
                    'before' => ['active' => $activeBefore],
                    'after' => ['active' => $activeAfter, 'action' => $action, 'keywords' => array_slice(array_map(fn (string $k) => KeywordMetricsService::normalize($k), $keywords), 0, self::MAX), 'devices' => $devices, 'city' => $city],
                ]],
            );
        }

        $verb = ['add' => 'Now tracking', 'pause' => 'Paused', 'resume' => 'Resumed'][$action];

        return Response::structured([
            'summary' => "{$verb} {$changed} keyword ".($changed === 1 ? 'row' : 'rows')." for {$domain}"
                .($already > 0 ? "; {$already} already ".($action === 'add' ? 'tracked' : ($action === 'pause' ? 'paused' : 'active')) : '')
                .($rejected !== [] ? '; '.count($rejected).' not accepted' : '')
                .'. '.sprintf('This site now has %d active keyword rows, about $%.2f a month at the current settings (%s, top %d).', $activeAfter, $settings->monthlyEstimate($activeAfter), $settings->frequency(), $settings->depth()),
            'site' => ResponseEnvelope::site($profile),
            'action' => $action,
            'changed' => $changed,
            'already' => $already,
            'rejected' => $rejected,
            'active_keyword_rows' => $activeAfter,
            'monthly_cost_usd' => $settings->monthlyEstimate($activeAfter),
            'monthly_cost_change_usd' => round(($activeAfter - $activeBefore) * $perKeyword, 2),
            'cost_note' => 'An estimate from DataForSEO\'s published prices, not yet confirmed by a real call. Spending is held by the rank tracking cap in Control.',
            'first_check' => $action === 'add' && $changed > 0 ? 'Queued within the hour; results usually follow within the hour after.' : null,
        ]);
    }
}
