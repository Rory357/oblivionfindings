/* P10 rules — Emergency access & downtime. Decisions: Main’s answers under
 * Stephan’s delegation, 1 October (README Q1–Q11, all (A), with the
 * refinements). The event log, the periods and the exports are P09 v1.1’s,
 * unchanged, with P10’s events added to the chain (Q8). */
import {
    CD_EVENTS,
    DOWNTIMES,
    ERRORS,
    EXPORTS,
    FLAGS,
    GRANTS,
    HOUSES,
    ORDERS,
    PEOPLE,
    PEOPLE_ORDER,
    PERSONAS,
    PRN_DOSES,
    TYPE_LABEL,
    fnv,
    has,
    orderOf,
    slotOutcome,
    type At,
    type Downtime,
    type ExportMade,
    type Grant,
    type House,
    type Outcome,
    type PersonId,
    type PersonaId,
    type ReviewOutcome,
} from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'ending' | 'expired' | 'required' | 'oncallnone' | 'offline' | 'stale' | 'loading' | 'empty' | 'unavailable' | 'logdown';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'ending', label: 'Rangi’s access ends in 8 minutes' },
    { key: 'expired', label: 'Rangi’s access ran out at 9:10 am' },
    { key: 'required', label: 'A second person is required' },
    { key: 'oncallnone', label: 'Required · no on-call contact set' },
    { key: 'offline', label: 'Offline' },
    { key: 'stale', label: 'Out of date' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No emergency access yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'logdown', label: 'Event log can’t be written' },
];

/* ───────────── dates (NZ calendar) ───────────── */
export const TODAY_ISO = '2026-09-28';
export const NOW_HM = '09:12';
export const NOW: At = { day: TODAY_ISO, hm: NOW_HM };
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
/** Minutes from 1 Jul 2026, NZ — for comparing and adding times across days. */
export const toMin = (a: At) => Math.round((ms(a.day) - ms('2026-07-01')) / 60000) + Number(a.hm.slice(0, 2)) * 60 + Number(a.hm.slice(3, 5));
export function fromMin(n: number): At {
    const d = Math.floor(n / 1440);
    const m = n - d * 1440;
    return { day: addDays('2026-07-01', d), hm: `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}` };
}
export const plusMin = (a: At, n: number) => fromMin(toMin(a) + n);
/** “9:31 am” today, “Sun 27 Sep, 8:40 pm” on another day. */
export const atText = (a: At) => (a.day === TODAY_ISO ? time12(a.hm) : `${labelIso(a.day)}, ${time12(a.hm)}`);
export const atLong = (a: At) => `${labelIso(a.day)}, ${time12(a.hm)}`;
export const fmtMin = (n: number) => (n % 60 === 0 ? `${n / 60} ${n === 60 ? 'hour' : 'hours'}` : n > 60 ? `${Math.floor(n / 60)} h ${n % 60} min` : `${n} minutes`);
export const stampAt = (): At => ({ ...NOW });

/* ───────────── periods (P09 Q4, for the audit-trail frame) ───────────── */
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
export const inPeriod = (day: string, p: Period) => day >= p.from && day <= p.to;

