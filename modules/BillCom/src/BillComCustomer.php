<?php

namespace Modules\BillCom;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cached snapshot of a Bill.com customer. Populated by `clockwork:sync-bill-customers`.
 *
 * Primary key is Bill.com's own ID (string, prefixed `0cu`) so site rows can
 * FK directly to it without an additional join.
 *
 * @property string $id
 * @property string $name
 * @property ?string $company_name
 * @property ?string $email
 * @property ?string $last_invoice_number
 * @property ?Carbon $last_invoice_at
 * @property bool $archived
 * @property ?Carbon $synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BillComCustomer extends Model
{
    protected $table = 'bill_com_customers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'company_name',
        'email',
        'last_invoice_number',
        'last_invoice_at',
        'archived',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'last_invoice_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class, 'bill_com_customer_id');
    }
}
