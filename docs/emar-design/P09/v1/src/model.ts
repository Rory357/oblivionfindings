/* P09 rules — Reports & audit. Every number comes from one dose-slot
 * projection (Q2; built with P01, drawn here over synthetic slots), counted by
 * NZ calendar day (Q4), with “Not applicable” for a zero denominator. The
 * audit trail is one append-only, hash-chained event log per house (Q7).
 * Decisions: Main’s answers under Stephan’s delegation, 1 October (README
 * Q1–Q11 and the refinements). */
import {
    CD_EVENTS,
    ERRORS,
    EXPORTS,
    HOUSES,
    ORDERS,
    PEOPLE,
    PEOPLE_ORDER,
    PERSONAS,
    PRN_DOSES,
    REVIEWS,
    SAC_DEFAULT,
    STAFF,
    STOCK,
    TYPE_LABEL,
    fnv,
    has,
    orderOf,
    slotOutcome,
    type ExportMade,
    type House,
    type MedError,
    type Outcome,
    type PersonId,
    type PersonaId,
    type Sac,
} from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline' | 'logdown';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No records yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
    { key: 'logdown', label: 'Event log can’t be written' },
];

export type Tone = 'critical' | 'warning' | 'info' | 'success' | 'neutral';

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export type Retention = 'y10' | 'y15' | 'y20';
export interface OrgRecords {
    retention: Retention;
    retentionReviewed: boolean;
    sac: boolean;
    sacReviewed: boolean;
    map: { death: Sac; moderate: Sac; minor: Sac; none: Sac };
    by?: string;
    at?: string;
}
export interface Runtime {
    exports: ExportMade[];
    errPatch: Record<string, Partial<MedError>>;
    incClosed: Record<string, { by: string; at: string; outcome: string }>;
    org: OrgRecords;
    orgDraft: Partial<OrgRecords> | null;
    history: { id: string; what: string; from: string; to: string; who: string; when: string; scope: string; area: string; fresh?: boolean }[];
    flash: string | null;
}
export const EMPTY_RT: Runtime = {
    exports: [],
    errPatch: {},
    incClosed: {},
    org: { retention: 'y10', retentionReviewed: false, sac: false, sacReviewed: false, map: { ...SAC_DEFAULT } },
    orgDraft: null,
    history: [],
    flash: null,
};

/* ───────────── who can do what (Main, Q5) ───────────── */
export const cdView = (p: PersonaId) => has(p, 'cd.view');
export const canReports = (p: PersonaId) => has(p, 'reports.view');
export const stockOnly = (p: PersonaId) => has(p, 'stock.only');
export const canAudit = (p: PersonaId) => has(p, 'audit.view');
export const canSetOrg = (p: PersonaId) => has(p, 'settings.org');
export const canCloseIncident = (p: PersonaId) => has(p, 'incidents.approve');
export const housesOf = (p: PersonaId) => PERSONAS[p].houses;
export const peopleOf = (p: PersonaId) => PEOPLE_ORDER.filter((x) => housesOf(p).includes(PEOPLE[x].house));
export const WHO_EXPORTS = 'coordinators and provider managers';
export const WHO_REPORTS = 'house leads, clinical leads, coordinators, managers and auditors';
export const WHO_AUDITS = 'clinical leads, coordinators, managers and auditors';

/* ───────────── dates (NZ calendar — Q4) ───────────── */
export const TODAY_ISO = '2026-09-28';
export const NOW_HM = '09:12';
const DAY = 86400000;
const ms = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
export const addDays = (s: string, n: number) => new Date(ms(s) + n * DAY).toISOString().slice(0, 10);
export const daysUntil = (d: string) => Math.round((ms(d) - ms(TODAY_ISO)) / DAY);
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MO = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function labelIso(s: string) {
    const d = new Date(ms(s));
    return `${WD[d.getUTCDay()]} ${d.getUTCDate()} ${MO[d.getUTCMonth()]}`;
}
export const time12 = (hm: string) => {
    const h = Number(hm.slice(0, 2));
    return `${h % 12 || 12}:${hm.slice(3, 5)} ${h < 12 ? 'am' : 'pm'}`;
};
const addMin = (hm: string, n: number) => {
    const t = Number(hm.slice(0, 2)) * 60 + Number(hm.slice(3, 5)) + n;
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
};
export function daysIn(from: string, to: string) {
    const out: string[] = [];
    for (let d = from; d <= to; d = addDays(d, 1)) out.push(d);
    return out;
}
export const lowerFirst = (t: string) => t.charAt(0).toLowerCase() + t.slice(1);
export const pct = (n: number, d: number) => (d > 0 ? `${Math.round((n / d) * 1000) / 10} %` : null);

