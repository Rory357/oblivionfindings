<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftOpenPosition extends Model
{
    protected $table = 'shift_open_positions';

    protected $fillable = [
        'organization_id',
        'shift_id',
        'status',
        'required_skills',
        'notes',
        'claimed_by',
        'claimed_at',
        'approved_by',
        'approved_at',
        'expires_at',
    ];

    protected $casts = [
        'required_skills' => 'array',
        'claimed_at' => 'datetime',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
