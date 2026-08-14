<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyLinks = $this->validatedLegacyReversalLinks();

        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->unsignedBigInteger('reversal_of_journal_id')
                ->nullable()
                ->after('reversed_by_journal_id');
        });

        foreach ($legacyLinks as $sourceId => $reversalId) {
            DB::table('fin_journals')->where('id', $reversalId)->update([
                'reversal_of_journal_id' => $sourceId,
            ]);
        }

        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->unique('reversed_by_journal_id', 'fin_journals_reversed_by_unique');
            $table->unique('reversal_of_journal_id', 'fin_journals_reversal_once_unique');
            $table->foreign('reversed_by_journal_id', 'fin_journals_reversed_by_fk')
                ->references('id')
                ->on('fin_journals')
                ->restrictOnDelete();
            $table->foreign('reversal_of_journal_id', 'fin_journals_reversal_of_fk')
                ->references('id')
                ->on('fin_journals')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fin_journals', function (Blueprint $table): void {
            $table->dropForeign('fin_journals_reversed_by_fk');
            $table->dropForeign('fin_journals_reversal_of_fk');
            $table->dropUnique('fin_journals_reversed_by_unique');
            $table->dropUnique('fin_journals_reversal_once_unique');
            $table->dropColumn('reversal_of_journal_id');
        });
    }

    /** @return array<int, int> Source journal id => reversal journal id. */
    private function validatedLegacyReversalLinks(): array
    {
        $claimedReversalIds = [];
        $links = [];

        DB::table('fin_journals')
            ->whereNotNull('reversed_by_journal_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $source) use (&$claimedReversalIds, &$links): void {
                $reversalId = (int) $source->reversed_by_journal_id;
                $reversal = DB::table('fin_journals')->where('id', $reversalId)->first();

                if ((int) $source->id === $reversalId
                    || ! $reversal
                    || (int) $source->organization_id !== (int) $reversal->organization_id
                    || $source->status !== 'posted'
                    || $reversal->status !== 'posted'
                    || isset($claimedReversalIds[$reversalId])
                    || ! $this->hasExactInverseLines((int) $source->id, $reversalId)) {
                    throw new RuntimeException(
                        "Cannot establish exact reversal lineage for legacy journal #{$source->id}. Finance review is required before migration."
                    );
                }

                $claimedReversalIds[$reversalId] = true;
                $links[(int) $source->id] = $reversalId;
            });

        return $links;
    }

    private function hasExactInverseLines(int $sourceId, int $reversalId): bool
    {
        $sourceLines = DB::table('fin_journal_lines')
            ->where('journal_id', $sourceId)
            ->get()
            ->map(fn (object $line): string => $this->lineSignature($line, true))
            ->sort()
            ->values()
            ->all();
        $reversalLines = DB::table('fin_journal_lines')
            ->where('journal_id', $reversalId)
            ->get()
            ->map(fn (object $line): string => $this->lineSignature($line, false))
            ->sort()
            ->values()
            ->all();

        return $sourceLines !== [] && $sourceLines === $reversalLines;
    }

    private function lineSignature(object $line, bool $invert): string
    {
        return json_encode([
            'account_id' => (int) $line->account_id,
            'description' => $line->description,
            'debit' => (string) ($invert ? $line->credit : $line->debit),
            'credit' => (string) ($invert ? $line->debit : $line->credit),
            'cost_centre_id' => $line->cost_centre_id === null ? null : (int) $line->cost_centre_id,
            'funding_stream_id' => $line->funding_stream_id === null ? null : (int) $line->funding_stream_id,
            'client_id' => $line->client_id === null ? null : (int) $line->client_id,
            'client_fund_id' => $line->client_fund_id === null ? null : (int) $line->client_fund_id,
            'site_id' => $line->site_id === null ? null : (int) $line->site_id,
            'tax_rate_id' => $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
            'tax_amount' => $invert
                ? bcsub('0', (string) $line->tax_amount, 2)
                : (string) $line->tax_amount,
        ], JSON_THROW_ON_ERROR);
    }
};
