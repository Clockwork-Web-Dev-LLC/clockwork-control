<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $ua_pattern
 * @property string $pattern_type substring|regex
 * @property string $source arcjet|manual
 * @property ?string $reference_url
 * @property ?Carbon $synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class AllowedBot extends Model
{
    use HasFactory;

    public const SOURCE_ARCJET = 'arcjet';

    public const SOURCE_MANUAL = 'manual';

    public const PATTERN_SUBSTRING = 'substring';

    public const PATTERN_REGEX = 'regex';

    protected $fillable = [
        'name',
        'ua_pattern',
        'pattern_type',
        'source',
        'reference_url',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function matches(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return false;
        }

        return match ($this->pattern_type) {
            self::PATTERN_REGEX => (bool) @preg_match('~'.$this->ua_pattern.'~', $userAgent),
            default => str_contains($userAgent, $this->ua_pattern),
        };
    }
}
