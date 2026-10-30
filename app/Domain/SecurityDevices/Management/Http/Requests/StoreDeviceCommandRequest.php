<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use stdClass;

class StoreDeviceCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('capability') !== EndpointScriptContract::CAPABILITY) {
            return;
        }
        abort_unless($this->isJson(), 415);
        abort_unless($this->getQueryString() === null, 400);
        try {
            $data = get_object_vars(EndpointScriptContract::decode($this->getContent()));
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid reviewed script request.');
        }
        $required = ['expected_actor_id', 'capability', 'parameters', 'reason', 'idempotency_key', 'impact_acknowledged'];
        $allowed = [...$required, 'it_change_id', 'break_glass'];
        abort_unless(array_diff($required, array_keys($data)) === [] && array_diff(array_keys($data), $allowed) === []
            && is_int($data['expected_actor_id']) && $data['expected_actor_id'] > 0 && $data['expected_actor_id'] <= 9007199254740991
            && $data['parameters'] instanceof stdClass && $data['impact_acknowledged'] === true
            && (! array_key_exists('break_glass', $data) || $data['break_glass'] === false)
            && (! isset($data['it_change_id']) || (is_int($data['it_change_id']) && $data['it_change_id'] > 0 && $data['it_change_id'] <= 9007199254740991)), 422);
        $data['parameters'] = get_object_vars($data['parameters']);
        $this->replace($data);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'audit_tail_event_id' => ['missing'],
            'origin_context' => ['missing'],
            'origin' => ['missing'],
            'client_id' => ['missing'],
            'assignment_id' => ['missing'],
            'consent_id' => ['missing'],
            'access_fingerprint' => ['missing'],
            'expected_actor_id' => ['required_if:capability,endpoint.service.manage,endpoint.computer.restart,endpoint.software.install,endpoint.script.run', 'integer', 'min:1'],
            'capability' => ['required', 'string', 'max:120'],
            'parameters' => ['present', 'array'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9:_-]+$/'],
            'it_change_id' => ['nullable', 'integer', 'min:1'],
            'break_glass' => ['sometimes', 'boolean'],
            'break_glass_reason' => ['nullable', 'string', 'min:20', 'max:1000'],
            'break_glass_reviewer_user_id' => ['nullable', 'integer', 'min:1'],
            'impact_acknowledged' => ['sometimes', 'boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
