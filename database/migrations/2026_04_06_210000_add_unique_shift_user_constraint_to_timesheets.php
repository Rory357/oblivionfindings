<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('timesheets')
            ->select('shift_id', 'user_id', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('shift_id')
            ->groupBy('shift_id', 'user_id')
            ->having('duplicate_count', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates
                ->take(5)
                ->map(fn ($row) => "shift {$row->shift_id} / user {$row->user_id}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add the unique timesheet shift/user constraint until duplicate rows are resolved: '.$sample
            );
        }

        Schema::table('timesheets', function (Blueprint $table) {
            $table->unique(['shift_id', 'user_id'], 'uq_timesheets_shift_user');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropUnique('uq_timesheets_shift_user');
        });
    }
};
