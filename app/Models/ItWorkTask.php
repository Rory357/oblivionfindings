<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItWorkTask extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'in_progress', 'blocked', 'completed', 'cancelled'];

    protected $fillable = [
        'ticket_id',
        'parent_task_id',
        'team_id',
        'assigned_to_user_id',
        'completed_by_user_id',
        'title',
        'description',
        'status',
        'due_at',
        'is_required',
        'evidence_required',
        'evidence',
        'completion_note',
        'completed_at',
        'sort_order',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'is_required' => 'boolean',
        'evidence_required' => 'boolean',
        'evidence' => 'array',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ItWorkTask::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ItWorkTask::class, 'parent_task_id');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            ItWorkTask::class,
            'it_work_task_dependencies',
            'task_id',
            'depends_on_task_id',
        )->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            ItWorkTask::class,
            'it_work_task_dependencies',
            'depends_on_task_id',
            'task_id',
        )->withTimestamps();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ItTeam::class, 'team_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
