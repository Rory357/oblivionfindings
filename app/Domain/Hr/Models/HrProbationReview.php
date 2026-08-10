<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrProbationReview extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'reviewer_user_id',
        'review_number',
        'review_date',
        'status',
        'areas_assessed',
        'concerns',
        'recommendation',
        'extension_weeks',
        'notes',
        'employee_acknowledged',
        'employee_acknowledged_at',
        'created_by',
    ];

    protected $casts = [
        'review_date' => 'date',
        'review_number' => 'integer',
        'areas_assessed' => 'array',
        'extension_weeks' => 'integer',
        'employee_acknowledged' => 'boolean',
        'employee_acknowledged_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
