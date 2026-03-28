<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyDrillParticipant extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'emergency_drill_id',
        'user_id',
        'role',
        'attended',
        'notes',
    ];

    protected $casts = [
        'attended' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function emergencyDrill(): BelongsTo
    {
        return $this->belongsTo(EmergencyDrill::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeAttended($query)
    {
        return $query->where('attended', true);
    }

    public function scopeWithRole($query, string $role)
    {
        return $query->where('role', $role);
    }
}
