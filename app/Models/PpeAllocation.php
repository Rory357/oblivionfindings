<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeAllocation extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'ppe_inventory_id',
        'user_id',
        'allocated_at',
        'returned_at',
        'fit_test_completed',
        'fit_test_date',
        'fit_test_result',
        'training_completed',
        'training_date',
        'acknowledged',
        'acknowledged_at',
        'acknowledged_by',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'returned_at' => 'datetime',
        'fit_test_completed' => 'boolean',
        'fit_test_date' => 'date',
        'training_completed' => 'boolean',
        'training_date' => 'date',
        'acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    // Relationships

    public function ppeInventory(): BelongsTo
    {
        return $this->belongsTo(PpeInventory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PpeAllocationAttachment::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereNull('returned_at');
    }

    public function scopeReturned($query)
    {
        return $query->whereNotNull('returned_at');
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->returned_at === null;
    }
}
