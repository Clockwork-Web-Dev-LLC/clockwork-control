<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This (site, target) pair should never appear as a pending update."
 * Exact analogue of ManageWP Orion's "Ignored" tab.
 *
 * No expiry semantics — a future migration can add `expires_at` if "ignore
 * this version only" becomes a use case. Today: ignore is forever until
 * removed.
 */
class PluginUpdateIgnore extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'target_kind',
        'target_slug',
        'note',
        'ignored_by_user_id',
        'ignored_at',
    ];

    protected $casts = [
        'ignored_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }
}
