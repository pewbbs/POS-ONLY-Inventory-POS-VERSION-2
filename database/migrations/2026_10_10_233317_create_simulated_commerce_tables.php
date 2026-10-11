<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0);
        });
        Schema::create('commerce_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 100);
            $table->string('kind');
            $table->boolean('enabled')->default(true);
            $table->boolean('simulate_failure')->default(false);
            $table->boolean('pending_sync')->default(true);
            $table->string('sync_state')->default('stale');
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('creation_key', 100);
            $table->string('creation_hash', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
            $table->index(['tenant_id', 'location_id']);
        });
        Schema::create('channel_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedInteger('published_quantity')->default(0);
            $table->timestamps();
            $table->unique(['commerce_channel_id', 'product_id']);
        });
        Schema::create('channel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_channel_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->string('external_reference', 100);
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->string('status')->default('pending');
            $table->json('items');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['commerce_channel_id', 'external_reference']);
            $table->unique(['commerce_channel_id', 'idempotency_key']);
            $table->index(['tenant_id', 'id']);
        });
        Schema::create('channel_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->string('status');
            $table->string('message');
            $table->unsignedInteger('listing_count')->default(0);
            $table->timestamps();
            $table->unique(['commerce_channel_id', 'idempotency_key']);
            $table->index(['tenant_id', 'id']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->string('source')->default('pos');
            $table->foreignId('channel_id')->nullable()->constrained('commerce_channels');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn('source');
        });
        Schema::dropIfExists('channel_syncs');
        Schema::dropIfExists('channel_orders');
        Schema::dropIfExists('channel_listings');
        Schema::dropIfExists('commerce_channels');
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('reserved_quantity');
        });
    }
};
