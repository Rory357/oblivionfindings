<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItMajorIncidentUpdate extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const AUDIENCES = ['internal', 'staff', 'clients', 'public'];

    public const KINDS = ['command_note', 'stakeholder_update', 'service_restored', 'resolution', 'review'];

    protected $fillable = [
        'major_incident_id', 'update_kind', 'audience', 'summary',
        'service_status', 'published_at', 'author_user_id',
    ];

    protected $casts = ['published_at' => 'datetime'];

    public function majorIncident(): BelongsTo
    {
        return $this->belongsTo(ItMajorIncident::class, 'major_incident_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
