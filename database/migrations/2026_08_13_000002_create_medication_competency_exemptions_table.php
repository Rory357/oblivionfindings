<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_competency_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->string('scope', 80);
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'site_id', 'scope', 'expires_at'],
                'med_comp_exempt_user_site_scope_expiry_idx',
            );
            $table->index(['revoked_at', 'expires_at'], 'med_comp_exempt_active_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_competency_exemptions');
    }
};
