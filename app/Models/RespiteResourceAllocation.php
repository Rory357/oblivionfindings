<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteResourceAllocation extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'booking_id',
        'resource_type',
        'resource_id',
        'start_at',
        'end_at',
        'status',
        'conflict_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RespiteBooking::class, 'booking_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'resource_id');
    }
}
