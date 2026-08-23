<?php

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERIOD_UNIQUE = 'fin_asset_depr_asset_period_uq';

    private const JOURNAL_UNIQUE = 'fin_asset_depr_journal_uq';

    private const JOURNAL_FOREIGN = 'fin_asset_depr_journal_fk';

    private const REVERSAL_JOURNAL_UNIQUE = 'fin_asset_depr_reversal_journal_uq';

    private const REVERSAL_JOURNAL_FOREIGN = 'fin_asset_depr_reversal_journal_fk';

    public function up(): void
    {
        $rows = DB::table('fin_fixed_asset_depreciations as depreciation')
            ->join('fin_fixed_assets as asset', 'asset.id', '=', 'depreciation.fixed_asset_id')
            ->select([
                'depreciation.id',
                'depreciation.fixed_asset_id',
                'depreciation.depreciation_date',
                'depreciation.amount',
                'depreciation.journal_id',
                'asset.organization_id',
            ])
            ->orderBy('depreciation.id')
            ->get();

        $periodOwners = [];
        $journalOwners = [];
        $normalizedRows = [];

        foreach ($rows as $row) {
            $period = Carbon::parse($row->depreciation_date)->startOfMonth()->toDateString();
            $periodKey = $row->fixed_asset_id.'|'.$period;

            if (isset($periodOwners[$periodKey])) {
                throw new RuntimeException(
                    "Cannot enforce fixed-asset monthly depreciation uniqueness: executions #{$periodOwners[$periodKey]} and #{$row->id} both claim asset #{$row->fixed_asset_id} for {$period}. Reconcile and reverse the duplicate before retrying the migration.",
                );
            }
            $periodOwners[$periodKey] = $row->id;

            if ($row->journal_id !== null) {
                if (isset($journalOwners[$row->journal_id])) {
                    throw new RuntimeException(
                        "Cannot enforce fixed-asset depreciation journal lineage: journal #{$row->journal_id} is linked to executions #{$journalOwners[$row->journal_id]} and #{$row->id}.",
                    );
                }
                $journalOwners[$row->journal_id] = $row->id;

                $journal = DB::table('fin_journals')->where('id', $row->journal_id)->first();
                if (! $journal) {
                    throw new RuntimeException(
                        "Cannot enforce fixed-asset depreciation journal lineage: execution #{$row->id} references missing journal #{$row->journal_id}.",
                    );
                }

                $legacySource = $journal->source_type === FinFixedAsset::class
                    && (int) $journal->source_id === (int) $row->fixed_asset_id;
                $canonicalSource = $journal->source_type === FinFixedAssetDepreciation::class
                    && (int) $journal->source_id === (int) $row->id;
                $journalPeriod = Carbon::parse($journal->journal_date)->startOfMonth()->toDateString();

                if ((int) $journal->organization_id !== (int) $row->organization_id
                    || $journal->status !== 'posted'
                    || $journal->reversal_of_journal_id !== null
                    || $journal->reversed_by_journal_id !== null
                    || $journalPeriod !== $period
                    || bccomp((string) $journal->total_amount, (string) $row->amount, 2) !== 0
                    || (! $legacySource && ! $canonicalSource)) {
                    throw new RuntimeException(
                        "Cannot enforce fixed-asset depreciation journal lineage: execution #{$row->id} and journal #{$journal->id} require finance review before migration.",
                    );
                }
            }

            $normalizedRows[] = [
                'id' => $row->id,
                'period' => $period,
                'journal_id' => $row->journal_id,
            ];
        }

        DB::transaction(function () use ($normalizedRows): void {
            foreach ($normalizedRows as $row) {
                DB::table('fin_fixed_asset_depreciations')->where('id', $row['id'])->update([
                    'depreciation_date' => $row['period'],
                ]);

                if ($row['journal_id'] !== null) {
                    DB::table('fin_journals')->where('id', $row['journal_id'])->update([
                        'source_type' => FinFixedAssetDepreciation::class,
                        'source_id' => $row['id'],
                    ]);
                }
            }
        });

        Schema::create('fin_journal_sequences', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
        });

        $organizationIds = DB::table('fin_accounts')->pluck('organization_id')
            ->merge(DB::table('fin_fixed_assets')->pluck('organization_id'))
            ->merge(DB::table('fin_journals')->pluck('organization_id'))
            ->unique()
            ->sort()
            ->values();

        foreach ($organizationIds as $organizationId) {
            $highest = DB::table('fin_journals')
                ->where('organization_id', $organizationId)
                ->pluck('journal_number')
                ->reduce(function (int $highest, string $journalNumber): int {
                    if (preg_match('/^JNL-(\d+)$/', $journalNumber, $matches) !== 1) {
                        return $highest;
                    }

                    return max($highest, (int) $matches[1]);
                }, 0);

            DB::table('fin_journal_sequences')->insert([
                'organization_id' => $organizationId,
                'next_number' => $highest + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('fin_fixed_asset_depreciations', function (Blueprint $table): void {
            $table->unique(['fixed_asset_id', 'depreciation_date'], self::PERIOD_UNIQUE);
            // The legacy composite index currently supports the fixed_asset_id
            // foreign key on MySQL. Establish the replacement unique index
            // first so dropping the legacy index cannot orphan that FK.
            $table->dropIndex('fin_asset_depr_asset_date_idx');
            $table->unique('journal_id', self::JOURNAL_UNIQUE);
            $table->foreign('journal_id', self::JOURNAL_FOREIGN)
                ->references('id')->on('fin_journals');
            $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('journal_id');
            $table->unique('reversal_journal_id', self::REVERSAL_JOURNAL_UNIQUE);
            $table->foreign('reversal_journal_id', self::REVERSAL_JOURNAL_FOREIGN)
                ->references('id')->on('fin_journals');
        });
    }

    public function down(): void
    {
        $rows = DB::table('fin_fixed_asset_depreciations')
            ->select(['id', 'fixed_asset_id', 'journal_id', 'reversal_journal_id'])
            ->orderBy('id')
            ->get();

        Schema::table('fin_fixed_asset_depreciations', function (Blueprint $table): void {
            $table->dropForeign(self::REVERSAL_JOURNAL_FOREIGN);
            $table->dropForeign(self::JOURNAL_FOREIGN);
            $table->dropUnique(self::REVERSAL_JOURNAL_UNIQUE);
            $table->dropUnique(self::JOURNAL_UNIQUE);
            // Restore the legacy supporting index before removing the unique
            // replacement that currently backs the fixed_asset_id foreign key.
            $table->index(['fixed_asset_id', 'depreciation_date'], 'fin_asset_depr_asset_date_idx');
            $table->dropUnique(self::PERIOD_UNIQUE);
            $table->dropColumn('reversal_journal_id');
        });

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                DB::table('fin_journals')
                    ->whereIn('id', array_filter([$row->journal_id, $row->reversal_journal_id]))
                    ->update([
                        'source_type' => FinFixedAsset::class,
                        'source_id' => $row->fixed_asset_id,
                    ]);
            }
        });

        Schema::dropIfExists('fin_journal_sequences');
    }
};
