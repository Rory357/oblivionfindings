<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeInspection extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'ppe_inventory_id',
        'inspected_by',
        'inspected_at',
        'result',
        'condition_after',
        'findings',
        'action_taken',
        'next_inspection_due',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'source_schema_version' => 'integer',
        'source_revision' => 'integer',
        'inspected_at' => 'datetime',
        'next_inspection_due' => 'date',
    ];

    // Relationships

    public function ppeInventory(): BelongsTo
    {
        return $this->belongsTo(PpeInventory::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PpeInspectionAttachment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Helpers

    public function passed(): bool
    {
        return $this->result === 'pass';
    }

    public function failed(): bool
    {
        return in_array($this->result, ['fail', 'condemned']);
    }

    /** Governed facts remain distinct from legacy rows and current authority. */
    public function sourceVersions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PpeSourceVersion::class, 'source_id')->where('source_type', 'inspection');
    }
}
