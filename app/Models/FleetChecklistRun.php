<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetChecklistRun extends Model
{
    protected $fillable = [
        'template_id',
        'asset_id',
        'user_id',
        'responses',
        'passed',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'responses' => 'array',
        'passed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistTemplate::class, 'template_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
