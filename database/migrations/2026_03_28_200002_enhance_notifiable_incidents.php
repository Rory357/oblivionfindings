<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->dateTime('notification_deadline')->nullable()->after('notification_reference');
            $table->boolean('site_preserved')->default(false)->after('notification_deadline');
            $table->dateTime('site_preservation_released_at')->nullable()->after('site_preserved');
            $table->unsignedBigInteger('site_preservation_released_by')->nullable()->after('site_preservation_released_at');
            $table->json('authority_response_tracking')->nullable()->after('site_preservation_released_by');
            $table->unsignedBigInteger('closure_certified_by')->nullable()->after('authority_response_tracking');
            $table->dateTime('closure_certified_at')->nullable()->after('closure_certified_by');
            $table->text('investigation_findings')->nullable()->after('closure_certified_at');
            $table->json('preventive_actions')->nullable()->after('investigation_findings');

            $table->foreign('site_preservation_released_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closure_certified_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->dropForeign(['site_preservation_released_by']);
            $table->dropForeign(['closure_certified_by']);
            $table->dropColumn([
                'notification_deadline',
                'site_preserved',
                'site_preservation_released_at',
                'site_preservation_released_by',
                'authority_response_tracking',
                'closure_certified_by',
                'closure_certified_at',
                'investigation_findings',
                'preventive_actions',
            ]);
        });
    }
};
