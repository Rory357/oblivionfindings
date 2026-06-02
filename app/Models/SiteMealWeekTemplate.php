<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteMealWeekTemplate extends Model
{
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'site_id',
        'name',
        'description',
        'meals',
        'is_starter',
        'created_by',
    ];

    protected $casts = [
        'meals' => 'array',
        'is_starter' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
