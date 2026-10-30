<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One medication alert as it was raised (eMAR P11 B2 alert log): the record
 * every recipient shares — who was told, how, and what happened since.
 *
 * @property int $id
 * @property string $type
 * @property string $dedupe_key
 * @property string|null $open_key
 * @property int|null $site_id
 * @property int|null $client_id
 * @property int|null $staff_user_id
 * @property bool $controlled
 * @property string $title
 * @property string $message
 * @property string $short_message
 * @property string|null $action_url
 * @property string $severity
 * @property array<string, mixed>|null $subject
 * @property bool $follow_up
 * @property string $status
 */
class MedicationAlert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_DEALT_WITH = 'dealt_with';

    protected $fillable = [
        'type',
        'dedupe_key',
        'open_key',
        'site_id',
        'client_id',
        'staff_user_id',
        'controlled',
        'title',
        'message',
        'short_message',
        'action_url',
        'severity',
        'subject',
        'follow_up',
        'status',
        'raised_at',
        'attended_at',
        'attended_by',
        'attended_how',
        'dealt_with_at',
        'outcome',
        'after_hours',
        'after_hours_source',
        'reached_nobody',
        'next_follow_up_at',
        'realert_count',
        'escalated_at',
    ];

    protected $casts = [
        'controlled' => 'boolean',
        'subject' => 'array',
        'follow_up' => 'boolean',
        'raised_at' => 'datetime',
        'attended_at' => 'datetime',
        'dealt_with_at' => 'datetime',
        'after_hours' => 'boolean',
        'reached_nobody' => 'boolean',
        'next_follow_up_at' => 'datetime',
        'realert_count' => 'integer',
        'escalated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MedicationAlertRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicationAlertEvent::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_DEALT_WITH;
    }
}
