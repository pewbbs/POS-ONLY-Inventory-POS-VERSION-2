<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('setup_token_hash', 64)->nullable()->unique();
            $table->timestamp('setup_expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('developer_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_admin_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('reason', 300);
            $table->json('metadata');
            $table->timestamp('created_at')->index();
            $table->index(['tenant_id', 'id']);
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('operations_paused')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('operations_paused');
        });
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('developer_sessions');
        Schema::dropIfExists('platform_admins');
    }
};
