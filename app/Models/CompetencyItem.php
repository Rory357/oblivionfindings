<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'framework_id',
        'code',
        'name',
        'description',
        'category',
        'required_proficiency',
        'assessment_criteria',
        'order',
    ];

    protected $casts = [
        'assessment_criteria' => 'array',
    ];

    /**
     * Competency framework this item belongs to.
     */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(CompetencyFramework::class, 'framework_id');
    }

    /**
     * Scope: By category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: By proficiency level.
     */
    public function scopeProficiency($query, string $proficiency)
    {
        return $query->where('required_proficiency', $proficiency);
    }
}
