<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteChecklistResponse extends Model
{
    use HasFactory;
    use WritesLegacyStorageContext;

    protected $fillable = [
        'run_id',
        'template_item_id',
        'response_value',
        'notes',
        'photo_path',
        'is_failed',
        'created_hazard_id',
        'created_damage_id',
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
