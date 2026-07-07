<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinAuditExport extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_audit_exports';

    protected $fillable = [
        'organization_id',
        'export_name',
        'period_from',
        'period_to',
        'include_journals',
        'include_bank_reconciliations',
        'include_ap',
        'include_ar',
        'include_gst',
        'include_fixed_assets',
        'file_path',
        'file_size_bytes',
        'status',
        'generated_at',
        'downloaded_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'include_journals' => 'boolean',
        'include_bank_reconciliations' => 'boolean',
        'include_ap' => 'boolean',
        'include_ar' => 'boolean',
        'include_gst' => 'boolean',
        'include_fixed_assets' => 'boolean',
        'file_size_bytes' => 'integer',
        'generated_at' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size_bytes;
        if (!$bytes) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
