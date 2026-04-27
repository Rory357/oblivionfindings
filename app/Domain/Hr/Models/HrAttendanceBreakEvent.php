<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAttendanceBreakEvent extends Model
{
    protected $fillable = [
        'session_id',
        'started_at',
        'ended_at',
        'minutes',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(HrAttendanceSession::class, 'session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
