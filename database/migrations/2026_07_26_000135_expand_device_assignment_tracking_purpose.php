<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('device_assignments', 'tracking_purpose')) {
            throw new RuntimeException('The device assignment tracking purpose column is missing.');
        }

        if (Schema::getColumnType('device_assignments', 'tracking_purpose') === 'text') {
            return;
        }

        Schema::table('device_assignments', function (Blueprint $table): void {
            $table->text('tracking_purpose')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately do not narrow this narrative field and risk truncating safeguarding evidence.
    }
};
