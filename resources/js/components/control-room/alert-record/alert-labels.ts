import type { WorkspaceAlert } from '@/components/control-room/alert-record/alert-sections';
import {
    ALERT_SEVERITY_VOCAB,
    ALERT_SLA_VOCAB,
    ALERT_STATUS_VOCAB,
    vocabularyFor,
    type StatusTone,
} from '@/lib/control-room-vocab';

/** Alert states in which the live response is still open. */
export const ACTIVE_ALERT_STATES = ['open', 'ack', 'triaging'] as const;

export function isActiveAlert(status: string): boolean {
    return (ACTIVE_ALERT_STATES as readonly string[]).includes(status);
}

/** "fall_detected" → "Fall detected" (sentence case, never Title Case). */
export function plainLabel(value: string | null | undefined): string {
    if (!value) return '';
    const words = value.replace(/[._-]+/g, ' ').trim().toLowerCase();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function alertReference(alert: {
    id: number;
    reference_number: string | null;
}): string {
    return alert.reference_number ?? `Alert ${alert.id}`;
}

/**
 * The alert's own words, in the same order the list uses
 * (AlertWorklistPresenter::summary): its title, summary or description,
 * then the signal's, then its notes, else its type — so a row and its
 * preview never word the same alert differently.
 */
export function alertSummary(alert: WorkspaceAlert): string {
    const ctx = (alert.context ?? {}) as Record<string, unknown>;
    const norm = (ctx.normalized_data ?? {}) as Record<string, unknown>;
    const pick = (value: unknown) =>
        typeof value === 'string' && value.trim() ? value.trim() : null;
    return (
        pick(ctx.title) ??
        pick(ctx.summary) ??
        pick(ctx.description) ??
        pick(norm.title) ??
        pick(norm.description) ??
        pick(alert.notes) ??
        plainLabel(alert.alert_type)
    );
}

export type AlertBadge = { label: string; tone: StatusTone };

export function statusBadge(status: string | null | undefined): AlertBadge {
    const v = vocabularyFor(ALERT_STATUS_VOCAB, status);
    return { label: v.label, tone: v.tone };
}

export function severityBadge(
    severity: string | null | undefined,
): AlertBadge {
    const v = vocabularyFor(ALERT_SEVERITY_VOCAB, severity);
    return { label: v.label, tone: v.tone };
}

export function targetBadge(sla: string | null | undefined): AlertBadge {
    const v = vocabularyFor(ALERT_SLA_VOCAB, sla);
    return { label: v.label, tone: v.tone };
}

export const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

export const INCIDENT_TYPE_OPTIONS = [
    { value: 'fall', label: 'Fall' },
    { value: 'injury', label: 'Injury' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'property_damage', label: 'Property damage' },
    { value: 'other', label: 'Other' },
];

export function incidentTypeFromAlert(alertType: string): string {
    const inferred = alertType.startsWith('incident.')
        ? alertType.slice('incident.'.length)
        : alertType === 'fall_detected'
          ? 'fall'
          : alertType;
    return INCIDENT_TYPE_OPTIONS.some((option) => option.value === inferred)
        ? inferred
        : 'other';
}
