<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollExport extends Model
{
    protected $table = 'payroll_exports';

    protected $fillable = [
        'organization_id',
        'export_type',
        'period_start',
        'period_end',
        'status',
        'timesheet_count',
        'total_hours',
        'total_amount',
        'file_path',
        'exported_at',
        'exported_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_hours' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'exported_at' => 'datetime',
    ];

    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
