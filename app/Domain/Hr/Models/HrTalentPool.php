<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrTalentPool extends Model
{
    protected $table = 'hr_talent_pool';

    protected $fillable = [
        'tenant_id',
        'candidate_id',
        'requisition_id',
        'reason',
        'pooled_by',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(HrJobRequisition::class, 'requisition_id');
    }

    public function pooledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pooled_by');
    }
}
