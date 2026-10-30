<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PKG-02B vehicle calendar: unavailable periods, a named driver and custody
 * detail on bookings, and key custody linked to the booking it served.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_unavailable_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 2000);
            // Set when a service appointment made the vehicle unavailable.
            $table->foreignId('work_order_id')->nullable()
                ->constrained('fleet_work_orders', 'id', 'fleet_unavailable_work_order_fk')->nullOnDelete();
            $table->string('state', 24)->default('active');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_unavailable_creator_fk')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users', 'id', 'fleet_unavailable_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 2000)->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'request_key'], 'fleet_unavailable_request_uq');
            $table->index(['asset_id', 'state', 'starts_at', 'ends_at'], 'fleet_unavailable_window_idx');
        });

        Schema::table('fleet_vehicle_bookings', function (Blueprint $table): void {
            $table->foreignId('driver_user_id')->nullable()->after('user_id')
                ->constrained('users', 'id', 'fleet_bookings_driver_fk')->nullOnDelete();
            $table->string('pickup_arrangement', 255)->nullable()->after('return_site_id');
            $table->unsignedInteger('lock_version')->default(1)->after('status');
            $table->string('checkout_condition', 50)->nullable()->after('odometer_out');
            $table->string('checkout_evidence_reference', 120)->nullable()->after('checkout_condition');
            $table->text('checkout_notes')->nullable()->after('checkout_evidence_reference');
            $table->string('return_evidence_reference', 120)->nullable()->after('condition_on_return');
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')
                ->constrained('users', 'id', 'fleet_bookings_canceller_fk')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('request_key', 100)->nullable()->after('cancelled_at');
            $table->char('request_fingerprint', 64)->nullable()->after('request_key');
            $table->unique(['user_id', 'request_key'], 'fleet_bookings_request_uq');
        });

        Schema::table('fleet_key_logs', function (Blueprint $table): void {
            $table->foreignId('booking_id')->nullable()->after('asset_id')
                ->constrained('fleet_vehicle_bookings', 'id', 'fleet_key_logs_booking_fk')->nullOnDelete();
            $table->index(['booking_id'], 'fleet_key_logs_booking_idx');
        });
    }

    public function down(): void
    {
        $hasRecords = DB::table('fleet_vehicle_unavailable_periods')->exists()
            || DB::table('fleet_key_logs')->whereNotNull('booking_id')->exists()
            || DB::table('fleet_vehicle_bookings')->where(fn ($query) => $query
                ->whereNotNull('driver_user_id')->orWhereNotNull('pickup_arrangement')
                ->orWhereNotNull('checkout_condition')->orWhereNotNull('checkout_evidence_reference')
                ->orWhereNotNull('checkout_notes')->orWhereNotNull('return_evidence_reference')
                ->orWhereNotNull('cancellation_reason')->orWhereNotNull('request_key')
                ->orWhere('lock_version', '>', 1))->exists();
        if ($hasRecords) {
            throw new RuntimeException('PKG-02B calendar records exist; preserve them instead of rolling back the vehicle calendar.');
        }

        Schema::table('fleet_key_logs', function (Blueprint $table): void {
            $table->dropForeign('fleet_key_logs_booking_fk');
            $table->dropIndex('fleet_key_logs_booking_idx');
            $table->dropColumn('booking_id');
        });
        Schema::table('fleet_vehicle_bookings', function (Blueprint $table): void {
            $table->dropUnique('fleet_bookings_request_uq');
            $table->dropForeign('fleet_bookings_driver_fk');
            $table->dropForeign('fleet_bookings_canceller_fk');
            $table->dropColumn(['driver_user_id', 'pickup_arrangement', 'lock_version', 'checkout_condition',
                'checkout_evidence_reference', 'checkout_notes', 'return_evidence_reference',
                'cancellation_reason', 'cancelled_by', 'cancelled_at', 'request_key', 'request_fingerprint']);
        });
        Schema::dropIfExists('fleet_vehicle_unavailable_periods');
    }
};
