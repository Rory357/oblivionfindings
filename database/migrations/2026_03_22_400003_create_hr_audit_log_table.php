<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action'); // created, updated, deleted, viewed, approved, rejected, signed, exported
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->datetime('created_at');

            $table->index(['tenant_id', 'auditable_type', 'auditable_id'], 'hr_audit_log_auditable_index');
            $table->index(['tenant_id', 'user_id', 'created_at'], 'hr_audit_log_user_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_log');
    }
};
