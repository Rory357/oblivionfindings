<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteHandoverNote extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'stay_id',
        'handover_type',
        'notes',
        'sensitive_flag',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'sensitive_flag' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
