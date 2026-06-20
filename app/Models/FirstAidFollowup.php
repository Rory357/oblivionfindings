<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * First Aid Register gold-standard upgrade — Step 2. A trackable follow-up on a
 * first-aid record (assign / due / complete) — re-check a wound, lodge the ACC45,
 * notify whānau. Mirrors FleetIncidentFollowup.
 */
class FirstAidFollowup extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'first_aid_followups';

    protected $fillable = [
        'first_aid_record_id',
        'assigned_to_user_id',
        'due_at',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(FirstAidRecord::class, 'first_aid_record_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return ! empty($this->completed_at);
    }
}
