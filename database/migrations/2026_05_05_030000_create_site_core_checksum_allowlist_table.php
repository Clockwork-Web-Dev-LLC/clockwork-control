<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site allowlist for `wp core verify-checksums` findings.
 *
 * Background: verify-checksums flags every file that doesn't match the
 * official WordPress core manifest. Many flagged files are legitimate
 * (security plugins drop wp-admin/.htaccess, hosting providers add
 * hardening files, etc). Without an allowlist, the fleet-wide /issues
 * page nags forever about benign findings.
 *
 * Schema decisions:
 * - bucket distinguishes 'modified' vs 'unexpected' vs 'missing' vs '*' (any).
 *   The same path could legitimately appear in different buckets across runs
 *   (e.g. someone deletes a file → "missing"; security plugin re-creates it →
 *   "unexpected"). Bucket-scoped allowlist entries avoid accidentally
 *   masking a different kind of finding for the same path.
 * - reason is free-text. Worth requiring a non-empty string in the form
 *   so future-you remembers why this was allowlisted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_core_checksum_allowlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('bucket', 32)->default('*');
            $table->text('reason')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The path is too long for a unique index in MySQL with utf8mb4
            // (3072 byte key limit). Index a hash of (site_id, path, bucket)
            // instead — the application enforces uniqueness by checking before
            // insert. The index here is for lookup speed, not constraint.
            $table->index(['site_id', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_core_checksum_allowlist');
    }
};
