<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 20)->default('open');
            $table->string('open_scope', 80)->nullable()->unique();
            $table->string('opening_key', 100);
            $table->string('opening_hash', 64);
            $table->unsignedBigInteger('opening_float_cents');
            $table->unsignedBigInteger('cash_sales_cents')->default(0);
            $table->unsignedBigInteger('cash_in_cents')->default(0);
            $table->unsignedBigInteger('cash_out_cents')->default(0);
            $table->unsignedBigInteger('expected_cash_cents')->nullable();
            $table->unsignedBigInteger('counted_cash_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->string('close_reason', 200)->nullable();
            $table->string('closing_key', 100)->nullable();
            $table->string('closing_hash', 64)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'opening_key']);
            $table->index(['tenant_id', 'user_id', 'location_id', 'status']);
        });
        Schema::create('cash_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 200);
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique(['cash_session_id', 'idempotency_key']);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('cash_session_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_session_id');
        });
        Schema::dropIfExists('cash_adjustments');
        Schema::dropIfExists('cash_sessions');
    }
};
