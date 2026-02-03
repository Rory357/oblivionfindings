<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcedureTask extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'procedure_run_id',
        'title',
        'description',
        'status',
        'assignee_id',
        'due_at',
        'sla_minutes',
        'required_evidence',
        'checklist',
        'completed_at',
        'completed_by',
        'reopened_at',
        'reopened_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_evidence' => 'array',
        'checklist' => 'array',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProcedureRun::class, 'procedure_run_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
