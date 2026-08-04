<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSnapshot extends Model
{
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_report_snapshots';

    protected $fillable = [
        'quarterly_plan_id',
        'report_type',
        'name',
        'checksum',
        'payload',
        'file_path',
        'generated_by',
        'generated_at',
        'immutable',
    ];

    protected $casts = [
        'payload' => 'array',
        'generated_at' => 'datetime',
        'immutable' => 'boolean',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(QuarterlyRoadmapPlan::class, 'quarterly_plan_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
