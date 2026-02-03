<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteCalendarEvent extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'booking_id',
        'stay_id',
        'event_type',
        'start_at',
        'end_at',
        'location_id',
        'staff_id',
        'visibility',
        'projection_status',
        'meta',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'meta' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RespiteBooking::class, 'booking_id');
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'location_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
