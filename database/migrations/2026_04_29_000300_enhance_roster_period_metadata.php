<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_periods')) {
            return;
        }

        Schema::table('roster_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('roster_periods', 'week_end')) {
                $table->date('week_end')->nullable()->after('week_start')->index();
            }

            if (! Schema::hasColumn('roster_periods', 'shift_count')) {
                $table->unsignedInteger('shift_count')->default(0)->after('status');
            }

            if (! Schema::hasColumn('roster_periods', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('published_by');
            }

            if (! Schema::hasColumn('roster_periods', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('archive_reason')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('roster_periods', 'notes')) {
                $table->text('notes')->nullable()->after('created_by');
            }

            if (! Schema::hasColumn('roster_periods', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('roster_periods')
            ->whereNull('week_end')
            ->orderBy('id')
            ->chunkById(500, function ($periods): void {
                foreach ($periods as $period) {
                    DB::table('roster_periods')
                        ->where('id', $period->id)
                        ->update([
                            'week_end' => Carbon::parse($period->week_start)
                                ->addDays(7)
                                ->toDateString(),
                            'shift_count' => DB::table('shifts')
                                ->where('roster_period_id', $period->id)
                                ->count(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('roster_periods')) {
            return;
        }

        Schema::table('roster_periods', function (Blueprint $table): void {
            foreach (['deleted_at', 'notes', 'locked_at', 'shift_count', 'week_end'] as $column) {
                if (Schema::hasColumn('roster_periods', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('roster_periods', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};
