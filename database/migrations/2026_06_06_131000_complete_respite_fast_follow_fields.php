<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('respite_booking_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_booking_requests', 'series_id')) {
                $table->string('series_id')->nullable()->after('fast_tracked')->index();
            }
            if (! Schema::hasColumn('respite_booking_requests', 'recurrence_rule')) {
                $table->string('recurrence_rule')->nullable()->after('series_id');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'allocated_days')) {
                $table->unsignedSmallInteger('allocated_days')->nullable()->after('recurrence_rule');
            }
        });

        Schema::table('respite_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_bookings', 'series_id')) {
                $table->string('series_id')->nullable()->after('recurrence_rule')->index();
            }
            if (! Schema::hasColumn('respite_bookings', 'copayment_basis')) {
                $table->string('copayment_basis')->default('none')->after('copayment_amount');
            }
            if (! Schema::hasColumn('respite_bookings', 'private_pay_portion')) {
                $table->decimal('private_pay_portion', 10, 2)->nullable()->after('copayment_basis');
            }
            if (! Schema::hasColumn('respite_bookings', 'cultural_placement_check')) {
                $table->json('cultural_placement_check')->nullable()->after('cultural_snapshot');
            }
            if (! Schema::hasColumn('respite_bookings', 'setting_restriction')) {
                $table->string('setting_restriction')->default('none')->after('cultural_placement_check');
            }
        });

        Schema::table('respite_stays', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_stays', 'bed_hold_status')) {
                $table->string('bed_hold_status')->nullable()->after('absence_records');
            }
            if (! Schema::hasColumn('respite_stays', 'bed_hold_reason')) {
                $table->string('bed_hold_reason')->nullable()->after('bed_hold_status');
            }
            if (! Schema::hasColumn('respite_stays', 'bed_hold_until')) {
                $table->timestamp('bed_hold_until')->nullable()->after('bed_hold_reason');
            }
        });
    }

    public function down(): void
    {
        $this->dropColumns('respite_stays', ['bed_hold_status', 'bed_hold_reason', 'bed_hold_until']);
        $this->dropColumns('respite_bookings', ['series_id', 'copayment_basis', 'private_pay_portion', 'cultural_placement_check', 'setting_restriction']);
        $this->dropColumns('respite_booking_requests', ['series_id', 'recurrence_rule', 'allocated_days']);
    }

    private function dropColumns(string $tableName, array $columns): void
    {
        $existing = array_filter($columns, fn (string $column) => Schema::hasColumn($tableName, $column));

        if ($existing === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};
