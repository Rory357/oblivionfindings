<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer approval chain: draft → pending_approval → approved/declined before an
 * offer can be sent. Adds the request timestamp and the decline reason; the
 * approver/approval time already live on approved_by/approved_at, and the
 * requester is the offer's created_by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_offers', 'approval_requested_at')) {
                $table->timestamp('approval_requested_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('hr_offers', 'approval_declined_reason')) {
                $table->text('approval_declined_reason')->nullable()->after('approval_requested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            foreach (['approval_requested_at', 'approval_declined_reason'] as $column) {
                if (Schema::hasColumn('hr_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
