<?php

namespace App\Http\Requests\It\Api;

use App\Domain\It\Services\ItApiWorkItemService;
use App\Models\ItServiceIdentity;
use App\Models\ItTicketLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LinkItApiWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = $this->attributes->get('it_service_identity');
        $source = $this->route('workItem');
        $target = $this->input('target_ticket_id');
        if (! $identity instanceof ItServiceIdentity || ! is_numeric($source)) {
            return false;
        }
        if (! is_numeric($target)) {
            abort_unless(app(ItApiWorkItemService::class)->authorizedTicket(
                $identity, (int) $source, 'work:link', true,
            ), 404);

            // Only an authorized source can receive malformed-target validation.
            return true;
        }
        $pair = app(ItApiWorkItemService::class)->authorizedRelatedTickets($identity, (int) $source, (int) $target);
        abort_unless($pair, 404);
        $this->attributes->set('it_api_ticket', $pair[0]);
        $this->attributes->set('it_api_related_ticket', $pair[1]);

        return true;
    }

    public function rules(): array
    {
        return [
            'target_ticket_id' => ['required', 'integer', 'min:1'],
            'source_version' => ['required', 'integer', 'min:1'],
            'target_version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'relationship' => ['required', Rule::in(ItTicketLink::BROWSER_RELATIONSHIPS)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not available for API relationships.');
            }
        }];
    }
}
