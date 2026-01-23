<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shift;

class TimelineEvent extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'source_type',
        'source_id',
        'occurred_at',
        'type',
        'actor_user_id',
        'client_id',
        'shift_id',
        'site_id',
        'subject',
        'body',
        'meta',
        'visibility',
        'is_pinned',
        'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'meta' => 'array',
        'is_pinned' => 'boolean',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }


    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
