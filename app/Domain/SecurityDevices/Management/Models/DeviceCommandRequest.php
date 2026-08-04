<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Enums\BreakGlassReviewOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandConfirmationMode;
use App\Domain\SecurityDevices\Management\Enums\CommandRisk;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Enums\ManagementLevel;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItChange;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use UnexpectedValueException;

class DeviceCommandRequest extends Model
{
    public const array IMMUTABLE_CONTRACT_ATTRIBUTES = [
        'command_uuid',
        'device_id',
        'site_id',
        'assignment_fingerprint',
        'requested_by_user_id',
        'it_change_id',
        'collector_id',
        'capability',
        'capability_version',
        'management_level',
        'risk',
        'confirmation_mode',
        'impact_acknowledged_at',
        'encrypted_parameters',
        'safe_parameter_summary',
        'reason',
        'expected_state',
        'reconciliation_rule',
        'idempotency_key',
        'signing_key_id',
        'signature',
        'provider',
        'is_break_glass',
        'break_glass_reason',
        'break_glass_reviewer_user_id',
        'break_glass_declared_at',
        'break_glass_review_due_at',
        'expires_at',
    ];

    protected $table = 'device_command_requests';

    protected $fillable = [
        'command_uuid', 'device_id', 'site_id', 'assignment_fingerprint', 'requested_by_user_id', 'approved_by_user_id',
        'it_change_id', 'collector_id', 'capability', 'capability_version', 'management_level',
        'risk', 'confirmation_mode', 'status', 'encrypted_parameters', 'safe_parameter_summary', 'reason', 'expected_state',
        'reconciliation_rule', 'idempotency_key', 'signing_key_id', 'signature', 'execution_route',
        'provider', 'safe_result_summary', 'safe_failure_reason', 'blocked_reason_code', 'is_break_glass',
        'break_glass_reason', 'break_glass_reviewer_user_id', 'break_glass_declared_at',
        'break_glass_review_due_at', 'break_glass_notification_sent_at',
        'break_glass_reviewed_by_user_id', 'break_glass_review_outcome', 'break_glass_review_summary',
        'step_up_confirmed_at', 'impact_acknowledged_at', 'approved_at', 'rejected_at', 'dispatched_at', 'started_at',
        'execution_completed_at', 'reconciled_at', 'cancelled_at', 'blocked_at', 'break_glass_reviewed_at',
        'expires_at',
    ];

    protected $hidden = [
        'assignment_fingerprint',
        'encrypted_parameters',
        'signing_key_id',
        'signature',
        'break_glass_reason',
        'break_glass_review_summary',
    ];

    protected $casts = [
        'capability_version' => 'integer',
        'management_level' => ManagementLevel::class,
        'risk' => CommandRisk::class,
        'confirmation_mode' => CommandConfirmationMode::class,
        'status' => CommandStatus::class,
        'encrypted_parameters' => 'encrypted:array',
        'safe_parameter_summary' => 'array',
        'expected_state' => 'array',
        'is_break_glass' => 'boolean',
        'break_glass_reason' => 'encrypted',
        'break_glass_declared_at' => 'immutable_datetime',
        'break_glass_review_due_at' => 'immutable_datetime',
        'break_glass_notification_sent_at' => 'immutable_datetime',
        'break_glass_review_outcome' => BreakGlassReviewOutcome::class,
        'break_glass_review_summary' => 'encrypted',
        'step_up_confirmed_at' => 'immutable_datetime',
        'impact_acknowledged_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime',
        'dispatched_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'execution_completed_at' => 'immutable_datetime',
        'reconciled_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'blocked_at' => 'immutable_datetime',
        'break_glass_reviewed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->command_uuid ??= (string) Str::orderedUuid();
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command requests are retained as immutable audit evidence.');
        });
    }

    protected function performUpdate(Builder $query)
    {
        if (collect(self::IMMUTABLE_CONTRACT_ATTRIBUTES)->contains(fn (string $attribute): bool => $this->isDirty($attribute))) {
            throw new UnexpectedValueException('The signed device command contract is immutable.');
        }

        $previousStatus = CommandStatus::from((string) $this->getRawOriginal('status'));
        $nextStatus = $this->status;
        if (! $previousStatus->canTransitionTo($nextStatus)) {
            throw new UnexpectedValueException("Invalid device command transition from {$previousStatus->value} to {$nextStatus->value}.");
        }
        if ($previousStatus->isTerminal() && $this->isDirty()) {
            $reviewFields = [
                'break_glass_reviewed_by_user_id',
                'break_glass_review_outcome',
                'break_glass_review_summary',
                'break_glass_reviewed_at',
            ];
            $dirtyFields = array_keys($this->getDirty());
            $isOneTimeBreakGlassReview = $this->is_break_glass
                && $this->getRawOriginal('break_glass_reviewed_at') === null
                && collect($dirtyFields)->every(fn (string $field): bool => in_array($field, $reviewFields, true));
            if (! $isOneTimeBreakGlassReview) {
                throw new UnexpectedValueException('Terminal device command history is immutable.');
            }
        }

        return parent::performUpdate($query);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function breakGlassReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'break_glass_reviewer_user_id');
    }

    public function breakGlassReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'break_glass_reviewed_by_user_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ItChange::class, 'it_change_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'collector_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(DeviceCommandApproval::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DeviceCommandAttempt::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(DeviceCommandReconciliation::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(DeviceCommandAuditEvent::class);
    }

    public function intakeAudits(): HasMany
    {
        return $this->hasMany(DeviceCommandIntakeAudit::class);
    }
}
