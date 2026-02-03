<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcedureRun extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'procedure_template_id',
        'subject_type',
        'subject_id',
        'status',
        'context',
        'version_snapshot',
        'started_at',
        'completed_at',
        'blocked_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'context' => 'array',
        'version_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcedureTemplate::class, 'procedure_template_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProcedureTask::class);
    }
}
