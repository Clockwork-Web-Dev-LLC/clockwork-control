<?php

namespace Modules\BillCom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached + classified Bill.com Items. The product / service catalog that
 * invoice line items reference via itemId.
 *
 * is_care_plan is set by name regex (default /care plan/i) during sync, but
 * a human can override it. regex_set tracks which set the value most recently
 * — when true, sync may re-evaluate; when false, the manual flag is sticky.
 *
 * @property string $id
 * @property string $name
 * @property bool $is_care_plan
 * @property bool $regex_set
 * @property ?Carbon $synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BillComCarePlanItem extends Model
{
    protected $table = 'bill_com_care_plan_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'is_care_plan',
        'regex_set',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_care_plan' => 'boolean',
            'regex_set' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
