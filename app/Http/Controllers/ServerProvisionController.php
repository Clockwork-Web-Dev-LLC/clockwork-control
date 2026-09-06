<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\Fail2ban\Fail2banProvisioner;
use Illuminate\Http\JsonResponse;

class ServerProvisionController extends Controller
{
    public function fail2ban(Server $server, Fail2banProvisioner $provisioner): JsonResponse
    {
        if (! $server->ssh_password && ! $server->ssh_private_key) {
            return response()->json([
                'ok' => false,
                'message' => 'Set an SSH password (or key) on this server before provisioning.',
                'output' => '',
                'already_provisioned' => false,
            ], 422);
        }

        $result = $provisioner->provision($server);

        return response()->json([
            ...$result,
            'provisioned_at' => $server->fresh()->clockwork_jail_provisioned_at?->diffForHumans(),
        ]);
    }
}
