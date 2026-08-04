<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteChecklistTemplateItem extends Model
{
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $fillable = [
        'template_id',
        'sort_order',
        'question',
        'response_type',
        'response_config',
        'is_required',
        'guidance',
        'failure_creates_hazard',
        'failure_creates_damage',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'failure_creates_hazard' => 'boolean',
        'failure_creates_damage' => 'boolean',
        'response_config' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplate::class, 'template_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SiteChecklistResponse::class, 'template_item_id');
    }
}
