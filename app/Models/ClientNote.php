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
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'is_pinned' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
