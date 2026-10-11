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
        Schema::create('paymongo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 180)->unique();
            $table->string('payload_digest', 64);
            $table->string('session_id', 150)->index();
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paymongo_webhook_events');
    }
};
