<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnonymizationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_type',
        'model_id',
        'reason',
        'fields_anonymized',
        'anonymization_methods',
        'data_subject_request_id',
        'anonymized_at',
        'anonymized_by_user_id',
        'reversible',
        'reversal_key_path',
    ];

    protected $casts = [
        'fields_anonymized' => 'array',
        'anonymization_methods' => 'array',
        'anonymized_at' => 'datetime',
        'reversible' => 'boolean',
    ];

    public function dataSubjectRequest(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class);
    }

    public function anonymizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anonymized_by_user_id');
    }
}
