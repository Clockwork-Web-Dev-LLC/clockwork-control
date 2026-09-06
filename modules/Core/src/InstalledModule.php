<?php

namespace Modules\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $module_id
 * @property string $name
 * @property string $source 'bundled' | 'marketplace' | 'custom'
 * @property ?string $provider_class
 * @property ?string $repo_url
 * @property ?string $version
 * @property bool $enabled
 * @property string $status 'active' | 'pending_review' | 'installing' | 'failed' | 'removed'
 * @property ?string $install_log
 * @property ?Carbon $installed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class InstalledModule extends Model
{
    protected $fillable = [
        'module_id',
        'name',
        'source',
        'provider_class',
        'repo_url',
        'version',
        'enabled',
        'status',
        'install_log',
        'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'installed_at' => 'datetime',
        ];
    }
}
