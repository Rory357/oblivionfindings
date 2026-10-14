<?php

namespace App\Domain\Monitoring\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Closed authoring intent. It grants no authority and contains no provider action. */
final class MaintenanceCommandInput
{
    public const OPERATIONS = ['create', 'update', 'cancel'];

    public static function rules(string $operation, bool $preview = false): array
    {
        $creating = $operation === 'create';
        $required = $creating ? ['required'] : ['sometimes'];
        $rules = [
            'expected_actor_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'request_uuid' => $preview ? ['prohibited'] : ['required', 'string', 'uuid', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD'],
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
        if ($operation === 'cancel') {
            return $rules;
        }

        return [...$rules,
            'site_id' => [...$required, 'integer', 'min:1', 'max:9007199254740991'],
            'monitor_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'starts_at' => [...$required, 'date'],
            'ends_at' => [...$required, 'date'],
            'recurrence' => ['sometimes', 'nullable', 'in:daily,weekly'],
            'recurrence_until' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'dst_overlap' => ['sometimes', 'in:reject,earlier,later'],
            'dst_gap' => ['sometimes', 'in:reject,skip'],
        ];
    }

    public static function normalize(string $operation, array $input): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw ValidationException::withMessages(['form' => 'Choose a supported maintenance operation.']);
        }
        $rules = self::rules($operation);
        self::assertClosed($input, array_keys($rules));
        // Match the active HTTP hygiene also for direct owner callers.
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $trimmed = Str::trim($value);
                $input[$key] = $trimmed === '' ? null : $trimmed;
            }
        }
        $values = Validator::make($input, $rules)->validate();
        foreach (['expected_actor_id', 'version', 'site_id', 'device_id', 'monitor_id'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = (int) $values[$field];
            }
        }
        $values['request_uuid'] = strtolower($values['request_uuid']);
        ksort($values);

        return $values;
    }

    public static function fingerprint(string $operation, ?int $target, array $normalized): string
    {
        ksort($normalized);

        return hash('sha256', json_encode([
            'input' => $normalized, 'operation' => $operation, 'target_window_id' => $target,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function assertClosed(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['form' => 'The maintenance request contains unsupported fields. Reopen settings and review the change.']);
        }
    }
}
