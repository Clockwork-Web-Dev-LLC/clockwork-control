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
use Modules\Seo\Ai\AiEngines;
use Modules\Seo\Ai\AiPrompts;
use Modules\Seo\Ai\AiVisibilitySettings;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\AiPrompt;

#[Name('seo_ai_prompts_update')]
#[Title('Add, pause or resume AI visibility prompts')]
#[Description('Change which prompts are asked of the AI engines for one site. action "add" starts tracking new prompts, which is a recurring cost: each prompt is asked of every engine on schedule until someone pauses it, so tell the person the monthly cost in the answer and only add what they agreed. A prompt is a question a customer would ask an AI assistant ("who is the best emergency plumber in Austin?"), not a keyword, and should not name the client. Up to 50 active prompts per site. "pause" stops asking and keeps the history; "resume" restarts. It cannot delete prompts or history.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class SeoAiPromptsUpdate extends SeoTool
{
    public const NAME = 'seo_ai_prompts_update';

    /** Adding prompts commits recurring spend, so Claude asks the person first. */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'action' => $schema->string()->enum(['add', 'pause', 'resume'])->required(),
            'prompts' => $schema->array()->min(1)->max(50)->items($schema->string())->description('For add: the prompts. For pause and resume: prompt text as tracked, or prompt ids from seo_ai_visibility.')->required(),
        ];
    }

    public function handle(Request $request, AiPrompts $prompts, AiVisibilitySettings $settings, AuditLog $audit): Response|ResponseFactory
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

        $list = $request->get('prompts');
        if (! is_array($list) || $list === [] || count($list) > 50 || array_filter($list, fn ($p) => ! is_string($p) && ! is_int($p)) !== []) {
            return Response::error('prompts must be a list of 1 to 50 prompts.');
        }

        $domain = (string) $profile->site->domain;
        $activeBefore = AiPrompt::query()->where('site_id', $profile->site_id)->where('is_active', true)->count();
        $rejected = [];

        if ($action === 'add') {
            $result = $prompts->add($profile->site, array_map('strval', $list), $user->id);
            $changed = $result['added'];
            $already = $result['existing'];
            $rejected = $result['rejected'];
        } else {
            $ids = [];
            $hashes = [];
            foreach ($list as $item) {
                is_int($item) || ctype_digit((string) $item) ? $ids[] = (int) $item : $hashes[] = AiPrompt::hash((string) $item);
            }
            $rows = AiPrompt::query()->where('site_id', $profile->site_id)
                ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('prompt_hash', $hashes))->get();

            $target = $action === 'resume';
            $already = $rows->where('is_active', $target)->count();
            $toChange = $rows->where('is_active', ! $target);
            if ($target && $activeBefore + $toChange->count() > AiVisibilitySettings::MAX_PROMPTS_PER_SITE) {
                return Response::error('Resuming these would pass the limit of '.AiVisibilitySettings::MAX_PROMPTS_PER_SITE.' active prompts for a site. Pause others first.');
            }
            $changed = $toChange->count();
            AiPrompt::query()->whereIn('id', $toChange->pluck('id'))->update(['is_active' => $target]);

            if ($rows->count() < count($list)) {
                $rejected[] = ['prompt' => (count($list) - $rows->count()).' of the prompts given', 'reason' => 'not tracked for this site'];
            }
        }

        $activeAfter = AiPrompt::query()->where('site_id', $profile->site_id)->where('is_active', true)->count();

        if ($changed > 0) {
            $audit->record(AuditLog::AI_PROMPTS_UPDATED, $user, self::NAME, $domain, ucfirst($action).($action === 'add' ? 'ed' : 'd')." {$changed} AI visibility prompts for {$domain}.", ['ai_prompts' => [
                'before' => ['active' => $activeBefore],
                'after' => ['active' => $activeAfter, 'action' => $action, 'prompts' => array_slice(array_map('strval', $list), 0, 50)],
            ]]);
        }

        $verb = ['add' => 'Now tracking', 'pause' => 'Paused', 'resume' => 'Resumed'][$action];
        $engines = array_map([AiEngines::class, 'label'], $settings->engines());

        return Response::structured([
            'summary' => "{$verb} {$changed} ".($changed === 1 ? 'prompt' : 'prompts')." for {$domain}"
                .($already > 0 ? "; {$already} already ".($action === 'add' ? 'tracked' : ($action === 'pause' ? 'paused' : 'active')) : '')
                .($rejected !== [] ? '; '.count($rejected).' not accepted' : '')
                .'. '.sprintf('This site now has %d active prompts, asked %s of %s: about $%.2f a month.', $activeAfter, $settings->frequency(), implode(', ', $engines) ?: 'no engine', $settings->monthlyEstimate($activeAfter)),
            'site' => ResponseEnvelope::site($profile),
            'action' => $action,
            'changed' => $changed,
            'already' => $already,
            'rejected' => $rejected,
            'active_prompts' => $activeAfter,
            'monthly_cost_usd' => $settings->monthlyEstimate($activeAfter),
            'monthly_cost_change_usd' => round($settings->monthlyEstimate($activeAfter) - $settings->monthlyEstimate($activeBefore), 2),
            'cost_note' => 'An estimate from DataForSEO\'s published prices, not yet confirmed by a real call. Spending is held by the AI visibility cap in Control.',
            'first_check' => $action === 'add' && $changed > 0 ? 'Queued within the hour; answers can take up to 45 minutes after that.' : null,
        ]);
    }
}
