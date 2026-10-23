<?php

namespace App\Services\Incidents;

/** Pure NEW window command protocol; no rule/source/override authority is inferred from its shape. */
final class IncidentEvidenceWindowCommandValues
{
    public const COMMAND = 'record_evidence_window';
    public const MAX_SOURCES = 64;

    public static function nonnegative(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0 || $value > 2147483646) { IncidentRecordingReconstructionValues::fail($field, 'Use a nonnegative whole-number expected revision.'); }
        return $value;
    }

    public static function input(array $input): array
    {
        $v = IncidentRecordingReconstructionValues::class;
        $v::exact($input, ['expected_actor_id', 'idempotency_key', 'expected_incident_evidence_version', 'expected_policy_revision',
            'requested_start_at', 'requested_end_at', 'approved_sources', 'reason'], 'command');
        if (!is_array($input['approved_sources']) || !array_is_list($input['approved_sources']) || count($input['approved_sources']) > self::MAX_SOURCES) {
            $v::fail('approved_sources', 'Use a finite explicit source list of at most 64 selected camera/recorder/Site identities.');
        }
        $sources = [];
        foreach ($input['approved_sources'] as $member) {
            if (!is_array($member)) { $v::fail('approved_sources', 'Use exact selected source identities.'); }
            $v::exact($member, ['camera_id', 'recorder_device_id', 'site_id'], 'approved_sources');
            $sources[] = ['camera_id' => $v::positive($member['camera_id'], 'camera_id'),
                'recorder_device_id' => $v::positive($member['recorder_device_id'], 'recorder_device_id'), 'site_id' => $v::positive($member['site_id'], 'site_id')];
        }
        usort($sources, static fn ($a, $b) => [$a['camera_id'], $a['recorder_device_id'], $a['site_id']] <=> [$b['camera_id'], $b['recorder_device_id'], $b['site_id']]);
        if (count(array_unique(array_map(static fn (array $source): string => IncidentRecordingReconstructionValues::digest($source), $sources))) !== count($sources)) { $v::fail('approved_sources', 'Select each source identity once.'); }
        return ['expected_actor_id' => $v::positive($input['expected_actor_id'], 'expected_actor_id'),
            'idempotency_key' => $v::uuid($input['idempotency_key'], 'idempotency_key'),
            'expected_incident_evidence_version' => self::nonnegative($input['expected_incident_evidence_version'], 'expected_incident_evidence_version'),
            'expected_policy_revision' => self::nonnegative($input['expected_policy_revision'], 'expected_policy_revision'),
            'requested_start_at' => $v::instant($input['requested_start_at'], 'requested_start_at'),
            'requested_end_at' => $v::instant($input['requested_end_at'], 'requested_end_at'), 'approved_sources' => $sources,
            'reason' => $v::text($input['reason'], 'reason', 10, 500)];
    }

    public static function fingerprint(int $actor, int $incident, array $input): string
    {
        $input = self::input($input); $v = IncidentRecordingReconstructionValues::class;
        $v::positive($incident, 'incident_id');
        if ($input['expected_actor_id'] !== $actor) { $v::fail('expected_actor_id', 'This window command belongs to another actor.'); }
        return $v::digest(['schema_version' => 1, 'actor_id' => $actor, 'incident_id' => $incident, 'command' => self::COMMAND, 'input' => $input]);
    }

    public static function confirmation(int $actor, array $input, array $window): array
    {
        $v = IncidentRecordingReconstructionValues::class; $input = self::input($input); $v::assertWindowRecord($window);
        if ($window['actor_user_id'] !== $actor || $input['expected_actor_id'] !== $actor
            || $window['introduced_evidence_version'] !== $input['expected_incident_evidence_version'] + 1
            || $window['rule_revision'] !== $input['expected_policy_revision'] || $window['start_at'] !== $input['requested_start_at']
            || $window['end_at'] !== $input['requested_end_at'] || $window['approved_sources'] !== $input['approved_sources']) {
            $v::fail('confirmation', 'The original window command and complete immutable window must match.');
        }
        return ['schema_version' => 1, 'actor_id' => $actor, 'incident_id' => $window['incident_id'],
            'command' => ['idempotency_key' => $input['idempotency_key'], 'kind' => self::COMMAND,
                'confirmed_incident_evidence_version' => $window['introduced_evidence_version'],
                'confirmed_evidence_window_revision' => $window['window_revision'], 'confirmed_at' => $window['recorded_at']],
            'window' => ['revision' => $window['window_revision'], 'start_at' => $window['start_at'], 'end_at' => $window['end_at'],
                'rule_identifier' => $window['rule_identifier'], 'rule_revision' => $window['rule_revision'], 'window_sha256' => $window['window_sha256']]];
    }
}