/* ───────────── periods (Q4) ───────────── */
export type PeriodKey = 'today' | '7d' | 'month' | 'last' | 'custom';
export interface Period {
    key: PeriodKey;
    label: string;
    from: string;
    to: string;
    text: string;
}
export const PERIOD_OPTIONS: { value: PeriodKey; label: string }[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: 'month', label: 'This month' },
    { value: 'last', label: 'Last month' },
    { value: 'custom', label: 'Custom…' },
];
export const RANGE_START = '2026-07-01';
export function periodOf(key: string | null, from?: string | null, to?: string | null): Period {
    if (key === 'today') return { key, label: 'Today', from: TODAY_ISO, to: TODAY_ISO, text: 'Mon 28 Sep, so far' };
    if (key === '7d') return { key, label: 'Last 7 days', from: addDays(TODAY_ISO, -6), to: TODAY_ISO, text: 'Tue 22 – Mon 28 Sep' };
    if (key === 'last') return { key, label: 'Last month', from: '2026-08-01', to: '2026-08-31', text: 'August 2026' };
    if (key === 'custom' && from && to && /^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to) && from <= to)
        return { key: 'custom', label: 'Custom', from, to, text: from === to ? labelIso(from) : `${labelIso(from)} – ${labelIso(to)}` };
    return { key: 'month', label: 'This month', from: '2026-09-01', to: TODAY_ISO, text: '1–28 Sep, so far' };
}

/* ───────────── the dose-slot projection (Q2) ───────────── */
export interface Slot {
    orderId: string;
    pid: PersonId;
    day: string;
    time: string;
    outcome: Outcome;
    late: boolean;
    by: string;
    cd: boolean;
    /** When it was recorded (NZ, 24-hour), where the shared records give it. */
    at?: string;
    unwitnessed?: boolean;
    note?: string;
}
const SLOT_CACHE = new Map<string, { counted: Slot[]; dueNow: number }>();
/** Scheduled slots in the period whose window has ended (counted), and the ones still inside their window today (not counted yet). */
export function slotsIn(from: string, to: string, pids: PersonId[]) {
    const key = `${from}|${to}|${pids.join(',')}`;
    const hit = SLOT_CACHE.get(key);
    if (hit) return hit;
    const counted: Slot[] = [];
    let dueNow = 0;
    for (const o of ORDERS) {
        if (o.prn || !pids.includes(o.pid)) continue;
        for (const day of daysIn(from < RANGE_START ? RANGE_START : from, to > TODAY_ISO ? TODAY_ISO : to)) {
            if ((o.from && day < o.from) || (o.to && day > o.to)) continue;
            for (const time of o.times) {
                if (day === TODAY_ISO && time > NOW_HM) continue;
                if (day === TODAY_ISO && addMin(time, 60) > NOW_HM) {
                    dueNow++;
                    continue;
                }
                const r = slotOutcome(o.id, day, time);
                counted.push({ orderId: o.id, pid: o.pid, day, time, outcome: r.outcome, late: r.late, by: r.by, cd: !!o.cd, at: r.at, unwitnessed: r.unwitnessed, note: r.note });
            }
        }
    }
    const out = { counted, dueNow };
    SLOT_CACHE.set(key, out);
    return out;
}
export interface DoseTotals {
    due: number;
    given: number;
    refused: number;
    withheld: number;
    missed: number;
    notRecorded: number;
    late: number;
}
export function totals(slots: Slot[]): DoseTotals {
    const t: DoseTotals = { due: slots.length, given: 0, refused: 0, withheld: 0, missed: 0, notRecorded: 0, late: 0 };
    for (const s of slots) {
        t[s.outcome]++;
        if (s.late) t.late++;
    }
    return t;
}

