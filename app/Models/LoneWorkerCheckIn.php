<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoneWorkerCheckIn extends Model
{
    use HasFactory;

    protected $table = 'lone_worker_check_ins';

    protected $fillable = [
        'lone_worker_session_id',
        'checked_in_at',
        'location_lat',
        'location_lng',
        'status',
        'notes',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LoneWorkerSession::class, 'lone_worker_session_id');
    }
}
