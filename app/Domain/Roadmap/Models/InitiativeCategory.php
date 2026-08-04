<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InitiativeCategory extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyStorageContext;

    protected $table = 'roadmap_initiative_categories';

    protected $fillable = [
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
