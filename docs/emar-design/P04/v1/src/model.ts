/* P04 rules — one order per medicine with kept versions, the independent check,
 * the prescriber’s written confirmation, the allergy confirmation, covert
 * reviews and reconciliation. Decisions: Main’s answers under Stephan’s
 * delegation, 30 September (README Q1–Q8), P00 v5 (the phone-instruction
 * setting), P11 v5 Q7 (warn 14 days before an end date) and P03 (a new order is
 * Administer until support is set). Synthetic only. */
import { TODAY } from './clock';
import {
    COVERT,
    EVENTS,
    ORDERS,
    PERSONAS,
    RECONCILIATIONS,
    type Covert,
    type Order,
    type OrderEvent,
    type PersonaId,
    type RecDecision,
    type Reconciliation,
    type Version,
} from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'covertDue' | 'covertOverdue' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'covertDue', label: 'Covert review due in 11 days' },
    { key: 'covertOverdue', label: 'Covert review overdue' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No orders yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export interface Runtime {
    versions: Record<string, Version[]>;
    added: Order[];
    stopped: Record<string, { on: string; reason: string; by: string }>;
    covert: Record<string, Partial<Covert>>;
    newCovert: Covert[];
    recs: Record<string, Partial<Reconciliation> & { decisions?: Record<string, RecDecision> }>;
    events: OrderEvent[];
    /** The P01 phone instruction, countersigned or queried here (P08a’s dialog). */
    phoneDone: string | null;
}
export const EMPTY_RT: Runtime = { versions: {}, added: [], stopped: {}, covert: {}, newCovert: [], recs: {}, events: [], phoneDone: null };

/* ───────────── who can do what (keys unchanged: medications.orders.manage / .verify) ───────────── */
export const canEnter = (p: PersonaId) => PERSONAS[p].perms.includes('orders.manage');
export const canCheck = (p: PersonaId) => PERSONAS[p].perms.includes('orders.verify');
export const cdView = (p: PersonaId) => PERSONAS[p].perms.includes('cd.view');
export const concealed = (o: Order, p: PersonaId) => !!o.cd && !cdView(p);
/** The independent check (Q2): not the person who entered it, and not the read-back witness. */
export function whyCantCheck(v: Version, p: PersonaId): string | null {
    const me = PERSONAS[p].name;
    if (!canCheck(p)) return 'Orders are checked by house leads, coordinators, clinical leads and managers.';
    if (v.enteredBy === me) return 'You entered this version — someone else checks it.';
    if (v.source.readBack?.witness === me) return 'You witnessed the read-back — someone else checks it.';
    return null;
}

/* ───────────── an order, as it stands now ───────────── */
export const allOrders = (rt: Runtime) => [...ORDERS, ...rt.added];
export const versionsOf = (o: Order, rt: Runtime): Version[] => rt.versions[o.id] ?? o.versions;
/** The version staff give from: the latest checked (or checked-alone) one. */
export const currentOf = (o: Order, rt: Runtime): Version | null => [...versionsOf(o, rt)].reverse().find((v) => v.state === 'checked' || v.state === 'lone') ?? null;
/** A newer version still waiting (or sent back). */
export const pendingOf = (o: Order, rt: Runtime): Version | null => {
    const vs = versionsOf(o, rt);
    const last = vs[vs.length - 1];
    return last && (last.state === 'waiting' || last.state === 'sentBack') ? last : null;
};
export const stoppedOf = (o: Order, rt: Runtime) => rt.stopped[o.id] ?? (o.status === 'stopped' ? { ...o.stopped!, by: '' } : null);
const days = (from: string, to: string) => Math.round((Date.parse(to) - Date.parse(from)) / 86400000);

export type OrderState = 'stopped' | 'allergy' | 'waiting' | 'sentBack' | 'written' | 'lone' | 'ending' | 'active';
export const STATE_BADGE: Record<OrderState, { label: string; variant: 'critical' | 'warning' | 'info' | 'success' | 'neutral' }> = {
    allergy: { label: 'Prescriber must confirm', variant: 'critical' },
    waiting: { label: 'Waiting to be checked', variant: 'warning' },
    sentBack: { label: 'Sent back', variant: 'warning' },
    written: { label: 'Written confirmation due', variant: 'warning' },
    lone: { label: 'Second check due', variant: 'warning' },
    ending: { label: 'Ending soon', variant: 'info' },
    active: { label: 'Checked', variant: 'success' },
    stopped: { label: 'Stopped', variant: 'neutral' },
};
export interface OrderStatus {
    state: OrderState;
    lines: string[];
    givable: boolean;
}
export function statusOf(o: Order, rt: Runtime): OrderStatus {
    const cur = currentOf(o, rt);
    const pend = pendingOf(o, rt);
    const stop = stoppedOf(o, rt);
    const lines: string[] = [];
    if (stop) return { state: 'stopped', lines: [`Stopped ${stop.on} · ${stop.reason}`], givable: false };
    if (pend?.state === 'waiting' && pend.allergy && !pend.allergy.confirmed) {
        lines.push(`Allergy match: ${pend.allergy.match}. It can’t be checked until the prescriber confirms it’s safe.`);
        return { state: 'allergy', lines, givable: !!cur };
    }
    if (pend?.state === 'waiting') {
        lines.push(cur ? `Version ${pend.v} (${pend.changed ?? 'a change'}) is waiting to be checked — staff give version ${cur.v} until then` : `New ${pend.source.type === 'written' ? 'written' : pend.source.type} order entered ${pend.enteredAt} by ${pend.enteredBy} — it can’t be given until someone else checks it`);
        return { state: 'waiting', lines, givable: !!cur };
    }
    if (pend?.state === 'sentBack') {
        lines.push(`Version ${pend.v} sent back by ${pend.sentBack!.by}: “${pend.sentBack!.reason}” · staff give version ${cur?.v ?? '—'}`);
        return { state: 'sentBack', lines, givable: !!cur };
    }
    if (cur?.state === 'lone') {
        lines.push(`Checked alone by ${cur.checked!.by} — a second check is due by ${cur.checked!.lone!.secondDue}`);
    }
    if (cur?.written && !cur.written.done) lines.push(`${cur.source.type === 'phone' ? 'Phone' : 'Verbal'} order — the prescriber’s written confirmation is due by ${cur.written.due}`);
    if (cur?.state === 'lone') return { state: 'lone', lines, givable: true };
    if (cur?.written && !cur.written.done) return { state: 'written', lines, givable: true };
    if (o.endIso && days(TODAY, o.endIso) <= 14) {
        const d = days(TODAY, o.endIso);
        lines.push(`Ends ${o.end} (in ${d} day${d === 1 ? '' : 's'}) — ask the prescriber whether it continues`);
        return { state: 'ending', lines, givable: true };
    }
    return { state: 'active', lines, givable: true };
}

/* ───────────── the To check queue ───────────── */
export type CheckKind = 'check' | 'allergy' | 'written' | 'second' | 'sentBack';
export interface CheckItem {
    kind: CheckKind;
    order: Order;
    version: Version;
}
export function queueOf(orders: Order[], rt: Runtime): CheckItem[] {
    const out: CheckItem[] = [];
    for (const o of orders) {
        if (stoppedOf(o, rt)) continue;
        const cur = currentOf(o, rt);
        const pend = pendingOf(o, rt);
        if (pend?.state === 'waiting') out.push({ kind: pend.allergy && !pend.allergy.confirmed ? 'allergy' : 'check', order: o, version: pend });
        if (pend?.state === 'sentBack') out.push({ kind: 'sentBack', order: o, version: pend });
        if (cur?.state === 'lone') out.push({ kind: 'second', order: o, version: cur });
        if (cur?.written && !cur.written.done) out.push({ kind: 'written', order: o, version: cur });
    }
    return out;
}

/* ───────────── covert (Q6) ───────────── */
export function covertOf(orderId: string, rt: Runtime, scn: Scenario): Covert | null {
    const base = [...rt.newCovert, ...COVERT].find((c) => c.orderId === orderId && (rt.covert[c.id]?.status ?? c.status) === 'active');
    if (!base) return null;
    const c = { ...base, ...rt.covert[base.id] } as Covert;
    // The viewer’s scenarios move the fixture’s review date only, never one saved in the preview.
    if (!COVERT.some((x) => x.id === base.id)) return c;
    if (scn === 'covertDue') return { ...c, review: '9 October 2026', reviewIso: '2026-10-09' };
    if (scn === 'covertOverdue') return { ...c, review: '25 September 2026', reviewIso: '2026-09-25' };
    return c;
}
export type CovertState = 'overdue' | 'due' | 'current';
export function covertState(c: Covert): { state: CovertState; line: string } {
    const d = days(TODAY, c.reviewIso);
    if (d < 0) return { state: 'overdue', line: `Review was due ${c.review} — covert giving is blocked until it’s reviewed` };
    if (d <= 14) return { state: 'due', line: `Review due ${c.review} (in ${d} days) — the house lead has a follow-up` };
    return { state: 'current', line: `Review by ${c.review}` };
}

/* ───────────── reconciliation (Q5) ───────────── */
export function recOf(r: Reconciliation, rt: Runtime): Reconciliation & { decisions: Record<string, RecDecision | null> } {
    const x = rt.recs[r.id] ?? {};
    const decisions = Object.fromEntries(r.items.map((i) => [i.key, x.decisions?.[i.key] ?? i.decision])) as Record<string, RecDecision | null>;
    return { ...r, ...x, decisions } as Reconciliation & { decisions: Record<string, RecDecision | null> };
}
/** The fixtures plus reconciliations started in the preview. */
export const allRecs = (rt: Runtime): Reconciliation[] => [...RECONCILIATIONS, ...(Object.values(rt.recs).filter((x) => x.id && !RECONCILIATIONS.some((r) => r.id === x.id)) as Reconciliation[])];
export const DECISION_LABEL: Record<RecDecision, string> = { continue: 'Continue', change: 'Change', stop: 'Stop', new: 'Start (new order)', ask: 'Ask the GP first' };

export const eventsOf = (rt: Runtime) => [...rt.events, ...EVENTS];
