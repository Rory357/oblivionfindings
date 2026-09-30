/* P03 rules — support per medicine, the assessment cap, agreements, reassessment
 * and consent changes. Decisions: P00 v5 / P01 v2 (the four support words and how
 * each is recorded), P02 v1 (the record and concealment rules) and the P03
 * questions put to Main on 30 September (README). Synthetic only. */
import { TODAY } from './clock';
import {
    AGREEMENTS,
    CHANGES,
    CONSENT_CHANGES,
    OUTCOME_CAP,
    PERSONAS,
    SUPPORT_ORDER,
    TRIGGER_EVENTS,
    currentAssessment,
    medsOf,
    type Agreement,
    type Assessment,
    type ChangeEvent,
    type ConsentChange,
    type Medicine,
    type Outcome,
    type PersonId,
    type PersonaId,
    type Support,
    type TriggerEvent,
} from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No assessments yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── runtime (what the preview records survive persona switches) ───────────── */
export interface Runtime {
    support: Record<string, Support>;
    assessments: Partial<Record<PersonId, Assessment>>;
    agreements: Partial<Record<PersonId, Agreement>>;
    consent: ConsentChange[];
    closedTriggers: string[];
    queued: string[];
    events: ChangeEvent[];
}
export const EMPTY_RT: Runtime = { support: {}, assessments: {}, agreements: {}, consent: [], closedTriggers: [], queued: [], events: [] };
/** Newest first: what was recorded in this session, then the fixtures. */
export const changesOf = (pid: PersonId, rt: Runtime) => [...rt.events.filter((e) => e.pid === pid), ...CHANGES.filter((e) => e.pid === pid)];

/* ───────────── who can do what ───────────── */
/** Assess, reassess, set support and record the agreement: today’s medications.orders.manage (unchanged). */
export const canAssess = (p: PersonaId) => PERSONAS[p].perms.includes('orders.manage');
/** Record what the person asked for: anyone who records doses with them, and leads. */
export const canRecordConsent = (p: PersonaId) => PERSONAS[p].perms.includes('administer') || canAssess(p);
export const cdView = (p: PersonaId) => PERSONAS[p].perms.includes('cd.view');
export const concealed = (m: Medicine, p: PersonaId) => !!m.cd && !cdView(p);
export const whoAssesses = 'house leads, coordinators and clinical leads';

/* ───────────── the person’s plan, as it stands now ───────────── */
export const assessmentOf = (pid: PersonId, rt: Runtime): Assessment | null => rt.assessments[pid] ?? currentAssessment(pid);
export const agreementOf = (pid: PersonId, rt: Runtime): Agreement | null => rt.agreements[pid] ?? AGREEMENTS.find((a) => a.pid === pid) ?? null;
export const supportOf = (m: Medicine, rt: Runtime): Support | null => rt.support[m.key] ?? m.support;
export const consentOf = (pid: PersonId, rt: Runtime) => [...rt.consent, ...CONSENT_CHANGES].filter((c) => c.pid === pid);
export const triggersOf = (pid: PersonId, rt: Runtime): TriggerEvent[] => [...TRIGGER_EVENTS.filter((t) => t.pid === pid), ...rt.consent.filter((c) => c.pid === pid).map(consentTrigger)].filter((t) => !rt.closedTriggers.includes(t.id));

/** The assessment’s result, in the four words, is the most independence allowed (Q2). */
export const capOf = (pid: PersonId, rt: Runtime): Support => {
    const a = assessmentOf(pid, rt);
    return a ? OUTCOME_CAP[a.outcome] : 'administer';
};
const idx = (s: Support) => SUPPORT_ORDER.indexOf(s);
/** Choices for one medicine: at or below the cap; controlled medicines at most Assist (Q6). */
export const allowedFor = (m: Medicine, cap: Support): Support[] => SUPPORT_ORDER.filter((s) => idx(s) >= Math.max(idx(cap), m.cd ? idx('assist') : 0));
export const moreIndependent = (a: Support, b: Support) => idx(a) < idx(b);

