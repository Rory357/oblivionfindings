<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_referral_outbox', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->index(['state', 'available_at'], 'care_outbox_due_state');
        });
        Permission::query()->firstOrCreate(['key' => 'referrals.communications.manage'], ['description' => 'Review and retry referral communications within current contact authority', 'group' => 'referrals', 'module' => 'Operations']);
    }

    public function down(): void
    {
        Schema::table('care_referral_outbox', function (Blueprint $table): void {
            $table->dropIndex('care_outbox_due_state');
            $table->dropColumn(['claim_token', 'sent_at']);
        });
    }
};
