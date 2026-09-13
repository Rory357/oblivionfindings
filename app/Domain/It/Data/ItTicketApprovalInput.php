<?php

namespace App\Domain\It\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class ItTicketApprovalInput
{
    public const OPERATIONS = ['request', 'decide', 'withdraw'];

    public const RESPONSIBILITY_FIELDS = ['primary_approver_user_id', 'cover_approver_user_id', 'expires_at', 'remind_at'];

    public static function rules(string $operation): array
    {
        $date = ['sometimes', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/'];

        return match ($operation) {
            'request' => ['reason' => ['nullable', 'string', 'max:1000'],
                'primary_approver_user_id' => ['sometimes', 'required', 'integer', 'min:1'],
                'cover_approver_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'expires_at' => $date, 'remind_at' => $date],
            'withdraw' => ['reason' => ['required', 'string', 'max:1000']],
            default => ['decision' => ['required', 'in:approve,reject'],
                'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000']],
        };
    }

    public static function normalize(string $operation, array $input): array
    {
        $data = Arr::only($input, array_keys(self::rules($operation)));
        if (isset($data['reason']) && is_string($data['reason'])) {
            $data['reason'] = trim($data['reason']);
            $data['reason'] = $data['reason'] === '' ? null : $data['reason'];
        }
        $data['reason'] ??= null;
        $data = Validator::make($data, self::rules($operation))->validate();
        foreach (['primary_approver_user_id', 'cover_approver_user_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['expires_at', 'remind_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->utc()->toIso8601String();
            }
        }
        ksort($data);

        return $data;
    }
}