/** The result today’s code computes (MedicationSelfAdminAssessment::computeOutcome), unchanged. */
export function computeOutcome(wishes: boolean, willing: boolean, total: number): Outcome {
    if (!wishes || !willing) return 'administered';
    if (total >= 21) return 'independent';
    if (total >= 16) return 'prompted';
    if (total >= 11) return 'supervised';
    return 'administered';
}

/** An agreement is needed when the person keeps or takes any medicine themselves (Q3). */
export const needsAgreement = (pid: PersonId, rt: Runtime) => medsOf(pid).some((m) => ['selfmanaged', 'prompt'].includes(supportOf(m, rt) ?? 'administer'));

export type PlanState = 'none' | 'reassess' | 'overdue' | 'soon' | 'current';
export interface PlanStatus {
    state: PlanState;
    lines: { text: string; tone?: 'warning' | 'critical' | 'success' }[];
    agreementMissing: boolean;
    unset: Medicine[];
}
const days = (fromIso: string, toIso: string) => Math.round((Date.parse(toIso) - Date.parse(fromIso)) / 86400000);
export function statusOf(pid: PersonId, rt: Runtime, persona: PersonaId): PlanStatus {
    const a = assessmentOf(pid, rt);
    const lines: PlanStatus['lines'] = [];
    const unset = medsOf(pid).filter((m) => supportOf(m, rt) === null && !concealed(m, persona));
    const agreementMissing = needsAgreement(pid, rt) && !agreementOf(pid, rt);
    const trig = triggersOf(pid, rt);
    let state: PlanState;
    if (!a) {
        state = 'none';
        lines.push({ text: 'No assessment — staff give every medicine (Administer)', tone: 'warning' });
    } else if (trig.length) {
        state = 'reassess';
        for (const t of trig) lines.push({ text: `${t.detail} · reassess by ${t.due} · ${t.owner}`, tone: 'critical' });
    } else if (a.reassessIso < TODAY) {
        state = 'overdue';
        lines.push({ text: `Support plan review date passed on ${a.reassessBy} — support stays as it is until the reassessment`, tone: 'warning' });
    } else if (days(TODAY, a.reassessIso) <= 30) {
        state = 'soon';
        lines.push({ text: `Reassess by ${a.reassessBy}` });
    } else state = 'current';
    if (unset.length) lines.push({ text: `${unset.length === 1 ? `${unset[0].name} has` : `${unset.length} medicines have`} no support set yet — staff give ${unset.length === 1 ? 'it' : 'them'}`, tone: 'warning' });
    if (agreementMissing) lines.push({ text: 'Agreement needed — the person keeps or takes a medicine themselves', tone: 'warning' });
    return { state, lines, agreementMissing, unset };
}
export const STATE_LABEL: Record<PlanState, { label: string; variant: 'critical' | 'warning' | 'info' | 'success' | 'neutral' }> = {
    reassess: { label: 'Reassess now', variant: 'critical' },
    overdue: { label: 'Review date passed', variant: 'warning' },
    none: { label: 'No assessment', variant: 'warning' },
    soon: { label: 'Reassess soon', variant: 'info' },
    current: { label: 'Up to date', variant: 'success' },
};

/** A consent change asking for more independence (or recorded lower) starts a reassessment follow-up (Q4, Q5). */
export function consentTrigger(c: ConsentChange): TriggerEvent {
    return { id: `tr-${c.id}`, pid: c.pid, kind: 'asked', at: c.at, detail: c.direction === 'less' ? 'Asked staff to do more — support lowered at once' : 'Asked to do more themselves', due: 'Monday 5 October', owner: 'Jordan Tipene' };
}

/** The mix of support across the person’s visible medicines. */
export function mixOf(pid: PersonId, rt: Runtime, persona: PersonaId) {
    const out: Partial<Record<Support | 'unset', number>> = {};
    let hidden = 0;
    for (const m of medsOf(pid)) {
        if (concealed(m, persona)) {
            hidden++;
            continue;
        }
        const s = supportOf(m, rt) ?? 'unset';
        out[s] = (out[s] ?? 0) + 1;
    }
    return { counts: out, hidden };
}