/* ───────────── who can do what ───────────── */
export const cdView = (p: PersonaId) => has(p, 'cd.view');
export const canReports = (p: PersonaId) => has(p, 'reports.view');
export const stockOnly = (p: PersonaId) => has(p, 'stock.only');
export const canAudit = (p: PersonaId) => has(p, 'audit.view');
export const housesOf = (p: PersonaId) => PERSONAS[p].houses;
export const peopleOf = (p: PersonaId) => PEOPLE_ORDER.filter((x) => housesOf(p).includes(PEOPLE[x].house));
export const WHO_EXPORTS = 'coordinators and provider managers';
export const WHO_AUDITS = 'clinical leads, coordinators, managers and auditors';
/** Q1: breakglass holders request; audit.view holders review. Nobody else is told about emergency access. */
export const canRequest = (p: PersonaId) => has(p, 'breakglass');
export const canReview = (p: PersonaId) => canAudit(p);
export const canSeeAccess = (p: PersonaId) => canRequest(p) || canReview(p);
export const canEndOthers = (p: PersonaId) => has(p, 'ea.end');
/** Q10: the downtime pack — house leads, clinical leads, coordinators and managers, for their houses (the auditor exports only the audit trail — P09). */
export const canPack = (p: PersonaId) => canReports(p) && has(p, 'errors.manage');
export const canSeeDowntime = (p: PersonaId) => canReports(p) && !stockOnly(p);
export const nameOf = (p: PersonaId) => PERSONAS[p].name;

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export interface EaSettings {
    second: 'off' | 'optional' | 'required';
    reviewDays: '1' | '2' | '3';
    secondReviewed: boolean;
    reviewReviewed: boolean;
}
export interface RtEvent {
    id: string;
    house: House;
    kind: EventKind;
    what: string;
    detail: string;
    by: string;
    pid?: PersonId;
    cd: boolean;
}
export interface PaperEntry {
    by: string;
    at: At;
    outcome: 'given' | 'refused' | 'withheld';
    hm: string;
    givenBy: string;
    note?: string;
    /** A lead entered it for someone else — they confirm it (Q11). */
    forOther?: boolean;
    confirmed?: { by: string; at: At };
    witness?: { name: string; confirmed?: At };
}
export interface Runtime {
    exports: ExportMade[];
    events: RtEvent[];
    grants: Record<string, Partial<Grant>>;
    newGrants: Grant[];
    acks: Record<string, { by: string; at: At; why: string }>;
    /** Doses recorded in the session on the person-MAR frame (slot key → record). */
    recorded: Record<string, { by: string; hm: string; grant?: string; outcome: string }>;
    paper: Record<string, PaperEntry>;
    extraPaper: Record<string, Downtime['items']>;
    newDowntimes: Downtime[];
    closedDowntimes: Record<string, { by: string; at: At }>;
    ea: EaSettings;
    eaDraft: Partial<EaSettings> | null;
    history: { id: string; what: string; from: string; to: string; who: string; when: string; scope: string; area: string; fresh?: boolean }[];
    flash: string | null;
    /** What was entered when access ran out — kept for when it starts again (Q5). */
    recordDraft: { pid: PersonId; orderId: string; outcome: string; hm: string } | null;
}
export const EA_DEFAULT: EaSettings = { second: 'optional', reviewDays: '2', secondReviewed: false, reviewReviewed: false };
export const EMPTY_RT: Runtime = {
    exports: [],
    events: [],
    grants: {},
    newGrants: [],
    acks: {},
    recorded: {},
    paper: {},
    extraPaper: {},
    newDowntimes: [],
    closedDowntimes: {},
    ea: { ...EA_DEFAULT },
    eaDraft: null,
    history: [],
    flash: null,
    recordDraft: null,
};

/* ───────────── the emergency access policy (P11 v5 values + P10’s additions) ───────────── */
/** P11 v5 defaults (Settings › Alerts & access › Emergency access): 1 hour, +30 minutes, never past 4 hours in all, reason required, flag 4 grants within 7 days. */
export const POLICY = { def: 60, ext: 30, max: 240, reason: true, repeatN: 4, repeatDays: 7 };
export const DURATIONS = [30, 60, 120, 240];
/** Q3: only durations up to the longest grant are offered. */
export const durationsOffered = () => DURATIONS.filter((m) => m <= POLICY.max);
export const eaNow = (rt: Runtime, scn: Scenario): EaSettings => ({ ...rt.ea, ...(scn === 'required' || scn === 'oncallnone' ? { second: 'required' as const } : {}) });

