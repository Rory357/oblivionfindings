<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteChecklistResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'tenant_id',
        'template_item_id',
        'response_value',
        'notes',
        'photo_path',
        'is_failed',
        'created_hazard_id',
    ];

    protected $casts = [
        'is_failed' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistRun::class, 'run_id');
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplateItem::class, 'template_item_id');
    }
}
