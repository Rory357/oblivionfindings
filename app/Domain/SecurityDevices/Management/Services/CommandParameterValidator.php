<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandCapabilityDefinition;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommandParameterValidator
{
    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    public function validate(CommandCapabilityDefinition $capability, array $parameters): array
    {
        $unknown = array_diff(array_keys($parameters), array_keys($capability->parameters));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'parameters' => 'The request contains parameters that are not allowed for this action.',
            ]);
        }

        $rules = [];
        foreach ($capability->parameters as $name => $schema) {
            $rules["parameters.{$name}"] = $this->rulesFor((array) $schema);
        }

        Validator::make(['parameters' => $parameters], $rules)->validate();

        return $parameters;
    }

    /** @return list<string> */
    private function rulesFor(array $schema): array
    {
        $rules = ['required'];
        $type = $schema['type'] ?? null;
        $rules[] = match ($type) {
            'integer' => 'integer',
            'string' => 'string',
            'date_time' => 'date',
            default => throw ValidationException::withMessages([
                'parameters' => 'The action parameter policy is invalid.',
            ]),
        };

        if (isset($schema['min'])) {
            $rules[] = 'min:'.(int) $schema['min'];
        }
        if (isset($schema['max'])) {
            $rules[] = 'max:'.(int) $schema['max'];
        }
        if (isset($schema['max_length'])) {
            $rules[] = 'max:'.(int) $schema['max_length'];
        }
        if (isset($schema['enum'])) {
            $rules[] = 'in:'.implode(',', Arr::wrap($schema['enum']));
        }

        return $rules;
    }
}
