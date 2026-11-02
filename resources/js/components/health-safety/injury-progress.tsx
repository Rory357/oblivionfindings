/**
 * The workplace injury and return-to-work record's round status steps and Progress card (boards G4-8 and G4-10,
 * approved by Stephan 1 Nov 2026; DESIGN.md "Records: status steps in the header, the Progress card under it").
 *
 * The header carries the injury's own five steps (Reported › Under treatment › Return to work › Recovered › Closed);
 * the safety review is a card in the body. The next step's date is the record's real "next review" field, said once in
 * the next-step bar, which holds the record's only filled button when the server lets this viewer do that step.
 *
 * Medical details stay private (hr.wellbeing.view). Decision 10: the coordinating team lead sees duty restrictions —
 * what the worker can do at work, never why — from `duty_restrictions`, which the server sends only to them.
 */
import type { PageHeaderStep } from '@/components/page/page-header-strip';
import type { RecordProgressActivity, RecordProgressItem, RecordProgressNext, RecordProgressPane } from '@/components/signature/record-progress';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { CheckCircle2, ClipboardCheck, Stethoscope, UserRoundCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import { injuryCommandAllowed, INJURY_COMMAND_LABELS } from './injury-command-state';
import { STATUS_ORDER, statusLabel } from './injury-constants';
import type { InjuryCommand, InjuryRecord } from './injury-workspace-types';

export type InjuryStepKey = (typeof STATUS_ORDER)[number];

const join = (...parts: (string | null | undefined | false)[]) => parts.filter(Boolean).join(' · ');
const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
/** "Tue 3 Nov" — a calendar date (YYYY-MM-DD read at UTC midnight: midday the same NZ day) or an instant's worker day. */
const day = (value: string | null | undefined) => (value ? formatDate(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00Z` : value, '') : '');
export const firstName = (name: string | null | undefined) => (name ? name.split(/\s+/)[0] : 'they');

/** One severity scale on both pages (G4-8): the injury's stored scale read as the safety review's words. */
export const INJURY_TO_REVIEW_SEVERITY: Record<string, string> = { minor: 'low', moderate: 'medium', serious: 'high', critical: 'critical' };

/** When the record first reached each step, from its readable history (none when private history is withheld). */
export function injuryStageDates(d: InjuryRecord): Partial<Record<InjuryStepKey, string>> {
    const dates: Partial<Record<InjuryStepKey, string>> = {};
    for (const entry of [...(d.history ?? [])].sort((a, b) => a.source_revision - b.source_revision)) {
        if (entry.access !== 'readable' || entry.after_access !== 'readable' || !entry.recorded_at) continue;
        const status = typeof entry.after?.status === 'string' ? entry.after.status : entry.source_revision === 1 ? 'reported' : null;
        if (status && STATUS_ORDER.includes(status as InjuryStepKey) && !dates[status as InjuryStepKey]) dates[status as InjuryStepKey] = entry.recorded_at;
    }
    if (!dates.reported) dates.reported = d.injury_date ?? d.created_at ?? undefined;
    // The coordinator without medical access: the first duty restriction is when the return to work began.
    const firstDuty = [...(d.duty_restrictions?.items ?? [])].map((item) => item.start_date).filter((value): value is string => !!value).sort()[0];
    if (!dates.return_to_work && firstDuty && STATUS_ORDER.indexOf(d.status as InjuryStepKey) >= 2) dates.return_to_work = firstDuty;
    return dates;
}

export function currentInjuryStep(d: InjuryRecord): InjuryStepKey {
    return STATUS_ORDER.includes(d.status as InjuryStepKey) ? (d.status as InjuryStepKey) : 'reported';
}

export function injuryRoundSteps(d: InjuryRecord): PageHeaderStep[] {
    const current = Math.max(0, STATUS_ORDER.indexOf(currentInjuryStep(d)));
    const closed = d.status === 'closed';
    const dates = injuryStageDates(d);
    return STATUS_ORDER.map((status, index) => {
        const at = dates[status];
        const meta =
            index === 0
                ? join(d.reported_by ?? null, day(at)) || undefined
                : at && index <= current
                  ? `${index === current && !closed ? 'Since ' : ''}${day(at)}`
                  : undefined;
        return {
            key: status,
            label: statusLabel(status),
            meta,
            state: closed || index < current ? 'done' : index === current ? 'now' : 'todo',
        };
    });
}

/* ------------------------------------------------------------------ */
/*  Next step bar                                                      */
/* ------------------------------------------------------------------ */

const NEXT: Record<string, { command: InjuryCommand; you: (worker: string) => string; them: (worker: string) => string; icon: typeof CheckCircle2 }> = {
    reported: { command: 'start_treatment', you: () => 'record that treatment has started', them: () => 'records that treatment has started', icon: Stethoscope },
    under_treatment: { command: 'begin_rtw', you: () => 'start recovery planning', them: () => 'starts recovery planning', icon: ClipboardCheck },
    return_to_work: {
        command: 'mark_recovered',
        you: (worker) => `record the recovery when ${worker} is back on full duties`,
        them: (worker) => `records the recovery when ${worker} is back on full duties`,
        icon: CheckCircle2,
    },
    recovered: { command: 'close', you: () => 'close the injury record', them: () => 'closes the injury record', icon: CheckCircle2 },
};

export type InjuryNextBar = RecordProgressNext & { owns: InjuryCommand | null };

export function injuryNextBar(d: InjuryRecord, viewerId: number | null | undefined, open: (command: InjuryCommand) => void): InjuryNextBar {
    const button = (command: InjuryCommand, label: string, icon: ReactNode) => (
        <Button type="button" onClick={() => open(command)}>
            {icon}
            {label}
        </Button>
    );
    if (d.status === 'closed') {
        return { owns: null, kicker: 'Closed', tone: 'done', title: 'The injury record is closed', text: d.safety_review ? 'Its safety review closes separately.' : undefined };
    }
    const coordinator = d.coordination?.coordinator ?? null;
    const review = d.coordination?.next_review_date ? `next review ${day(d.coordination.next_review_date)}` : undefined;
    if (!coordinator) {
        const can = injuryCommandAllowed(d, 'assign');
        return {
            owns: can ? 'assign' : null,
            title: 'Choose who coordinates this injury',
            text: can ? 'The coordinator follows the recovery through and records each step.' : 'A health and safety manager at the site chooses the coordinator.',
            action: can ? button('assign', 'Choose coordinator', <UserRoundCheck className="size-4" aria-hidden="true" />) : undefined,
        };
    }
    const step = NEXT[d.status];
    if (!step) return { owns: null, title: `${coordinator.name} coordinates this injury`, text: review };
    const you = viewerId != null && coordinator.id === viewerId;
    const worker = firstName(d.worker?.name);
    const can = injuryCommandAllowed(d, step.command);
    const Icon = step.icon;
    return {
        owns: can ? step.command : null,
        title: you ? `You ${step.you(worker)}` : `${coordinator.name} ${step.them(worker)}`,
        text: review,
        action: can ? button(step.command, INJURY_COMMAND_LABELS[step.command], <Icon className="size-4" aria-hidden="true" />) : undefined,
    };
}

/* ------------------------------------------------------------------ */
/*  The picked step's pane                                             */
/* ------------------------------------------------------------------ */

type Dated = RecordProgressActivity & { at: string };

const HISTORY_WORDS: Record<string, string> = {
    create: 'reported the injury',
    update: 'updated the injury details',
    assign: 'chose the coordinator',
    review: 'recorded a recovery review',
    record_capacity: 'recorded a fitness-for-work check',
    create_rtw_plan: 'added a recovery plan',
    update_rtw_plan: 'updated the recovery plan',
    add_modified_duty: 'set modified duties',
    upload_evidence: 'added a file',
    remove_evidence: 'removed a file',
};
/** Which step a history entry belongs to. */
function historyStep(command: string, status: unknown): InjuryStepKey | null {
    if (command === 'create') return 'reported';
    if (command === 'transition' && typeof status === 'string' && STATUS_ORDER.includes(status as InjuryStepKey)) return status as InjuryStepKey;
    if (['create_rtw_plan', 'update_rtw_plan', 'add_modified_duty', 'review'].includes(command)) return 'return_to_work';
    if (command === 'record_capacity') return 'under_treatment';
    return null;
}

function activityFor(d: InjuryRecord, step: InjuryStepKey, viewerId: number | null | undefined): Dated[] {
    const coordinator = d.coordination?.coordinator ?? null;
    const viewerNamed = (name: string | null | undefined) => (name && coordinator && coordinator.id === viewerId && coordinator.name === name ? 'You' : name);
    const items: Dated[] = [];
    for (const entry of d.history ?? []) {
        if (entry.access !== 'readable' || !entry.recorded_at) continue;
        const status = entry.after_access === 'readable' ? entry.after?.status : undefined;
        if (historyStep(entry.command, status) !== step) continue;
        items.push({
            key: `v-${entry.source_revision}`,
            at: entry.recorded_at,
            time: formatDateTime(entry.recorded_at),
            who: viewerNamed(entry.recorder?.name) ?? undefined,
            what:
                entry.command === 'transition' && typeof status === 'string'
                    ? `moved it to ${statusLabel(status).toLowerCase()}`
                    : (HISTORY_WORDS[entry.command] ?? 'changed the record'),
        });
    }
    if (step === 'reported' && !items.length && d.injury_date) {
        items.push({ key: 'reported', at: d.created_at ?? d.injury_date, time: formatDateTime(d.created_at ?? d.injury_date), who: d.reported_by ?? undefined, what: 'reported the injury' });
    }
    if (step === 'return_to_work') {
        for (const duty of d.duty_restrictions?.items ?? []) {
            if (!duty.set_at) continue;
            items.push({
                key: `duty-${duty.id}`,
                at: duty.set_at,
                time: formatDateTime(duty.set_at),
                who: duty.set_by ?? undefined,
                what: `set the duty restrictions${duty.end_date ? ` until ${day(duty.end_date)}` : ''}`,
            });
        }
    }
    return items;
}

function log(items: Dated[], onAll?: () => void): RecordProgressPane['activity'] {
    return {
        items: items.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime()).map(({ at: _at, ...item }) => item),
        state: 'ready',
        onAll,
    };
}

/** The duty restrictions in force: the coordinator's summary, or (with medical access) the latest plan's modified duties. */
export function currentDuties(d: InjuryRecord): { key: string; restrictions: string | null; duties: string | null; until: string | null; by: string | null }[] {
    if (d.duty_restrictions) {
        return d.duty_restrictions.items.map((item) => ({ key: `r-${item.id}`, restrictions: item.restrictions, duties: item.duties, until: item.end_date, by: item.set_by }));
    }
    const plan = d.rtw_plans?.[0];
    return (plan?.modified_duties ?? []).map((duty) => ({
        key: `m-${duty.id}`,
        restrictions: duty.restrictions,
        duties: duty.modified_duties_description,
        until: duty.end_date,
        by: duty.recorder?.name ?? null,
    }));
}

export type InjuryPaneHandlers = { onBack?: () => void; onHistory?: () => void };

export function injuryStepPane(d: InjuryRecord, step: InjuryStepKey, viewerId: number | null | undefined, h: InjuryPaneHandlers): RecordProgressPane {
    const base = { onBack: h.onBack };
    const medical = d.medical_access === 'readable';
    const worker = firstName(d.worker?.name);
    const coordinator = d.coordination?.coordinator ?? null;
    const youCoordinate = viewerId != null && coordinator?.id === viewerId;
    const dates = injuryStageDates(d);
    const timeOff = d.lost_time_days ? plural(d.lost_time_days, 'day') : 'None';
    const activity = log(activityFor(d, step, viewerId), h.onHistory);
    const empty = 'Nothing was added at this step yet.';

    if (step === 'reported') {
        return {
            ...base,
            title: 'Reported',
            meta: join(d.reported_by ? `By ${d.reported_by}` : null, dates.reported ? formatDateTime(dates.reported) : null) || undefined,
            items: [
                { key: 'injury', label: 'Injury', value: d.injury_words ?? d.injury_type_label },
                { key: 'what', label: 'What happened', value: d.description || 'Not recorded' },
                { key: 'where', label: 'Where', value: d.site?.name ?? 'Site not recorded' },
                { key: 'off', label: 'Time off work', value: timeOff },
            ],
            activity,
        };
    }

    if (step === 'under_treatment') {
        const items: RecordProgressItem[] = medical
            ? [
                  { key: 'treatment', label: 'Treatment', value: d.medical_treatment_label ?? 'Treatment level not recorded' },
                  { key: 'immediate', label: 'Straight away', value: d.immediate_treatment || 'Not recorded' },
                  { key: 'acc', label: 'ACC claim', value: d.acc_claim_number ? `Lodged · ${d.acc_claim_number}` : d.claim_delivery?.internally_recorded ? 'Lodged' : 'Not lodged' },
              ]
            : [{ key: 'acc', label: 'ACC claim lodged', value: d.claim_delivery?.internally_recorded ? 'Yes' : 'No' }];
        return {
            ...base,
            title: 'Under treatment',
            meta: dates.under_treatment ? `Since ${day(dates.under_treatment)}` : undefined,
            items,
            empty,
            activity,
            note: medical ? undefined : `Medical details stay private: you see whether an ACC claim was lodged, never the claim or the treatment.`,
        };
    }

    if (step === 'return_to_work') {
        const duties = currentDuties(d);
        const items: RecordProgressItem[] = [
            ...duties.map((duty) => ({
                key: duty.key,
                label: 'Duty restrictions',
                value: [duty.restrictions, duty.duties].filter(Boolean).join('; ') || 'Not recorded',
                meta: join(duty.until ? `Until ${day(duty.until)}` : null, duty.by ? `set by ${duty.by}` : null, d.duty_restrictions ? 'the reason stays private' : null) || undefined,
            })),
            {
                key: 'review',
                label: 'Next review',
                value: d.coordination?.next_review_date ? day(d.coordination.next_review_date) : 'Not set',
                meta: coordinator ? (youCoordinate ? 'You, as coordinator' : `${coordinator.name}, as coordinator`) : undefined,
            },
            { key: 'off', label: 'Time off work', value: timeOff },
        ];
        return {
            ...base,
            title: 'Return to work',
            meta: join(dates.return_to_work ? `Since ${day(dates.return_to_work)}` : null, duties.length ? 'on modified duties' : null) || undefined,
            items,
            activity,
            note: medical ? undefined : d.duty_restrictions ? `Medical details stay private: you see what ${worker} can do at work, never why.` : 'Medical details stay private.',
        };
    }

    if (step === 'recovered') {
        return {
            ...base,
            title: 'Recovered',
            meta: dates.recovered ? day(dates.recovered) : 'Not yet',
            items: d.actual_return_date || dates.recovered
                ? [
                      { key: 'back', label: 'Back on full duties', value: d.actual_return_date ? day(d.actual_return_date) : 'Not recorded' },
                      { key: 'expected', label: 'Expected return', value: d.expected_return_date ? day(d.expected_return_date) : 'Not recorded' },
                  ]
                : [],
            empty: `${worker.charAt(0).toUpperCase()}${worker.slice(1)} isn’t recorded as recovered yet.`,
            activity,
        };
    }

    return {
        ...base,
        title: 'Closed',
        meta: d.status === 'closed' ? (dates.closed ? day(dates.closed) : 'Closed') : 'Not closed yet',
        items: [],
        empty: d.status === 'closed' ? 'The injury record is closed.' : `It closes after ${worker} has recovered.`,
        activity,
    };
}
