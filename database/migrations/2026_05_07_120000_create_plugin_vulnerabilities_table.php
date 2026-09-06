<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror of the Wordfence Intelligence v2 production vulnerability feed,
     * normalized to one row per (vulnerability, software-slug, version-range)
     * tuple so version-in-range matching is a simple WHERE on the slug index.
     *
     * Refreshed daily by `clockwork:refresh-plugin-vulnerabilities`. Truncates
     * + reinserts on each refresh — the feed is small (~3MB JSON) and treating
     * it as a snapshot avoids drift between rows.
     */
    public function up(): void
    {
        Schema::create('plugin_vulnerabilities', function (Blueprint $table) {
            $table->id();

            // Identity from Wordfence — the vuln UUID. Multiple rows can share
            // it (one vuln may affect multiple plugins or multiple version
            // ranges of the same plugin).
            $table->string('wordfence_id', 64);

            // Software identity.
            $table->string('slug', 255)->index();
            $table->string('software_type', 16)->default('plugin'); // plugin|theme|core

            // Version range affected. Null/"*" means unbounded on that side.
            // from_inclusive=true means version >= from is in range.
            $table->string('from_version', 64)->nullable();
            $table->boolean('from_inclusive')->default(true);
            $table->string('to_version', 64)->nullable();
            $table->boolean('to_inclusive')->default(true);

            // The version (or first version) where the issue is patched.
            // Null means no fix yet — the only mitigation is removal.
            $table->string('patched_in', 64)->nullable();

            // Severity + CVE for prioritization in the UI.
            $table->string('title', 512);
            $table->string('cve', 32)->nullable()->index();
            $table->decimal('cvss_score', 4, 1)->nullable();
            $table->string('cvss_severity', 16)->nullable(); // low|medium|high|critical
            $table->string('url', 512)->nullable();

            // Publication timestamps from the feed.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('feed_updated_at')->nullable();

            $table->timestamps();

            // Two rows can share (wordfence_id, slug) when the same vuln has
            // multiple distinct version ranges. Use a covering uniqueness on
            // the version range too.
            $table->unique(
                ['wordfence_id', 'slug', 'from_version', 'to_version'],
                'plugin_vulns_uniq'
            );

            // Hot lookup path: per-slug match in PluginVulnerabilityMatcher.
            $table->index(['software_type', 'slug', 'cvss_score'], 'plugin_vulns_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_vulnerabilities');
    }
};
