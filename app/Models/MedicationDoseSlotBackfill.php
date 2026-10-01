<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One run of the dose-slot backfill (P01 foundation C5): the NZ period it
 * reconstructs, its cursor and its counts.
 */
class MedicationDoseSlotBackfill extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'from_date',
        'to_date',
        'status',
        'last_order_id',
        'orders_done',
        'slots_created',
        'outcomes_written',
        'records_without_slot',
        'days_from_records',
        'orders_skipped',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'last_order_id' => 'integer',
        'orders_done' => 'integer',
        'slots_created' => 'integer',
        'outcomes_written' => 'integer',
        'records_without_slot' => 'integer',
        'days_from_records' => 'integer',
        'orders_skipped' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
