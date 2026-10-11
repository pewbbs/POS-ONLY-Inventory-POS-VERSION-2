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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->string('billing_cycle')->default('monthly');
            $table->string('status')->default('trialing');
            $table->timestamp('trial_ends_at');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->string('pending_plan')->nullable();
            $table->string('pending_billing_cycle')->nullable();
            $table->timestamps();
        });
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->string('plan');
            $table->string('billing_cycle');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->default('open');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_key', 100)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'id']);
            $table->unique(['tenant_id', 'payment_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('subscriptions');
    }
};
