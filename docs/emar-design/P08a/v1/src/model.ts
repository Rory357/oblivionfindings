/* P08a rules — who sees each follow-up, its state, who can act on it, and how
 * reminders and carry-over work. Decisions: Stephan’s D12 (due by the end of
 * the next shift; visible to everyone rostered plus the house lead until
 * resolved) and Main’s answers under delegation, 30 Sep 2026 (Q1–Q6, README).
 * Synthetic only. */
import { NOW_MIN, labelOf } from './clock';
import { LEAD_TYPES, PERSONAS, STAFF, type FollowUp, type HouseKey, type PersonaId } from './data';
import type { FuState } from './ui';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'ackPending' | 'delivery' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string; group: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)', group: 'Follow-ups' },
    { key: 'ackPending', label: 'Night handover not acknowledged yet', group: 'Follow-ups' },
    { key: 'delivery', label: 'Reminders and escalation switched on', group: 'Follow-ups' },
    { key: 'loading', label: 'Loading', group: 'Page' },
    { key: 'empty', label: 'Nothing to follow up', group: 'Page' },
    { key: 'unavailable', label: 'Couldn’t load', group: 'Page' },
    { key: 'stale', label: 'Out of date', group: 'Page' },
    { key: 'offline', label: 'Offline — saved on this device', group: 'Page' },
];

/** The day shift ends at 3:00 pm: “check again” and “try again” times can’t go past it (Q2). */
export const SHIFT_END_MIN = 15 * 60;
export const SHIFT_END = '3:00 pm';

/* ───────────── P11 v5 Delivery (re-alert / escalate) — off until a manager switches it on ───────────── */
export const DELIVERY = { realertEvery: 30, realertMax: 3, escalateAfter: 60, escalateTo: 'the house lead', attended: 'acknowledged' };

export interface RuntimeFu {
    done?: { at: string; by: string; outcome: string; late?: string };
    couldnt?: { at: string; by: string; reason: string; again: string; againMin: number };
    owner?: string;
    reassigned?: { from: string; by: string; at: string; why: string };
    queued?: boolean;
    answer?: 'yes' | 'no';
}

/** The owner shown for a follow-up (Q1: carried-over items get their owner when the handover is acknowledged). */
export function ownerOf(f: FollowUp, rt: RuntimeFu | undefined, scn: Scenario, acked: boolean): string | null {
    if (rt?.owner) return rt.owner;
    if (f.carried && scn === 'ackPending' && !acked) return null;
    return f.owner;
}

export function stateOf(f: FollowUp, rt: RuntimeFu | undefined, persona: PersonaId): FuState {
    const done = rt?.done ?? f.done;
    if (rt?.queued) return 'queued';
    if (done) return done.late ? 'late' : 'done';
    if (f.type === 'confirm' && PERSONAS[persona].name !== f.owner && !rt?.answer) return 'waiting';
    const c = rt?.couldnt ?? (f.couldnt ? { ...f.couldnt, againMin: f.dueMin } : undefined);
    if (c) return c.againMin < NOW_MIN ? 'overdue' : 'couldnt';
    return f.dueMin < NOW_MIN ? 'overdue' : 'due';
}
export const isOpen = (s: FuState) => s === 'overdue' || s === 'due' || s === 'couldnt' || s === 'waiting' || s === 'queued';

/** How long ago something was due, in plain words. */
export function overdueBy(dueMin: number): string {
    const m = NOW_MIN - dueMin;
    const h = Math.floor(m / 60);
    return h ? `${h} h ${m % 60} min` : `${m} min`;
}
export const dueLabel = (f: FollowUp) => (f.dueMin > 1440 ? `By ${f.due}` : f.dueMin < 0 ? `Was due ${f.due}` : `${f.dueMin < NOW_MIN ? 'Was due' : 'Due'} ${f.due}`);

/* ───────────── who sees what ───────────── */
/** A follow-up is visible at the persona’s approved houses; controlled-medicine follow-ups need controlled-medicine view (EM-12). */
export function visible(f: FollowUp, persona: PersonaId): boolean {
    const p = PERSONAS[persona];
    if (!p.houses.includes(f.house)) return false;
    if (f.cd && !p.perms.includes('cd.view')) return false;
    return true;
}
export const isLead = (f: FollowUp) => LEAD_TYPES.includes(f.type);
const rostered = (persona: PersonaId, house: HouseKey) => !!PERSONAS[persona].shift && PERSONAS[persona].houses.includes(house);

/** What the persona can do with a follow-up (Main, 30 Sep, Q4). */
export function canAct(f: FollowUp, persona: PersonaId): { close: boolean; reassign: boolean; why: string | null } {
    const p = PERSONAS[persona];
    const lead = p.perms.includes('followups.manage');
    if (f.type === 'confirm') {
        const me = p.name === f.owner;
        return { close: me, reassign: false, why: me ? null : `Only ${f.owner} can answer — it asks whether they were there.` };
    }
    if (isLead(f)) return { close: lead, reassign: lead, why: lead ? null : 'For the house lead — house leads and clinical leads close it. It shows here so everyone on shift knows it’s being dealt with.' };
    const close = rostered(persona, f.house);
    return { close, reassign: close || lead, why: close ? null : 'Closed by the owner or anyone rostered at the house. You can reassign it to someone on shift.' };
}

/** People rostered on a covering shift now at the house (reassign picker, Q4). */
export const onShiftAt = (house: HouseKey) => STAFF.filter((s) => s.house === house && s.onShift);

/* ───────────── reminder and escalation lines (P11 v5 Delivery) ───────────── */
export function deliveryLine(f: FollowUp, state: FuState, scn: Scenario): string | null {
    if (scn !== 'delivery' || state !== 'overdue') return null;
    if (f.id === 'fu-ibu') return 'Reminded 3 times (every 30 minutes) · escalated to Jordan Tipene at 12:30 am · not acknowledged';
    if (f.id === 'fu-disputed') return 'Reminded 3 times · escalated to Hana Kereama (clinical lead) at 8:00 am · not acknowledged';
    return `Reminded every ${DELIVERY.realertEvery} minutes · escalates to ${DELIVERY.escalateTo} after ${DELIVERY.escalateAfter} minutes`;
}

/* ───────────── plain dose-schedule numbers for P01’s header (not modelled here) ───────────── */
export const SCHEDULE = { dueNow: 4, dueCaption: '3 people · by 10:00 am', late: 1, lateCaption: 'Oldest due 8:00 am', recorded: 9, of: 13 };

export const endOfShiftError = `Choose a time before ${SHIFT_END}, the end of your shift.`;
export const timeLabel = labelOf;
