<?php

namespace App\Console\Commands;

use App\Models\AllowedBot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('clockwork:sync-allowed-bots')]
#[Description('Fetch the arcjet/well-known-bots JSON and upsert UA patterns into the allowed_bots table.')]
class SyncAllowedBots extends Command
{
    public function handle(): int
    {
        $url = (string) config('clockwork.arcjet.bots_url');
        $this->info("Fetching well-known bots from {$url}");

        $response = Http::timeout(30)->retry(2, 500)->get($url);

        if ($response->failed()) {
            $this->error("Fetch failed: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $bots = $response->json();

        if (! is_array($bots)) {
            $this->error('Unexpected response: not a JSON array.');

            return self::FAILURE;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($bots as $bot) {
            if (! is_array($bot) || empty($bot['id'])) {
                continue;
            }

            $patterns = $bot['pattern']['accepted'] ?? [];

            if (! is_array($patterns) || $patterns === []) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || $pattern === '') {
                    continue;
                }

                $rows[] = [
                    'name' => (string) $bot['id'],
                    'ua_pattern' => $pattern,
                    'pattern_type' => AllowedBot::PATTERN_REGEX,
                    'source' => AllowedBot::SOURCE_ARCJET,
                    'reference_url' => $bot['url'] ?? null,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            $this->warn('No bot patterns found in payload.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $now) {
            foreach (array_chunk($rows, 500) as $chunk) {
                AllowedBot::upsert(
                    $chunk,
                    uniqueBy: ['source', 'ua_pattern'],
                    update: ['name', 'pattern_type', 'reference_url', 'synced_at', 'updated_at'],
                );
            }

            AllowedBot::query()
                ->where('source', AllowedBot::SOURCE_ARCJET)
                ->where('synced_at', '<', $now)
                ->delete();
        });

        $this->info('Synced '.count($rows).' bot patterns from arcjet/well-known-bots.');

        return self::SUCCESS;
    }
}
