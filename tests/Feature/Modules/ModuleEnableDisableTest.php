<?php

use App\Services\CloudProvider\CloudProviderRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Modules\Azure\AzureServiceProvider;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleCatalog;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleStateResolver;
use Modules\Core\NullCloudProvider;
use Modules\Slack\SlackServiceProvider;
use Modules\Vultr\VultrServiceProvider;

beforeEach(function () {
    app(ModuleStateResolver::class)->flush();
});

afterEach(function () {
    app(ModuleStateResolver::class)->flush();
});

it('defaults to enabled for all bundled modules when installed_modules is empty (fail-open)', function () {
    $resolver = app(ModuleStateResolver::class);

    expect($resolver->isEnabled('slack'))->toBeTrue()
        ->and($resolver->isEnabled('azure'))->toBeTrue()
        ->and($resolver->isEnabled('spinupwp'))->toBeTrue()
        ->and($resolver->isEnabled('non_existent_module'))->toBeTrue();
});

it('resolves false when a module is explicitly disabled in installed_modules', function () {
    InstalledModule::create([
        'module_id' => 'azure',
        'name' => 'Azure',
        'enabled' => false,
    ]);

    $resolver = app(ModuleStateResolver::class);
    $resolver->flush();

    expect($resolver->isEnabled('azure'))->toBeFalse()
        ->and($resolver->isEnabled('slack'))->toBeTrue();
});

it('omits a disabled module from ModuleRegistry manifests', function () {
    InstalledModule::create([
        'module_id' => 'azure',
        'name' => 'Azure',
        'enabled' => false,
    ]);

    $resolver = app(ModuleStateResolver::class);
    $resolver->flush();

    $registry = new ModuleRegistry;
    $app = app();
    $app->instance(ModuleRegistry::class, $registry);

    // Register Azure while disabled
    $azureProvider = new AzureServiceProvider($app);
    $azureProvider->register();

    $ids = collect($registry->manifests())->pluck('id')->all();
    expect($ids)->not->toContain('azure');

    // Re-enable and register
    InstalledModule::where('module_id', 'azure')->update(['enabled' => true]);
    $resolver->flush();

    $azureProvider->register();
    $ids = collect($registry->manifests())->pluck('id')->all();
    expect($ids)->toContain('azure');
});

it('falls back to NullCloudProvider when a cloud provider module is disabled', function () {
    InstalledModule::create([
        'module_id' => 'vultr',
        'name' => 'Vultr',
        'enabled' => false,
    ]);

    $resolver = app(ModuleStateResolver::class);
    $resolver->flush();

    $registry = new ModuleRegistry;
    app()->instance(ModuleRegistry::class, $registry);

    // Re-register Vultr provider
    $vultrProvider = new VultrServiceProvider(app());
    $vultrProvider->register();

    $cloudRegistry = app(CloudProviderRegistry::class);
    $resolved = $cloudRegistry->resolve('vultr');

    expect($resolved)->toBeInstanceOf(NullCloudProvider::class);
});

it('does not tag SlackNotifier into clockwork.notifiers when Slack is disabled', function () {
    $container = new Container;
    $resolver = Mockery::mock(ModuleStateResolver::class);
    $resolver->shouldReceive('isEnabled')->with('slack')->andReturn(false);
    $container->instance(ModuleStateResolver::class, $resolver);
    $container->instance(ModuleRegistry::class, new ModuleRegistry);

    /** @var Application $container */
    $provider = new SlackServiceProvider($container);
    $provider->register();

    expect($container->tagged('clockwork.notifiers'))->toBeEmpty();

    // Now test enabled
    $containerEnabled = new Container;
    $resolverEnabled = Mockery::mock(ModuleStateResolver::class);
    $resolverEnabled->shouldReceive('isEnabled')->with('slack')->andReturn(true);
    $containerEnabled->instance(ModuleStateResolver::class, $resolverEnabled);
    $containerEnabled->instance(ModuleRegistry::class, new ModuleRegistry);

    /** @var Application $containerEnabled */
    $providerEnabled = new SlackServiceProvider($containerEnabled);
    $providerEnabled->register();

    expect($containerEnabled->tagged('clockwork.notifiers'))->toHaveCount(1);
});

it('returns all bundled modules from ModuleCatalog independent of enabled state', function () {
    InstalledModule::create([
        'module_id' => 'kinsta',
        'name' => 'Kinsta',
        'enabled' => false,
    ]);

    $bundled = ModuleCatalog::bundled();

    expect($bundled)->toHaveKey('kinsta')
        ->and($bundled)->toHaveKey('spinupwp')
        ->and($bundled)->toHaveKey('slack')
        ->and($bundled['kinsta']['manifest']->name)->toBe('Kinsta');
});
