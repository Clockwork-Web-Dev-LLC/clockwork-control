<?php

namespace Modules\Seo\Support;

use App\Support\Settings;

/**
 * Non-secret SEO settings, stored in app_settings. Secrets (the Google client
 * secret) live in integration_credentials instead.
 */
class SeoSettings
{
    /** No account is assumed: each agency names its own, or leaves it empty. */
    public const DEFAULT_LOGIN_HINT = '';

    public const DEFAULT_FINAL_AFTER_DAYS = 5;

    /**
     * GA4 sessionSource values that count as AI assistants, for history before
     * GA4's AI Assistant channel (2026-05-13) and for YoY. RE2 syntax.
     */
    public const DEFAULT_AI_REFERRER_REGEX = '(chatgpt\.com|chat\.openai\.com|perplexity\.ai|gemini\.google\.com|copilot\.microsoft\.com|claude\.ai|chat\.deepseek\.com|grok\.com|meta\.ai|you\.com|chat\.mistral\.ai)';

    public function __construct(private readonly Settings $settings) {}

    public function loginHint(): string
    {
        return (string) $this->settings->get('clockwork.seo.login_hint', self::DEFAULT_LOGIN_HINT);
    }

    /** Days after month end before a month's numbers count as final. */
    public function finalAfterDays(): int
    {
        return max(1, (int) $this->settings->get('clockwork.seo.final_after_days', self::DEFAULT_FINAL_AFTER_DAYS));
    }

    public function aiReferrerRegex(): string
    {
        $value = (string) $this->settings->get('clockwork.seo.ai_referrer_regex', '');

        return $value !== '' ? $value : self::DEFAULT_AI_REFERRER_REGEX;
    }

    /** @param array{login_hint: ?string, final_after_days: int, ai_referrer_regex: ?string} $values */
    public function save(array $values): void
    {
        $this->settings->put('clockwork.seo.login_hint', trim((string) $values['login_hint']));
        $this->settings->put('clockwork.seo.final_after_days', $values['final_after_days']);
        $this->settings->put('clockwork.seo.ai_referrer_regex', trim((string) $values['ai_referrer_regex']));
    }
}
