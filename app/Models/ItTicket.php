<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A general IT helpdesk ticket (not onboarding-driven), worked from the
 * /it Tickets queue.
 */
class ItTicket extends Model
{
    use AuditableChanges;

    public const CATEGORIES = ['hardware', 'account', 'network', 'other'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'requester_user_id',
        'assigned_to_user_id',
        'category',
        'priority',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
