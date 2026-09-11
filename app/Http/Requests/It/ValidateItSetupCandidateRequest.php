<?php

namespace App\Http\Requests\It;

use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ValidateItSetupCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApproved() && $this->user()->canDo('it.manage')
            && (int) $this->input('actor_user_id') === (int) $this->user()->id;
    }

    public function rules(): array
    {
        $fields = match ($this->input('resource')) {
            'teams' => ['name', 'description', 'manager_user_id', 'is_active', 'members'],
            'services' => ['key', 'name', 'description', 'owner_user_id', 'status', 'criticality', 'is_active'],
            'queues' => ['key', 'name', 'description', 'team_id', 'routing_priority', 'is_default', 'work_types', 'categories', 'priorities', 'service_ids', 'site_ids', 'default_assignee_user_id', 'cover_user_id', 'is_active'],
            default => [],
        };
        $rules = [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'resource' => ['required', Rule::in(['teams', 'queues', 'services'])],
            'record_id' => ['present', 'nullable', 'integer', 'min:1'],
            'context_uuid' => ['required', 'uuid'],
            'candidate_uuid' => ['required', 'uuid'],
            'configuration_version' => ['present', Rule::requiredIf(fn () => $this->input('record_id') !== null), 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'step_index' => ['required', 'integer', 'min:0', 'max:'.($this->input('resource') === 'queues' ? 3 : 2)],
            'bound_scopes' => ['present', 'array', 'list', 'max:100'],
            'bound_scopes.*' => ['array:user_ids,site_ids,team_ids,service_ids'],
        ];
        foreach (['user_ids', 'site_ids', 'team_ids', 'service_ids'] as $binding) {
            $rules['bound_scopes.*.'.$binding] = ['sometimes', 'array', 'list', 'max:200'];
            // Laravel expands nested wildcard distinct rules across all history.
            // A retained person may legitimately recur after a Site change.
            for ($index = 0; $index < min(100, count((array) $this->input('bound_scopes', []))); $index++) {
                $rules['bound_scopes.'.$index.'.'.$binding.'.*'] = ['integer', 'min:1', 'distinct'];
            }
        }
        // Recovery validates shape and scope, not completeness or uniqueness.
        // Empty required inputs remain available for the user to correct.
        foreach (['base_fields', 'fields'] as $prefix) {
            $rules[$prefix] = ['present', 'array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $rules[$prefix.'.'.$field] = match ($field) {
                    'name' => ['sometimes', 'nullable', 'string', 'max:255'],
                    'key' => ['sometimes', 'nullable', 'string', 'max:100'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                    'is_active', 'is_default' => ['sometimes', 'boolean'],
                    'routing_priority' => ['sometimes', 'integer', 'between:0,1000'],
                    'members', 'service_ids', 'site_ids' => ['sometimes', 'array', 'max:200'],
                    'work_types', 'categories', 'priorities' => ['sometimes', 'array', 'max:30'],
                    'status' => ['sometimes', Rule::in(ItService::STATUSES)],
                    'criticality' => ['sometimes', Rule::in(ItService::CRITICALITIES)],
                    default => ['sometimes', 'nullable', 'integer', 'min:1'],
                };
            }
            if (in_array('members', $fields, true)) {
                $rules[$prefix.'.members.*'] = ['array:user_id,role'];
                $rules[$prefix.'.members.*.user_id'] = ['required', 'integer', 'min:1', 'distinct'];
                $rules[$prefix.'.members.*.role'] = ['required', Rule::in(ItTeam::MEMBER_ROLES)];
            }
            foreach (['site_ids', 'service_ids'] as $field) {
                if (in_array($field, $fields, true)) {
                    $rules[$prefix.'.'.$field.'.*'] = ['integer', 'min:1', 'distinct'];
                }
            }
            foreach (['work_types' => ItTicket::WORK_TYPES, 'categories' => ItTicket::CATEGORIES, 'priorities' => ItTicket::PRIORITIES] as $field => $values) {
                if (in_array($field, $fields, true)) {
                    $rules[$prefix.'.'.$field.'.*'] = ['string', 'distinct', Rule::in($values)];
                }
            }
        }

        return $rules;
    }
}
