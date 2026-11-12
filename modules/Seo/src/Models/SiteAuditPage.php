<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One page found by a crawl.
 *
 * @property int $id
 * @property int $audit_id
 * @property string $url
 * @property string $url_hash
 * @property ?int $status_code
 * @property ?string $title
 * @property ?string $description
 * @property ?string $h1
 * @property ?string $canonical
 * @property ?int $word_count
 * @property ?int $click_depth
 * @property ?int $internal_links
 * @property ?int $external_links
 * @property ?int $size_bytes
 * @property ?int $load_ms
 * @property ?float $score
 * @property ?list<string> $failed_checks
 */
class SiteAuditPage extends Model
{
    public $timestamps = false;

    protected $table = 'seo_site_audit_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer', 'word_count' => 'integer', 'click_depth' => 'integer', 'internal_links' => 'integer',
            'external_links' => 'integer', 'size_bytes' => 'integer', 'load_ms' => 'integer', 'score' => 'float', 'failed_checks' => 'array',
        ];
    }
}
