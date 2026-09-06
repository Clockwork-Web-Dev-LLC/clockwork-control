<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $color
 * @property ?string $description
 * @property int $sort_order
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, Server> $servers
 */
class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'color',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Auto-slug from name when slug isn't explicitly set. Settings UI doesn't expose a slug
        // field — we derive it so the URL/filter param stays stable even if the display name
        // is edited later. Numeric suffix on collision so renames can't conflict.
        static::saving(function (Tag $tag): void {
            if (! $tag->slug) {
                $base = Str::slug($tag->name);
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->where('id', '!=', $tag->id)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $tag->slug = $slug;
            }
        });
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)->orderBy('name');
    }
}
