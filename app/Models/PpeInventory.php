<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeInventory extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'ppe_inventory';

    protected $fillable = [
        'ppe_type_id',
        'site_id',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'expiry_date',
        'condition',
        'quantity',
        'location',
        'status',
        'last_inspected_at',
        'next_inspection_due',
        'condemned_at',
        'condemned_by',
        'condemned_reason',
        'disposed_at',
        'disposed_by',
        'disposal_method',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiry_date' => 'date',
        'last_inspected_at' => 'date',
        'next_inspection_due' => 'date',
        'condemned_at' => 'datetime',
        'disposed_at' => 'datetime',
    ];

    // Relationships

    public function ppeType(): BelongsTo
    {
        return $this->belongsTo(PpeType::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PpeAllocation::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(PpeInspection::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PpeAttachment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function condemnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'condemned_by');
    }

    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    // Scopes

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeInspectionDue($query)
    {
        return $query->whereNotNull('next_inspection_due')
            ->where('next_inspection_due', '<=', now()->toDateString());
    }

    public function scopeCondemned($query)
    {
        return $query->where('status', 'condemned');
    }

    // Helpers

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isInspectionDue(): bool
    {
        return $this->next_inspection_due && $this->next_inspection_due->isPast();
    }
}