/* ───────────── rounds, derived from their slots (Q3, NF-15) ───────────── */
export type RoundName = 'Morning' | 'Midday' | 'Afternoon' | 'Evening';
export const ROUND_END: Record<RoundName, string> = { Morning: '10:00', Midday: '13:00', Afternoon: '15:00', Evening: '21:30' };
export const roundOf = (time: string): RoundName => (time < '11:00' ? 'Morning' : time < '13:30' ? 'Midday' : time < '17:00' ? 'Afternoon' : 'Evening');
export type RoundResult = 'onTime' | 'late' | 'notCompleted' | 'notStarted';
export const ROUND_LABEL: Record<RoundResult, string> = { onTime: 'On time', late: 'Late', notCompleted: 'Not completed', notStarted: 'Not started' };
export interface RoundRow {
    house: House;
    day: string;
    name: RoundName;
    result: RoundResult;
    slots: number;
}
export function roundsIn(from: string, to: string, pids: PersonId[]) {
    const { counted } = slotsIn(from, to, pids);
    const groups = new Map<string, Slot[]>();
    for (const s of counted) {
        const k = `${PEOPLE[s.pid].house}|${s.day}|${roundOf(s.time)}`;
        groups.set(k, [...(groups.get(k) ?? []), s]);
    }
    const rows: RoundRow[] = [];
    let notEnded = 0;
    for (const [k, list] of groups) {
        const [house, day, name] = k.split('|') as [House, string, RoundName];
        if (day === TODAY_ISO && ROUND_END[name] > NOW_HM) {
            notEnded++;
            continue;
        }
        const unrec = list.filter((s) => s.outcome === 'notRecorded').length;
        const result: RoundResult = unrec === list.length ? 'notStarted' : unrec ? 'notCompleted' : list.some((s) => s.late) ? 'late' : 'onTime';
        rows.push({ house, day, name, result, slots: list.length });
    }
    // Today’s rounds whose window hasn’t ended have no counted slots yet; they aren’t counted either.
    return { rows, notEnded };
}

