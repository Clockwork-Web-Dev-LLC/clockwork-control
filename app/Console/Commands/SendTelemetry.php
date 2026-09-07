<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryPayloadBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelemetry extends Command
{
    protected $signature = 'clockwork:send-telemetry';

    protected $description = 'Send the anonymous usage report (site count and enabled modules — nothing else) to the project maintainer. On by default; disable anytime via settings or CLOCKWORK_TELEMETRY_ENABLED=false.';

    public function handle(TelemetryPayloadBuilder $builder): int
    {
        $endpoint = (string) config('clockwork.telemetry.endpoint');
        $payload = $builder->build();

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Clockwork-Control/'.config('clockwork.version', '1.1.0')])
                ->post($endpoint, $payload);

            if (! $response->successful()) {
                Log::warning('telemetry.send_failed', ['status' => $response->status()]);
                $this->warn("Telemetry send failed: HTTP {$response->status()}");

                return self::SUCCESS; // never treat this as a real failure — never blocks the scheduler
            }
        } catch (Throwable $e) {
            Log::warning('telemetry.send_failed', ['error' => $e->getMessage()]);
            $this->warn("Telemetry send failed: {$e->getMessage()}");

            return self::SUCCESS;
        }

        $this->info('Telemetry sent: '.json_encode($payload));

        return self::SUCCESS;
    }
}
