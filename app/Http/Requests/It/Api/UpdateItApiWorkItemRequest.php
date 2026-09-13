<?php

namespace App\Http\Requests\It\Api;

use App\Domain\It\Services\ItApiFieldPolicy;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Models\ItServiceIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateItApiWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = $this->attributes->get('it_service_identity');
        $id = $this->route('workItem');
        if (! $identity instanceof ItServiceIdentity || ! is_numeric($id)) {
            return false;
        }
        $ticket = app(ItApiWorkItemService::class)->authorizedTicket($identity, (int) $id, 'work:update', true);
        abort_unless($ticket, 404);
        $this->attributes->set('it_api_ticket', $ticket);

        return true;
    }

    public function rules(): array
    {
        return ItApiFieldPolicy::updateRules();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            app(ItApiFieldPolicy::class)->assertUpdateFields($this->attributes->get('it_service_identity'), array_keys($this->all()));
            if (array_intersect(array_keys($this->all()), [...ItServiceIdentity::UPDATE_FIELDS, 'release_priority_override']) === []) {
                $validator->errors()->add('form', 'Supply at least one permitted property to update.');
            }
        }];
    }
}