/* ───────────── grants: status, due dates, who may act ───────────── */
function scenarioGrant(g: Grant, scn: Scenario): Grant {
    if (g.id !== 'EA-13') return g;
    if (scn === 'ending') return { ...g, start: { day: TODAY_ISO, hm: '08:20' }, firstEnd: { day: TODAY_ISO, hm: '09:20' } };
    if (scn === 'expired') return { ...g, start: { day: TODAY_ISO, hm: '08:10' }, firstEnd: { day: TODAY_ISO, hm: '09:10' } };
    return g;
}
export function allGrants(rt: Runtime, scn: Scenario): Grant[] {
    if (scn === 'empty') return [];
    const base = GRANTS.map((g) => scenarioGrant(g, scn)).map((g) => (rt.grants[g.id] ? { ...g, ...rt.grants[g.id] } : g));
    return [...base, ...rt.newGrants.map((g) => (rt.grants[g.id] ? { ...g, ...rt.grants[g.id] } : g))];
}
export const endOf = (g: Grant): At => g.extensions.length ? g.extensions[g.extensions.length - 1].to : g.firstEnd;
export const latestOf = (g: Grant): At => plusMin(g.start, POLICY.max);
/** When a grant ends by itself, it’s recorded as ended at that time (build note: a scheduled sweep writes the event). */
export function endedOf(g: Grant): Grant['ended'] | undefined {
    if (g.ended) return g.ended;
    if (toMin(endOf(g)) <= toMin(NOW)) return { how: 'ranOut', at: endOf(g) };
    return undefined;
}
export const isLive = (g: Grant) => !endedOf(g);
export const minutesLeft = (g: Grant) => toMin(endOf(g)) - toMin(NOW);
export const reviewDueOf = (g: Grant, ea: EaSettings): At | null => {
    const e = endedOf(g);
    return e ? plusMin(e.at, Number(ea.reviewDays) * 1440) : null;
};
export const needsReview = (g: Grant) => !!endedOf(g) && g.reviews.length === 0;
export const isOverdue = (g: Grant, ea: EaSettings) => needsReview(g) && toMin(reviewDueOf(g, ea)!) < toMin(NOW);
export const houseOfGrant = (g: Grant) => PEOPLE[g.pid].house;
export const currentReview = (g: Grant) => g.reviews[g.reviews.length - 1];
/** Q6: never the person who used it or the one who confirmed it. */
export function reviewBlock(p: PersonaId, g: Grant): null | 'own' | 'confirmed' | 'noaccess' | 'live' | 'house' {
    if (!canReview(p)) return 'noaccess';
    if (!housesOf(p).includes(houseOfGrant(g))) return 'house';
    if (g.by === nameOf(p)) return 'own';
    if (g.second?.name === nameOf(p)) return 'confirmed';
    if (isLive(g)) return 'live';
    return null;
}
export const REVIEW_BLOCK_TEXT: Record<'own' | 'confirmed' | 'live', string> = {
    own: 'You used it, so someone else reviews it — a clinical lead, coordinator or auditor.',
    confirmed: 'You confirmed it as the second person, so someone else reviews it.',
    live: 'It’s still running. It can be reviewed once it ends.',
};
export const HOW_ENDED = (g: Grant) => {
    const e = endedOf(g);
    if (!e) return 'Running';
    if (e.how === 'ranOut') return `Its time ran out at ${atText(e.at)}`;
    if (e.how === 'done') return `Ended early by ${e.by}, ${atText(e.at)}`;
    return `Ended by ${e.by}, ${atText(e.at)}`;
};
export const OUTCOME_LABEL: Record<ReviewOutcome, string> = { justified: 'Justified', notJustified: 'Not justified' };
/** Reviewers told when a grant starts (Q7): the house’s reviewers, not the grantee — and the second person. */
export function reviewersOf(h: House, except: string[]) {
    return (Object.keys(PERSONAS) as PersonaId[]).filter((p) => canReview(p) && housesOf(p).includes(h) && !except.includes(PERSONAS[p].name)).map((p) => PERSONAS[p].name);
}
/** Live flags: repeat use, reported only (Q7). An acknowledged flag comes back on new use. */
export function flagsOf(rt: Runtime, scn: Scenario) {
    if (scn === 'empty') return [];
    const newer = rt.newGrants.filter((g) => g.by === 'Rangi Parata');
    return FLAGS.map((f) => {
        const ack = rt.acks[f.id];
        const back = ack && newer.some((g) => toMin(g.start) > toMin(ack.at));
        return { ...f, grants: [...f.grants, ...newer.map((g) => g.id)], ack: back ? undefined : ack, back };
    });
}

