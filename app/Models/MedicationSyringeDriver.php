<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationSyringeDriver extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'client_id',
        'site_id',
        'status',
        'commenced_at',
        'commenced_by',
        'witnessed_by',
        'witnessed_at',
        'witness_method',
        'rate',
        'rate_unit',
        'duration_hours',
        'contents',
        'site_of_insertion',
        'notes',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'commenced_at' => 'datetime',
        'witnessed_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_hours' => 'decimal:2',
        'contents' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function commencedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commenced_by');
    }

    public function witnessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MedicationSyringeDriverCheck::class);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }
}
