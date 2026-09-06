<?php

/*
|--------------------------------------------------------------------------
| Structural invariants
|--------------------------------------------------------------------------
|
| These don't test behavior — they test that the codebase keeps the shape
| it's supposed to have. Cheap to run, cheap to keep green, and the first
| thing a new contributor's PR bumps into if it drifts.
|
| Deliberately NOT using arch()->preset()->laravel() wholesale — its
| controller-method whitelist (only index/show/create/store/edit/update/
| destroy/__invoke) doesn't fit this app: most controllers here have
| legitimate custom domain actions (installCompanion, bulkUpdate,
| runCustomerSync, etc.), which is normal, sensible design for an app this
| shape, not a smell the preset should be forcing us away from. Its Mail
| rule (must implement ShouldQueue) also doesn't hold — both Mail classes
| here send synchronously on purpose. Cherry-picking only the rules that
| are actually true today, verified one at a time, rather than force-
| fitting the whole preset and papering over the mismatches.
*/

arch('no debug statements left in the codebase')
    ->expect(['dd', 'ddd', 'dump', 'var_dump', 'ray', 'print_r', 'exit'])
    ->not->toBeUsed();

arch('env() is only read from config/ files — everywhere else must use config()')
    ->expect('env')
    ->not->toBeUsed();

arch('console commands extend Laravel\'s base Command')
    ->expect('App\Console\Commands')
    ->classes()
    ->toExtend('Illuminate\Console\Command');

arch('controllers extend the app\'s base Controller')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toExtend('App\Http\Controllers\Controller')
    ->ignoring('App\Http\Controllers\Controller');

arch('models live under App\Models and extend Eloquent\'s base Model')
    ->expect('App\Models')
    ->classes()
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('queued jobs implement ShouldQueue')
    ->expect('App\Jobs')
    ->classes()
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('mailables extend Laravel\'s base Mailable')
    ->expect('App\Mail')
    ->classes()
    ->toExtend('Illuminate\Mail\Mailable');

arch('every HostingProvider module implements the shared contract')
    ->expect([
        'Modules\Pressable\PressableHostingProvider',
        'Modules\SpinupWp\SpinupWpHostingProvider',
        'Modules\WPEngine\WPEngineHostingProvider',
        'Modules\Kinsta\KinstaHostingProvider',
        'Modules\Cloudways\CloudwaysHostingProvider',
    ])
    ->toImplement('Modules\Core\Contracts\HostingProvider');

arch('every CloudProvider module implements the shared contract')
    ->expect([
        'Modules\Azure\AzureCloudProvider',
        'Modules\Hetzner\HetznerCloudProvider',
        'Modules\DigitalOcean\DigitalOceanCloudProvider',
        'Modules\Cloudways\CloudwaysCloudProvider',
        'Modules\Vultr\VultrCloudProvider',
        'Modules\Linode\LinodeCloudProvider',
    ])
    ->toImplement('Modules\Core\Contracts\CloudProvider');

arch('every module service provider extends the shared base')
    ->expect([
        'Modules\Azure\AzureServiceProvider',
        'Modules\Hetzner\HetznerServiceProvider',
        'Modules\DigitalOcean\DigitalOceanServiceProvider',
        'Modules\Vultr\VultrServiceProvider',
        'Modules\Linode\LinodeServiceProvider',
        'Modules\Pressable\PressableServiceProvider',
        'Modules\SpinupWp\SpinupWpServiceProvider',
        'Modules\WPEngine\WPEngineServiceProvider',
        'Modules\Kinsta\KinstaServiceProvider',
        'Modules\Cloudways\CloudwaysServiceProvider',
        'Modules\Mattermost\MattermostServiceProvider',
        'Modules\Slack\SlackServiceProvider',
        'Modules\Twilio\TwilioServiceProvider',
        'Modules\BillCom\BillComServiceProvider',
        'Modules\ClientSlack\ClientSlackServiceProvider',
    ])
    ->toExtend('Modules\Core\ModuleServiceProvider');

arch('installer classes never depend on Modules')
    ->expect('App\Installer')
    ->not->toUse('Modules');
