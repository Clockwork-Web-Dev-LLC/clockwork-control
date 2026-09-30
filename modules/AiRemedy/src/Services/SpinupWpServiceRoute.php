<?php

namespace Modules\AiRemedy\Services;

use App\Models\Server;
use Modules\Core\ModuleStateResolver;
use Modules\SpinupWp\SpinupWpClient;
use Throwable;

/**
 * Runs service restarts through the SpinupWP API instead of SSH + sudo on
 * SpinupWP-managed servers: no sudo password crosses the wire, and each restart
 * lands in SpinupWP's own event log.
 *
 * Only `restart` is routed. SpinupWP has no reload endpoint, and silently
 * turning a graceful reload into a restart would drop in-flight requests, so
 * `reload` commands keep going over SSH.
 */
class SpinupWpServiceRoute
{
    /**
     * `sudo systemctl restart <svc>` / `sudo service <svc> restart` → SpinupWP service key.
     */
    public function serviceFor(string $command): ?string
    {
        $command = trim($command);

        if (preg_match('/^sudo\s+systemctl\s+restart\s+([a-zA-Z0-9_\-\.]+)$/i', $command, $m)
            || preg_match('/^sudo\s+service\s+([a-zA-Z0-9_\-\.]+)\s+restart$/i', $command, $m)) {
            $unit = strtolower(preg_replace('/\.service$/', '', $m[1]) ?? $m[1]);

            return match (true) {
                (bool) preg_match('/^php[0-9\.]*-fpm$/', $unit) => 'php',
                $unit === 'nginx' => 'nginx',
                in_array($unit, ['mysql', 'mariadb'], true) => 'mysql',
                in_array($unit, ['redis', 'redis-server'], true) => 'redis',
                default => null,
            };
        }

        return null;
    }

    /**
     * Whether this command on this server would go through the SpinupWP API.
     */
    public function applies(string $command, Server $server): bool
    {
        return $this->serviceFor($command) !== null && $this->client($server) !== null;
    }

    /**
     * @return array{exit_status: int, output: string}
     */
    public function run(string $command, Server $server): array
    {
        $service = $this->serviceFor($command);
        $client = $this->client($server);
        if ($service === null || $client === null) {
            return ['exit_status' => 1, 'output' => 'SpinupWP API route unavailable for this command.'];
        }

        try {
            $response = $client->post("/servers/{$server->spinupwp_id}/services/{$service}/restart");
            $eventId = $response->json('event_id');

            return [
                'exit_status' => 0,
                'output' => "Restart requested via SpinupWP API ({$service})".($eventId ? " — SpinupWP event #{$eventId}" : '').'.',
            ];
        } catch (Throwable $e) {
            return ['exit_status' => 1, 'output' => 'SpinupWP API restart failed: '.$e->getMessage()];
        }
    }

    protected function client(Server $server): ?SpinupWpClient
    {
        if (! $server->spinupwp_id || ! class_exists(SpinupWpClient::class)) {
            return null;
        }

        if (! app(ModuleStateResolver::class)->isEnabled('spinupwp')) {
            return null;
        }

        try {
            $client = app(SpinupWpClient::class);
        } catch (Throwable) {
            return null;
        }

        return $client->isConfigured() && ! $client->isViewOnly() ? $client : null;
    }
}
