<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteInspectionRecord extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'schedule_id',
        'site_id',
        'due_date',
        'completed_at',
        'completed_by_user_id',
        'result',
        'findings',
        'corrective_actions',
        'linked_hazard_id',
        'linked_checklist_run_id',
        'evidence_photos',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'evidence_photos' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SiteInspectionSchedule::class, 'schedule_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNull('completed_at')
                     ->where('due_date', '<', now()->toDateString());
    }

    public function isOverdue(): bool
    {
        return is_null($this->completed_at) && $this->due_date < now()->toDateString();
    }

    public function passed(): bool
    {
        return $this->result === 'pass';
    }

    public function failed(): bool
    {
        return $this->result === 'fail';
    }
}
