/* P08b rules — medication errors and the incidents the medication side closes:
 * where they live and are reported, two plain questions for reach and harm, the
 * medicine from the chart, a neutral summary everywhere outside the error, the
 * stages with an owner and due dates, closing linked incidents through the
 * Incidents module, who can do what, telling the person, duplicates and honest
 * statistics. Decisions: Main’s answers under Stephan’s delegation, 1 October
 * (README Q1–Q10 and the refinements). */
import { ERRORS, HOUSES, INCIDENTS, PEOPLE, PERSONAS, TRIAGE_DEFAULT, TRIAGE_LABEL, TYPE_LABEL, has, orderOf, type ErrType, type Harm, type House, type MedError, type MedIncident, type PersonaId } from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No errors yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export type TriageValue = keyof typeof TRIAGE_LABEL;
export interface Runtime {
    errors: MedError[];
    patch: Record<string, Partial<MedError>>;
    incidents: Record<string, Partial<MedIncident>>;
    newIncidents: MedIncident[];
    org: { value: TriageValue; reviewed: boolean; by?: string; at?: string };
    history: { id: string; what: string; from: string; to: string; who: string; when: string; scope: string; area: string; fresh?: boolean }[];
    orgDraft: TriageValue | null;
    flash: string | null;
}
export const EMPTY_RT: Runtime = { errors: [], patch: {}, incidents: {}, newIncidents: [], org: { ...TRIAGE_DEFAULT }, history: [], orgDraft: null, flash: null };

/* ───────────── who can do what (Main, Q7) ───────────── */
export const canReport = (p: PersonaId) => has(p, 'record');
/** Triage, investigate, actions, telling the person, close, reopen: the new medications.errors.manage. */
export const canManage = (p: PersonaId) => has(p, 'errors.manage');
export const cdView = (p: PersonaId) => has(p, 'cd.view');
/** Clinical leads without controlled keys see a controlled error redacted and can’t act on it (Main, Q7). */
export const canActOn = (p: PersonaId, e: MedError) => canManage(p) && (!isControlled(e) || cdView(p));
/** The person who reported an error never closes it (Q5). */
export const canCloseError = (p: PersonaId, e: MedError) => canActOn(p, e) && e.reported.by !== PERSONAS[p].name;
/** Closing an incident stays the Incidents module’s own check (incidents.approve, Main Q6). */
export const canCloseIncident = (p: PersonaId) => has(p, 'incidents.approve');
export const canSetOrg = (p: PersonaId) => has(p, 'settings.org');
/** The register and its CSV: people who manage errors, and auditors. */
export const canSeeAll = (p: PersonaId) => canManage(p) || has(p, 'audit.view');
export const WHO_MANAGES = 'house leads, clinical leads, coordinators and managers';
export const WHO_CLOSES_INCIDENTS = 'coordinators and provider managers';
export const housesOf = (p: PersonaId) => PERSONAS[p].houses;

/* ───────────── dates (NZ days) ───────────── */
export const TODAY_ISO = '2026-09-28';
const DAY = 86400000;
const iso = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
export const daysUntil = (d: string) => Math.round((iso(d) - iso(TODAY_ISO)) / DAY);
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MO = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function labelIso(s: string) {
    const d = new Date(iso(s));
    return `${WD[d.getUTCDay()]} ${d.getUTCDate()} ${MO[d.getUTCMonth()]}`;
}
export const addDays = (s: string, n: number) => new Date(iso(s) + n * DAY).toISOString().slice(0, 10);
export const lowerFirst = (t: string) => t.charAt(0).toLowerCase() + t.slice(1);
export const localLabel = (v: string) => {
    const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/.exec(v);
    if (!m) return v;
    const h = Number(m[2]);
    return `${labelIso(m[1])}, ${h % 12 || 12}:${m[3]} ${h < 12 ? 'am' : 'pm'}`;
};

/** Q5 addition to P11 — when a report made now must be triaged. */
export const TRIAGE_ORDER: TriageValue[] = ['fourHours', 'endOfDay', 'nextDay'];
export function triageDue(v: TriageValue): { label: string; iso: string } {
    if (v === 'fourHours') return { label: 'by 1:12 pm today', iso: TODAY_ISO };
    if (v === 'endOfDay') return { label: 'by the end of today (Mon 28 Sep)', iso: TODAY_ISO };
    return { label: 'by the end of tomorrow (Tue 29 Sep)', iso: addDays(TODAY_ISO, 1) };
}