/* ───────────── downtime (Q9, Q11) ───────────── */
export function allDowntimes(rt: Runtime, scn: Scenario): Downtime[] {
    if (scn === 'empty') return [];
    return [...DOWNTIMES, ...rt.newDowntimes].map((d) => ({ ...d, items: [...d.items, ...(rt.extraPaper[d.id] ?? [])] }));
}
export type ItemState = 'toEnter' | 'confirm' | 'witness' | 'done';
export function itemState(rt: Runtime, id: string): ItemState {
    const e = rt.paper[id];
    if (!e) return 'toEnter';
    if (e.witness && !e.witness.confirmed) return 'witness';
    if (e.forOther && !e.confirmed) return 'confirm';
    return 'done';
}
export const ITEM_LABEL: Record<ItemState, string> = { toEnter: 'To enter', confirm: 'Waiting for them to confirm', witness: 'Witness to confirm', done: 'Entered from paper' };

/* ───────────── the dose-slot projection (P09 Q2, unchanged) ───────────── */
export interface Slot {
    orderId: string;
    pid: PersonId;
    day: string;
    time: string;
    outcome: Outcome;
    late: boolean;
    by: string;
    cd: boolean;
    at?: string;
    unwitnessed?: boolean;
    note?: string;
}
const SLOT_CACHE = new Map<string, { counted: Slot[]; dueNow: number }>();
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

