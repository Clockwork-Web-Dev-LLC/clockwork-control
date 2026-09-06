<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per security-scan run (any type) per site. Append-only history.
 *
 * scan_type discriminates the row shape:
 *   - 'sitecheck'      -> Sucuri SiteCheck remote scan; uses has_malware_hit + blacklist_hit
 *   - 'core_checksums' -> wp core verify-checksums over SSH; uses modified_files_count + details
 *
 * Adding future scan types (plugin/theme checksums, DB malware indicators) becomes a
 * constant on the model rather than another migration. status is the single coarse
 * verdict — 'clean' / 'issues_found' / 'failed' — that the dashboards and the issues
 * page key off of, regardless of scan_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_security_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('scan_type', 32);
            $table->timestamp('scanned_at');
            $table->string('status', 16);

            $table->boolean('has_malware_hit')->default(false);
            $table->boolean('blacklist_hit')->default(false);
            $table->unsignedSmallInteger('modified_files_count')->default(0);

            $table->string('summary', 512)->nullable();
            $table->json('details')->nullable();
            $table->string('error', 512)->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamps();

            // Per-site latest-scan-of-type lookup (the most common read).
            $table->index(['site_id', 'scan_type', 'scanned_at']);
            // Fleet inventory + issues page: latest-per-type across all sites.
            $table->index(['scan_type', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_security_scans');
    }
};