/* ───────────── errors ───────────── */
export function allErrors(rt: Runtime): MedError[] {
    return [...rt.errors, ...ERRORS].map((e) => (rt.patch[e.id] ? ({ ...e, ...rt.patch[e.id] } as MedError) : e));
}
export const errorOf = (rt: Runtime, id: string) => allErrors(rt).find((e) => e.id === id) ?? null;
export const houseOf = (e: MedError) => PEOPLE[e.pid].house;
/** Controlled if any medicine it’s about is controlled (EM-12). */
export const isControlled = (e: Pick<MedError, 'orderIds'>) => e.orderIds.some((o) => orderOf(o).cd);
/** Readers see errors at their houses; support workers see their own reports (Q7). */
export function visible(p: PersonaId, e: MedError) {
    if (!housesOf(p).includes(houseOf(e))) return false;
    if (!canSeeAll(p)) return e.reported.by === PERSONAS[p].name || e.accounts.some((a) => a.by === PERSONAS[p].name);
    return true;
}
export const errorsIn = (rt: Runtime, p: PersonaId) => allErrors(rt).filter((e) => visible(p, e));
/** Free text and the medicine are hidden inside a controlled error for readers without controlled view (Q4). */
export const redacted = (p: PersonaId, e: Pick<MedError, 'orderIds'>) => isControlled(e) && !cdView(p);
export const medsText = (p: PersonaId, e: Pick<MedError, 'orderIds' | 'notAbout' | 'offChart'>) =>
    redacted(p, e) ? 'Controlled medicine' : e.orderIds.length ? e.orderIds.map((o) => orderOf(o).med).join(' and ') : e.notAbout === 'offChart' ? `Not on the chart — ${e.offChart}` : 'Not about one medicine';

/** Q4 — the neutral summary used everywhere outside the error: never the free text, never a medicine name. */
export const summaryOf = (e: { pid: MedError['pid']; type: ErrType }) => `Medication error — ${lowerFirst(TYPE_LABEL[e.type])} — ${HOUSES[PEOPLE[e.pid].house]}`;

export type Tone = 'critical' | 'warning' | 'info' | 'success' | 'neutral';
/** Harm badges by severity; nothing that is harm is ever green (Main). */
export function harmTone(e: Pick<MedError, 'reach' | 'harm'>): { label: string; variant: Tone } {
    if (e.reach === 'no') return { label: 'Near miss', variant: 'info' };
    const h: Harm = e.harm;
    if (h === 'severe' || h === 'death') return { label: h === 'death' ? 'Death' : 'Severe or permanent harm', variant: 'critical' };
    if (h === 'moderate') return { label: 'Moderate harm', variant: 'warning' };
    if (h === 'minor') return { label: 'Minor or temporary harm', variant: 'warning' };
    if (h === 'unknown' || e.reach === 'unsure') return { label: 'Harm not known yet', variant: 'neutral' };
    return { label: 'Reached · no harm', variant: 'neutral' };
}
export const hasHarm = (e: MedError) => e.reach === 'yes' && ['minor', 'moderate', 'severe', 'death'].includes(e.harm);
/** Q6 — an incident is required when it reached the person with moderate harm or worse, or came from “More than ordered”. */
export const incidentRequired = (e: Pick<MedError, 'reach' | 'harm' | 'source'>) => e.source === 'more' || (e.reach !== 'no' && ['moderate', 'severe', 'death'].includes(e.harm));

export const openActions = (e: MedError) => e.actions.filter((a) => !a.done);
/** What still stands between this error and closing (Q5, Q8). */
export function closeBlockers(e: MedError): string[] {
    const out: string[] = [];
    if (e.stage === 'triage') out.push('It hasn’t been triaged yet.');
    if (openActions(e).length) out.push(`${openActions(e).length} ${openActions(e).length === 1 ? 'action is' : 'actions are'} still open.`);
    if (e.reach !== 'no' && e.disclosure?.state !== 'told') out.push('Telling the person hasn’t been recorded.');
    return out;
}
export function stageState(e: MedError): { label: string; variant: Tone; line?: string } {
    if (e.stage === 'closed') return { label: 'Closed', variant: 'neutral', line: e.closed ? `${e.closed.by}, ${e.closed.at}` : undefined };
    if (e.stage === 'triage') {
        const n = daysUntil(e.triageDueIso);
        return n < 0 ? { label: 'Triage overdue', variant: 'critical', line: `Was due ${e.triageDue.replace(/^by /, '')}` } : { label: 'To triage', variant: 'warning', line: `Due ${e.triageDue}` };
    }
    const late = e.investigateDueIso && daysUntil(e.investigateDueIso) < 0;
    const due = late ? `was due ${e.investigateDue}` : `due ${e.investigateDue}`;
    if (e.stage === 'actions') {
        if (openActions(e).length) return { label: `Actions — ${openActions(e).length} open`, variant: 'info', line: `${e.owner} · next action ${openActions(e)[0].due}` };
        if (!closeBlockers(e).length) return { label: 'Ready to close', variant: 'info', line: `${e.owner} · ${due}` };
        return { label: 'Telling the person to record', variant: 'warning', line: `${e.owner} · ${due}` };
    }
    return late ? { label: 'Investigation overdue', variant: 'critical', line: `${e.owner} · ${due}` } : { label: 'Investigating', variant: 'info', line: `${e.owner} · ${due}` };
}
export const actionTone = (dueIso: string): Tone => (daysUntil(dueIso) < 0 ? 'critical' : daysUntil(dueIso) === 0 ? 'warning' : 'neutral');
export const actionState = (dueIso: string) => (daysUntil(dueIso) < 0 ? 'Overdue' : daysUntil(dueIso) === 0 ? 'Due today' : 'Open');

