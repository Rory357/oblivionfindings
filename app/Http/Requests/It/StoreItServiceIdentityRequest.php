<?php

namespace App\Http\Requests\It;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItServiceIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreItServiceIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = (int) $this->user()->organization_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'actor_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('organization_id', $tenantId)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ItServiceIdentity::ABILITIES)],
            'allowed_work_types' => ['required', 'array', 'min:1'],
            'allowed_work_types.*' => ['string', Rule::in(['incident', 'service_request', 'security_request'])],
            'allowed_site_ids' => ['present', 'array'],
            'allowed_site_ids.*' => ['integer', Rule::exists('sites', 'id')->where('tenant_id', $tenantId)],
            'create_fields' => ['required', 'array', 'min:4'],
            'create_fields.*' => ['string', Rule::in(ItServiceIdentity::CREATE_FIELDS)],
            'read_fields' => ['present', 'array'],
            'read_fields.*' => ['string', Rule::in(ItServiceIdentity::READ_FIELDS)],
            'require_signature' => ['required', 'boolean'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:300'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $missing = array_diff(ItServiceIdentity::REQUIRED_CREATE_FIELDS, (array) $this->input('create_fields', []));
            if ($missing !== []) {
                $validator->errors()->add(
                    'create_fields',
                    'Title, category, priority, and work type must remain enabled for intake.',
                );
            }

            $tenantId = (int) $this->user()->organization_id;
            $actorId = (int) $this->input('actor_user_id');
            if (! ItStaffDirectory::agents($tenantId)->contains(fn ($user) => $user->id === $actorId)) {
                $validator->errors()->add('actor_user_id', 'Choose an IT agent in this organisation.');
            }
        }];
    }
}
