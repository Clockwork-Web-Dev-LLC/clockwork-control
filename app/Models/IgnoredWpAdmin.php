<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-row acknowledgement of a flagged WordPress administrator.
 *
 * @property int $id
 * @property int $site_id
 * @property string $subject
 * @property ?string $reason
 * @property ?int $ignored_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site $site
 * @property-read ?User $user
 */
class IgnoredWpAdmin extends Model
{
    protected $fillable = [
        'site_id',
        'subject',
        'reason',
        'ignored_by_user_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }

    public static function subjectFor(string $email, string $login): string
    {
        $email = strtolower(trim($email));
        if ($email !== '') {
            return $email;
        }

        return strtolower(trim($login));
    }
}
