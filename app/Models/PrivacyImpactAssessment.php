<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyImpactAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'assessment_name',
        'project_or_process',
        'description',
        'assessment_type',
        'assessor_id',
        'assessment_date',
        'personal_data_types',
        'data_subjects',
        'processing_purpose',
        'legal_basis',
        'identified_risks',
        'overall_risk_level',
        'mitigation_measures',
        'residual_risk_level',
        'outcome',
        'approved_by_user_id',
        'approved_at',
        'review_date',
    ];

    protected $casts = [
        'assessment_date' => 'datetime',
        'approved_at' => 'datetime',
        'review_date' => 'date',
        'personal_data_types' => 'array',
        'data_subjects' => 'array',
        'identified_risks' => 'array',
        'mitigation_measures' => 'array',
    ];

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
