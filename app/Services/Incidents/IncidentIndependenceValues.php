<?php

namespace App\Services\Incidents;

use Illuminate\Support\Str;

/** Reported involvement and private policy only; never misconduct, competence or HR classification. */
final class IncidentIndependenceValues
{
    public const DEFAULT_MODE = 'block_involved_access';
    public const MODES = ['block_involved_access', 'assignment_only'];

    public static function digest(mixed $value): string { return IncidentRoutingValues::digest($value); }
    public static function fail(string $field, string $message): never { IncidentRoutingValues::error($field, $message); }
    public static function integer(mixed $value, string $field, bool $zero = false): int { return IncidentRoutingValues::integer($value, $field, $zero); }
    public static function uuid(mixed $value, string $field = 'request_uuid'): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) { self::fail($field, 'Use a valid identity for this exact work.'); }
        return strtolower($value);
    }
    public static function mode(mixed $mode): string
    {
        if (! in_array($mode, self::MODES, true)) { self::fail('mode', 'Choose a supported independent-handling policy.'); }
        return $mode;
    }
    public static function policyCommand(array $input): array
    {
        IncidentRoutingValues::keys($input, ['mode', 'expected_policy_revision', 'request_uuid', 'reason'], 'policy');
        if (! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen($input['reason']) > 4000) { self::fail('reason', 'Record the actual reason for this policy change.'); }
        return ['mode' => self::mode($input['mode'] ?? null), 'expected_policy_revision' => self::integer($input['expected_policy_revision'] ?? null, 'expected_policy_revision', true),
            'request_uuid' => self::uuid($input['request_uuid'] ?? null), 'reason' => $input['reason']];
    }
    public static function handling(array $input): array
    {
        IncidentRoutingValues::keys($input, ['requested', 'reason_code', 'identification', 'involved_user_ids'], 'independent_handling');
        if (($input['requested'] ?? null) !== true || ! in_array($input['reason_code'] ?? null, ['usual_manager_involved', 'other_staff_involved'], true)
            || ! in_array($input['identification'] ?? null, ['named', 'unknown'], true) || ! is_array($input['involved_user_ids'] ?? null)
            || ! array_is_list($input['involved_user_ids']) || count($input['involved_user_ids']) > 10) {
            self::fail('independent_handling', 'Record an explicit independent-handling request and whether the person is identified.');
        }
        $ids = array_map(fn ($id) => self::integer($id, 'independent_handling.involved_user_ids'), $input['involved_user_ids']);
        if (count(array_unique($ids)) !== count($ids) || ($input['identification'] === 'named' ? $ids === [] : $ids !== [])) {
            self::fail('independent_handling.involved_user_ids', 'Choose distinct permitted people, or explicitly record that the person is not identified.');
        }
        sort($ids);
        return ['requested' => true, 'reason_code' => $input['reason_code'], 'identification' => $input['identification'], 'involved_user_ids' => $ids];
    }
    public static function intakeCommand(array $input): array
    {
        IncidentRoutingValues::keys($input, ['request_uuid', 'report_request_uuid', 'expected_revision', 'expected_policy_revision', 'intent', 'capture_context', 'independent_handling'], 'independent_handling');
        if (! in_array($input['intent'] ?? null, ['draft', 'submit'], true) || ! is_array($input['capture_context'] ?? null) || ! is_array($input['independent_handling'] ?? null)) {
            self::fail('independent_handling', 'Use the actual report intent and source context.');
        }
        $context = $input['capture_context'];
        IncidentRoutingValues::keys($context, ['client_id', 'site_id', 'shift_id'], 'capture_context');
        return ['request_uuid' => self::uuid($input['request_uuid'] ?? null), 'report_request_uuid' => self::uuid($input['report_request_uuid'] ?? null, 'report_request_uuid'),
            'expected_revision' => self::integer($input['expected_revision'] ?? null, 'expected_revision', true), 'expected_policy_revision' => self::integer($input['expected_policy_revision'] ?? null, 'expected_policy_revision', true), 'intent' => $input['intent'],
            'capture_context' => ['client_id' => self::integer($context['client_id'] ?? null, 'capture_context.client_id'), 'site_id' => self::integer($context['site_id'] ?? null, 'capture_context.site_id'),
                'shift_id' => ($context['shift_id'] ?? null) === null ? null : self::integer($context['shift_id'], 'capture_context.shift_id')],
            'independent_handling' => self::handling($input['independent_handling'])];
    }
    /** The shared acceptance UUID UNIQUE index arbitrates cross-policy and cross-domain races. */
    public static function uniqueReceipt(callable $operation): mixed
    {
        try { return $operation(); }
        catch (\Illuminate\Database\QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062
                && str_contains($error->getMessage(), 'health_safety_setting_changes_command_key_unique')) {
                self::fail('request_uuid', 'This identity was accepted for other work. Preserve your entries and review the exact original command.');
            }
            throw $error;
        }
    }
}
