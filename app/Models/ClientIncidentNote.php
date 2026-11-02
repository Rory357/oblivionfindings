<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A dated note beside a submitted incident report's words (answer 20). Append-only. */
class ClientIncidentNote extends Model
{
    public const UPDATED_AT = null;

    public const FIELD_WHAT_HAPPENED = 'what_happened';

    public const FIELD_DONE_STRAIGHT_AWAY = 'done_straight_away';

    public const FIELDS = [self::FIELD_WHAT_HAPPENED, self::FIELD_DONE_STRAIGHT_AWAY];

    protected $table = 'client_incident_notes';

    protected $fillable = ['client_incident_id', 'field', 'note', 'author_id', 'author_name'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A note beside a report is kept as it was written.'));
        static::deleting(fn () => throw new LogicException('A note beside a report is kept as it was written.'));
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'client_incident_id');
    }
}