/* ───────────── duplicates (Q9) ───────────── */
/** An open report about the same person and the same medicine, within a day either side. */
export function duplicateOf(rt: Runtime, pid: string, orderIds: string[], occurredIso: string) {
    return allErrors(rt).find((e) => e.stage !== 'closed' && e.pid === pid && orderIds.some((o) => e.orderIds.includes(o)) && Math.abs(daysUntil(e.occurredIso) - daysUntil(occurredIso)) <= 1) ?? null;
}

/* ───────────── statistics (Q10) — one number each, NZ weeks by when it happened ───────────── */
export const WEEKS = Array.from({ length: 8 }, (_, n) => addDays(TODAY_ISO, -7 * (7 - n)));
export const weekOf = (d: string) => WEEKS.find((w) => d >= w && d < addDays(w, 7)) ?? null;
export const inLast90 = (e: MedError) => daysUntil(e.occurredIso) >= -90;
export const band = (e: MedError): 'harm' | 'noHarm' | 'nearMiss' => (e.reach === 'no' ? 'nearMiss' : hasHarm(e) ? 'harm' : 'noHarm');

/* ───────────── incidents (Q6) ───────────── */
export function allIncidents(rt: Runtime): MedIncident[] {
    return [...rt.newIncidents, ...INCIDENTS].map((i) => ({ ...i, ...rt.incidents[i.id] }) as MedIncident);
}
export const incidentOf = (rt: Runtime, id: string) => allIncidents(rt).find((i) => i.id === id) ?? null;
export const incidentHouse = (i: MedIncident): House => PEOPLE[i.pid].house;
export const incidentsIn = (rt: Runtime, p: PersonaId) => (canSeeAll(p) ? allIncidents(rt).filter((i) => housesOf(p).includes(incidentHouse(i))) : []);
/** The neutral title an incident carries (EM-12 wording for P07b’s; the error summary for errors). */
export function incidentTitle(rt: Runtime, i: MedIncident) {
    if (i.source === 'discrepancy') return `Controlled medicine count discrepancy — ${HOUSES[incidentHouse(i)]}`;
    if (i.source === 'loss') return `Controlled medicine loss — ${HOUSES[incidentHouse(i)]}`;
    const e = errorOf(rt, i.ref);
    return e ? summaryOf(e) : 'Medication error';
}
export const isReady = (rt: Runtime, i: MedIncident) => i.status !== 'closed' && (i.source === 'error' ? errorOf(rt, i.ref)?.stage === 'closed' : !!i.ready);
export function incidentState(rt: Runtime, i: MedIncident): { label: string; variant: Tone; line: string } {
    if (i.status === 'closed') return { label: 'Closed', variant: 'neutral', line: i.closed ? `${i.closed.by}, ${i.closed.at} — ${i.closed.outcome}` : '' };
    const src = i.source === 'error' ? errorOf(rt, i.ref) : null;
    if (isReady(rt, i)) return { label: i.source === 'error' ? 'Ready to close — medication error closed' : i.source === 'loss' ? 'Ready to close — loss closed' : 'Ready to close — discrepancy closed', variant: 'warning', line: i.ready ? `Close note from ${i.ready.by}, ${i.ready.at}` : '' };
    return { label: i.source === 'error' ? 'Waiting for the medication error to close' : i.sourceOpen ?? 'Waiting', variant: 'neutral', line: i.source === 'error' ? `${i.ref} · ${src ? lowerFirst(stageState(src).label) : ''}` : i.ref };
}
export const INC_STATUS: Record<MedIncident['status'], string> = { submitted: 'Submitted — awaiting review', reviewed: 'Reviewed', closed: 'Closed' };