/* ───────────── as-needed, controlled, errors, reviews, stock, staff ───────────── */
export const inPeriod = (day: string, p: Period) => day >= p.from && day <= p.to;
export const prnIn = (p: Period, pids: PersonId[]) => PRN_DOSES.filter((d) => inPeriod(d.day, p) && pids.includes(orderOf(d.orderId).pid));
export function cdIn(p: Period, pids: PersonId[]) {
    const { counted } = slotsIn(p.from, p.to, pids);
    const given = counted.filter((s) => s.cd && s.outcome === 'given');
    const prn = prnIn(p, pids).filter((d) => orderOf(d.orderId).cd);
    const houses = [...new Set(pids.filter((x) => ORDERS.some((o) => o.cd && o.pid === x)).map((x) => PEOPLE[x].house))];
    const days = daysIn(p.from, p.to > TODAY_ISO ? TODAY_ISO : p.to);
    const counts = houses.reduce((n, h) => n + days.reduce((m, d) => m + CD_EVENTS.counts[h].times.filter((t) => d < TODAY_ISO || t <= NOW_HM).length, 0), 0);
    const unwitnessed = given.filter((s) => s.unwitnessed).length;
    const inScope = (orderId: string) => pids.includes(orderOf(orderId).pid);
    return {
        given: given.length + prn.length,
        witnessed: given.length + prn.length - unwitnessed,
        counts,
        discrepancies: CD_EVENTS.discrepancies.filter((d) => inPeriod(d.day, p) && inScope(d.orderId)),
        losses: CD_EVENTS.losses.filter((d) => inPeriod(d.day, p) && inScope(d.orderId)),
        destructions: CD_EVENTS.destructions.filter((d) => inPeriod(d.day, p) && inScope(d.orderId)),
        byOrder: ORDERS.filter((o) => o.cd && pids.includes(o.pid)).map((o) => {
            const n = given.filter((s) => s.orderId === o.id).length + prn.filter((d) => d.orderId === o.id).length;
            return { order: o, given: n, witnessed: n - given.filter((s) => s.orderId === o.id && s.unwitnessed).length };
        }),
    };
}
export function allErrors(rt: Runtime): MedError[] {
    return ERRORS.map((e) => (rt.errPatch[e.id] ? ({ ...e, ...rt.errPatch[e.id] } as MedError) : e));
}
export const errorsIn = (rt: Runtime, p: Period, pids: PersonId[]) => allErrors(rt).filter((e) => inPeriod(e.occurredIso, p) && pids.includes(e.pid));
export const isControlledErr = (e: MedError) => e.orderIds.some((o) => orderOf(o).cd);
export const redactedErr = (p: PersonaId, e: MedError) => isControlledErr(e) && !cdView(p);
export const reviewsIn = (pids: PersonId[]) => REVIEWS.filter((r) => pids.includes(r.pid));
export const stockLines = (p: PersonaId) => STOCK.filter((l) => housesOf(p).includes(PEOPLE[orderOf(l.orderId).pid].house) && (!orderOf(l.orderId).cd || cdView(p)));
export const daysLeft = (l: (typeof STOCK)[number]) => (l.perDay > 0 ? Math.floor(l.onHand / l.perDay) : null);
export const staffIn = (p: PersonaId) => STAFF.filter((s) => housesOf(p).includes(s.house));

/* ───────────── SAC (Q11) ───────────── */
/** The closer’s preselection from the organisation’s mapping; severe or permanent has none. Near misses get no SAC. */
export function sacPreselect(org: OrgRecords, e: MedError): Sac | null | 'none' {
    if (e.reached === 'no') return 'none';
    if (e.harm === 'severe') return null;
    if (e.harm === 'death') return org.map.death;
    if (e.harm === 'moderate') return org.map.moderate;
    if (e.harm === 'minor') return org.map.minor;
    return org.map.none;
}

