<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCalendarEventException extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_event_id',
        'exception_date',
        'is_cancelled',
        'overridden_fields',
    ];

    protected $casts = [
        'exception_date' => 'date',
        'is_cancelled' => 'boolean',
        'overridden_fields' => 'array',
    ];

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(SiteCalendarEvent::class, 'parent_event_id');
    }
}
