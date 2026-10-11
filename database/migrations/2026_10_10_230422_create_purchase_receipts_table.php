<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('number');
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->json('items');
            $table->string('note', 250)->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'idempotency_key'], 'purchase_receipts_order_key_unique');
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
