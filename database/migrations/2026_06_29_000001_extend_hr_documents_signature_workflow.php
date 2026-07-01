<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the HR Documents + e-signature schema for the redesigned hub:
 *  - documents gain a `version` (supersede chain) so the Library can show v-tags.
 *  - signature requests gain sender-side workflow fields: signing order, due
 *    date, reminder bookkeeping and an optional per-request message — enabling
 *    sequential signing, nudges and overdue tracking on the manager inbox.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_documents', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('generated_from_template');
            }
        });

        Schema::table('hr_document_signatures', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_document_signatures', 'signing_order')) {
                // 'parallel' (all at once) or 'sequential' (one after another)
                $table->string('signing_order')->default('parallel')->after('status');
            }
            if (! Schema::hasColumn('hr_document_signatures', 'order_index')) {
                $table->unsignedInteger('order_index')->default(0)->after('signing_order');
            }
            if (! Schema::hasColumn('hr_document_signatures', 'due_at')) {
                $table->date('due_at')->nullable()->after('requested_at');
            }
            if (! Schema::hasColumn('hr_document_signatures', 'reminder_sent_at')) {
                $table->datetime('reminder_sent_at')->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('hr_document_signatures', 'message')) {
                $table->text('message')->nullable()->after('declined_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_documents', function (Blueprint $table) {
            if (Schema::hasColumn('hr_documents', 'version')) {
                $table->dropColumn('version');
            }
        });

        Schema::table('hr_document_signatures', function (Blueprint $table) {
            foreach (['signing_order', 'order_index', 'due_at', 'reminder_sent_at', 'message'] as $column) {
                if (Schema::hasColumn('hr_document_signatures', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
