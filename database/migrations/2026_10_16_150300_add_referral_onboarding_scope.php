<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A single application-wide write mutex; never an organisation/tenant boundary.
        Schema::create('client_identity_write_mutex', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('client_identity_write_mutex')->insert(['id' => 1]);
        Schema::table('client_onboarding_workflows', function (Blueprint $table): void {
            $table->string('scope', 32)->default('person');
            $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('source_referral_id')->nullable()->constrained('care_referrals')->restrictOnDelete();
            $table->index(['client_id', 'scope', 'status'], 'onboarding_client_scope_status');
        });
    }

    public function down(): void
    {
        Schema::table('client_onboarding_workflows', function (Blueprint $table): void {
            $table->dropIndex('onboarding_client_scope_status');
            $table->dropConstrainedForeignId('source_referral_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('service_context_id');
            $table->dropColumn('scope');
        });
        Schema::dropIfExists('client_identity_write_mutex');
    }
};
