<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ItTicketLink extends Model
{
    public const RELATIONSHIPS = [
        'affected_device',
        'source_alert',
        'affected_service',
        'affected_site',
        'affected_asset',
        'affected_vehicle',
        'source_record',
        'knowledge_article',
        'related_incident',
        'related_problem',
        'related_change',
        'major_incident_member',
        'command_request',
    ];

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'relationship',
        'linkable_type',
        'linkable_id',
        'context',
        'created_by_user_id',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
