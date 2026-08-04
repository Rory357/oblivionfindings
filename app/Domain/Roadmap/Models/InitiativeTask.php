<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeTask extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_tasks';

    protected $fillable = [
        'initiative_id',
        'initiative_milestone_id',
        'site_id',
        'task_type',
        'title',
        'description',
        'assignee_user_id',
        'status',
        'priority',
        'due_date',
        'effort_hours_est',
        'effort_hours_actual',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'effort_hours_est' => 'decimal:2',
        'effort_hours_actual' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class, 'initiative_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(InitiativeMilestone::class, 'initiative_milestone_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }
}
