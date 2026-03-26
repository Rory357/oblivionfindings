<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_handovers', function (Blueprint $table) {
            $table->json('checklist_items')->nullable()->after('general_notes');
            $table->text('safety_concerns')->nullable()->after('checklist_items');
            $table->integer('medication_errors_count')->default(0)->after('safety_concerns');
            $table->integer('pending_gp_followups')->default(0)->after('medication_errors_count');
            $table->json('clients_requiring_attention')->nullable()->after('pending_gp_followups');
            $table->boolean('previous_shift_notes_read')->default(false)->after('clients_requiring_attention');
            $table->text('stock_issues_identified')->nullable()->after('previous_shift_notes_read');
            $table->text('prescriber_changes_summary')->nullable()->after('stock_issues_identified');
        });
    }

    public function down(): void
    {
        Schema::table('medication_handovers', function (Blueprint $table) {
            $table->dropColumn([
                'checklist_items',
                'safety_concerns',
                'medication_errors_count',
                'pending_gp_followups',
                'clients_requiring_attention',
                'previous_shift_notes_read',
                'stock_issues_identified',
                'prescriber_changes_summary',
            ]);
        });
    }
};
