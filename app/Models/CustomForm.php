<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomForm extends Model
{
    use SoftDeletes;

    protected $table = 'custom_forms';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'form_type',
        'schema',
        'is_active',
        'is_template',
        'created_by',
    ];

    protected $casts = [
        'schema' => 'array',
        'is_active' => 'boolean',
        'is_template' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CustomFormSubmission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('form_type', $type);
    }
}
