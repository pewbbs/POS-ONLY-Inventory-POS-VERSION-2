<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorder_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('target_quantity');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'location_id', 'supplier_id'], 'reorder_rules_scope_unique');
            $table->index(['tenant_id', 'enabled', 'id'], 'reorder_rules_tenant_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_rules');
    }
};
