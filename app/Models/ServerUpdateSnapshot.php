<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property Carbon $polled_at
 * @property int $total_updates
 * @property int $security_updates
 * @property bool $reboot_required
 * @property ?array<int, string> $reboot_required_pkgs
 * @property string $poll_status
 * @property ?string $poll_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServerUpdateSnapshot extends Model
{
    use HasFactory;

    public const STATUS_OK = 'ok';

    public const STATUS_SSH_FAILED = 'ssh_failed';

    public const STATUS_PARSE_FAILED = 'parse_failed';

    protected $fillable = [
        'server_id',
        'polled_at',
        'total_updates',
        'security_updates',
        'reboot_required',
        'reboot_required_pkgs',
        'upgradable_pkgs',
        'poll_status',
        'poll_error',
    ];

    protected $casts = [
        'polled_at' => 'datetime',
        'total_updates' => 'integer',
        'security_updates' => 'integer',
        'reboot_required' => 'boolean',
        'reboot_required_pkgs' => 'array',
        'upgradable_pkgs' => 'array',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
