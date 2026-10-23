<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeWorkProcedureVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'safe_work_procedure_id',
        'version_number',
        'content_snapshot',
        'change_summary',
        'changed_by', 'document_manifest', 'provenance', 'captured_at', 'approved_by', 'approved_at', 'legacy_approved_by', 'legacy_approved_at', 'content_digest',
    ];

    protected $casts = [
        'content_snapshot' => 'array',
        'version_number' => 'integer', 'document_manifest' => 'array', 'captured_at' => 'datetime', 'approved_at' => 'datetime', 'legacy_approved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedure::class, 'safe_work_procedure_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function legacyApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legacy_approved_by');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
