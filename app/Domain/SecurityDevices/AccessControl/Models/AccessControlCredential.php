<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use UnexpectedValueException;

class AccessControlCredential extends Model
{
    public const HOLDER_STAFF = 'staff';

    public const HOLDER_CLIENT = 'client';

    public const VALID_HOLDER_TYPES = [self::HOLDER_STAFF, self::HOLDER_CLIENT];

    public const STATUS_PENDING_ISSUE = 'pending_issue';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ISSUE_FAILED = 'issue_failed';

    public const STATUS_PENDING_REVOKE = 'pending_revoke';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_REVOKE_FAILED = 'revoke_failed';

    public const RECONCILIATION_REQUIRED = 'required';

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_FAILED = 'failed';

    public const RECONCILIATION_RECONCILED = 'reconciled';

    public const PROVIDER_ACTION_ISSUE = 'issue';

    public const PROVIDER_ACTION_REVOKE = 'revoke';

    /** @var list<string> */
    public const LIFECYCLE_EVIDENCE_FIELDS = [
        'site_id',
        'access_schedule_id',
        'label',
        'holder_type',
        'holder_id',
        'reference_key',
        'status',
        'provider_reconciliation_status',
        'provider_reconciliation_action',
        'provider_reconciliation_request_key',
        'provider_reconciliation_event_key',
        'provider_reconciliation_requested_at',
        'provider_reconciliation_confirmed_at',
        'provider_reconciliation_failure_reason',
        'valid_from',
        'valid_until',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
        'created_at',
    ];

    protected $table = 'access_control_credentials';

