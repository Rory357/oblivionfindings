<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentTypeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'consent_type_id',
        'version',
        'description',
        'purpose',
        'legal_basis',
        'changes_summary',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'changes_summary' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function consentType(): BelongsTo
    {
        return $this->belongsTo(ConsentType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
