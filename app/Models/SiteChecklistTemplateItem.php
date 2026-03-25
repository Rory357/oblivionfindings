<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteChecklistTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'template_id',
        'sort_order',
        'question',
        'response_type',
        'response_config',
        'is_required',
        'guidance',
        'failure_creates_hazard',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_required' => 'boolean',
        'response_config' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplate::class, 'template_id');
    }
}
