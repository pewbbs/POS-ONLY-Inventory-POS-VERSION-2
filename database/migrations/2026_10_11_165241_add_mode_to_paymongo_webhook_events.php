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
        Schema::table('paymongo_webhook_events', function (Blueprint $table): void {
            $table->string('mode', 20)->default('test');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paymongo_webhook_events', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};
