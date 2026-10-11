<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('option_names');
            $table->json('defaults');
            $table->string('creation_key', 100);
            $table->string('creation_hash', 64);
            $table->timestamps();
            $table->unique(['tenant_id', 'creation_key']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_family_id')->nullable()->constrained();
            $table->json('variant_options')->nullable();
            $table->string('variant_key', 64)->nullable();
            $table->unique(['product_family_id', 'variant_key']);
        });
        Schema::create('product_variant_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->json('product_ids');
            $table->timestamps();
            $table->unique(['product_family_id', 'idempotency_key'], 'variant_batch_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_batches');
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['product_family_id', 'variant_key']);
            $table->dropConstrainedForeignId('product_family_id');
            $table->dropColumn(['variant_options', 'variant_key']);
        });
        Schema::dropIfExists('product_families');
    }
};
