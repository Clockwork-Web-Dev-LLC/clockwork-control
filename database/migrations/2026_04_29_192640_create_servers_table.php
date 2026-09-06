<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user');
            $table->text('ssh_private_key')->nullable();
            $table->text('ssh_password')->nullable();
            $table->string('spinupwp_id')->nullable()->index();
            $table->string('do_droplet_id')->nullable()->index();
            $table->string('status')->default('unknown');
            $table->boolean('is_ignored')->default(false)->index();
            $table->string('ignore_reason')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();

            $table->unique(['hostname', 'ssh_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
