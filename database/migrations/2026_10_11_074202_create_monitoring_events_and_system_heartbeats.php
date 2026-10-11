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
        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('status', 20);
            $table->string('summary', 200);
            $table->string('error_class', 120)->nullable();
            $table->bigInteger('error_code')->nullable();
            $table->string('pending_scope', 80)->nullable()->unique();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'kind', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('component', 30);
            $table->string('connection', 80)->nullable();
            $table->string('queue', 80)->nullable();
            $table->string('scope_key', 64)->unique();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('monitoring_events');
    }
};
