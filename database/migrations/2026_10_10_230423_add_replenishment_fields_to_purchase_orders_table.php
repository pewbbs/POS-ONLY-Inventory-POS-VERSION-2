<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('source')->default('manual');
            $table->foreignId('reorder_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->index(['tenant_id', 'location_id', 'status'], 'purchase_orders_outstanding');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('purchase_orders_outstanding');
            $table->dropConstrainedForeignId('reorder_rule_id');
            $table->dropColumn(['source', 'approved_at', 'cancelled_at']);
        });
    }
};
