<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteChecklistRun extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'assignment_id',
        'site_id',
        'template_id',
        'scheduled_date',
        'started_at',
        'completed_at',
        'completed_by_user_id',
        'status',
        'completion_percentage',
        'items_passed',
        'items_failed',
        'overall_notes',
        'photos',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'completion_percentage' => 'decimal:2',
        'items_passed' => 'integer',
        'items_failed' => 'integer',
        'photos' => 'array',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistAssignment::class, 'assignment_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplate::class, 'template_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SiteChecklistResponse::class, 'run_id');
    }

    // Scopes
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_date', '<', now()->toDateString())
                     ->whereIn('status', ['scheduled', 'in_progress']);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    // Helpers
    public function isOverdue(): bool
    {
        return $this->scheduled_date < now()->toDateString() &&
               in_array($this->status, ['scheduled', 'in_progress']);
    }

    public function calculateCompletion(): void
    {
        $total = $this->template->items()->count();
        $completed = $this->responses()->count();
        $failed = $this->responses()->where('is_failed', true)->count();

        $this->completion_percentage = $total > 0 ? ($completed / $total) * 100 : 0;
        $this->items_passed = $completed - $failed;
        $this->items_failed = $failed;
        $this->save();
    }
}
