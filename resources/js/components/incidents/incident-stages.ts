/**
 * Where an incident is up to — the six stages the Incidents list (stage dots)
 * and the incident page (header stage line + next step) share, derived only
 * from fields the register and the incident page already carry. Approved H&S
 * drawings pg-hsn-1 / pg-hsn-2 (Stephan, 23 Oct 2026).
 *
 * Reported › Owner › Manager review › Safety review › Actions › Closed
 *
 * Known gaps (no field yet): when an owner became needed, the manager review
 * due time, and a per-row safety review status on the register.
 */
import { HS_LABELS } from '@/lib/health-safety/hs-labels';
import type { PageHeaderNextStep, PageHeaderStage } from '@/components/page/page-header-strip';
import { formatDate, formatDateTime, formatTime, toDateInput } from '@/lib/datetime';
import type { IncidentDetail } from './incident-detail-dialog';
import type { IncidentOwnership } from './incident-ownership-panel';

export const INCIDENT_STAGE_COUNT = 6;

export const INCIDENT_TYPE_LABELS: Record<string, string> = {
    injury: 'Injury',
    fall: 'Fall',
    behaviour: 'Behaviour',
    medication: 'Medication',
    safeguarding: 'Safeguarding',
    near_miss: 'Near miss',
    property_damage: 'Property damage',
    missing_person: 'Missing person',
    complaint: 'Complaint',
    other: 'Other',
};

export const SEVERITY_LABELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export const SEVERITY_VARIANT: Record<string, 'success' | 'warning' | 'critical'> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

export const INCIDENT_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    reviewed: 'Reviewed',
    closed: 'Closed',
};

export const SOURCE_LABELS: Record<string, string> = {
    manual: 'Staff',
    control_room: 'Control room',
    sensor: 'Sensor',
    automated: 'Automated',
};

/** B1.1: the safety review stage in the shared words (hs-labels.ts). */
export const HS_STATUS_LABELS: Record<string, string> = HS_LABELS.review_status;

