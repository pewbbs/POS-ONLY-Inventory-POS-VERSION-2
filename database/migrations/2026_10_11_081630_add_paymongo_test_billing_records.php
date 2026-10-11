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
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('payment_provider', 30)->nullable();
            $table->string('payment_mode', 20)->nullable();
        });
        Schema::create('billing_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('reference')->unique();
            $table->string('active_scope', 80)->nullable()->unique();
            $table->string('status', 30);
            $table->string('session_id', 100)->nullable()->unique();
            $table->string('payment_id', 100)->nullable()->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('mode', 20)->default('test');
            $table->text('checkout_url')->nullable();
            $table->json('checkout_payload');
            $table->timestamp('last_requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('failure_summary', 200)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'billing_invoice_id', 'id'], 'billing_attempts_tenant_invoice_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_payment_attempts');
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn(['payment_provider', 'payment_mode']);
        });
    }
};
