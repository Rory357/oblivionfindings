<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataBreachLog extends Model
{
    use HasFactory;

    protected $table = 'data_breach_logs';

    protected $fillable = [
        'breach_reference',
        'breach_type',
        'severity',
        'discovered_at',
        'discovered_by_user_id',
        'nature_of_breach',
        'affected_data_categories',
        'approximate_individuals_affected',
        'likely_consequences',
        'measures_taken',
        'requires_authority_notification',
        'authority_notified_at',
        'authority_reference',
        'requires_subject_notification',
        'subjects_notified_at',
        'notification_method',
        'status',
        'resolution_notes',
        'resolved_at',
        'created_by',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'authority_notified_at' => 'datetime',
        'subjects_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'affected_data_categories' => 'array',
        'requires_authority_notification' => 'boolean',
        'requires_subject_notification' => 'boolean',
    ];

    public function discoveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discovered_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
