<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationCompetencyAssessment extends Model
{
    use AuditableChanges, HasFactory;

    protected $fillable = [
        'user_id',
        'assessor_id',
        'assessment_type',
        'status',
        'assessment_date',
        'expiry_date',
        'medication_knowledge',
        'five_rights',
        'safety_checks',
        'documentation',
        'controlled_drugs',
        'prn_assessment',
        'insulin_competent',
        'inhaler_competent',
        'topical_competent',
        'covert_admin_knowledge',
        'error_reporting',
        'allergy_awareness',
        'total_score',
        'pass_threshold',
        'strengths',
        'areas_for_improvement',
        'action_plan',
        'assessor_comments',
        'observed_rounds',
        'can_administer_unsupervised',
        'can_witness_controlled',
        'restricted',
        'restriction_notes',
        'not_seen_areas',
    ];

    protected $casts = [
        'assessment_date' => 'date',
        'expiry_date' => 'date',
        'medication_knowledge' => 'boolean',
        'five_rights' => 'boolean',
        'safety_checks' => 'boolean',
        'documentation' => 'boolean',
        'controlled_drugs' => 'boolean',
        'prn_assessment' => 'boolean',
        'insulin_competent' => 'boolean',
        'inhaler_competent' => 'boolean',
        'topical_competent' => 'boolean',
        'covert_admin_knowledge' => 'boolean',
        'error_reporting' => 'boolean',
        'allergy_awareness' => 'boolean',
        'total_score' => 'integer',
        'pass_threshold' => 'integer',
        'observed_rounds' => 'array',
        'can_administer_unsupervised' => 'boolean',
        'can_witness_controlled' => 'boolean',
        'restricted' => 'boolean',
        'not_seen_areas' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'passed')
            ->where(function ($q) {
                $q->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now()->toDateString());
            });
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now()->toDateString());
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'passed')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isPassed(): bool
    {
        return $this->status === 'passed' && ! $this->isExpired();
    }

    public function getCompetencyAreasAttribute(): array
    {
        return [
            'Medication Knowledge' => $this->medication_knowledge,
            'Five Rights' => $this->five_rights,
            'Safety Checks' => $this->safety_checks,
            'Documentation' => $this->documentation,
            'Controlled Drugs' => $this->controlled_drugs,
            'PRN Assessment' => $this->prn_assessment,
            'Insulin Administration' => $this->insulin_competent,
            'Inhaler Technique' => $this->inhaler_competent,
            'Topical Application' => $this->topical_competent,
            'Covert Administration' => $this->covert_admin_knowledge,
            'Error Reporting' => $this->error_reporting,
            'Allergy Awareness' => $this->allergy_awareness,
        ];
    }

    public function getPassedCountAttribute(): int
    {
        return collect($this->competencyAreas)->filter()->count();
    }

    public function getTotalAreasAttribute(): int
    {
        return collect($this->competencyAreas)->count();
    }
}
