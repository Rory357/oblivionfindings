<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_funding_streams')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fin_funding_streams MODIFY funder_type ENUM('whaikaha','acc','nasc','private_pay','moh','carer_support','egl_if','te_whatu_ora','msd','private','other') NOT NULL");
        }

        DB::table('fin_funding_streams')->where('funder_type', 'moh')->update(['funder_type' => 'whaikaha']);
        DB::table('fin_funding_streams')->where('funder_type', 'private_pay')->update(['funder_type' => 'private']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fin_funding_streams MODIFY funder_type ENUM('whaikaha','carer_support','nasc','egl_if','acc','te_whatu_ora','msd','private','other') NOT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_funding_streams')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fin_funding_streams MODIFY funder_type ENUM('whaikaha','carer_support','nasc','egl_if','acc','te_whatu_ora','msd','private','private_pay','moh','other') NOT NULL");
        }

        DB::table('fin_funding_streams')->where('funder_type', 'private')->update(['funder_type' => 'private_pay']);
        DB::table('fin_funding_streams')->whereIn('funder_type', ['carer_support', 'egl_if', 'te_whatu_ora', 'msd'])->update(['funder_type' => 'other']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fin_funding_streams MODIFY funder_type ENUM('whaikaha','acc','nasc','private_pay','moh','other') NOT NULL");
        }
    }
};
