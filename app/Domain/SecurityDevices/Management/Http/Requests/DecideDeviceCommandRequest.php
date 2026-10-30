<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DecideDeviceCommandRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('command')?->capability !== EndpointScriptContract::CAPABILITY) {
            return;
        }
        abort_unless($this->isJson(), 415);
        abort_unless($this->getQueryString() === null, 400);
        try {
            $data = get_object_vars(EndpointScriptContract::decode($this->getContent(), 8192));
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script command decision.');
        }
        abort_unless(count($data) === 3 && array_diff(array_keys($data), ['expected_actor_id', 'decision', 'comment']) === []
            && is_int($data['expected_actor_id'] ?? null) && $data['expected_actor_id'] > 0 && $data['expected_actor_id'] <= 9007199254740991, 422);
        $this->replace($data);
    }

    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.commands.approve') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_actor_id' => [$this->route('command')?->capability === EndpointScriptContract::CAPABILITY ? 'required' : 'sometimes', 'integer:strict', 'min:1'],
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
