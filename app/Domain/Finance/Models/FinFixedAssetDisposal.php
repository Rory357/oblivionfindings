<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinFixedAssetDisposal extends Model
{
    use AuditableChanges;

    public const OCCURRENCE_TYPE = 'disposal';

    public const POSTING_MODE_JOURNAL = 'journal';

    public const POSTING_MODE_NO_GL = 'no_gl';

    public const POSTING_MODE_LEGACY_UNVERIFIED = 'legacy_unverified';

    protected $table = 'fin_fixed_asset_disposals';

    protected $fillable = [
        'fixed_asset_id',
        'occurrence_type',
        'posting_mode',
        'disposed_date',
        'purchase_cost',
        'accumulated_depreciation',
        'book_value',
        'disposal_proceeds',
        'gain_loss',
        'request_hash',
        'journal_digest',
        'journal_id',
        'created_by',
    ];

    protected $hidden = [
        'request_hash',
        'journal_digest',
    ];

    protected $casts = [
        'disposed_date' => 'date',
        'purchase_cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'book_value' => 'decimal:2',
        'disposal_proceeds' => 'decimal:2',
        'gain_loss' => 'decimal:2',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FinFixedAsset::class, 'fixed_asset_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function requestHash(string $disposedDate, string $disposalProceeds): string
    {
        return hash('sha256', json_encode([
            'occurrence_type' => self::OCCURRENCE_TYPE,
            'disposed_date' => $disposedDate,
            'disposal_proceeds' => number_format((float) $disposalProceeds, 2, '.', ''),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function journalDigest(
        int $organizationId,
        string $journalDate,
        ?string $reference,
        ?string $description,
        int $disposalId,
        array $lines,
        ?string $totalAmount = null,
    ): string {
        $normalizedLines = array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'description' => $line['description'] ?? null,
            'debit' => self::money($line['debit'] ?? 0),
            'credit' => self::money($line['credit'] ?? 0),
            'cost_centre_id' => self::nullableId($line['cost_centre_id'] ?? null),
            'funding_stream_id' => self::nullableId($line['funding_stream_id'] ?? null),
            'client_id' => self::nullableId($line['client_id'] ?? null),
            'client_fund_id' => self::nullableId($line['client_fund_id'] ?? null),
            'site_id' => self::nullableId($line['site_id'] ?? null),
            'tax_rate_id' => self::nullableId($line['tax_rate_id'] ?? null),
            'tax_amount' => self::money($line['tax_amount'] ?? 0),
        ], $lines);

        $computedTotal = array_reduce(
            $normalizedLines,
            static fn (string $total, array $line): string => bcadd($total, $line['debit'], 2),
            '0.00',
        );

        return hash('sha256', json_encode([
            'organization_id' => $organizationId,
            'journal_date' => $journalDate,
            'type' => 'standard',
            'reference' => $reference,
            'description' => $description,
            'source_type' => self::class,
            'source_id' => $disposalId,
            'status' => 'posted',
            'reversal_of_journal_id' => null,
            'reversed_by_journal_id' => null,
            'total_amount' => self::money($totalAmount ?? $computedTotal),
            'lines' => $normalizedLines,
        ], JSON_THROW_ON_ERROR));
    }

    private static function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }

    private static function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
