<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mysql_monitor_samples', function (Blueprint $table) {
            $table->id();
            $table->ulid('database_server_id');
            $table->ulid('organization_id');
            $table->timestamp('sampled_at');
            $table->unsignedBigInteger('row_lock_waits')->nullable();
            $table->unsignedBigInteger('row_lock_time_ms')->nullable();
            $table->unsignedInteger('threads_connected')->nullable();
            $table->unsignedInteger('threads_running')->nullable();
            $table->unsignedInteger('waiting_transactions')->nullable();
            $table->string('diagnostic_status', 32);
            $table->index(['database_server_id', 'sampled_at']);
            $table->index(['organization_id', 'sampled_at']);
            $table->foreign('database_server_id')->references('id')->on('database_servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mysql_monitor_samples');
    }
};
