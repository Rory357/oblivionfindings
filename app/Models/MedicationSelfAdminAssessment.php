<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationSelfAdminAssessment extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'medication_self_admin_assessments';

    protected $fillable = [
        'client_id',
        'status',
        'outcome',
        'cognitive_capacity',
        'physical_dexterity',
        'vision_ability',
        'swallowing_ability',
        'understanding_score',
        'can_identify_medications',
        'can_read_labels',
        'can_open_packaging',
        'can_manage_timing',
        'can_store_safely',
        'willing_to_self_admin',
        'risk_factors',
        'support_needed',
        'safe_storage_notes',
        'assessor_notes',
        'assessed_by',
        'assessment_date',
        'reassessment_date',
        'reassessment_trigger',
    ];

    protected $casts = [
        'assessment_date' => 'date',
        'reassessment_date' => 'date',
        'can_identify_medications' => 'boolean',
        'can_read_labels' => 'boolean',
        'can_open_packaging' => 'boolean',
        'can_manage_timing' => 'boolean',
        'can_store_safely' => 'boolean',
        'willing_to_self_admin' => 'boolean',
        'cognitive_capacity' => 'integer',
        'physical_dexterity' => 'integer',
        'vision_ability' => 'integer',
        'swallowing_ability' => 'integer',
        'understanding_score' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function getTotalScoreAttribute(): int
    {
        return ($this->cognitive_capacity ?? 0)
            + ($this->physical_dexterity ?? 0)
            + ($this->vision_ability ?? 0)
            + ($this->swallowing_ability ?? 0)
            + ($this->understanding_score ?? 0);
    }

    public function getOutcomeLabelAttribute(): string
    {
        return match ($this->outcome) {
            'independent' => 'Category 1: Independent Self-Administration',
            'prompted' => 'Category 2: Self-Administration with Prompting',
            'supervised' => 'Category 3: Supervised Self-Administration',
            'administered' => 'Category 4: Full Staff Administration',
            default => 'Not Assessed',
        };
    }

    public function isReassessmentDue(): bool
    {
        return $this->reassessment_date && $this->reassessment_date->isPast();
    }
}
