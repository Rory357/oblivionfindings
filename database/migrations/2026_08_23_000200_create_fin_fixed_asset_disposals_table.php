<?php

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDisposal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ASSET_OCCURRENCE_UNIQUE = 'fin_asset_disposal_asset_occurrence_uq';

    private const JOURNAL_UNIQUE = 'fin_asset_disposal_journal_uq';

    private const JOURNAL_FOREIGN = 'fin_asset_disposal_journal_fk';

    /**
     * Depends on 2026_08_23_000080_enforce_fixed_asset_depreciation_period.php.
     * That migration establishes the shared fin_journal_sequences mutex which
     * every disposal must acquire, including a disposal that creates no journal.
     */
    public function up(): void
    {
        if (! Schema::hasTable('fin_journal_sequences')) {
            throw new RuntimeException(
                'Fixed-asset disposal lineage requires migration 2026_08_23_000080 '
                .'and its shared fin_journal_sequences mutex to run first.',
            );
        }

        $legacyDisposals = DB::table('fin_fixed_assets')
            ->where('status', 'disposed')
            ->select([
                'id',
                'purchase_date',
                'disposed_date',
                'purchase_cost',
                'accumulated_depreciation',
                'disposal_proceeds',
            ])
            ->orderBy('id')
            ->get();

        $normalizedLegacyDisposals = [];
        foreach ($legacyDisposals as $asset) {
            if ($asset->disposed_date === null || $asset->disposal_proceeds === null) {
                throw new RuntimeException(
                    "Fixed asset #{$asset->id} is marked disposed without complete terminal values. "
                    .'Reconcile it before applying fixed-asset disposal lineage.',
                );
            }

            $purchaseCost = bcadd((string) $asset->purchase_cost, '0', 2);
            $accumulated = bcadd((string) $asset->accumulated_depreciation, '0', 2);
            $proceeds = bcadd((string) $asset->disposal_proceeds, '0', 2);
            if ($asset->purchase_date === null
                || (string) $asset->disposed_date < (string) $asset->purchase_date
                || bccomp($purchaseCost, '0.00', 2) < 0
                || bccomp($accumulated, '0.00', 2) < 0
                || bccomp($accumulated, $purchaseCost, 2) > 0
                || bccomp($proceeds, '0.00', 2) < 0) {
                throw new RuntimeException(
                    "Fixed asset #{$asset->id} has invalid disposal accounting values. "
                    .'Reconcile it before applying fixed-asset disposal lineage.',
                );
            }

            $bookValue = bcsub($purchaseCost, $accumulated, 2);
            $normalizedLegacyDisposals[] = [
                'fixed_asset_id' => $asset->id,
                'occurrence_type' => FinFixedAssetDisposal::OCCURRENCE_TYPE,
                'posting_mode' => FinFixedAssetDisposal::POSTING_MODE_LEGACY_UNVERIFIED,
                'disposed_date' => $asset->disposed_date,
                'purchase_cost' => $purchaseCost,
                'accumulated_depreciation' => $accumulated,
                'book_value' => $bookValue,
                'disposal_proceeds' => $proceeds,
                'gain_loss' => bcsub($proceeds, $bookValue, 2),
                'request_hash' => FinFixedAssetDisposal::requestHash($asset->disposed_date, $proceeds),
                'journal_digest' => null,
                'journal_id' => null,
                'created_by' => null,
            ];
        }

        Schema::create('fin_fixed_asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fin_fixed_assets')->restrictOnDelete();
            $table->enum('occurrence_type', [FinFixedAssetDisposal::OCCURRENCE_TYPE]);
            $table->enum('posting_mode', [
                FinFixedAssetDisposal::POSTING_MODE_JOURNAL,
                FinFixedAssetDisposal::POSTING_MODE_NO_GL,
                FinFixedAssetDisposal::POSTING_MODE_LEGACY_UNVERIFIED,
            ]);
            $table->date('disposed_date');
            $table->decimal('purchase_cost', 14, 2);
            $table->decimal('accumulated_depreciation', 14, 2);
            $table->decimal('book_value', 14, 2);
            $table->decimal('disposal_proceeds', 14, 2);
            $table->decimal('gain_loss', 14, 2);
            $table->char('request_hash', 64);
            $table->char('journal_digest', 64)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'occurrence_type'], self::ASSET_OCCURRENCE_UNIQUE);
            $table->unique('journal_id', self::JOURNAL_UNIQUE);
            $table->foreign('journal_id', self::JOURNAL_FOREIGN)
                ->references('id')->on('fin_journals');
        });

        $now = now();
        foreach ($normalizedLegacyDisposals as $disposal) {
            DB::table('fin_fixed_asset_disposals')->insert([
                ...$disposal,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $linkedDisposals = DB::table('fin_fixed_asset_disposals')
            ->whereNotNull('journal_id')
            ->select(['id', 'fixed_asset_id', 'journal_id'])
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($linkedDisposals): void {
            foreach ($linkedDisposals as $disposal) {
                $journal = DB::table('fin_journals')->where('id', $disposal->journal_id)->first();
                if ($journal === null
                    || $journal->source_type !== FinFixedAssetDisposal::class
                    || (int) $journal->source_id !== (int) $disposal->id) {
                    throw new RuntimeException(
                        "Cannot roll back fixed-asset disposal lineage for occurrence #{$disposal->id}: "
                        .'its journal source is missing or conflicting.',
                    );
                }

                DB::table('fin_journals')->where('id', $journal->id)->update([
                    'source_type' => FinFixedAsset::class,
                    'source_id' => $disposal->fixed_asset_id,
                ]);
            }
        });

        Schema::dropIfExists('fin_fixed_asset_disposals');
    }
};
