<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ceo_board_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('ceo_board_reports', 'period_start')) {
                $table->date('period_start')->nullable()->after('status');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'period_end')) {
                $table->date('period_end')->nullable()->after('period_start');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'executive_summary')) {
                $table->text('executive_summary')->nullable()->after('period_end');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'decisions_sought')) {
                $table->json('decisions_sought')->nullable()->after('recommendations');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'matters_arising')) {
                $table->json('matters_arising')->nullable()->after('decisions_sought');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'kpi_snapshot')) {
                $table->json('kpi_snapshot')->nullable()->after('matters_arising');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'presented_at')) {
                $table->timestamp('presented_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('ceo_board_reports', 'presented_by')) {
                $table->foreignId('presented_by')->nullable()->constrained('users')->after('presented_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ceo_board_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ceo_board_reports', 'presented_by')) {
                $table->dropConstrainedForeignId('presented_by');
            }
            $columns = [
                'period_start', 'period_end', 'executive_summary',
                'decisions_sought', 'matters_arising', 'kpi_snapshot',
                'presented_at',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('ceo_board_reports', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
