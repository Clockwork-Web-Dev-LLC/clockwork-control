<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property Carbon $worked_on
 * @property string $hours
 * @property string $description
 * @property ?int $user_id
 * @property-read Site $site
 * @property-read ?User $user
 */
class SiteWorkLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'worked_on',
        'hours',
        'description',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'hours' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
