<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHouseRoomHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'client_id',
        'assigned_from',
        'assigned_until',
        'assigned_by_user_id',
        'notes',
    ];

    protected $casts = [
        'assigned_from' => 'date',
        'assigned_until' => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteHouseRoom::class, 'room_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
