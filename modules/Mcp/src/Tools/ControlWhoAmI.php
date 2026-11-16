<?php

namespace Modules\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Core\ModuleRegistry;
use Modules\Mcp\Support\McpUser;

#[Name('control_whoami')]
#[Title('Who am I in Control')]
#[Description('The Control user this connection acts as, their role, and which tools are available. Call it first if a tool you expect is missing.')]
#[IsReadOnly]
#[IsIdempotent]
class ControlWhoAmI extends Tool
{
    public function handle(Request $request, ModuleRegistry $registry): Response|ResponseFactory
    {
        $user = McpUser::from($request);
        if ($user === null) {
            return Response::error('No active Control user is attached to this connection.');
        }

        $tools = array_map(
            fn (string $class) => app($class)->name(),
            $registry->mcpTools(),
        );
        sort($tools);

        return Response::structured([
            'summary' => "{$user->name} ({$user->role}) on Clockwork Control ".config('clockwork.version').'.',
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'server_version' => (string) config('clockwork.version'),
            'tools' => ['control_whoami', ...$tools],
        ]);
    }
}
