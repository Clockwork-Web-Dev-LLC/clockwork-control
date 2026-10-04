<?php

namespace Modules\EmailAuth\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain
 * @property ?array<int, string> $custom_dkim_selectors
 * @property ?Carbon $ignored_at
 * @property ?string $ignored_reason
 * @property ?string $last_overall_status
 * @property ?Carbon $last_checked_at
 * @property ?Carbon $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailAuthDomain extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'domain',
        'custom_dkim_selectors',
        'ignored_at',
        'ignored_reason',
        'last_overall_status',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'custom_dkim_selectors' => 'array',
            'ignored_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(EmailAuthCheck::class, 'domain', 'domain')->orderByDesc('checked_at');
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(EmailAuthCheck::class, 'domain', 'domain')->latestOfMany('checked_at');
    }

    public function isIgnored(): bool
    {
        return $this->ignored_at !== null;
    }
}
