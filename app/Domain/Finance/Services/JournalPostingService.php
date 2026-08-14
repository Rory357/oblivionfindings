<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Events\JournalPosted;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class JournalPostingService
{
    /**
     * Create a journal in draft status.
     */
    public function createDraftJournal(?int $orgId, array $data): FinJournal
    {
        return DB::transaction(fn () => $this->createDraftJournalRecord($orgId, $data));
    }

    /**
     * Post a draft journal to the general ledger.
     * This is THE critical method — the only way to post a journal.
     */
    public function post(FinJournal $journal): FinJournal
    {
        return DB::transaction(function () use ($journal) {
            $journal->loadMissing('lines');

            // 1. Journal must be in draft status
            if ($journal->status !== 'draft') {
                throw new InvalidArgumentException(
                    "Journal {$journal->journal_number} cannot be posted: status is '{$journal->status}', expected 'draft'."
                );
            }

            // 2. Journal must have at least 2 lines
            if ($journal->lines->count() < 2) {
                throw new InvalidArgumentException(
                    "Journal {$journal->journal_number} must have at least 2 lines to be posted."
                );
            }

            // 3. Debits must equal credits (to 2 decimal places)
            $totalDebits = '0';
            $totalCredits = '0';
            foreach ($journal->lines as $line) {
                $totalDebits = bcadd($totalDebits, (string) $line->debit, 2);
                $totalCredits = bcadd($totalCredits, (string) $line->credit, 2);
            }

            if (bccomp($totalDebits, $totalCredits, 2) !== 0) {
                throw new InvalidArgumentException(
                    "Journal {$journal->journal_number} is out of balance: debits ({$totalDebits}) do not equal credits ({$totalCredits})."
                );
            }

            // 4. All account_ids must reference active accounts in the same org
            $accountIds = $journal->lines->pluck('account_id')->unique()->filter()->values();
            $validAccountCount = FinAccount::where('organization_id', $journal->organization_id)
                ->where('is_active', true)
                ->whereIn('id', $accountIds)
                ->count();

            if ($validAccountCount !== $accountIds->count()) {
                throw new InvalidArgumentException(
                    "Journal {$journal->journal_number} contains invalid or inactive account references."
                );
            }

            // 5. Fiscal period must exist and be open
            $period = FinFiscalPeriod::where('organization_id', $journal->organization_id)
                ->where('start_date', '<=', $journal->journal_date)
                ->where('end_date', '>=', $journal->journal_date)
                ->first();

            if (! $period) {
                throw new InvalidArgumentException(
                    "No fiscal period found for journal date {$journal->journal_date->toDateString()} in this organisation."
                );
            }

            if ($period->status !== 'open') {
                throw new InvalidArgumentException(
                    "Fiscal period '{$period->name}' is '{$period->status}', expected 'open'."
                );
            }

            // 6. All cost_centre_ids must reference active cost centres in the same org
            $costCentreIds = $journal->lines->pluck('cost_centre_id')->unique()->filter()->values();
            if ($costCentreIds->isNotEmpty()) {
                $validCcCount = FinCostCentre::where('organization_id', $journal->organization_id)
                    ->where('is_active', true)
                    ->whereIn('id', $costCentreIds)
                    ->count();

                if ($validCcCount !== $costCentreIds->count()) {
                    throw new InvalidArgumentException(
                        "Journal {$journal->journal_number} contains invalid or inactive cost centre references."
                    );
                }
            }

            // 7. All funding_stream_ids must reference active funding streams in the same org
            $fundingStreamIds = $journal->lines->pluck('funding_stream_id')->unique()->filter()->values();
            if ($fundingStreamIds->isNotEmpty()) {
                $validFsCount = FinFundingStream::where('organization_id', $journal->organization_id)
                    ->where('is_active', true)
                    ->whereIn('id', $fundingStreamIds)
                    ->count();

                if ($validFsCount !== $fundingStreamIds->count()) {
                    throw new InvalidArgumentException(
                        "Journal {$journal->journal_number} contains invalid or inactive funding stream references."
                    );
                }
            }

            // All validations passed — post the journal
            $journal->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $journal->created_by ?? Auth::id(),
                'fiscal_period_id' => $period->id,
                'total_amount' => $totalDebits,
            ]);

            event(new JournalPosted($journal));

            return $journal->refresh();
        });
    }

    /**
     * Create a reversing journal for a posted journal.
     */
    public function reverse(FinJournal $journal, ?string $reason = null, array $attributes = []): FinJournal
    {
        $allowedAttributes = [
            'journal_date',
            'reference',
            'description',
            'source_type',
            'source_id',
            'actor_id',
        ];
        $unsupportedAttributes = array_diff(array_keys($attributes), $allowedAttributes);
        if ($unsupportedAttributes !== []) {
            throw new InvalidArgumentException(
                'Unsupported journal reversal attributes: '.implode(', ', $unsupportedAttributes).'.'
            );
        }

        return DB::transaction(function () use ($journal, $reason, $attributes): FinJournal {
            $journal = FinJournal::query()
                ->lockForUpdate()
                ->findOrFail($journal->getKey());

            if ($journal->status !== 'posted') {
                throw new InvalidArgumentException(
                    "Journal {$journal->journal_number} cannot be reversed: status is '{$journal->status}', expected 'posted'."
                );
            }

            $journal->setRelation('lines', $journal->lines()->lockForUpdate()->get());

            if ($existing = $this->resolveExistingReversal($journal)) {
                return $existing;
            }

            $description = "Reversal of {$journal->journal_number}";
            if ($reason) {
                $description .= " — {$reason}";
            }

            $reversalData = [
                'journal_date' => $attributes['journal_date'] ?? now()->toDateString(),
                'type' => 'adjustment',
                'reference' => $attributes['reference'] ?? "REV-{$journal->journal_number}",
                'description' => $attributes['description'] ?? $description,
                'source_type' => array_key_exists('source_type', $attributes)
                    ? $attributes['source_type']
                    : $journal->source_type,
                'source_id' => array_key_exists('source_id', $attributes)
                    ? $attributes['source_id']
                    : $journal->source_id,
                'actor_id' => $attributes['actor_id'] ?? Auth::id(),
                'lines' => $journal->lines->map(fn ($line) => [
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'cost_centre_id' => $line->cost_centre_id,
                    'funding_stream_id' => $line->funding_stream_id,
                    'client_id' => $line->client_id,
                    'client_fund_id' => $line->client_fund_id,
                    'site_id' => $line->site_id,
                    'tax_rate_id' => $line->tax_rate_id,
                    'tax_amount' => bcsub('0', (string) $line->tax_amount, 2),
                ])->all(),
            ];

            $reversingJournal = $this->createDraftJournalRecord(
                $journal->organization_id,
                $reversalData,
                $journal->id,
            );
            $reversingJournal = $this->post($reversingJournal);

            $this->assertReversalLineage($journal, $reversingJournal);

            $journal->forceFill(['reversed_by_journal_id' => $reversingJournal->id])->save();

            return $reversingJournal->load('lines');
        });
    }

    /**
     * Convenience method: create a draft journal and post it immediately.
     * Used by integration bridges (payroll, billing, etc.).
     */
    public function createAndPost(?int $orgId, array $data): FinJournal
    {
        $journal = $this->createDraftJournal($orgId, $data);

        return $this->post($journal);
    }

    /**
     * Generate the next sequential journal number for an organisation.
     * Format: JNL-000001, JNL-000002, etc.
     */
    public function generateJournalNumber(?int $orgId): string
    {
        $maxNumber = FinJournal::where('organization_id', $orgId)
            ->selectRaw('MAX(CAST(SUBSTRING(journal_number, 5) AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return 'JNL-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function createDraftJournalRecord(
        ?int $orgId,
        array $data,
        ?int $reversalOfJournalId = null,
    ): FinJournal {
        // Journal numbers are organisation-wide. Serialize number allocation
        // on a stable chart row so concurrent source services cannot derive
        // and insert the same next number.
        FinAccount::query()
            ->where('organization_id', $orgId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $journal = FinJournal::create([
            'organization_id' => $orgId,
            'journal_number' => $this->generateJournalNumber($orgId),
            'journal_date' => $data['journal_date'],
            'type' => $data['type'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'status' => 'draft',
            'reversal_of_journal_id' => $reversalOfJournalId,
            'total_amount' => 0,
            'created_by' => $data['actor_id'] ?? Auth::id(),
        ]);

        $totalDebits = '0';

        foreach ($data['lines'] as $line) {
            $journal->lines()->create([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'cost_centre_id' => $line['cost_centre_id'] ?? null,
                'funding_stream_id' => $line['funding_stream_id'] ?? null,
                'client_id' => $line['client_id'] ?? null,
                'client_fund_id' => $line['client_fund_id'] ?? null,
                'site_id' => $line['site_id'] ?? null,
                'tax_rate_id' => $line['tax_rate_id'] ?? null,
                'tax_amount' => $line['tax_amount'] ?? 0,
            ]);

            $totalDebits = bcadd($totalDebits, (string) ($line['debit'] ?? 0), 2);
        }

        $journal->update(['total_amount' => $totalDebits]);

        return $journal->load('lines');
    }

    private function resolveExistingReversal(FinJournal $journal): ?FinJournal
    {
        $linkedBySource = $journal->reversed_by_journal_id
            ? FinJournal::query()->lockForUpdate()->find($journal->reversed_by_journal_id)
            : null;
        $linkedByReversal = FinJournal::query()
            ->where('reversal_of_journal_id', $journal->id)
            ->lockForUpdate()
            ->first();

        if ($journal->reversed_by_journal_id && ! $linkedBySource) {
            throw new RuntimeException(
                "Journal {$journal->journal_number} has a missing linked reversal. Finance review is required."
            );
        }

        if ($linkedBySource && $linkedByReversal && ! $linkedBySource->is($linkedByReversal)) {
            throw new RuntimeException(
                "Journal {$journal->journal_number} has conflicting reversal provenance. Finance review is required."
            );
        }

        $existing = $linkedBySource ?? $linkedByReversal;
        if (! $existing) {
            return null;
        }

        $this->assertReversalLineage($journal, $existing);

        // Recover a valid inverse row whose source pointer was not persisted by
        // legacy or interrupted code. The durable unique reverse pointer is the
        // authority; no second ledger effect is created.
        if ($journal->reversed_by_journal_id === null) {
            $journal->forceFill(['reversed_by_journal_id' => $existing->id])->save();
        }

        return $existing->load('lines');
    }

    private function assertReversalLineage(FinJournal $source, FinJournal $reversal): void
    {
        if ($source->is($reversal)
            || (int) $source->organization_id !== (int) $reversal->organization_id
            || (int) $reversal->reversal_of_journal_id !== (int) $source->id
            || $reversal->status !== 'posted') {
            throw new RuntimeException(
                "Journal {$source->journal_number} has invalid reversal provenance. Finance review is required."
            );
        }

        $source->setRelation('lines', $source->lines()->lockForUpdate()->get());
        $reversal->setRelation('lines', $reversal->lines()->lockForUpdate()->get());

        $expected = $source->lines
            ->map(fn ($line): string => $this->lineSignature($line, true))
            ->sort()
            ->values()
            ->all();
        $actual = $reversal->lines
            ->map(fn ($line): string => $this->lineSignature($line, false))
            ->sort()
            ->values()
            ->all();

        if ($expected === [] || $expected !== $actual) {
            throw new RuntimeException(
                "Journal {$source->journal_number} has a linked reversal that is not its exact inverse. Finance review is required."
            );
        }
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
}
