<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskEventLink extends Model
{
    use HasFactory;

    protected $table = 'risk_event_links';

    protected $fillable = [
        'risk_register_entry_id',
        'event_type',
        'event_id',
        'event_reference',
        'event_severity',
        'event_occurred_at',
        'link_rationale',
        'linked_by',
        'linked_at',
    ];

    protected $casts = [
        'event_occurred_at' => 'datetime',
        'linked_at' => 'datetime',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(RiskRegisterEntry::class, 'risk_register_entry_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function event()
    {
        // Note: This requires careful implementation based on event_type
        $modelClass = match($this->event_type) {
            'incident' => \App\Models\ClientIncident::class,
            'alert' => \App\Models\ControlRoomAlert::class,
            'safeguarding' => \App\Models\SafeguardingConcern::class,
            'breach' => \App\Models\DataBreachLog::class,
            default => null,
        };
        
        return $modelClass ? $modelClass::find($this->event_id) : null;
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('event_occurred_at');
    }
}
