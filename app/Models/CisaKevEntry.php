<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entry in CISA's Known Exploited Vulnerabilities (KEV) catalog.
 *
 * @property int $id
 * @property string $cve
 * @property string $vendor_project
 * @property string $product
 * @property ?Carbon $date_added
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CisaKevEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'cve',
        'vendor_project',
        'product',
        'date_added',
    ];

    protected $casts = [
        'date_added' => 'date',
    ];
}
