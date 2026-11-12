<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One paid API call in the spend ledger.
 *
 * @property int $id
 * @property string $provider
 * @property string $feature
 * @property string $endpoint
 * @property ?int $site_id
 * @property ?int $user_id
 * @property int $tasks
 * @property float $cost_usd
 * @property string $status
 * @property ?string $error
 * @property ?Carbon $created_at
 */
class ApiUsage extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    public const STATUS_REFUSED = 'refused';

    public $timestamps = false;

    protected $table = 'seo_api_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tasks' => 'integer',
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
