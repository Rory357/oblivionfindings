<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InitiativeCategory extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'roadmap_initiative_categories';

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function initiatives(): HasMany
    {
        return $this->hasMany(Initiative::class, 'category_id');
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where(function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)
                ->orWhereNull('tenant_id');
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
