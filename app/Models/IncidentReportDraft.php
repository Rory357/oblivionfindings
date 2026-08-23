<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentReportDraft extends Model
{
    protected $fillable = [
        'request_uuid',
        'user_id',
        'site_id',
        'client_id',
        'mode',
        'entry_context',
        'encrypted_payload',
        'payload_hash',
        'revision',
        'saved_at',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'encrypted_payload',
        'payload_hash',
    ];

    protected $casts = [
        'encrypted_payload' => 'encrypted:array',
        'revision' => 'integer',
        'saved_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
