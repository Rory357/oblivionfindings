<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHouseRoomHistory extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    // Eloquent would otherwise auto-pluralize the class name to
    // `site_house_room_histories`. The actual migration creates a
    // singular `site_house_room_history` table.
    protected $table = 'site_house_room_history';

    protected $fillable = [
        'room_id',
        'tenant_id',
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
