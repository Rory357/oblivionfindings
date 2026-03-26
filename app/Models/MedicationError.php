<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicationError extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'client_incident_id',
        'error_type',
        'severity',
        'description',
        'immediate_action',
        'contributing_factors',
        'reported_by',
        'reported_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'outcome',
        'preventive_actions',
        'status',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'client_incident_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Scopes ───────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['reported', 'investigating']);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('error_type', $type);
    }
}
