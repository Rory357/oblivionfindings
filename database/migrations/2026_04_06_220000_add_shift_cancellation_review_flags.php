<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addReviewColumns('client_medication_administrations', 'notes');
        $this->addReviewColumns('medication_rounds', 'notes');
        $this->addReviewColumns('fleet_resident_transports', 'notes');
        $this->addReviewColumns('fleet_vehicle_bookings', 'notes');
    }

    public function down(): void
    {
        $this->dropReviewColumns('fleet_vehicle_bookings');
        $this->dropReviewColumns('fleet_resident_transports');
        $this->dropReviewColumns('medication_rounds');
        $this->dropReviewColumns('client_medication_administrations');
    }

    private function addReviewColumns(string $tableName, string $afterColumn): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $afterColumn) {
            if (! Schema::hasColumn($tableName, 'review_required')) {
                $table->boolean('review_required')->default(false)->after($afterColumn);
            }

            if (! Schema::hasColumn($tableName, 'review_reason')) {
                $table->text('review_reason')->nullable()->after('review_required');
            }

            if (! Schema::hasColumn($tableName, 'review_flagged_at')) {
                $table->timestamp('review_flagged_at')->nullable()->after('review_reason');
            }

            if (! Schema::hasColumn($tableName, 'review_flagged_by')) {
                $table->foreignId('review_flagged_by')->nullable()->after('review_flagged_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    private function dropReviewColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'review_flagged_by')) {
                $table->dropConstrainedForeignId('review_flagged_by');
            }

            foreach (['review_flagged_at', 'review_reason', 'review_required'] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
