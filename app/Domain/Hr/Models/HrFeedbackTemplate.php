<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrFeedbackTemplate extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'questions',
        'is_default',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'questions' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get questions as a key => question associative array.
     */
    public function getQuestionsMap(): array
    {
        return collect($this->questions)->pluck('question', 'key')->all();
    }
}