/* ───────────── the medication event log (P09 Q7) + P10’s events (Q8) ───────────── */
export type EventKind = 'dose' | 'controlled' | 'order' | 'error' | 'access' | 'downtime' | 'settings' | 'export';
export const KIND_LABEL: Record<EventKind, string> = { dose: 'Doses', controlled: 'Controlled medicines', order: 'Orders', error: 'Medication errors', access: 'Emergency access', downtime: 'Downtime & paper', settings: 'Settings', export: 'Exports' };
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
const recordOf = (pid: PersonId) => `${PEOPLE[pid].pref}’s record`;
/** P10’s events for one grant (EA-9’s opening is P09’s E-bg-1, kept as it is). */
export function grantEvents(g: Grant): Omit<LogEvent, 'seq' | 'hash' | 'prev'>[] {
    const house = houseOfGrant(g);
    const out: Omit<LogEvent, 'seq' | 'hash' | 'prev'>[] = [];
    if (g.id !== 'EA-9')
        out.push({ id: `E-${g.id}-open`, house, day: g.start.day, hm: g.start.hm, kind: 'access', what: `Emergency access opened — ${recordOf(g.pid)}`, detail: `${g.id} · ${g.why}${g.second ? ` · confirmed by ${g.second.name} with their PIN` : ''} · until ${time12(g.firstEnd.hm)}`, by: g.by, pid: g.pid, cd: false });
    g.extensions.forEach((x, i) => out.push({ id: `E-${g.id}-ext-${i}`, house, day: x.at.day, hm: x.at.hm, kind: 'access', what: `Emergency access extended — ${recordOf(g.pid)}`, detail: `${g.id} · until ${time12(x.to.hm)} · ${x.why}`, by: g.by, pid: g.pid, cd: false }));
    const e = endedOf(g);
    if (e && (e.how !== 'ranOut' || toMin(e.at) <= toMin(NOW)))
        out.push({ id: `E-${g.id}-close`, house, day: e.at.day, hm: e.at.hm, kind: 'access', what: `Emergency access closed — ${recordOf(g.pid)}`, detail: `${g.id} · ${e.how === 'ranOut' ? 'Its time ran out' : e.how === 'done' ? `Ended early by ${e.by}` : `Ended by ${e.by}: ${e.why}`}`, by: e.how === 'ranOut' ? 'Automatic' : e.by!, pid: g.pid, cd: false });
    g.reviews.forEach((r, i) => out.push({ id: `E-${g.id}-rev-${i}`, house, day: r.at.day, hm: r.at.hm, kind: 'access', what: `Emergency access ${r.correction ? 'review corrected' : 'reviewed'} — ${recordOf(g.pid)}`, detail: `${g.id} · ${OUTCOME_LABEL[r.outcome]}${r.correction ? ` — ${r.correction}` : ''}${r.notes ? ` · ${r.notes}` : ''}`, by: r.by, pid: g.pid, cd: false }));
    return out;
}
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
    for (const e of ERRORS) raw.push({ id: `E-${e.id}-rep`, house: PEOPLE[e.pid].house, day: e.reportedIso, hm: e.reportedHm, kind: 'error', what: `Medication error reported — ${e.id}`, detail: `Medication error — ${lowerFirst(TYPE_LABEL[e.type])} — ${HOUSES[PEOPLE[e.pid].house]}`, by: e.reportedBy, pid: e.pid, cd: false });
    raw.push({ id: 'E-order-ferrous', house: 'kowhai', day: '2026-08-20', hm: '15:05', kind: 'order', what: 'Order stopped — Ferrous sulfate', detail: 'Stopped by Dr Lena Chen (synthetic); entered by Jordan Tipene', by: 'Jordan Tipene', pid: 'aroha', orderId: 'o-ferrous', cd: false });
    raw.push({ id: 'E-bg-1', house: 'rimu', day: '2026-09-18', hm: '02:20', kind: 'access', what: 'Emergency access opened — Ben’s record', detail: 'Night call to the after-hours GP; closed at 2:55 am', by: 'Ana Lemalu', pid: 'ben', cd: false });
    raw.push({ id: 'E-set-h6', house: 'kowhai', day: '2026-09-20', hm: '10:41', kind: 'settings', what: 'Medicine rule paused — Digoxin, record pulse', detail: 'Active → Paused · all houses', by: 'Hana Kereama', cd: false });
    for (const x of EXPORTS) raw.push({ id: `E-${x.id}`, house: x.house, day: x.day, hm: x.hm ?? '12:00', kind: 'export', what: `Export made — ${x.what}`, detail: `${x.detail} · purpose: ${x.purpose}`, by: x.by, cd: x.what.startsWith('Controlled'), pid: undefined });
    // P10 (Q8): each grant’s events, the dose recorded under EA-13, the repeat-use flag and the downtime period.
    for (const g of GRANTS) raw.push(...grantEvents(g));
    raw.push({ id: 'E-o-losartan-2026-09-28-09:00', house: 'kowhai', day: TODAY_ISO, hm: '09:02', kind: 'dose', what: 'Dose given — Losartan', detail: '9:00 am dose — under emergency access EA-13', by: 'Rangi Parata', pid: 'aroha', orderId: 'o-losartan', cd: false });
    for (const f of FLAGS) raw.push({ id: `E-${f.id}`, house: 'kowhai', day: f.raised.day, hm: f.raised.hm, kind: 'access', what: `Repeat use flagged — ${f.who}`, detail: `${f.grants.length} grants within ${POLICY.repeatDays} days (${f.grants.join(', ')}) — reported, not blocked`, by: 'Automatic', cd: false });
    for (const d of DOWNTIMES) raw.push({ id: `E-${d.id}`, house: d.house, day: d.declared.at.day, hm: d.declared.at.hm, kind: 'downtime', what: `Downtime recorded — ${HOUSES[d.house]}`, detail: `${labelIso(d.start.day)}, ${time12(d.start.hm)}–${time12(d.end.hm)} · ${d.why} · ${d.items.length} paper records to enter`, by: d.declared.by, cd: false });
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
/** P09’s follow-ups for unrecorded doses, with F-15 now pointing at P10’s downtime period. */
export const GAP_STATUS: Record<string, string> = {
    'o-losartan|2026-09-14|09:00': 'Follow-up F-12 closed — Aroha was at her sister’s; the dose was given there',
    'o-sertraline|2026-09-24|08:00': 'Follow-up F-19 open — Jordan Tipene',
    'o-amlodipine|2026-09-19|09:00': 'Follow-up F-15 closed — given at 9:40, recorded on paper while offline',
    'o-melatonin|2026-08-11|20:30': 'Follow-up F-6 closed — refused; recorded late on paper',
};
export const PAGE = 50;

