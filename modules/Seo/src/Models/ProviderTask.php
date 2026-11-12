<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A task posted to a provider's queue, waiting to be collected.
 *
 * @property int $id
 * @property string $provider
 * @property string $feature
 * @property string $kind
 * @property string $task_id
 * @property ?int $site_id
 * @property ?array $context
 * @property string $status
 * @property int $attempts
 * @property float $cost_usd
 * @property ?string $error
 * @property ?Carbon $posted_at
 * @property ?Carbon $collected_at
 */
class ProviderTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $table = 'seo_provider_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'attempts' => 'integer',
            'cost_usd' => 'float',
            'posted_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }
}
