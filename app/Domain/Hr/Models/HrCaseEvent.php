<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCaseEvent extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'case_id',
        'event_type',
        'title',
        'description',
        'occurred_at',
        'document_path',
        'visibility',
        'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hrCase(): BelongsTo
    {
        return $this->belongsTo(HrCase::class, 'case_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
