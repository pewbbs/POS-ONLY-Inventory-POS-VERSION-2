<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->timestamp('offline_recorded_at')->nullable();
            $table->timestamp('offline_synced_at')->nullable();
            $table->string('offline_request_hash', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['offline_recorded_at', 'offline_synced_at', 'offline_request_hash']);
        });
    }
};
