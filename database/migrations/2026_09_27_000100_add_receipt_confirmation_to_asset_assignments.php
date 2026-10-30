<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_assignments', function (Blueprint $table): void {
            $table->timestamp('receipt_confirmed_at')->nullable()->after('assigned_at');
            $table->foreignId('receipt_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('receipt_confirmed_at');
            $table->string('receipt_note', 500)->nullable()->after('receipt_confirmed_by_user_id');
            $table->index(['released_at', 'receipt_confirmed_at'], 'asset_assignments_receipt_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('asset_assignments', function (Blueprint $table): void {
            $table->dropIndex('asset_assignments_receipt_pending_index');
            $table->dropConstrainedForeignId('receipt_confirmed_by_user_id');
            $table->dropColumn(['receipt_confirmed_at', 'receipt_note']);
        });
    }
};
