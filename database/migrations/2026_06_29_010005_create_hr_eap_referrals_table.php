<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Assistance Programme (EAP) referrals. Sensitive and confidential by
 * default — only the referrer and an EAP coordinator should read these. Staff may
 * also self-refer from My HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_eap_referrals')) {
            return;
        }

        Schema::create('hr_eap_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_category'); // workload | personal | wellbeing | other
            $table->string('provider')->nullable();
            $table->string('status')->default('submitted'); // submitted | accepted | in_progress | closed
            $table->boolean('consent_given')->default(false);
            $table->boolean('is_self_referral')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'staff_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_eap_referrals');
    }
};
