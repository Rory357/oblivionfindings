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
        'changed_by',
    ];

    protected $casts = [
        'content_snapshot' => 'array',
        'version_number' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(SafeWorkProcedure::class, 'safe_work_procedure_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
