<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable objective blueprint ("create from template") — a title,
 * description and a set of key-result blueprints that prefill the objective
 * wizard.
 */
class HrGoalTemplate extends Model
{
    use WritesLegacyStorageContext;

    protected $table = 'hr_goal_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'title',
        'description',
        'goal_type',
        'category',
        'priority',
        'key_results',
        'is_active',
    ];

    protected $casts = [
        'key_results' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
