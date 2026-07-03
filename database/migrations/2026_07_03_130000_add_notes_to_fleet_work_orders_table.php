<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WorkOrderController@store/@update validate `notes` and the work-order wizard +
 * detail page read/write it, but the column never existed — the field was
 * silently discarded on save. Add it so the existing surfaces start persisting.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('fleet_work_orders', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('completion_notes');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_work_orders', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
