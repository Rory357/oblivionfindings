<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicalProfile extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const DISABILITY_OPTIONS = [
        // Sensory
        ['value' => 'blind', 'label' => 'Blind / Visually Impaired', 'group' => 'Sensory'],
        ['value' => 'deaf', 'label' => 'Deaf / Hard of Hearing', 'group' => 'Sensory'],
        ['value' => 'deafblind', 'label' => 'Deafblind', 'group' => 'Sensory'],
        ['value' => 'low_vision', 'label' => 'Low Vision', 'group' => 'Sensory'],
        // Physical
        ['value' => 'wheelchair_user', 'label' => 'Wheelchair User', 'group' => 'Physical'],
        ['value' => 'limited_mobility', 'label' => 'Limited Mobility', 'group' => 'Physical'],
        ['value' => 'amputation', 'label' => 'Amputation', 'group' => 'Physical'],
        ['value' => 'cerebral_palsy', 'label' => 'Cerebral Palsy', 'group' => 'Physical'],
        ['value' => 'muscular_dystrophy', 'label' => 'Muscular Dystrophy', 'group' => 'Physical'],
        ['value' => 'spinal_cord_injury', 'label' => 'Spinal Cord Injury', 'group' => 'Physical'],
        ['value' => 'chronic_pain', 'label' => 'Chronic Pain Condition', 'group' => 'Physical'],
        // Cognitive / Intellectual
        ['value' => 'intellectual_disability', 'label' => 'Intellectual Disability', 'group' => 'Cognitive'],
        ['value' => 'autism_spectrum', 'label' => 'Autism Spectrum Disorder', 'group' => 'Cognitive'],
        ['value' => 'down_syndrome', 'label' => 'Down Syndrome', 'group' => 'Cognitive'],
        ['value' => 'acquired_brain_injury', 'label' => 'Acquired Brain Injury', 'group' => 'Cognitive'],
        ['value' => 'dementia', 'label' => 'Dementia', 'group' => 'Cognitive'],
        ['value' => 'learning_disability', 'label' => 'Learning Disability', 'group' => 'Cognitive'],
        // Mental Health
        ['value' => 'schizophrenia', 'label' => 'Schizophrenia', 'group' => 'Mental Health'],
        ['value' => 'bipolar_disorder', 'label' => 'Bipolar Disorder', 'group' => 'Mental Health'],
        ['value' => 'ptsd', 'label' => 'PTSD', 'group' => 'Mental Health'],
        ['value' => 'anxiety_disorder', 'label' => 'Anxiety Disorder', 'group' => 'Mental Health'],
        ['value' => 'depression', 'label' => 'Depression (Clinical)', 'group' => 'Mental Health'],
        // Neurological
        ['value' => 'epilepsy', 'label' => 'Epilepsy', 'group' => 'Neurological'],
        ['value' => 'multiple_sclerosis', 'label' => 'Multiple Sclerosis', 'group' => 'Neurological'],
        ['value' => 'parkinsons', 'label' => "Parkinson's Disease", 'group' => 'Neurological'],
        ['value' => 'stroke', 'label' => 'Stroke', 'group' => 'Neurological'],
        // Communication
        ['value' => 'speech_impairment', 'label' => 'Speech / Language Impairment', 'group' => 'Communication'],
        ['value' => 'nonverbal', 'label' => 'Nonverbal', 'group' => 'Communication'],
    ];

    public const ALLERGEN_OPTIONS = [
        // Medications
        ['value' => 'penicillin', 'label' => 'Penicillin', 'group' => 'Medications'],
        ['value' => 'aspirin', 'label' => 'Aspirin / NSAIDs', 'group' => 'Medications'],
        ['value' => 'sulfonamides', 'label' => 'Sulfonamides', 'group' => 'Medications'],
        ['value' => 'codeine', 'label' => 'Codeine / Opioids', 'group' => 'Medications'],
        ['value' => 'ibuprofen', 'label' => 'Ibuprofen', 'group' => 'Medications'],
        ['value' => 'morphine', 'label' => 'Morphine', 'group' => 'Medications'],
        ['value' => 'latex', 'label' => 'Latex', 'group' => 'Medications'],
        ['value' => 'contrast_dye', 'label' => 'Contrast Dye', 'group' => 'Medications'],
        ['value' => 'anaesthetics', 'label' => 'Local Anaesthetics', 'group' => 'Medications'],
        // Food
        ['value' => 'peanuts', 'label' => 'Peanuts', 'group' => 'Food'],
        ['value' => 'tree_nuts', 'label' => 'Tree Nuts', 'group' => 'Food'],
        ['value' => 'dairy', 'label' => 'Dairy / Lactose', 'group' => 'Food'],
        ['value' => 'eggs', 'label' => 'Eggs', 'group' => 'Food'],
        ['value' => 'shellfish', 'label' => 'Shellfish', 'group' => 'Food'],
        ['value' => 'fish', 'label' => 'Fish', 'group' => 'Food'],
        ['value' => 'wheat_gluten', 'label' => 'Wheat / Gluten', 'group' => 'Food'],
        ['value' => 'soy', 'label' => 'Soy', 'group' => 'Food'],
        ['value' => 'sesame', 'label' => 'Sesame', 'group' => 'Food'],
        // Environmental
        ['value' => 'pollen', 'label' => 'Pollen', 'group' => 'Environmental'],
        ['value' => 'dust_mites', 'label' => 'Dust Mites', 'group' => 'Environmental'],
        ['value' => 'mould', 'label' => 'Mould', 'group' => 'Environmental'],
        ['value' => 'animal_dander', 'label' => 'Animal Dander', 'group' => 'Environmental'],
        ['value' => 'insect_stings', 'label' => 'Insect Stings (Bee/Wasp)', 'group' => 'Environmental'],
    ];

    protected $fillable = [
        'client_id',
        'medical_history',
        'disabilities',
        'allergies',
        'notes',
    ];

    protected $casts = [
        'disabilities' => 'array',
        'allergies' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function isBlind(): bool
    {
        return $this->hasDisability('blind') || $this->hasDisability('deafblind');
    }

    public function hasDisability(string $key): bool
    {
        return in_array($key, $this->disabilities ?? [], true);
    }

    public function hasAllergy(string $key): bool
    {
        return in_array($key, $this->allergies ?? [], true);
    }

    public function scopeWithDisability(Builder $query, string $disability): Builder
    {
        return $query->whereJsonContains('disabilities', $disability);
    }

    public function scopeWithAllergy(Builder $query, string $allergy): Builder
    {
        return $query->whereJsonContains('allergies', $allergy);
    }
}
