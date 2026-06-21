<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First Aid Register gold-standard upgrade — Step 3. The single canonical rule set for
 * recording a first-aid treatment, shared with UpdateFirstAidRecordRequest and mirrored
 * by the FE wizard / shared options module so FE and BE enums cannot drift.
 *
 * Outcome vocabulary is the canonical seven (duplicate spellings collapsed by the
 * gold-standard migration); `ambulance_called` is a boolean flag, never an outcome.
 */
class StoreFirstAidRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->canDo('hazards.manage') || $this->user()?->canDo('hazards.create'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'exists:sites,id'],
            'treated_person_id' => ['nullable', 'exists:users,id'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'treated_person_name' => ['required', 'string', 'max:255'],
            'treated_person_type' => ['required', 'in:staff,client,visitor,contractor'],
            'treatment_date' => ['required', 'date'],
            'injury_illness_type' => ['required', 'in:cut,burn,bruise,sprain,fracture,fall,head_injury,eye_injury,allergic_reaction,breathing_difficulty,chest_pain,seizure,fainting,nausea,sting,choking,other'],
            'injury_illness_description' => ['required', 'string'],
            'body_part' => ['nullable', 'string', 'max:255'],
            'treatment_given' => ['required', 'string'],
            'treatment_outcome' => ['required', 'in:returned_to_activity,sent_home,medical_centre,sent_to_hospital,ongoing_monitoring,refused_treatment,other'],
            'ambulance_called' => ['boolean'],
            'first_aider_id' => ['required', 'exists:users,id'],
            'first_aider_notes' => ['nullable', 'string'],
            'incident_reported' => ['boolean'],
            'related_incident_id' => ['nullable', 'exists:client_incidents,id'],
        ];
    }
}
