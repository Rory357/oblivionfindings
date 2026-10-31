<?php

namespace App\Services\Incidents;

/** Shared exact scalar strict-audit contract for the reader and forthcoming command writer. */
final class IncidentRecordingAuditValues
{
    public const WINDOW_RECORDED = 'incidents.evidence.window_recorded';
    public const RECORDING_ATTACHED = 'incidents.evidence.recording_attached';
    public const WINDOW_INVALIDATED = 'incidents.evidence.window_invalidated';

    private static function common(array $window, int $version): array
    {
        $v = IncidentRecordingReconstructionValues::class;
        $v::assertWindowRecord($window);
        return ['schema_version' => 1, 'incident_id' => $window['incident_id'],
            'incident_evidence_version' => $v::revision($version, 'incident_evidence_version'),
            'window_revision' => $window['window_revision'], 'window_sha256' => $window['window_sha256']];
    }

    public static function window(array $window): array
    {
        return [...self::common($window, $window['introduced_evidence_version']),
            'policy_change_id' => $window['policy_change_id'], 'rule_revision' => $window['rule_revision']];
    }

    public static function attachment(array $window, int $version, string $attachmentUuid, string $referenceUuid,
        string $idempotencyKey, string $commandFingerprint, string $sourceSnapshotHash): array
    {
        $v = IncidentRecordingReconstructionValues::class;
        if ($version <= $window['introduced_evidence_version']) { $v::fail('incident_evidence_version', 'Attachment audit must follow its original window.'); }
        return [...self::common($window, $version), 'attachment_uuid' => $v::uuid($attachmentUuid, 'attachment_uuid'),
            'reference_uuid' => $v::uuid($referenceUuid, 'reference_uuid'), 'idempotency_key' => $v::uuid($idempotencyKey, 'idempotency_key'),
            'command_fingerprint' => $v::hash($commandFingerprint, 'command_fingerprint'),
            'source_snapshot_sha256' => $v::hash($sourceSnapshotHash, 'source_snapshot_sha256')];
    }

    public static function invalidation(array $window, int $version, string $reason): array
    {
        $v = IncidentRecordingReconstructionValues::class;
        if ($version <= $window['introduced_evidence_version'] || $v::text($reason, 'reason', 10, 500) !== $reason) {
            $v::fail('reason', 'Use the exact validated original invalidation reason after the original window.');
        }
        return [...self::common($window, $version), 'reason_sha256' => hash('sha256', $reason)];
    }
}
