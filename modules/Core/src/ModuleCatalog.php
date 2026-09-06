<?php

namespace Modules\Core;

/**
 * Discovers bundled modules by inspecting bootstrap/providers.php directly.
 *
 * Used by the setup/picker UI to display all available bundled modules
 * regardless of whether they are currently enabled at runtime.
 */
class ModuleCatalog
{
    /**
     * @return array<string, array{
     *     provider_class: class-string<ModuleServiceProvider>,
     *     manifest: ModuleManifest,
     *     provider: ModuleServiceProvider,
     *     category: string
     * }>
     */
    public static function bundled(): array
    {
        $providersFile = base_path('bootstrap/providers.php');
        if (! file_exists($providersFile)) {
            return [];
        }

        /** @var list<class-string> $classes */
        $classes = require $providersFile;
        $modules = [];

        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, ModuleServiceProvider::class)) {
                /** @var ModuleServiceProvider $provider */
                $provider = new $class(app());
                $manifest = $provider->manifest();
                $modules[$manifest->id] = [
                    'provider_class' => $class,
                    'manifest' => $manifest,
                    'provider' => $provider,
                    'category' => self::categorize($provider),
                ];
            }
        }

        return $modules;
    }

    public static function categorize(ModuleServiceProvider $provider): string
    {
        $id = $provider->manifest()->id;

        if ($provider->authProvider() !== null || str_starts_with($id, 'auth_')) {
            return 'authentication';
        }

        if (in_array($id, ['gtmetrix', 'psi'], true)) {
            return 'performance';
        }

        if (in_array($id, ['sucuri', 'llar'], true)) {
            return 'security';
        }

        if (in_array($id, ['contact-forms', 'backup-relay'], true)) {
            return 'maintenance';
        }

        if (in_array($id, ['spinupwp', 'cloudways', 'gridpane'], true)) {
            return 'control_panels';
        }

        if (in_array($id, ['pressable', 'wpengine', 'kinsta'], true)) {
            return 'managed_hosts';
        }

        if (in_array($id, ['digitalocean', 'hetzner', 'vultr', 'linode', 'azure'], true) || $provider->cloudProvider() !== null) {
            return 'cloud_vps';
        }

        if (in_array($id, ['slack', 'mattermost', 'twilio', 'client_slack'], true) || $provider->smsNotifier() !== null) {
            return 'notifications';
        }

        return 'misc';
    }
}
