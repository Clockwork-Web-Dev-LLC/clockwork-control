<?php

namespace Modules\Seo\Ai;

/**
 * The AI assistants Control can ask, through DataForSEO's queued
 * endpoints. Each returns the answer a logged-out person would see for a
 * country and language, with the sources it links to.
 */
final class AiEngines
{
    /**
     * key => [label, post path, get path with {id}, price per check]
     *
     * Prices are DataForSEO's published standard-queue prices, not yet
     * confirmed by a real call.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: float}>
     */
    public const ALL = [
        'chat_gpt' => ['ChatGPT', '/v3/ai_optimization/chat_gpt/llm_scraper/task_post', '/v3/ai_optimization/chat_gpt/llm_scraper/task_get/advanced/{id}', 0.0012],
        'gemini' => ['Gemini', '/v3/ai_optimization/gemini/llm_scraper/task_post', '/v3/ai_optimization/gemini/llm_scraper/task_get/advanced/{id}', 0.0012],
        'google_ai_mode' => ['Google AI Mode', '/v3/serp/google/ai_mode/task_post', '/v3/serp/google/ai_mode/task_get/advanced/{id}', 0.0012],
    ];

    public static function label(string $engine): string
    {
        return self::ALL[$engine][0] ?? $engine;
    }

    public static function kind(string $engine): string
    {
        return "ai_{$engine}";
    }

    public static function price(string $engine): float
    {
        return self::ALL[$engine][3] ?? 0.0;
    }
}