/* ───────────── the medication event log (Q7) ───────────── */
export type EventKind = 'dose' | 'controlled' | 'order' | 'error' | 'access' | 'settings' | 'export';
export const KIND_LABEL: Record<EventKind, string> = { dose: 'Doses', controlled: 'Controlled medicines', order: 'Orders', error: 'Medication errors', access: 'Emergency access', settings: 'Settings', export: 'Exports' };
export interface LogEvent {
    id: string;
    seq: number;
    house: House;
    day: string;
    hm: string;
    kind: EventKind;
    what: string;
    detail: string;
    by: string;
    pid?: PersonId;
    orderId?: string;
    cd: boolean;
    hash: string;
    prev: string;
}
const hex = (n: number) => n.toString(16).padStart(8, '0');
let LOG: LogEvent[] | null = null;
/** Every event from 1 July, in order, each linked to the one before it at its house. */
export function eventLog(): LogEvent[] {
    if (LOG) return LOG;
    const raw: Omit<LogEvent, 'seq' | 'hash' | 'prev'>[] = [];
    const { counted } = slotsIn(RANGE_START, TODAY_ISO, PEOPLE_ORDER);
    for (const s of counted) {
        if (s.outcome === 'notRecorded') continue;
        const o = orderOf(s.orderId);
        const at = s.at ?? (s.late ? addMin(s.time, 65) : addMin(s.time, (fnv(s.orderId + s.day) % 25) + 1));
        raw.push({ id: `E-${s.orderId}-${s.day}-${s.time}`, house: PEOPLE[s.pid].house, day: s.day, hm: s.day === TODAY_ISO && at > NOW_HM ? '09:10' : at, kind: s.cd ? 'controlled' : 'dose', what: `${s.outcome === 'given' ? 'Dose given' : s.outcome === 'refused' ? 'Dose refused' : s.outcome === 'withheld' ? 'Dose withheld' : 'Dose missed'} — ${o.med}`, detail: `${time12(s.time)} dose${s.late ? ', recorded late' : ''}${s.note ? ` — ${s.note}` : ''}`, by: s.by, pid: s.pid, orderId: s.orderId, cd: s.cd });
    }
    for (const d of PRN_DOSES) {
        const o = orderOf(d.orderId);
        const hm = d.at.includes('pm') && !d.at.startsWith('12') ? `${String(Number(d.at.split(':')[0]) + 12).padStart(2, '0')}:${d.at.split(':')[1].slice(0, 2)}` : `${d.at.split(':')[0].padStart(2, '0')}:${d.at.split(':')[1].slice(0, 2)}`;
        raw.push({ id: `E-prn-${d.orderId}-${d.day}`, house: PEOPLE[o.pid].house, day: d.day, hm, kind: o.cd ? 'controlled' : 'dose', what: `As-needed dose given — ${o.med}`, detail: d.effect ? `Effect: ${d.effect}` : 'Effect not recorded yet', by: d.by, pid: o.pid, orderId: o.id, cd: !!o.cd });
    }
    // Grace’s 9:00 am clonazepam was given at 8:05 (P08b MED-0048): recorded, though its window is still open
    raw.push({ id: 'E-o-clonazepam-2026-09-28-09:00', house: 'kowhai', day: TODAY_ISO, hm: '08:05', kind: 'controlled', what: 'Dose given — Clonazepam', detail: '9:00 am dose, given at 8:05 — reported as MED-0048', by: 'Priya Shah', pid: 'grace', orderId: 'o-clonazepam', cd: true });
    raw.push({ id: 'E-mph-correction', house: 'kowhai', day: '2026-09-25', hm: '16:30', kind: 'controlled', what: 'Dose corrected — Methylphenidate', detail: 'The 12:10 pm dose was recorded as 2 tablets; voided and re-entered as 1, witnessed by Daniel Ahn', by: 'Jordan Tipene', pid: 'aroha', orderId: 'o-methylphenidate', cd: true });
    for (const h of ['kowhai', 'rimu'] as House[])
        for (const day of daysIn(RANGE_START, TODAY_ISO))
            CD_EVENTS.counts[h].times.forEach((hm, i) => {
                if (day === TODAY_ISO && hm > NOW_HM) return;
                const [by, witness] = i === 0 ? CD_EVENTS.counts[h].by : [...CD_EVENTS.counts[h].by].reverse();
                raw.push({ id: `E-count-${h}-${day}-${hm}`, house: h, day, hm, kind: 'controlled', what: `Controlled count — ${HOUSES[h]}`, detail: `Shift-change count of every controlled medicine, witnessed by ${witness}`, by, cd: true });
            });
    for (const d of CD_EVENTS.discrepancies) raw.push({ id: `E-${d.id}`, house: PEOPLE[orderOf(d.orderId).pid].house, day: d.day, hm: d.hm, kind: 'controlled', what: `Discrepancy ${d.id} — ${orderOf(d.orderId).med}`, detail: d.what, by: d.by, pid: orderOf(d.orderId).pid, orderId: d.orderId, cd: true });
    for (const d of CD_EVENTS.losses) raw.push({ id: `E-${d.id}`, house: PEOPLE[orderOf(d.orderId).pid].house, day: d.day, hm: d.hm, kind: 'controlled', what: `Loss ${d.id} — ${orderOf(d.orderId).med}`, detail: d.what, by: d.by, pid: orderOf(d.orderId).pid, orderId: d.orderId, cd: true });
    for (const d of CD_EVENTS.destructions) raw.push({ id: `E-${d.id}`, house: PEOPLE[orderOf(d.orderId).pid].house, day: d.day, hm: d.hm, kind: 'controlled', what: `Destruction ${d.id} — ${orderOf(d.orderId).med}`, detail: d.what, by: d.by, pid: orderOf(d.orderId).pid, orderId: d.orderId, cd: true });
    for (const e of ERRORS) {
        raw.push({ id: `E-${e.id}-rep`, house: PEOPLE[e.pid].house, day: e.reportedIso, hm: e.reportedHm, kind: 'error', what: `Medication error reported — ${e.id}`, detail: `Medication error — ${lowerFirst(TYPE_LABEL[e.type])} — ${HOUSES[PEOPLE[e.pid].house]}`, by: e.reportedBy, pid: e.pid, cd: false });
    }
    raw.push({ id: 'E-order-ferrous', house: 'kowhai', day: '2026-08-20', hm: '15:05', kind: 'order', what: 'Order stopped — Ferrous sulfate', detail: 'Stopped by Dr Lena Chen (synthetic); entered by Jordan Tipene', by: 'Jordan Tipene', pid: 'aroha', orderId: 'o-ferrous', cd: false });
    raw.push({ id: 'E-bg-1', house: 'rimu', day: '2026-09-18', hm: '02:20', kind: 'access', what: 'Emergency access opened — Ben’s record', detail: 'Night call to the after-hours GP; closed at 2:55 am', by: 'Ana Lemalu', pid: 'ben', cd: false });
    raw.push({ id: 'E-set-h6', house: 'kowhai', day: '2026-09-20', hm: '10:41', kind: 'settings', what: 'Medicine rule paused — Digoxin, record pulse', detail: 'Active → Paused · all houses', by: 'Hana Kereama', cd: false });
    for (const x of EXPORTS) raw.push({ id: `E-${x.id}`, house: x.house, day: x.day, hm: x.hm ?? '12:00', kind: 'export', what: `Export made — ${x.what}`, detail: `${x.detail} · purpose: ${x.purpose}`, by: x.by, cd: x.what.startsWith('Controlled'), pid: undefined });
    raw.sort((a, b) => `${a.day}T${a.hm}${a.id}`.localeCompare(`${b.day}T${b.hm}${b.id}`));
    const head: Record<House, { seq: number; hash: string }> = { kowhai: { seq: 16400, hash: '5e1c09a2' }, rimu: { seq: 3100, hash: '0b7d44e1' } };
    LOG = raw.map((r) => {
        const h = head[r.house];
        const seq = h.seq + 1;
        const hash = hex(fnv(`${h.hash}|${r.id}|${r.day}T${r.hm}|${r.by}`));
        const ev: LogEvent = { ...r, seq, hash, prev: h.hash };
        head[r.house] = { seq, hash };
        return ev;
    });
    return LOG;
}
export const eventAt = (e: LogEvent) => `${labelIso(e.day)}, ${time12(e.hm)}`;
export const chainHead = (h: House) => eventLog().filter((e) => e.house === h).slice(-1)[0];
/** Unrecorded doses — gaps — over the whole period, each with what was done about it (Q7). */
export const GAP_STATUS: Record<string, string> = {
    'o-losartan|2026-09-14|09:00': 'Follow-up F-12 closed — Aroha was at her sister’s; the dose was given there',
    'o-sertraline|2026-09-24|08:00': 'Follow-up F-19 open — Jordan Tipene',
    'o-amlodipine|2026-09-19|09:00': 'Follow-up F-15 closed — given at 9:40, recorded on paper while offline',
    'o-melatonin|2026-08-11|20:30': 'Follow-up F-6 closed — refused; recorded late on paper',
};
export const PAGE = 50;

