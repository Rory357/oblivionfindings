<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class GovernanceFeedbackEscalation extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'source_type', 'source_id', 'title', 'description', 'severity',
        'status', 'resolution_notes', 'assigned_to', 'escalated_by',
        'escalated_at', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'investigating']);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function resolve(int $userId, string $notes): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function escalateToBoard(int $userId): void
    {
        $this->update([
            'status' => 'escalated_to_board',
            'escalated_by' => $userId,
            'escalated_at' => now(),
        ]);
    }
}
