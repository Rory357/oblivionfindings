<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable command binding; stores references and a fingerprint, never request text. */
class ItTicketCommandReceipt extends Model
{
    public const CHANNEL = 'browser';

    public const CREATE_OPERATION = 'ticket.create';

    /** Audience is fingerprinted so changing it cannot reuse a reply identity. */
    public const COMMENT_OPERATION = 'ticket.comment';

    public const UPDATE_OPERATION = 'ticket.update';

    public const MERGE_OPERATION = 'ticket.merge';

    public const RELATIONSHIP_OPERATION = 'ticket.relationship';

    public const TASK_OPERATIONS = ['task.create', 'task.update', 'task.complete', 'task.reopen', 'task.reorder'];

    public const APPROVAL_OPERATIONS = ['approval.request', 'approval.decide', 'approval.withdraw'];

    protected $fillable = [
        'actor_user_id', 'channel', 'operation', 'request_uuid',
        'request_hash', 'it_ticket_id', 'committed_at',
        'it_ticket_comment_id', 'committed_ticket_version', 'result_metadata',
    ];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime', 'committed_ticket_version' => 'integer', 'result_metadata' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'it_ticket_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ItTicketComment::class, 'it_ticket_comment_id');
    }
}
