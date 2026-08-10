<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->foreignId('replacement_collector_id')
                ->nullable()
                ->after('issued_by_user_id');
            $table->foreign('replacement_collector_id', 'monitoring_collector_enrol_replace_fk')
                ->references('id')
                ->on('monitoring_collectors')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_collector_enrollments', function (Blueprint $table): void {
            $table->dropForeign('monitoring_collector_enrol_replace_fk');
            $table->dropColumn('replacement_collector_id');
        });
    }
};