/* ───────────── exports (Q8) ───────────── */
export type ExportKind = 'mar' | 'cdreg' | 'round' | 'doses' | 'errors' | 'stock' | 'audit';
export interface ExportDef {
    key: ExportKind;
    name: string;
    format: 'PDF' | 'CSV';
    what: string;
    limit: string;
    identifiable: boolean;
}
export const EXPORT_DEFS: ExportDef[] = [
    { key: 'mar', name: 'MAR', format: 'PDF', what: 'One person, one month: every medicine on their chart that month, ceased ones included, with each dose', limit: 'One month per file', identifiable: true },
    { key: 'cdreg', name: 'Controlled drug register', format: 'PDF', what: 'One controlled medicine: every entry, count and witness', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'round', name: 'Round sheet', format: 'PDF', what: 'One house, one day: each round and its doses', limit: 'One day per file', identifiable: true },
    { key: 'doses', name: 'Doses', format: 'CSV', what: 'Every scheduled dose and its outcome, for the people you can see', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'errors', name: 'Medication errors', format: 'CSV', what: 'Each report’s facts; accounts and notes where you can open the report in full', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'stock', name: 'Stock', format: 'CSV', what: 'Stock on hand, days left, reorder level, expiry and cost — no people', limit: 'As at today', identifiable: false },
    { key: 'audit', name: 'Audit trail', format: 'CSV', what: 'The events you can see, each with its place in the chain', limit: 'Up to 12 months per file', identifiable: true },
];
/** Who can make which export (Main, Q5): managers everything; the auditor the audit trail; finance stock. */
export function canExportKind(p: PersonaId, k: ExportKind) {
    if (k === 'audit') return has(p, 'audit.export');
    if (stockOnly(p)) return k === 'stock' && has(p, 'reports.export');
    if (k === 'cdreg') return has(p, 'reports.export') && cdView(p);
    return has(p, 'reports.export');
}
export const PURPOSES = [
    { key: 'care', label: 'Clinical care or review', description: 'For the person’s care, a review or a handover' },
    { key: 'audit', label: 'Audit or inspection', description: 'An internal audit, a certification audit or a funder' },
    { key: 'whanau', label: 'Asked for by the person or whānau', description: 'A request for their own records' },
    { key: 'incident', label: 'Looking into an incident', description: 'An error, a discrepancy, a loss or a complaint' },
    { key: 'costs', label: 'Costs and budgeting', description: 'Stock and spending' },
    { key: 'other', label: 'Something else', description: 'Say what' },
];

