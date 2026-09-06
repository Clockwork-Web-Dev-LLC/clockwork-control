<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $phone E.164 (e.g. "+15555550100")
 * @property ?string $email_fallback
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, NotificationOffWindow> $offWindows
 */
class NotificationRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email_fallback',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function offWindows(): HasMany
    {
        return $this->hasMany(NotificationOffWindow::class, 'recipient_id');
    }
}
