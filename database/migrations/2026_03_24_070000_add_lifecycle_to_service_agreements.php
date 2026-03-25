<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('service_agreements', 'nasc_assessment_date')) {
                $table->date('nasc_assessment_date')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('service_agreements', 'funding_approved_date')) {
                $table->date('funding_approved_date')->nullable()->after('nasc_assessment_date');
            }
            if (! Schema::hasColumn('service_agreements', 'signed_date')) {
                $table->date('signed_date')->nullable()->after('funding_approved_date');
            }
            if (! Schema::hasColumn('service_agreements', 'first_service_date')) {
                $table->date('first_service_date')->nullable()->after('signed_date');
            }
            if (! Schema::hasColumn('service_agreements', 'review_due_date')) {
                $table->date('review_due_date')->nullable()->after('first_service_date');
            }
            if (! Schema::hasColumn('service_agreements', 'renewal_date')) {
                $table->date('renewal_date')->nullable()->after('review_due_date');
            }
            if (! Schema::hasColumn('service_agreements', 'terminated_at')) {
                $table->datetime('terminated_at')->nullable()->after('renewal_date');
            }
            if (! Schema::hasColumn('service_agreements', 'terminated_reason')) {
                $table->text('terminated_reason')->nullable()->after('terminated_at');
            }
            if (! Schema::hasColumn('service_agreements', 'suspended_at')) {
                $table->datetime('suspended_at')->nullable()->after('terminated_reason');
            }
            if (! Schema::hasColumn('service_agreements', 'suspended_reason')) {
                $table->text('suspended_reason')->nullable()->after('suspended_at');
            }
            if (! Schema::hasColumn('service_agreements', 'resumed_at')) {
                $table->datetime('resumed_at')->nullable()->after('suspended_reason');
            }
        });

        Schema::create('service_agreement_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_agreement_id')->constrained('service_agreements')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_agreement_status_changes');

        Schema::table('service_agreements', function (Blueprint $table) {
            $cols = [
                'nasc_assessment_date', 'funding_approved_date', 'signed_date',
                'first_service_date', 'review_due_date', 'renewal_date',
                'terminated_at', 'terminated_reason',
                'suspended_at', 'suspended_reason', 'resumed_at',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('service_agreements', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
