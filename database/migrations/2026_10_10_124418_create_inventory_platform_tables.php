<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_demo')->default(false);
            $table->json('settings');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role')->default('cashier');
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('type')->default('store');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->string('category')->default('General');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->unsignedInteger('reorder_level')->default(10);
            $table->string('color')->default('#ddd9d0');
            $table->string('variant')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->unique(['tenant_id', 'barcode']);
        });
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
            $table->index(['tenant_id', 'location_id']);
        });
        foreach (['customers', 'suppliers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->string('number');
            $table->string('status')->default('completed');
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->json('items');
            $table->json('payments');
            $table->unsignedBigInteger('subtotal_cents');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('paid_cents');
            $table->unsignedBigInteger('change_cents')->default(0);
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->integer('quantity');
            $table->string('type');
            $table->string('reason');
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('number');
            $table->string('status')->default('ordered');
            $table->json('items');
            $table->unsignedBigInteger('total_cents');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('reorder_level');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
        });
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->string('variant')->default('a');
            $table->timestamps();
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['analytics_events', 'low_stock_alerts', 'purchase_orders', 'stock_movements', 'sales', 'suppliers', 'customers', 'stocks', 'products', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('tenants');
    }
};