export function sentenceCase(value: string): string {
    const text = value.replaceAll('_', ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function incidentTypeLabel(type: string | null | undefined): string {
    if (!type) return 'Incident';
    return INCIDENT_TYPE_LABELS[type] ?? sentenceCase(type);
}

export function plural(n: number, one: string, many = `${one}s`): string {
    return `${n} ${n === 1 ? one : many}`;
}

/** Whole NZ calendar days from `value` to `now` (never negative). */
export function daysSince(value: string | null | undefined, now: Date = new Date()): number | null {
    if (!value) return null;
    const from = toDateInput(value);
    const to = toDateInput(now);
    if (!from || !to) return null;
    const days = Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000);
    return Number.isFinite(days) ? Math.max(0, days) : null;
}

/** "Mon 19 Oct 2026, 7:40 pm" — a record timestamp with its year. */
export function dateTimeWithYear(value: string | null | undefined, fallback = '—'): string {
    if (!value) return fallback;
    const day = toDateInput(value);
    if (!day) return fallback;
    return `${formatDate(value)} ${day.slice(0, 4)}, ${formatTime(value)}`;
}

/** "Mon 19 Oct 2026" */
export function dateWithYear(value: string | null | undefined, fallback = '—'): string {
    if (!value) return fallback;
    const day = toDateInput(value);
    if (!day) return fallback;
    return `${formatDate(value)} ${day.slice(0, 4)}`;
}

/** "Mon 9:15 pm" — short stage meta. */
export function shortWhen(value: string | null | undefined): string | undefined {
    if (!value) return undefined;
    const weekday = formatDate(value).split(' ')[0];
    return `${weekday} ${formatTime(value)}`;
}

export function worksafePending(notifiable: boolean | null | undefined, status: string | null | undefined): boolean {
    return notifiable === true && !['notified', 'acknowledged'].includes(status ?? '');
}

export function ownerNeeded(ownership: IncidentOwnership | null | undefined): 'unassigned' | 'replacement_needed' | null {
    if (ownership?.state === 'unassigned' || ownership?.state === 'replacement_needed') return ownership.state;
    return null;
}

/* ------------------------------------------------------------------ */
/*  Register rows                                                      */
/* ------------------------------------------------------------------ */

export type IncidentRowStageInput = {
    status: string;
    ownership?: IncidentOwnership;
    investigation_status: string | null;
    open_followups_count: number;
    is_notifiable: boolean;
    worksafe_notification_status: string | null;
};

export type IncidentRowStage = {
    stage: number;
    label: string;
    note?: string;
    tone?: 'critical' | 'warning' | 'success';
};

/** The stage dots for one register row — from the fields every row carries. */
export function incidentRowStage(row: IncidentRowStageInput): IncidentRowStage {
    const worksafe = worksafePending(row.is_notifiable, row.worksafe_notification_status) ? 'WorkSafe to notify' : undefined;
    const join = (...parts: (string | undefined)[]) => parts.filter(Boolean).join(' · ') || undefined;
    if (row.status === 'draft') return { stage: 1, label: 'Draft', note: 'Not submitted yet' };
    if (row.status === 'closed') return { stage: INCIDENT_STAGE_COUNT, label: 'Closed', tone: 'success' };
    const needed = row.status === 'submitted' || row.status === 'reviewed' ? ownerNeeded(row.ownership) : null;
    if (needed) {
        return {
            stage: 2,
            label: 'Owner',
            tone: 'critical',
            note: join(needed === 'replacement_needed' ? 'Owner no longer eligible' : 'Waiting for an owner', worksafe),
        };
    }
    if (row.status === 'submitted') return { stage: 3, label: 'Manager review', tone: 'warning', note: join('Waiting for review', worksafe) };
    if (['pending', 'in_progress'].includes(row.investigation_status ?? '')) {
        return { stage: 4, label: 'Safety review', note: join('Investigating', worksafe) };
    }
    return {
        stage: 5,
        label: 'Actions',
        note: join(row.open_followups_count > 0 ? plural(row.open_followups_count, 'follow-up') : 'No open follow-ups', worksafe),
    };
}

/* ------------------------------------------------------------------ */
/*  The incident page                                                  */
/* ------------------------------------------------------------------ */

type StageKey = 'reported' | 'owner' | 'review' | 'safety' | 'actions' | 'closed';

export type IncidentStageLine = {
    steps: PageHeaderStage[];
    current: StageKey | null;
    openFollowups: number;
    openCorrectiveActions: number;
};

function requirement(d: IncidentDetail, key: string) {
    return d.close_gate.requirements.find((item) => item.key === key) ?? null;
}

/** The six stages for one incident, with who/when beneath each where the page carries it. */
export function incidentStageLine(d: IncidentDetail, now: Date = new Date()): IncidentStageLine {
    const openFollowups = d.followups.filter((item) => !item.completed_at).length;
    const openCorrectiveActions = d.hs_event
        ? d.hs_event.corrective_actions.filter((item) => !['verified', 'closed'].includes(item.status)).length
        : 0;
    const hsStatus = d.hs_event?.status ?? null;
    const hsRestricted = !d.hs_event && d.linked_access?.health_safety === 'restricted';
    const investigation = requirement(d, 'incident_investigation');
    const governance = requirement(d, 'health_safety_governance');
    const ownership = d.ownership;
    const needed = ownerNeeded(ownership);
    const closed = d.status === 'closed';

    const done: Record<StageKey, boolean> = {
        reported: d.status !== 'draft',
        owner: d.status !== 'draft' && (closed || !needed),
        review: ['reviewed', 'closed'].includes(d.status),
        safety:
            closed ||
            ((investigation ? investigation.complete : true) &&
                (d.hs_event
                    ? ['corrective_action', 'monitoring', 'closed'].includes(hsStatus ?? '')
                    : hsRestricted
                      ? (governance?.complete ?? false)
                      : true)),
        actions:
            closed ||
            (openFollowups === 0 &&
                (d.hs_event ? hsStatus === 'closed' : hsRestricted ? (governance?.complete ?? false) : true)),
        closed,
    };
    const order: StageKey[] = ['reported', 'owner', 'review', 'safety', 'actions', 'closed'];
    const current = order.find((key) => !done[key]) ?? null;
    const latestOwnerChange = ownership?.history?.[0];
    const ownerName = ownership?.owner?.name ?? null;
    const hsOwner = d.hs_event?.handover.owner?.name ?? null;
    const waited = daysSince(d.submitted_at ?? d.occurred_at, now);

    const meta: Record<StageKey, string | undefined> = {
        reported:
            d.status === 'draft'
                ? 'Not submitted yet'
                : [d.reporter?.name, shortWhen(d.submitted_at)].filter(Boolean).join(' · ') || undefined,
        owner: done.owner
            ? [ownerName ?? ownership?.last_owner_name, shortWhen(latestOwnerChange?.changed_at)].filter(Boolean).join(' · ') || undefined
            : needed === 'replacement_needed'
              ? 'Owner needs replacing'
              : needed && waited !== null
                ? `Waiting ${plural(waited, 'day')}`
                : undefined,
        review: done.review ? (d.reviewed_at ? `Reviewed ${shortWhen(d.reviewed_at)}` : undefined) : current === 'review' ? 'Waiting for review' : undefined,
        safety: d.hs_event
            ? [d.hs_event.reference_number, hsOwner].filter(Boolean).join(' · ')
            : hsRestricted
              ? 'Access restricted'
              : d.linked_access?.health_safety === 'absent'
                ? 'No safety review linked'
                : undefined,
        actions:
            openFollowups || openCorrectiveActions
                ? [openFollowups ? plural(openFollowups, 'follow-up') : null, openCorrectiveActions ? plural(openCorrectiveActions, 'corrective action') : null]
                      .filter(Boolean)
                      .join(' · ')
                : done.actions && done.review
                  ? 'Nothing open'
                  : undefined,
        closed: closed ? shortWhen(d.closed_at) : current === 'closed' && d.close_gate.allowed ? 'Ready to close' : undefined,
    };
    const labels: Record<StageKey, string> = {
        reported: d.status === 'draft' ? 'Draft' : 'Reported',
        owner: 'Owner',
        review: 'Manager review',
        safety: 'Safety review',
        actions: 'Actions',
        closed: 'Closed',
    };
    const tone = (key: StageKey): PageHeaderStage['tone'] =>
        key === 'owner' ? 'critical' : key === 'review' ? 'warning' : undefined;
    return {
        current,
        openFollowups,
        openCorrectiveActions,
        steps: order.map((key) => ({
            label: labels[key],
            meta: meta[key],
            state: done[key] ? 'done' : key === current ? 'now' : 'todo',
            ...(key === current ? { tone: tone(key) } : {}),
        })),
    };
}

/** "Who does what next" for the header — one line, with one quieter line under it. */
export function incidentNextStep(
    d: IncidentDetail,
    line: IncidentStageLine,
    { readOnly, responseActive, responseHref, now = new Date() }: { readOnly: boolean; responseActive: boolean; responseHref: string | null | undefined; now?: Date },
): PageHeaderNextStep {
    const upTo = "Where it's up to";
    const owner = d.ownership?.owner?.name ?? null;
    const hs = d.hs_event;
    const hsOwner = hs?.handover.owner?.name ?? null;
    if (d.journey_state === 'Journey repair required') {
        return {
            label: readOnly ? upTo : 'Next step',
            tone: 'critical',
            text: 'The linked incident journey needs repair',
            meta: 'Repair the linked incident journey before closing this incident.',
        };
    }
    if (responseActive && d.status !== 'closed') {
        return {
            label: readOnly ? upTo : 'Next step',
            tone: 'warning',
            text: 'The Control Room response is still active',
            meta: `The operational response is still active. Response and incident review are separate decisions.${responseHref ? '' : ' A permitted Control Room responder must continue the response.'}`,
        };
    }
    if (d.status === 'closed') {
        return {
            label: 'Closed',
            tone: 'success',
            text: `Closed ${d.closed_at ? formatDateTime(d.closed_at) : ''}${d.closed_outcome ? ` · ${d.closed_outcome}` : ''}`.trim(),
            meta:
                d.journey_state === 'Journey closed'
                    ? 'The connected journey is closed.'
                    : 'The incident report is closed. Linked response or safety work still has its own completion requirements.',
        };
    }
    if (d.status === 'draft') {
        return {
            label: readOnly ? upTo : 'Next step',
            text: readOnly ? 'The report is still a draft' : 'Finish the report and submit it for review',
            meta: 'Draft · not submitted yet',
        };
    }
    const needed = ownerNeeded(d.ownership);
    if (needed === 'unassigned') {
        const waited = daysSince(d.submitted_at ?? d.occurred_at, now);
        return {
            label: readOnly ? upTo : `Needs an owner${waited !== null ? ` · waiting ${plural(waited, 'day')}` : ''}`,
            tone: 'critical',
            text: readOnly ? 'Waiting for an owner' : 'Choose who follows this incident through',
            meta: d.reporter?.name ? (
                `Reported by ${d.reporter.name}${d.submitted_at ? `, ${dateTimeWithYear(d.submitted_at)}` : ''}`
            ) : undefined,
        };
    }
    if (needed === 'replacement_needed') {
        return {
            label: readOnly ? upTo : 'Owner needs replacing',
            tone: 'critical',
            text: readOnly ? 'Waiting for a new owner' : 'Choose a new owner',
            meta: `Previous owner: ${d.ownership?.owner?.name ?? d.ownership?.last_owner_name ?? 'Not recorded'}. Their current access or eligibility no longer supports this assignment.`,
        };
    }
    const who = owner ?? 'The incident owner';
    if (line.current === 'review') {
        return {
            label: readOnly ? upTo : 'Next step',
            tone: readOnly ? undefined : 'warning',
            text: readOnly ? `${who} is finishing the manager review` : `${who} finishes the manager review`,
            meta:
                !readOnly && hs
                    ? `then Health & safety accepts it and ${hsOwner ?? 'a safety reviewer'} runs safety review ${hs.reference_number}`
                    : undefined,
        };
    }
    if (line.current === 'safety') {
        if (!hs) {
            return {
                label: readOnly ? upTo : 'Next step',
                text: 'Health & safety finishes the linked safety work',
                meta: 'The linked H&S work must be completed by an authorised person before this incident can close.',
            };
        }
        const reviewer = hsOwner ?? 'Health & safety';
        return {
            label: readOnly ? upTo : 'Next step',
            text: readOnly ? `${reviewer} is running safety review ${hs.reference_number}` : `${reviewer} runs safety review ${hs.reference_number}`,
            meta: HS_STATUS_LABELS[hs.status] ?? sentenceCase(hs.status),
        };
    }
    if (line.current === 'actions') {
        const parts = [
            line.openFollowups ? plural(line.openFollowups, 'follow-up') : null,
            line.openCorrectiveActions ? plural(line.openCorrectiveActions, 'corrective action') : null,
        ].filter(Boolean);
        return {
            label: readOnly ? upTo : 'Next step',
            text: parts.length
                ? `${readOnly ? 'Finishing' : 'Finish'} ${parts.join(' and ')}`
                : hs
                  ? `Health & safety closes safety review ${hs.reference_number}`
                  : 'Finish the linked safety work',
            meta: readOnly ? undefined : 'Then the incident can close',
        };
    }
    return {
        label: readOnly ? upTo : 'Next step',
        tone: d.close_gate.allowed ? 'success' : undefined,
        text: d.close_gate.allowed
            ? readOnly
                ? 'Ready to close'
                : `${who} closes the incident`
            : 'Finish the checks before closing',
        meta: d.close_gate.allowed
            ? 'Everything needed to close is done'
            : plural(d.close_gate.requirements.filter((item) => !item.complete).length, 'check') + ' still to finish',
    };
}
