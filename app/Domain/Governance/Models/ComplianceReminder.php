<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class ComplianceReminder extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'compliance_obligation_id',
        'days_before_due',
        'scheduled_at',
        'sent_at',
        'notified_users',
        'status',
        'error_message',
        'is_escalation',
        'escalation_level',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'notified_users' => 'array',
        'is_escalation' => 'boolean',
        'escalation_level' => 'integer',
    ];

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(ComplianceObligation::class, 'compliance_obligation_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDue($query)
    {
        return $query->where('scheduled_at', '<=', now())
            ->where('status', 'pending');
    }

    public function markSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    public function markAcknowledged(): void
    {
        $this->update(['status' => 'acknowledged']);
    }
}
