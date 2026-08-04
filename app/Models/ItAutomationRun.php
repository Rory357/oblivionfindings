<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItAutomationRun extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['running', 'succeeded', 'failed', 'skipped'];

    protected $fillable = [
        'automation_key', 'schedule_expression', 'status', 'started_at',
        'finished_at', 'runtime_ms', 'error_summary', 'result_summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'runtime_ms' => 'integer',
        'result_summary' => 'array',
    ];
}
