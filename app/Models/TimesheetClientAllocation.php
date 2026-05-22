<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-client time allocation against a {@see Timesheet}.
 *
 * See `database/migrations/2026_05_23_000010_create_timesheet_client_allocations_table.php`
 * for the allocation-method semantics and the data-model contract. Sum of
 * `hours` across rows must equal the parent timesheet's `hours` (within
 * a 0.01h rounding tolerance); validation lives in the controllers that
 * write to this table.
 */
class TimesheetClientAllocation extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const METHOD_SINGLE = 'single';
    public const METHOD_RESIDENTIAL_HOUSE = 'residential_house';
    public const METHOD_EQUAL_SPLIT = 'equal_split';
    public const METHOD_MANUAL = 'manual';
    public const METHOD_TIME_SEGMENTED = 'time_segmented';

    public const METHODS = [
        self::METHOD_SINGLE,
        self::METHOD_RESIDENTIAL_HOUSE,
        self::METHOD_EQUAL_SPLIT,
        self::METHOD_MANUAL,
        self::METHOD_TIME_SEGMENTED,
    ];

    protected $fillable = [
        'timesheet_id',
        'client_id',
        'hours',
        'allocation_method',
        'starts_at',
        'ends_at',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Derived duration in hours from start/end when the row is time-segmented,
     * otherwise the stored `hours` value. Useful for sanity-checking that the
     * segment window actually adds up to what the worker recorded.
     */
    protected function segmentHours(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->starts_at || ! $this->ends_at) {
                return (float) $this->hours;
            }

            return round(
                $this->starts_at->floatDiffInRealHours($this->ends_at),
                2,
            );
        });
    }
}
