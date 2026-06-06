<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respite_booking_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_booking_requests', 'funding_source')) {
                $table->string('funding_source')->nullable()->after('preference_notes');
            }

            if (! Schema::hasColumn('respite_booking_requests', 'service_agreement_id')) {
                $table->foreignId('service_agreement_id')
                    ->nullable()
                    ->after('funding_reference')
                    ->constrained('service_agreements')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('respite_booking_requests', 'funding_status')) {
                $table->enum('funding_status', ['not_required', 'pending_approval', 'approved', 'declined', 'expired'])
                    ->default('not_required')
                    ->after('service_agreement_id');
            }

            if (! Schema::hasColumn('respite_booking_requests', 'funding_approved_ref')) {
                $table->string('funding_approved_ref')->nullable()->after('funding_status');
            }

            if (! Schema::hasColumn('respite_booking_requests', 'funding_approved_at')) {
                $table->timestamp('funding_approved_at')->nullable()->after('funding_approved_ref');
            }
        });

        Schema::table('respite_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_bookings', 'funding_source')) {
                $table->string('funding_source')->nullable()->after('status');
            }

            if (! Schema::hasColumn('respite_bookings', 'funding_reference')) {
                $table->string('funding_reference')->nullable()->after('funding_source');
            }

            if (! Schema::hasColumn('respite_bookings', 'service_agreement_id')) {
                $table->foreignId('service_agreement_id')
                    ->nullable()
                    ->after('funding_reference')
                    ->constrained('service_agreements')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('respite_bookings', 'funding_status')) {
                $table->enum('funding_status', ['not_required', 'pending_approval', 'approved', 'declined', 'expired'])
                    ->default('not_required')
                    ->after('service_agreement_id');
            }

            if (! Schema::hasColumn('respite_bookings', 'funding_approved_ref')) {
                $table->string('funding_approved_ref')->nullable()->after('funding_status');
            }

            if (! Schema::hasColumn('respite_bookings', 'funding_approved_at')) {
                $table->timestamp('funding_approved_at')->nullable()->after('funding_approved_ref');
            }
        });
    }

    public function down(): void
    {
        Schema::table('respite_bookings', function (Blueprint $table) {
            if (Schema::hasColumn('respite_bookings', 'service_agreement_id')) {
                $table->dropConstrainedForeignId('service_agreement_id');
            }

            foreach (['funding_source', 'funding_reference', 'funding_status', 'funding_approved_ref', 'funding_approved_at'] as $column) {
                if (Schema::hasColumn('respite_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('respite_booking_requests', function (Blueprint $table) {
            if (Schema::hasColumn('respite_booking_requests', 'service_agreement_id')) {
                $table->dropConstrainedForeignId('service_agreement_id');
            }

            foreach (['funding_source', 'funding_status', 'funding_approved_ref', 'funding_approved_at'] as $column) {
                if (Schema::hasColumn('respite_booking_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
