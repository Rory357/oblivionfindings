<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'shift_handovers_outgoing_shift_unique';

    private const SUPPORTING_INDEX = 'shift_handovers_outgoing_shift_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('shift_handovers', self::UNIQUE_INDEX)) {
            $this->ensureForeignKeySupportingIndex();

            return;
        }

        $duplicates = DB::table('shift_handovers')
            ->select('outgoing_shift_id')
            ->whereNotNull('outgoing_shift_id')
            ->groupBy('outgoing_shift_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('outgoing_shift_id')
            ->limit(20)
            ->pluck('outgoing_shift_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot enforce one shift handover per outgoing Shift. Resolve duplicate outgoing_shift_id values first: '.
                implode(', ', $duplicates),
            );
        }

        $this->ensureForeignKeySupportingIndex();

        Schema::table('shift_handovers', function (Blueprint $table): void {
            $table->unique('outgoing_shift_id', self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('shift_handovers', self::UNIQUE_INDEX)) {
            return;
        }

        $this->ensureForeignKeySupportingIndex();

        Schema::table('shift_handovers', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });
    }

    private function ensureForeignKeySupportingIndex(): void
    {
        if (Schema::hasIndex('shift_handovers', self::SUPPORTING_INDEX)) {
            return;
        }

        Schema::table('shift_handovers', function (Blueprint $table): void {
            $table->index('outgoing_shift_id', self::SUPPORTING_INDEX);
        });
    }
};
