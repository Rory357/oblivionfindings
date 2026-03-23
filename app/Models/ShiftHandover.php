<?php

namespace App\Models;

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
        'handover_notes',
        'client_mood',
        'tasks_pending',
        'medications_due',
        'incidents_to_note',
        'acknowledged_at',
    ];

    protected $casts = [
        'tasks_pending' => 'array',
        'medications_due' => 'array',
        'incidents_to_note' => 'array',
        'acknowledged_at' => 'datetime',
    ];

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
}
