<?php

namespace Modules\AiRemedy;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Modules\AiRemedy\Services\OpenRouterClient;
use Throwable;

class AiRemedyCheck implements DiagnosticCheck
{
    public function __construct(
        protected ?OpenRouterClient $client = null
    ) {
        $this->client ??= app(OpenRouterClient::class);
    }

    public function id(): string
    {
        return 'ai-remedy';
    }

    public function name(): string
    {
        return 'AiRemedy (OpenRouter API)';
    }

    public function description(): string
    {
        return 'Tests connectivity and API key validity against OpenRouter API endpoints.';
    }

    public function run(): CheckResult
    {
        $apiKey = $this->client?->getApiKey();
        if (empty($apiKey)) {
            return CheckResult::fail('No OpenRouter API key configured');
        }

        $start = microtime(true);
        try {
            $test = $this->client->testConnection();
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($test['ok']) {
                return CheckResult::ok($test['message'], null, $ms);
            }

            return CheckResult::fail($test['message'], null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail('Connection failed', $e->getMessage(), (int) ((microtime(true) - $start) * 1000));
        }
    }
}
