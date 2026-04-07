<?php

namespace App\Models;

use App\Services\ShiftSafetyInvariantService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftHandover extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'outgoing_shift_id',
        'incoming_shift_id',
        'client_id',
        'outgoing_staff_id',
        'incoming_staff_id',
        'status',
        'handover_notes',
        'client_mood',
        'tasks_pending',
        'medications_due',
        'incidents_to_note',
        'follow_up_items',
        'submitted_at',
        'submitted_by',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected $casts = [
        'tasks_pending' => 'array',
        'medications_due' => 'array',
        'incidents_to_note' => 'array',
        'follow_up_items' => 'array',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $handover): void {
            app(ShiftSafetyInvariantService::class)->assertHandover($handover);
        });
    }

    public function outgoingShift()
    {
        return $this->belongsTo(Shift::class, 'outgoing_shift_id');
    }

    public function incomingShift()
    {
        return $this->belongsTo(Shift::class, 'incoming_shift_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function outgoingStaff()
    {
        return $this->belongsTo(User::class, 'outgoing_staff_id');
    }

    public function incomingStaff()
    {
        return $this->belongsTo(User::class, 'incoming_staff_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
