<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteMedicationReconciliation extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'stay_id',
        'type',
        'status',
        'source',
        'count_received',
        'discrepancies',
        'first_dose_due_at',
        'reconciled_by_user_id',
        'reconciled_at',
        'override_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'count_received' => 'integer',
        'discrepancies' => 'array',
        'first_dose_due_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }
}
