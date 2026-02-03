<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteRiskPlanActivation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'stay_id',
        'client_id',
        'risk_assessment_id',
        'plan_type',
        'plan_name',
        'status',
        'plan_details',
        'triggers',
        'interventions',
        'escalation_steps',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_notes',
        'staff_acknowledgments',
        'activated_at',
        'deactivated_at',
        'deactivation_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plan_details' => 'array',
        'triggers' => 'array',
        'interventions' => 'array',
        'escalation_steps' => 'array',
        'staff_acknowledgments' => 'array',
        'reviewed_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';

    // Plan types
    public const TYPE_BEHAVIOUR = 'behaviour';
    public const TYPE_SAFETY = 'safety';
    public const TYPE_MEDICAL = 'medical';
    public const TYPE_MOBILITY = 'mobility';
    public const TYPE_COMMUNICATION = 'communication';

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(ClientRisk::class, 'risk_assessment_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePendingReview($query)
    {
        return $query->where('status', self::STATUS_PENDING_REVIEW);
    }

    public function scopeForStay($query, int $stayId)
    {
        return $query->where('stay_id', $stayId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('plan_type', $type);
    }

    public function scopeNeedingAcknowledgment($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereJsonLength('staff_acknowledgments', '<', 1);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function activate(): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function deactivate(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ]);
    }

    public function suspend(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SUSPENDED,
            'deactivation_reason' => $reason,
        ]);
    }

    public function markReviewed(int $userId, ?string $notes = null): void
    {
        $this->update([
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }

    public function addStaffAcknowledgment(int $userId, string $userName): void
    {
        $acknowledgments = $this->staff_acknowledgments ?? [];

        // Check if already acknowledged
        foreach ($acknowledgments as $ack) {
            if ($ack['user_id'] === $userId) {
                return;
            }
        }

        $acknowledgments[] = [
            'user_id' => $userId,
            'user_name' => $userName,
            'acknowledged_at' => now()->toIso8601String(),
        ];

        $this->update(['staff_acknowledgments' => $acknowledgments]);
    }

    public function hasStaffAcknowledged(int $userId): bool
    {
        $acknowledgments = $this->staff_acknowledgments ?? [];

        foreach ($acknowledgments as $ack) {
            if ($ack['user_id'] === $userId) {
                return true;
            }
        }

        return false;
    }

    public function getAcknowledgmentCount(): int
    {
        return count($this->staff_acknowledgments ?? []);
    }

    public function getTriggersList(): array
    {
        return $this->triggers ?? [];
    }

    public function getInterventionsList(): array
    {
        return $this->interventions ?? [];
    }
}
