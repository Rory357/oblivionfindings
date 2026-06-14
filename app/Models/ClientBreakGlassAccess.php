<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientBreakGlassAccess extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'user_id',
        'revoked_by',
        'reason',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
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
}
