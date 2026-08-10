<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-off request on a helpdesk ticket (§P-S3). Certain categories need a
 * manager's approval before an agent may resolve/fulfil; this row is the
 * decision log — one live (pending/approved) request at a time, kept for audit.
 */
class ItTicketApproval extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'it_ticket_id',
        'requested_by',
        'approver_id',
        'status',
        'reason',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
