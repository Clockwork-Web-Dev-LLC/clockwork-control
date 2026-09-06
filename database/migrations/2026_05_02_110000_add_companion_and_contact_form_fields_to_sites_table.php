<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foundation for the Clockwork Companion mu-plugin + the contact-form
     * test add-on (see Plans/humming-stargazing-sky.md).
     *
     * Two concerns share this migration because they ship together:
     *
     * 1. companion_*  — install/version/secret/last-seen for the mu-plugin
     *    Clockwork drops on opted-in sites. Secret is encrypted at rest via
     *    the model's Crypt cast (mirrors sites.db_password).
     *
     * 2. contact_form_* — per-site state machine for the daily form test.
     *    state nullable = "never tested"; failure_streak gates Mattermost +
     *    client-email notifications (no ping until streak >= 2).
     *
     * client_email lives on the site row in Phase 1; Phase 2 will introduce
     * an accounts table and migrate it there.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Companion mu-plugin
            $table->boolean('companion_installed')->default(false)->after('archived_by_migration_id');
            $table->string('companion_version', 16)->nullable()->after('companion_installed');
            $table->json('companion_capabilities')->nullable()->after('companion_version');
            $table->text('companion_secret')->nullable()->after('companion_capabilities');
            $table->timestamp('companion_last_seen_at')->nullable()->after('companion_secret');

            // Contact-form test add-on
            $table->string('contact_form_plugin', 32)->nullable()->after('companion_last_seen_at');
            $table->boolean('contact_form_test_enabled')->default(false)->after('contact_form_plugin');
            $table->timestamp('contact_form_test_subscribed_at')->nullable()->after('contact_form_test_enabled');
            $table->timestamp('contact_form_test_unsubscribed_at')->nullable()->after('contact_form_test_subscribed_at');
            $table->string('client_email')->nullable()->after('contact_form_test_unsubscribed_at');
            $table->string('contact_form_test_form_id', 64)->nullable()->after('client_email');
            $table->string('contact_form_test_url')->nullable()->after('contact_form_test_form_id');
            $table->string('contact_form_test_state', 16)->nullable()->after('contact_form_test_url');
            $table->timestamp('contact_form_test_state_changed_at')->nullable()->after('contact_form_test_state');
            $table->timestamp('contact_form_last_test_at')->nullable()->after('contact_form_test_state_changed_at');
            $table->text('contact_form_last_test_error')->nullable()->after('contact_form_last_test_at');
            $table->unsignedInteger('contact_form_test_failure_streak')->default(0)->after('contact_form_last_test_error');
            $table->timestamp('contact_forms_detected_at')->nullable()->after('contact_form_test_failure_streak');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'companion_installed',
                'companion_version',
                'companion_capabilities',
                'companion_secret',
                'companion_last_seen_at',
                'contact_form_plugin',
                'contact_form_test_enabled',
                'contact_form_test_subscribed_at',
                'contact_form_test_unsubscribed_at',
                'client_email',
                'contact_form_test_form_id',
                'contact_form_test_url',
                'contact_form_test_state',
                'contact_form_test_state_changed_at',
                'contact_form_last_test_at',
                'contact_form_last_test_error',
                'contact_form_test_failure_streak',
                'contact_forms_detected_at',
            ]);
        });
    }
};
