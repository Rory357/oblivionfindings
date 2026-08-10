<?php

namespace App\Domain\SecurityDevices\AccessControl\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class AccessControlScheduleRevision extends Model
{
    public $timestamps = false;

    protected $table = 'access_control_schedule_revisions';

    protected $fillable = [
        'access_schedule_id',
        'site_id',
        'version',
        'action',
        'snapshot',
        'change_reason',
        'active_credentials_affected',
        'provider_confirmed_credentials_affected',
        'provider_request_key',
        'provider_event_key',
        'provider_confirmed',
        'recorded_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
        'active_credentials_affected' => 'integer',
        'provider_confirmed_credentials_affected' => 'integer',
        'provider_confirmed' => 'boolean',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AccessControlScheduleRevision $revision): void {
            if ((int) data_get($revision->snapshot, 'site_id') !== (int) $revision->site_id) {
                throw new UnexpectedValueException('Schedule revision snapshot Site provenance does not match its governed Site.');
            }
            if ($revision->provider_confirmed
                && (blank($revision->provider_request_key)
                    || blank($revision->provider_event_key)
                    || $revision->action !== 'provider_reconciled'
                    || data_get($revision->snapshot, 'provider_reconciliation_status') !== AccessControlSchedule::RECONCILIATION_RECONCILED
                    || data_get($revision->snapshot, 'provider_reconciliation_request_key') !== $revision->provider_request_key
                    || data_get($revision->snapshot, 'provider_reconciliation_event_key') !== $revision->provider_event_key)) {
                throw new UnexpectedValueException('Provider-confirmed schedule revisions require correlated request and event references.');
            }
            if (filled($revision->provider_event_key) && blank($revision->provider_request_key)) {
                throw new UnexpectedValueException('Schedule provider events require a correlated request reference.');
            }
            if (filled($revision->provider_event_key)
                && data_get($revision->snapshot, 'provider_reconciliation_event_key') !== $revision->provider_event_key) {
                throw new UnexpectedValueException('Schedule provider revision snapshot does not match its provider event reference.');
            }
        });
        static::updating(static function (): never {
            throw new UnexpectedValueException('Access-control schedule revisions are immutable.');
        });
        static::deleting(static function (): never {
            throw new UnexpectedValueException('Access-control schedule revisions are retained as immutable audit history.');
        });
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AccessControlSchedule::class, 'access_schedule_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
