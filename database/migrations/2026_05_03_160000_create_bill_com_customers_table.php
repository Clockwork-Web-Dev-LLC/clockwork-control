<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local cache of Bill.com customers. Populated by `clockwork:sync-bill-customers`.
 *
 * Primary key is Bill.com's own ID (string, prefixed `0cu...`) — not an
 * auto-increment — so site rows can FK directly to it without a join through
 * a local autoincrement.
 *
 * Read by: per-site Settings → Billing dropdown, future Bill.com customers
 * admin page. Sync is read-only on the Bill.com side; this table is the
 * agency's view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_com_customers', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // 0cu<22 base64 chars> typically — leave headroom
            $table->string('name', 255);
            $table->string('company_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('last_invoice_number', 64)->nullable();
            $table->timestamp('last_invoice_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_com_customers');
    }
};
