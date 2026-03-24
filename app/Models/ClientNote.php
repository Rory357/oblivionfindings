<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNote extends Model
{
    use AuditableChanges;

    protected $table = 'client_notes';

    protected $fillable = [
        'client_id',
        'shift_id',
        'user_id',
        'type',
        'subject',
        'goal',
        'body',
        'occurred_at',
        'visibility',
        'is_pinned',
        'is_flagged',
        'flagged_reason',
        'reviewed_at',
        'reviewed_by',
        'is_private',
        'attachments',
        'mood_rating',
        'organization_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_pinned' => 'boolean',
        'is_flagged' => 'boolean',
        'is_private' => 'boolean',
        'reviewed_at' => 'datetime',
        'attachments' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }

    public function scopeShiftLinked($query)
    {
        return $query->whereNotNull('shift_id');
    }
}
