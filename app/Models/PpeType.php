<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeType extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'name',
        'category',
        'description',
        'hazards_addressed',
        'standards_reference',
        'inspection_frequency',
        'typical_lifespan_months',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships

    public function inventory(): HasMany
    {
        return $this->hasMany(PpeInventory::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