    protected $fillable = [
        'site_id',
        'access_schedule_id',
        'label',
        'holder_type',
        'holder_id',
        'reference_key',
        'status',
        'provider_reconciliation_status',
        'provider_reconciliation_action',
        'provider_reconciliation_request_key',
        'provider_reconciliation_event_key',
        'provider_reconciliation_requested_at',
        'provider_reconciliation_confirmed_at',
        'provider_reconciliation_failure_reason',
        'valid_from',
        'valid_until',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'provider_reconciliation_requested_at' => 'datetime',
        'provider_reconciliation_confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AccessControlCredential $credential): void {
            $credential->forceFill([
                'status' => $credential->status ?: self::STATUS_PENDING_ISSUE,
                'provider_reconciliation_status' => $credential->provider_reconciliation_status ?: self::RECONCILIATION_REQUIRED,
                'provider_reconciliation_action' => $credential->provider_reconciliation_action ?: self::PROVIDER_ACTION_ISSUE,
            ]);
            self::assertTruthfulEvidenceState($credential);
        });
        static::updating(static function (AccessControlCredential $credential): void {
            $changedEvidence = array_values(array_intersect(
                array_keys($credential->getDirty()),
                self::LIFECYCLE_EVIDENCE_FIELDS,
            ));

            if ($changedEvidence !== []) {
                throw new UnexpectedValueException(
                    'Access-control credential lifecycle evidence is immutable. Record provider transitions through AccessControlCredentialTransitionService.',
                );
            }
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control credentials cannot be hard deleted. Provider lifecycle evidence must be retained.');
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AccessControlSchedule::class, 'access_schedule_id');
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(AccessControlCredentialLifecycleEvent::class, 'access_credential_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function latestLifecycleEvent(): HasOne
    {
        return $this->hasOne(AccessControlCredentialLifecycleEvent::class, 'access_credential_id')
            ->ofMany('sequence', 'max');
    }

    public function bindingEvents(): HasMany
    {
        return $this->hasMany(AccessControlCredentialDeviceBindingEvent::class, 'access_credential_id');
    }

    public static function assertTruthfulEvidenceState(AccessControlCredential $credential): void
    {
        $status = (string) $credential->status;
        $action = (string) $credential->provider_reconciliation_action;
        $reconciliation = (string) $credential->provider_reconciliation_status;
        $issueStatuses = [self::STATUS_PENDING_ISSUE, self::STATUS_ACTIVE, self::STATUS_ISSUE_FAILED];
        $revokeStatuses = [self::STATUS_PENDING_REVOKE, self::STATUS_REVOKED, self::STATUS_REVOKE_FAILED];

        if (($action === self::PROVIDER_ACTION_ISSUE && ! in_array($status, $issueStatuses, true))
            || ($action === self::PROVIDER_ACTION_REVOKE && ! in_array($status, $revokeStatuses, true))
            || ! in_array($action, [self::PROVIDER_ACTION_ISSUE, self::PROVIDER_ACTION_REVOKE], true)) {
            throw new UnexpectedValueException('Provider action and credential lifecycle status do not agree.');
        }
        if (! in_array($reconciliation, [
            self::RECONCILIATION_REQUIRED,
            self::RECONCILIATION_PENDING,
            self::RECONCILIATION_FAILED,
            self::RECONCILIATION_RECONCILED,
        ], true)) {
            throw new UnexpectedValueException('Provider reconciliation state is not recognised.');
        }

        $pendingStatus = $action === self::PROVIDER_ACTION_REVOKE ? self::STATUS_PENDING_REVOKE : self::STATUS_PENDING_ISSUE;
        $failureStatus = $action === self::PROVIDER_ACTION_REVOKE ? self::STATUS_REVOKE_FAILED : self::STATUS_ISSUE_FAILED;
        $terminalStatus = $action === self::PROVIDER_ACTION_REVOKE ? self::STATUS_REVOKED : self::STATUS_ACTIVE;

        if ($reconciliation === self::RECONCILIATION_REQUIRED
            && ($status !== $pendingStatus
                || filled($credential->provider_reconciliation_request_key)
                || filled($credential->provider_reconciliation_event_key)
                || $credential->provider_reconciliation_confirmed_at !== null
                || filled($credential->provider_reconciliation_failure_reason))) {
            throw new UnexpectedValueException('Provider-required evidence must remain pending without synthetic request, event, confirmation, or failure claims.');
        }
        if ($reconciliation === self::RECONCILIATION_PENDING
            && ($status !== $pendingStatus
                || blank($credential->provider_reconciliation_request_key)
                || blank($credential->provider_reconciliation_event_key)
                || $credential->provider_reconciliation_requested_at === null
                || $credential->provider_reconciliation_confirmed_at !== null
                || filled($credential->provider_reconciliation_failure_reason))) {
            throw new UnexpectedValueException('Pending provider evidence requires correlated request and event references.');
        }
        if ($reconciliation === self::RECONCILIATION_FAILED
            && ($status !== $failureStatus
                || blank($credential->provider_reconciliation_request_key)
                || blank($credential->provider_reconciliation_event_key)
                || $credential->provider_reconciliation_requested_at === null
                || $credential->provider_reconciliation_confirmed_at !== null
                || blank($credential->provider_reconciliation_failure_reason))) {
            throw new UnexpectedValueException('Failed provider evidence requires correlated request, event, state, and reason.');
        }
        if ($reconciliation === self::RECONCILIATION_RECONCILED
            && ($status !== $terminalStatus
                || blank($credential->provider_reconciliation_request_key)
                || blank($credential->provider_reconciliation_event_key)
                || $credential->provider_reconciliation_requested_at === null
                || $credential->provider_reconciliation_confirmed_at === null
                || filled($credential->provider_reconciliation_failure_reason))) {
            throw new UnexpectedValueException('Reconciled provider evidence requires correlated request, event, terminal state, and confirmation time.');
        }

        if ($status === self::STATUS_REVOKED
            && ($credential->revoked_at === null || blank($credential->revocation_reason))) {
            throw new UnexpectedValueException('Provider-confirmed revocation requires its time and reason.');
        }
        if ($status !== self::STATUS_REVOKED
            && ($credential->revoked_at !== null || $credential->revoked_by_user_id !== null || filled($credential->revocation_reason))) {
            throw new UnexpectedValueException('Unconfirmed revocation state cannot retain confirmed revocation fields.');
        }
    }
}
