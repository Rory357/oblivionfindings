<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

class AccessControlSchedule extends Model
{
    public const RECONCILIATION_REQUIRED = 'required';

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_FAILED = 'failed';

    public const RECONCILIATION_RECONCILED = 'reconciled';

    /** @var list<string> */
    public const GOVERNED_FIELDS = [
        'site_id',
        'name',
        'timezone',
        'days',
        'starts_at',
        'ends_at',
        'is_active',
        'version',
        'provider_reconciliation_status',
        'provider_reconciliation_request_key',
        'provider_reconciliation_event_key',
        'provider_reconciliation_confirmed_at',
        'provider_reconciliation_failure_reason',
        'provider_reconciliation_required_at',
        'created_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
        'created_at',
    ];

    protected $table = 'access_control_schedules';

    protected $fillable = [
        'site_id',
        'name',
        'timezone',
        'days',
        'starts_at',
        'ends_at',
        'is_active',
        'version',
        'provider_reconciliation_status',
        'provider_reconciliation_request_key',
        'provider_reconciliation_event_key',
        'provider_reconciliation_confirmed_at',
        'provider_reconciliation_failure_reason',
        'provider_reconciliation_required_at',
        'created_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'provider_reconciliation_confirmed_at' => 'immutable_datetime',
        'provider_reconciliation_required_at' => 'immutable_datetime',
        'deactivated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AccessControlSchedule $schedule): void {
            $schedule->forceFill([
                'version' => $schedule->version ?: 1,
                'provider_reconciliation_status' => $schedule->provider_reconciliation_status ?: self::RECONCILIATION_REQUIRED,
                'provider_reconciliation_required_at' => $schedule->provider_reconciliation_required_at ?: now(),
            ]);
            self::assertTruthfulProviderState($schedule);
        });
        static::updating(static function (AccessControlSchedule $schedule): void {
            $changedEvidence = array_values(array_intersect(
                array_keys($schedule->getDirty()),
                self::GOVERNED_FIELDS,
            ));

            if ($changedEvidence !== []) {
                throw new UnexpectedValueException(
                    'Access-control schedule evidence is governed. Record changes through an Access Control lifecycle service.',
                );
            }
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control schedules cannot be hard deleted. Deactivate the schedule instead.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AccessControlCredential::class, 'access_schedule_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AccessControlScheduleRevision::class, 'access_schedule_id')->orderByDesc('version');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }

    public static function assertTruthfulProviderState(AccessControlSchedule $schedule): void
    {
        $status = (string) $schedule->provider_reconciliation_status;
        $requestKey = $schedule->provider_reconciliation_request_key;
        $eventKey = $schedule->provider_reconciliation_event_key;
        $confirmedAt = $schedule->provider_reconciliation_confirmed_at;
        $failureReason = $schedule->provider_reconciliation_failure_reason;

        if (! in_array($status, [
            self::RECONCILIATION_REQUIRED,
            self::RECONCILIATION_PENDING,
            self::RECONCILIATION_FAILED,
            self::RECONCILIATION_RECONCILED,
        ], true)) {
            throw new UnexpectedValueException('Schedule provider reconciliation state is not recognised.');
        }
        if ($status === self::RECONCILIATION_REQUIRED
            && (filled($requestKey) || filled($eventKey) || $confirmedAt !== null || filled($failureReason))) {
            throw new UnexpectedValueException('Provider-required schedules cannot retain synthetic provider evidence.');
        }
        if ($status === self::RECONCILIATION_PENDING
            && (blank($requestKey) || blank($eventKey) || $confirmedAt !== null || filled($failureReason))) {
            throw new UnexpectedValueException('Pending schedule reconciliation requires correlated provider request and event references.');
        }
        if ($status === self::RECONCILIATION_FAILED
            && (blank($requestKey) || blank($eventKey) || $confirmedAt !== null || blank($failureReason))) {
            throw new UnexpectedValueException('Failed schedule reconciliation requires correlated provider evidence and a reason.');
        }
        if ($status === self::RECONCILIATION_RECONCILED
            && (blank($requestKey) || blank($eventKey) || $confirmedAt === null || filled($failureReason))) {
            throw new UnexpectedValueException('Reconciled schedules require correlated provider request, event, and confirmation evidence.');
        }
    }
}
