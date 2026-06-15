<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientBreakGlassAccess extends Model
{
    use SoftDeletes;

    /** Pre-filled grant duration. */
    public const DEFAULT_MINUTES = 60;

    /** Hard policy cap for a grant window (including extensions): 4 hours. */
    public const MAX_MINUTES = 240;

    /** How long a single "Extend" adds. */
    public const EXTEND_MINUTES = 30;

    protected $fillable = [
        'client_id',
        'user_id',
        'revoked_by',
        'reason',
        'reason_category',
        'authorization_mode',
        'co_signed_by',
        'acknowledged_min_necessary',
        'acknowledged_incident_report',
        'expires_at',
        'reviewed_at',
        'reviewed_by',
        'review_outcome',
        'review_notes',
        'incident_report_linked',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'acknowledged_min_necessary' => 'boolean',
        'acknowledged_incident_report' => 'boolean',
        'incident_report_linked' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function coSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_signed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Short attribution label for the grant card / audit ("Self-authorised", "Co-signed by …"). */
    public function authorizationLabel(): ?string
    {
        if ($this->authorization_mode === 'co_sign') {
            return 'Co-signed by '.($this->coSignedBy?->name ?? 'second approver');
        }
        if ($this->authorization_mode === 'self') {
            return 'Self-authorised';
        }

        return null; // legacy grants have no recorded mode
    }
}
