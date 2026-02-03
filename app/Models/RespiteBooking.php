<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteBooking extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'booking_request_id',
        'client_id',
        'start_at',
        'end_at',
        'status',
        'assigned_coordinator_id',
        'cancellation_reason',
        'approvals',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'approvals' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RespiteBookingRequest::class, 'booking_request_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_coordinator_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(RespiteStay::class, 'booking_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RespiteResourceAllocation::class, 'booking_id');
    }

    public function shift()
    {
        return $this->hasOne(Shift::class, 'respite_booking_id');
    }
}
