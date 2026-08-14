<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_checklist_runs', function (Blueprint $table): void {
            $table->string('signature_name')->nullable()->after('overall_notes');
            $table->timestamp('signature_signed_at')->nullable()->after('signature_name');
            $table->string('signature_ip_address', 45)->nullable()->after('signature_signed_at');
            $table->string('signature_user_agent', 255)->nullable()->after('signature_ip_address');
            $table->string('completion_authority', 40)->nullable()->after('signature_user_agent');
            $table->string('completion_authority_reason', 255)->nullable()->after('completion_authority');
            $table->char('signature_payload_hash', 64)->nullable()->after('completion_authority_reason');
        });
    }

    public function down(): void
    {
        Schema::table('site_checklist_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'signature_name',
                'signature_signed_at',
                'signature_ip_address',
                'signature_user_agent',
                'completion_authority',
                'completion_authority_reason',
                'signature_payload_hash',
            ]);
        });
    }
};
