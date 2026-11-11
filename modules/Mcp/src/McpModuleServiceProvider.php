<?php

namespace Modules\Mcp;

use Laravel\Mcp\Facades\Mcp;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Mcp\Servers\ClockworkServer;

/**
 * Claude access: one MCP server that other modules add tools to through
 * ModuleServiceProvider::mcpTools(). It lives in its own module, not in
 * Core, so it can be turned off.
 *
 * Only the local stdio transport is registered today ("php artisan
 * mcp:start clockwork", for Claude Code on the Control host). It has no
 * login, so it runs as the user named in CLOCKWORK_MCP_LOCAL_USER and is
 * not registered at all without one. The network endpoint, with per-user
 * OAuth, is a later step (plans/seo-semrush-replacement.md, step 8).
 */
class McpModuleServiceProvider extends ModuleServiceProvider
{
    public const LOCAL_HANDLE = 'clockwork';

    public function boot(): void
    {
        if ($this->enabled() && filled(config('clockwork.mcp.local_user'))) {
            Mcp::local(self::LOCAL_HANDLE, ClockworkServer::class);
        }
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'mcp',
            name: 'Claude access',
            description: 'Lets Claude read Control through an MCP server. Other modules add the tools; SEO is the first.',
            status: ModuleManifest::STATUS_LOOKING_FOR_TESTERS,
            statusNote: 'Local use only so far: Claude Code on the Control host, over stdio. No network endpoint yet.',
        );
    }
}
