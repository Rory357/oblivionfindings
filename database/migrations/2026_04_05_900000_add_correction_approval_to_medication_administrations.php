<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->enum('correction_status', ['pending', 'approved', 'rejected'])->nullable()->after('correction_reason');
            $table->foreignId('correction_approved_by')->nullable()->after('correction_status')->constrained('users')->nullOnDelete();
            $table->timestamp('correction_approved_at')->nullable()->after('correction_approved_by');
            $table->text('correction_rejection_reason')->nullable()->after('correction_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->dropForeign(['correction_approved_by']);
            $table->dropColumn([
                'correction_status',
                'correction_approved_by',
                'correction_approved_at',
                'correction_rejection_reason',
            ]);
        });
    }
};