/* ───────────── definitions — one per number (Q2, Q4; the contract page lists them) ───────────── */
export interface Def {
    name: string;
    counts: string;
    na?: string;
}
export const DEFS: Record<string, Def> = {
    due: { name: 'Doses due', counts: 'Scheduled doses whose time and 60-minute window ended inside the period, by NZ date. Today’s doses still inside their window aren’t counted yet.' },
    given: { name: 'Given as due', counts: 'Doses recorded as given ÷ doses due.', na: 'No doses were due — for example only as-needed medicines, or none has reached the end of its window yet today.' },
    refused: { name: 'Refused', counts: 'Doses recorded as refused by the person.' },
    withheld: { name: 'Withheld', counts: 'Doses recorded as withheld for a clinical reason.' },
    missed: { name: 'Missed', counts: 'Doses recorded as missed — a staff omission, reported as a medication error.' },
    notRecorded: { name: 'Not recorded', counts: 'Doses whose window ended with nothing recorded — each is on the audit trail’s Unrecorded doses list.' },
    late: { name: 'Recorded late', counts: 'Given doses recorded after their window ended.' },
    roundOnTime: { name: 'Rounds on time', counts: 'Rounds whose window has ended where every dose was recorded inside the window ÷ rounds ended. Worked out from the doses, in NZ time.', na: 'No round has ended yet in the period.' },
    roundLate: { name: 'Late rounds', counts: 'Every dose recorded, one or more after the window.' },
    roundNotCompleted: { name: 'Not completed', counts: 'The window ended with one or more doses not recorded.' },
    roundNotStarted: { name: 'Not started', counts: 'The window ended with no dose recorded.' },
    prnGiven: { name: 'As-needed doses given', counts: 'As-needed (PRN) doses recorded as given in the period.' },
    prnEffect: { name: 'Effect recorded', counts: 'As-needed doses with their effect recorded ÷ as-needed doses given.', na: 'No as-needed dose was given.' },
    cdGiven: { name: 'Controlled doses given', counts: 'Scheduled and as-needed controlled doses recorded as given. Needs controlled-medicine access.' },
    cdWitnessed: { name: 'Witnessed', counts: 'Controlled doses with a second person’s witness PIN ÷ controlled doses given. A dose given under a witness override isn’t witnessed.', na: 'No controlled dose was given.' },
    cdCounts: { name: 'Counts done', counts: 'Witnessed counts of every controlled medicine at a house, at each shift change (7:00 am and 7:00 pm; Rimu House ten minutes later).' },
    errReached: { name: 'Reached the person', counts: 'Medication errors, by when they happened, that reached the person — with harm, with no harm or not known yet.' },
    errHarm: { name: 'With harm', counts: 'Reached the person with minor, moderate, severe or permanent harm, or death.' },
    errNear: { name: 'Near misses', counts: 'Errors that didn’t reach the person. Counted separately and reported with the rest.' },
    revDue: { name: 'Reviews due', counts: 'Medication reviews with a due date in the period.' },
    revDone: { name: 'Done', counts: 'Reviews recorded in the period.' },
    revOverdue: { name: 'Overdue now', counts: 'Booked reviews past their due date, as at now.' },
    stockBelow: { name: 'Below reorder level', counts: 'Lines at or under their reorder level, as at now.' },
    stockOut: { name: 'Running out in 7 days', counts: 'Stock on hand ÷ doses a day ≤ 7.', na: 'As-needed lines have no daily use, so no days left.' },
    stockExpiry: { name: 'Expiring in 30 days', counts: 'Lines whose expiry is within 30 days.' },
    staffCurrent: { name: 'Competency current', counts: 'Staff who give medicines with a current assessment, as at now.' },
};

