<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AuditableChanges;

class StaffInduction extends Model
{
    use SoftDeletes, AuditableChanges;

    protected $fillable = [
        'user_id',
        'site_id',
        'conducted_by',
        'started_at',
        'completed_at',
        'status', // e.g., 'pending', 'completed'
        'notes',
        'checklist_data', // JSON checklist responses
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'checklist_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}