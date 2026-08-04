<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CareNoteTemplate extends Model
{
    use SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $table = 'care_note_templates';

    protected $fillable = [
        'name',
        'description',
        'template_type',
        'fields',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