/* ───────────── exports made in this session join the log, chained after its head ───────────── */
let RT_CACHE: { n: number; list: LogEvent[] } | null = null;
export function logWithRuntime(rt: Runtime): LogEvent[] {
    if (RT_CACHE && RT_CACHE.n === rt.exports.length) return RT_CACHE.list;
    const base = eventLog();
    const head: Record<House, { seq: number; hash: string }> = { kowhai: { seq: chainHead('kowhai').seq, hash: chainHead('kowhai').hash }, rimu: { seq: chainHead('rimu').seq, hash: chainHead('rimu').hash } };
    const extra = [...rt.exports].reverse().map((x) => {
        const h = head[x.house];
        const seq = h.seq + 1;
        const hash = hex(fnv(`${h.hash}|${x.id}|${x.at}|${x.by}`));
        head[x.house] = { seq, hash };
        const ev: LogEvent = { id: `E-${x.id}`, seq, house: x.house, day: TODAY_ISO, hm: NOW_HM, kind: 'export', what: `Export made — ${x.what}`, detail: `${x.detail} · purpose: ${x.purpose}`, by: x.by, cd: x.what.startsWith('Controlled'), hash, prev: h.hash };
        return ev;
    });
    RT_CACHE = { n: rt.exports.length, list: [...base, ...extra] };
    return RT_CACHE.list;
}
export function eventsFor(rt: Runtime, p: Period, pids: PersonId[], houses: House[], kind: string, person: string | null) {
    return logWithRuntime(rt)
        .filter((e) => inPeriod(e.day, p) && houses.includes(e.house) && (!e.pid || pids.includes(e.pid)) && (kind === 'all' || e.kind === kind) && (!person || e.pid === person))
        .reverse();
}
/** Inside a controlled event, the medicine and the detail need controlled-medicine access; the row stays (the person rule). */
export const redactedEvent = (p: PersonaId, e: LogEvent) => e.cd && !cdView(p);
export const eventTitle = (p: PersonaId, e: LogEvent) => (redactedEvent(p, e) ? (e.what.startsWith('Controlled count') ? 'Controlled count' : e.what.startsWith('Export') ? 'Export made — controlled register' : `Controlled medicine — ${lowerFirst(e.what.split(' — ')[0])}`) : e.what);
