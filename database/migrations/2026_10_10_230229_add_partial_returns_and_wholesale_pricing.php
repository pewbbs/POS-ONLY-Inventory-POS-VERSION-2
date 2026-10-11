<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('wholesale_price_cents')->nullable();
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('price_tier', 20)->default('retail');
            $table->unsignedBigInteger('returned_cents')->default(0);
        });
        DB::table('sales')->where('status', 'returned')->update(['returned_cents' => DB::raw('total_cents')]);
        Schema::create('sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('number');
            $table->string('idempotency_key', 100)->nullable();
            $table->string('request_hash', 64);
            $table->string('reason', 200);
            $table->json('items');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();
            $table->unique(['sale_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['price_tier', 'returned_cents']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('wholesale_price_cents');
        });
    }
};
