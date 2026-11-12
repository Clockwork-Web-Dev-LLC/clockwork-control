<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The latest audit of one page on a client's site.
 *
 * @property int $id
 * @property int $site_id
 * @property string $url
 * @property string $url_hash
 * @property string $fetch_status
 * @property ?int $http_status
 * @property ?string $final_url
 * @property ?string $title
 * @property ?string $meta_description
 * @property ?list<string> $h1s
 * @property ?list<string> $h2s
 * @property ?string $canonical
 * @property ?string $meta_robots
 * @property ?list<string> $schema_types
 * @property ?int $word_count
 * @property ?string $error
 * @property ?Carbon $audited_at
 */
class PageAudit extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NOT_HTML = 'not_html';

    public const STATUS_ERROR = 'error';

    public $timestamps = false;

    protected $table = 'seo_page_audits';

    protected $fillable = [
        'site_id', 'url', 'url_hash', 'fetch_status', 'http_status', 'final_url', 'title', 'meta_description',
        'h1s', 'h2s', 'canonical', 'meta_robots', 'schema_types', 'word_count', 'error', 'audited_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'h1s' => 'array',
            'h2s' => 'array',
            'schema_types' => 'array',
            'word_count' => 'integer',
            'audited_at' => 'datetime',
        ];
    }
}
