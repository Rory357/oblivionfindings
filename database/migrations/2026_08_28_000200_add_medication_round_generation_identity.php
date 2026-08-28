<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'med_rounds_template_date_unique';

    private const SUPPORTING_INDEX = 'med_rounds_template_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('medication_rounds', self::UNIQUE_INDEX)) {
            $this->ensureForeignKeySupportingIndex();

            return;
        }

        $duplicates = DB::table('medication_rounds')
            ->select(['round_template_id', 'round_date'])
            ->selectRaw('COUNT(*) as duplicate_count')
            ->whereNotNull('round_template_id')
            ->groupBy('round_template_id', 'round_date')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('round_template_id')
            ->orderBy('round_date')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $identities = $duplicates
                ->map(fn (object $row): string => sprintf(
                    'template=%s date=%s count=%s',
                    $row->round_template_id,
                    $row->round_date,
                    $row->duplicate_count,
                ))
                ->implode('; ');

            throw new RuntimeException(
                'Cannot add the medication-round generation identity until duplicate rows are reconciled: '
                .$identities,
            );
        }

        $this->ensureForeignKeySupportingIndex();

        Schema::table('medication_rounds', function (Blueprint $table): void {
            $table->unique(['round_template_id', 'round_date'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('medication_rounds', self::UNIQUE_INDEX)) {
            return;
        }

        $this->ensureForeignKeySupportingIndex();

        Schema::table('medication_rounds', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    private function ensureForeignKeySupportingIndex(): void
    {
        if (Schema::hasIndex('medication_rounds', self::SUPPORTING_INDEX)) {
            return;
        }

        Schema::table('medication_rounds', function (Blueprint $table): void {
            $table->index('round_template_id', self::SUPPORTING_INDEX);
        });
    }
};
