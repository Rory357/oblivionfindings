<?php

namespace App\Services\Incidents;

use DateTimeImmutable;

/** Pure policy shape and scope; never a source or override permission grant. */
final class IncidentEvidenceWindowPolicyValues
{
    public const SOURCE_TYPES = ['sensor', 'camera', 'door_access', 'staff_location', 'supported_person_location', 'response'];
    public const TRIGGERS = ['injury'=>'Injury','fall'=>'Fall','behaviour'=>'Behaviour','medication'=>'Medication','safeguarding'=>'Safeguarding','near_miss'=>'Near miss','property_damage'=>'Property damage','missing_person'=>'Missing person','complaint'=>'Complaint','other'=>'Other'];

    public static function policy(array $policy): array
    {
        $v = IncidentRecordingReconstructionValues::class;
        $v::exact($policy, ['schema_version', 'enabled', 'rules'], 'policy');
        if ($policy['schema_version'] !== 1 || !is_bool($policy['enabled']) || !is_array($policy['rules']) || !array_is_list($policy['rules'])) {
            $v::fail('policy', 'Use the supported policy schema with an explicit enabled state and rule list.');
        }
        if ((!$policy['enabled'] && $policy['rules'] !== []) || ($policy['enabled'] && $policy['rules'] === [])) {
            $v::fail('policy.rules', 'Enabled policies require explicit rules; disabled policies require an empty rule list.');
        }
        $ids = $pairs = []; $rules = [];
        foreach ($policy['rules'] as $rule) {
            if (!is_array($rule)) { $v::fail('policy.rules', 'Use explicit rule objects.'); }
            $v::exact($rule, ['identifier','trigger','site_id','before_seconds','after_seconds','max_before_seconds','max_after_seconds','source_types'], 'policy.rules');
            foreach (['identifier' => 128, 'trigger' => 80] as $key => $max) {
                $rule[$key] = $v::text($rule[$key], 'policy.rules.'.$key, 1, $max);
                if (!preg_match('/\A[a-z][a-z0-9_.-]*\z/', $rule[$key])) { $v::fail('policy.rules.'.$key, 'Use a stable lowercase identifier.'); }
            }
            if ($rule['site_id'] !== null) { $v::positive($rule['site_id'], 'policy.rules.site_id'); }
            if (!array_key_exists($rule['trigger'], self::TRIGGERS)) { $v::fail('policy.rules.trigger', 'Choose a supported canonical incident type.'); }
            foreach (['before_seconds','after_seconds','max_before_seconds','max_after_seconds'] as $key) {
                if (!is_int($rule[$key]) || $rule[$key] < 0 || $rule[$key] > 2147483647) { $v::fail('policy.rules.'.$key, 'Use a nonnegative whole number of seconds within storage bounds.'); }
            }
            if ($rule['before_seconds'] + $rule['after_seconds'] === 0 || $rule['max_before_seconds'] < $rule['before_seconds'] || $rule['max_after_seconds'] < $rule['after_seconds']) {
                $v::fail('policy.rules', 'Require a nonempty window and explicit expansion bounds at least as large as its base durations.');
            }
            if (!is_array($rule['source_types']) || !array_is_list($rule['source_types']) || $rule['source_types'] === [] || count(array_unique($rule['source_types'], SORT_REGULAR)) !== count($rule['source_types'])) {
                $v::fail('policy.rules.source_types', 'Choose distinct supported source types.');
            }
            foreach ($rule['source_types'] as $type) { if (!is_string($type) || !in_array($type, self::SOURCE_TYPES, true)) { $v::fail('policy.rules.source_types', 'Choose supported source types.'); } }
            sort($rule['source_types'], SORT_STRING);
            $pair = $rule['trigger'].':'.($rule['site_id'] ?? '*');
            if (isset($ids[$rule['identifier']]) || isset($pairs[$pair])) { $v::fail('policy.rules', 'Rule identifiers and trigger/Site selections must be unique.'); }
            $ids[$rule['identifier']] = $pairs[$pair] = true; $rules[] = $rule;
        }
        usort($rules, fn ($a, $b) => strcmp($a['identifier'], $b['identifier']));
        return ['schema_version' => 1, 'enabled' => $policy['enabled'], 'rules' => $rules];
    }

    public static function effectiveRule(array $policy, string $trigger, int $siteId): ?array
    {
        $policy = self::policy($policy); $fallback = null;
        if (!$policy['enabled']) { return null; }
        foreach ($policy['rules'] as $rule) {
            if ($rule['trigger'] !== $trigger) { continue; }
            if ($rule['site_id'] === $siteId) { return $rule; }
            if ($rule['site_id'] === null) { $fallback = $rule; }
        }
        return $fallback;
    }

    /** Uses canonical occurrence floored to the source's whole-second UTC protocol. */
    public static function assertWindow(array $window, array $policy): void
    {
        $v = IncidentRecordingReconstructionValues::class;
        $v::assertWindowRecord($window); $policy = self::policy($policy);
        $rule = null;
        foreach ($policy['rules'] as $candidate) { if ($candidate['identifier'] === $window['rule_identifier']) { $rule = $candidate; break; } }
        if (!$policy['enabled'] || !$rule || $rule['trigger'] !== $window['context_anchor']['incident_type'] || self::effectiveRule($policy, $window['context_anchor']['incident_type'], $window['effective_site_id']) !== $rule) { $v::fail('window', 'The original policy does not approve this incident type and rule at the captured Site.'); }
        if ($window['approved_sources'] !== [] && !in_array('camera', $rule['source_types'], true)) { $v::fail('window', 'The original rule does not permit camera source selection.'); }
        $occurred = new DateTimeImmutable($window['context_anchor']['occurred_at']);
        $second = $occurred->getTimestamp();
        $before = $second - (new DateTimeImmutable($window['start_at']))->getTimestamp();
        $after = (new DateTimeImmutable($window['end_at']))->getTimestamp() - $second;
        if ($before < $rule['before_seconds'] || $after < $rule['after_seconds'] || $before > $rule['max_before_seconds'] || $after > $rule['max_after_seconds']) { $v::fail('window', 'The recorded window is outside its explicit policy bounds.'); }
        if (($before !== $rule['before_seconds'] || $after !== $rule['after_seconds']) && $window['override_reason'] === null) { $v::fail('override_reason', 'Expanded windows require an attributable purpose and separate current override authority.'); }
    }
}
