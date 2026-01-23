<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnifiConnection extends Model
{
    protected $fillable = [
        'site_id',
        'base_url',
        'username',
        'password_encrypted',
        'api_token_encrypted',
        'controller_type',
        'verify_tls',
        'last_synced_at',
        'status',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