/* ───────────── exports (P09 Q8) + P10’s downtime pack (Q9, Q10) ───────────── */
export type ExportKind = 'mar' | 'cdreg' | 'round' | 'doses' | 'errors' | 'stock' | 'audit' | 'pack' | 'ea';
export interface ExportDef {
    key: ExportKind;
    name: string;
    format: 'PDF' | 'CSV';
    what: string;
    limit: string;
    identifiable: boolean;
}
export const EXPORT_DEFS: ExportDef[] = [
    { key: 'pack', name: 'Downtime pack', format: 'PDF', what: 'One house, today or tomorrow: blank recording sheets for every scheduled dose, the round sheets, controlled register pages and how to record on paper', limit: 'Today or tomorrow', identifiable: true },
    { key: 'mar', name: 'MAR', format: 'PDF', what: 'One person, one month: every medicine on their chart that month, ceased ones included, with each dose', limit: 'One month per file', identifiable: true },
    { key: 'cdreg', name: 'Controlled drug register', format: 'PDF', what: 'One controlled medicine: every entry, count and witness', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'round', name: 'Round sheet', format: 'PDF', what: 'One house, one day: each round and its doses', limit: 'One day per file', identifiable: true },
    { key: 'doses', name: 'Doses', format: 'CSV', what: 'Every scheduled dose and its outcome, for the people you can see', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'errors', name: 'Medication errors', format: 'CSV', what: 'Each report’s facts; accounts and notes where you can open the report in full', limit: 'Up to 12 months per file', identifiable: true },
    { key: 'stock', name: 'Stock', format: 'CSV', what: 'Stock on hand, days left, reorder level, expiry and cost — no people', limit: 'As at today', identifiable: false },
    { key: 'audit', name: 'Audit trail', format: 'CSV', what: 'The events you can see, each with its place in the chain', limit: 'Up to 12 months per file', identifiable: true },
];
/** The emergency access history file (Q8) — P09’s export dialog, under the audit-trail export key (D6). */
export const EA_EXPORT: ExportDef = { key: 'ea', name: 'Emergency access history', format: 'CSV', what: 'Every grant you can see: who, for whom, why, how long, what was done and its review', limit: 'Up to 12 months per file', identifiable: true };
export function canExportKind(p: PersonaId, k: ExportKind) {
    if (k === 'pack') return canPack(p);
    if (k === 'audit' || k === 'ea') return has(p, 'audit.export');
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

/* ───────────── session events join the log, chained after its head ───────────── */
let RT_CACHE: { n: number; list: LogEvent[] } | null = null;
export function logWithRuntime(rt: Runtime): LogEvent[] {
    if (RT_CACHE && RT_CACHE.n === rt.events.length) return RT_CACHE.list;
    const base = eventLog();
    const head: Record<House, { seq: number; hash: string }> = { kowhai: { seq: chainHead('kowhai').seq, hash: chainHead('kowhai').hash }, rimu: { seq: chainHead('rimu').seq, hash: chainHead('rimu').hash } };
    const extra = rt.events.map((x) => {
        const h = head[x.house];
        const seq = h.seq + 1;
        const hash = hex(fnv(`${h.hash}|${x.id}|${NOW_HM}|${x.by}`));
        head[x.house] = { seq, hash };
        const ev: LogEvent = { ...x, seq, day: TODAY_ISO, hm: NOW_HM, hash, prev: h.hash };
        return ev;
    });
    RT_CACHE = { n: rt.events.length, list: [...base, ...extra] };
    return RT_CACHE.list;
}
export function eventsFor(rt: Runtime, p: Period, pids: PersonId[], houses: House[], kind: string, person: string | null) {
    return logWithRuntime(rt)
        .filter((e) => inPeriod(e.day, p) && houses.includes(e.house) && (!e.pid || pids.includes(e.pid)) && (kind === 'all' || e.kind === kind) && (!person || e.pid === person))
        .reverse();
}
export const redactedEvent = (p: PersonaId, e: LogEvent) => e.cd && !cdView(p);
export const eventTitle = (p: PersonaId, e: LogEvent) => (redactedEvent(p, e) ? (e.what.startsWith('Controlled count') ? 'Controlled count' : e.what.startsWith('Export') ? 'Export made — controlled register' : `Controlled medicine — ${lowerFirst(e.what.split(' — ')[0])}`) : e.what);
/** A session event, with a unique id. */
export const rtEvent = (rt: Runtime, e: Omit<RtEvent, 'id'>): RtEvent => ({ ...e, id: `E-rt-${rt.events.length + 1}` });

/** The frontline personas’ shifts this morning (P09’s top bar). */
const SHIFT: Partial<Record<PersonaId, string>> = { sw: 'clocked in 7:02 am', lead: 'clocked in 8:04 am', rimu: 'clocked in 6:55 am' };
export const frontlineShift = (p: PersonaId) => SHIFT[p] ?? null;
