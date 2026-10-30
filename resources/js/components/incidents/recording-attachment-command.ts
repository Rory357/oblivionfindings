import type { RecordingDetail } from '@/pages/security-devices/cctv/_recording-contract';
import { cctvUuid } from '@/pages/security-devices/cctv/live-contract';
import type { RecordingReferenceEvidenceData } from './recording-reference-evidence';
import { validRecordingPreview, type RecordingChoice, type RecordingPreview } from './recording-window-command';

export type RecordingAttachmentCommand = {
    expected_actor_id: number;
    idempotency_key: string;
    expected_incident_evidence_version: number;
    evidence_window_revision: number;
    reference_uuid: string;
    purpose: string;
};
export type AttachmentBasis = {
    actor: number; incident: number; version: number; revision: number;
    start: string; end: string; sources: RecordingChoice[];
};
const positive = (n: unknown): n is number => typeof n === 'number' && Number.isSafeInteger(n) && n > 0;
const object = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : null;
const instant = (value: unknown, fractional = false): value is string => {
    if (typeof value !== 'string' || !(fractional
        ? /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/
        : /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/).test(value)) return false;
    const time = Date.parse(value);
    return Number.isFinite(time) && new Date(time).toISOString().slice(0, 19) === value.slice(0, 19);
};
export function attachmentBasis(preview: RecordingPreview | null | undefined, evidence: RecordingReferenceEvidenceData | undefined,
    actor: number, incident: number): AttachmentBasis | null {
    if (!validRecordingPreview(preview, actor, incident) || !preview!.permissions.can_attach ||
        !evidence || evidence.schema_version !== 1 || evidence.incident_id !== incident ||
        !positive(evidence.incident_evidence_version) || evidence.incident_evidence_version !== preview!.incident_evidence_version ||
        !evidence.window || evidence.window.context_state !== 'current' || !positive(evidence.window.revision) ||
        evidence.window.revision !== preview!.active_window_revision || !instant(evidence.window.start_at) ||
        !instant(evidence.window.end_at) || Date.parse(evidence.window.end_at) <= Date.parse(evidence.window.start_at)) return null;
    return { actor, incident, version: evidence.incident_evidence_version, revision: evidence.window.revision,
        start: evidence.window.start_at, end: evidence.window.end_at, sources: preview!.sources.items.filter((item) => item.in_active_window) };
}
export function referenceMatchesWindow(detail: RecordingDetail, basis: AttachmentBasis, source: RecordingChoice): boolean {
    // Existing references may belong to another creator. The decoder establishes the current viewer.
    return detail.actor_id === basis.actor && detail.camera.id === source.camera.id &&
        detail.recorder.id === source.recorder.id && detail.camera.site.id === source.site_id &&
        detail.reference.requested_start_at === basis.start && detail.reference.requested_end_at === basis.end;
}
export function confirmsRecordingAttachment(value: unknown, request: RecordingAttachmentCommand, basis: AttachmentBasis): boolean {
    const data = object(value), command = object(data?.command), attachment = object(data?.attachment), current = object(data?.current);
    const source = object(current?.current_source);
    return !!data && !!command && !!attachment && !!current && !!source &&
        data.schema_version === 1 && data.actor_id === request.expected_actor_id && data.incident_id === basis.incident &&
        command.idempotency_key === request.idempotency_key && command.kind === 'attach_recording_reference' &&
        command.confirmed_incident_evidence_version === request.expected_incident_evidence_version + 1 &&
        command.confirmed_evidence_window_revision === request.evidence_window_revision && instant(command.confirmed_at, true) &&
        cctvUuid(attachment.attachment_uuid) && attachment.kind === 'recording_reference' &&
        attachment.reference_uuid === request.reference_uuid && attachment.requested_start_at === basis.start &&
        attachment.requested_end_at === basis.end && attachment.state === 'reference_only' && attachment.preserved === false &&
        attachment.window_coverage === 'not_verified' && positive(current.incident_evidence_version) &&
        current.incident_evidence_version >= request.expected_incident_evidence_version + 1 &&
        (current.evidence_window_revision === null || positive(current.evidence_window_revision)) &&
        typeof source.state === 'string' && ['ready', 'changed', 'withdrawn', 'unavailable'].includes(source.state) &&
        (source.source_verified_at === null || instant(source.source_verified_at) || instant(source.source_verified_at, true));
}
