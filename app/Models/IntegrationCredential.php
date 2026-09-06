<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $integration
 * @property string $key
 * @property ?string $value encrypted at rest
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class IntegrationCredential extends Model
{
    use HasFactory;

    protected $fillable = ['integration', 'key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
