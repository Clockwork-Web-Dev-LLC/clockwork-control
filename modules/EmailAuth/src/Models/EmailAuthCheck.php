<?php

namespace Modules\EmailAuth\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain
 * @property string $overall_status
 * @property string $spf_status
 * @property ?string $spf_record
 * @property int $spf_lookup_count
 * @property string $dmarc_status
 * @property ?string $dmarc_policy
 * @property ?string $dmarc_record
 * @property string $dkim_status
 * @property ?array<int, string> $dkim_selectors_found
 * @property bool $mx_present
 * @property ?array<int, array{check: string, severity: string, code: string, message: string}> $findings
 * @property Carbon $checked_at
 * @property ?string $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailAuthCheck extends Model
{
    use HasFactory;

    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'domain',
        'overall_status',
        'spf_status',
        'spf_record',
        'spf_lookup_count',
        'dmarc_status',
        'dmarc_policy',
        'dmarc_record',
        'dkim_status',
        'dkim_selectors_found',
        'mx_present',
        'findings',
        'checked_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'spf_lookup_count' => 'integer',
            'dkim_selectors_found' => 'array',
            'mx_present' => 'boolean',
            'findings' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function statusBadgeClass(?string $status = null): string
    {
        $s = $status ?? $this->overall_status;

        return match ($s) {
            self::STATUS_PASS => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::STATUS_WARN => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::STATUS_FAIL => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
            default => 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30',
        };
    }

    public function statusLabel(?string $status = null): string
    {
        $s = $status ?? $this->overall_status;

        return match ($s) {
            self::STATUS_PASS => 'Pass',
            self::STATUS_WARN => 'Warning',
            self::STATUS_FAIL => 'Fail',
            default => 'Unknown',
        };
    }

    /**
     * Associated active sites using this domain or subdomains under it.
     *
     * @return Collection<int, Site>
     */
    public function sites(): Collection
    {
        return Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->where(function ($q) {
                $q->where('domain', $this->domain)
                    ->orWhere('domain', 'like', '%.'.$this->domain);
            })
            ->get();
    }
}
