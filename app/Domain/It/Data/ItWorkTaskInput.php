<?php

namespace App\Domain\It\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** One allowlist for HTTP and direct canonical task writers. */
final class ItWorkTaskInput
{
    public const OPERATIONS = ['create', 'update', 'complete', 'reopen', 'reorder'];

    public static function rules(string $operation): array
    {
        if ($operation === 'complete') {
            return ['completion_note' => ['nullable', 'string', 'max:5000'],
                'evidence' => ['nullable', 'array', 'list', 'max:20'], 'evidence.*' => ['required', 'string', 'max:2000']];
        }
        if ($operation === 'reopen') {
            return ['reason' => ['required', 'string', 'max:2000']];
        }
        if ($operation === 'reorder') {
            return ['ordered_ids' => ['present', 'array', 'list', 'max:1000'], 'ordered_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
        }

        return [
            'title' => [...($operation === 'create' ? [] : ['sometimes']), 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_to_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'approval_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'is_required' => ['sometimes', 'boolean'], 'evidence_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'dependency_ids' => ['sometimes', 'array', 'list', 'max:1000'],
            'dependency_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ...($operation === 'update' ? [
                'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'blocked', 'cancelled'])],
                // The locked current task determines whether this is a real
                // cancellation, restoration or prerequisite/required repair.
                'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ] : []),
        ];
    }

    public static function normalize(string $operation, array $input, bool $partial = false): array
    {
        $rules = self::rules($operation);
        if ($partial) {
            foreach ($rules as $field => $constraints) {
                $rules[$field] = array_values(array_filter($constraints, fn ($rule) => ! is_string($rule)
                    || (! in_array($rule, ['required', 'present'], true) && ! str_starts_with($rule, 'required_if:'))));
                // HTTP normalizes unfinished blank text to null. Permission
                // proof must retain that work; final commands stay strict.
                if (in_array('string', $rules[$field], true) && ! in_array('nullable', $rules[$field], true)) {
                    $rules[$field][] = 'nullable';
                }
            }
        }
        $data = Arr::only($input, array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')));
        foreach (['title', 'reason'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        $data = Validator::make($data, $rules)->validate();
        foreach (['team_id', 'assigned_to_user_id', 'approval_id', 'sort_order'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['is_required', 'evidence_required'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }
        foreach (['dependency_ids', 'ordered_ids'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = array_values(array_map('intval', $data[$field]));
                if ($field === 'dependency_ids') {
                    sort($data[$field]);
                }
            }
        }
        if (isset($data['due_at'])) {
            $data['due_at'] = CarbonImmutable::parse($data['due_at'])->utc()->toIso8601String();
        }
        ksort($data);

        return $data;
    }
}
