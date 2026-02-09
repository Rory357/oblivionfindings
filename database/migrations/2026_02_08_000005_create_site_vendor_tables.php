<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('service_type', 50)->index();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('after_hours_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('account_number')->nullable();
            $table->text('notes')->nullable();
            $table->enum('preferred_contact_method', ['phone', 'after_hours', 'email'])->default('phone');
            $table->boolean('is_preferred')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'service_type']);
        });

        Schema::create('site_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('site_vendors')->nullOnDelete();
            $table->string('label');
            $table->string('credential_type', 30);
            $table->text('encrypted_value');
            $table->string('iv')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->foreignId('last_rotated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('requires_reauth')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'credential_type']);
        });

        Schema::create('site_credential_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained('site_credentials')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('action', ['view_list', 'reveal', 'copy', 'edit', 'rotate', 'delete']);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_credential_audit_logs');
        Schema::dropIfExists('site_credentials');
        Schema::dropIfExists('site_vendors');
    }
};
