<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSurvey extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrSurveyFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'survey_type',
        'status',
        'is_anonymous',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function questions(): HasMany
    {
        return $this->hasMany(HrSurveyQuestion::class, 'survey_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(HrSurveyResponse::class, 'survey_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
