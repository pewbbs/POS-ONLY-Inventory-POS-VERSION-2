<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('creation_key', 100)->nullable();
            $table->string('creation_hash', 64)->nullable();
            $table->unique(['tenant_id', 'creation_key']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'creation_key']);
            $table->dropColumn(['creation_key', 'creation_hash']);
        });
    }
};
