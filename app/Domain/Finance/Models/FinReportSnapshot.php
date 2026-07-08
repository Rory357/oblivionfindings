<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinReportSnapshot extends Model
{
    use HasFactory;

    protected $table = 'fin_report_snapshots';

    protected $fillable = [
        'organization_id',
        'report_type',
        'period_start',
        'period_end',
        'data',
        'generated_at',
        'generated_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'data' => 'array',
        'generated_at' => 'datetime',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('report_type', $type);
    }
}
