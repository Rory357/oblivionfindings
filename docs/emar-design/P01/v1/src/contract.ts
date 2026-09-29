/* THE recording contract (P01). Every entry point — Meds today, guided round,
 * as-needed, MAR "Mark given", client profile, Fleet transport — derives what
 * to show and check from `requirementsFor()`, and every save goes through the
 * same outcome rules. Wording is the approved P00 v5 contract unless a P01
 * decision says otherwise (see README "What P01 decides"). Synthetic only. */
import type { StatusVariant } from '@/components/ui/status-badge';
import { NOW_MIN, labelOf } from './clock';
import {
    PEOPLE,
    PERSONAS,
    ROSTERS,
    RULES,
    type Dose,
    type MedRule,
    type PersonaId,
    type PrnOrder,
    type Staff,
    can,
} from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario =
    | 'normal'
    | 'loading'
    | 'empty'
    | 'unavailable'
    | 'stale'
    | 'offline'
    | 'offlineRefused'
    | 'reject'
    | 'uncertain'
    | 'duplicate'
    | 'orderChanged'
    | 'notClockedIn'
    | 'notOnShift'
    | 'siteNotApproved'
    | 'competencyExpired'
    | 'restrictedBlock'
    | 'covertMissing'
    | 'cdNoWitness'
    | 'alone';
export const SCENARIOS: { key: Scenario; label: string; group: string }[] = [
    { key: 'normal', label: 'Normal shift', group: 'Page' },
    { key: 'loading', label: 'Loading', group: 'Page' },
    { key: 'empty', label: 'No work left', group: 'Page' },
    { key: 'unavailable', label: 'Couldn’t load', group: 'Page' },
    { key: 'stale', label: 'Out of date', group: 'Page' },
    { key: 'offline', label: 'Offline — saved on this device', group: 'Saving' },
    { key: 'offlineRefused', label: 'Offline item refused when sent', group: 'Saving' },
    { key: 'reject', label: 'Server refuses the next save', group: 'Saving' },
    { key: 'uncertain', label: 'Save not confirmed', group: 'Saving' },
    { key: 'duplicate', label: 'Someone else recorded it first', group: 'Saving' },
    { key: 'orderChanged', label: 'Order changed mid-round', group: 'Saving' },
    { key: 'notClockedIn', label: 'Not clocked in', group: 'Blocked' },
    { key: 'notOnShift', label: 'Respite guest not on your shift', group: 'Blocked' },
    { key: 'siteNotApproved', label: 'Person moved to a house outside your access', group: 'Blocked' },
    { key: 'competencyExpired', label: 'Competency expired', group: 'Blocked' },
    { key: 'restrictedBlock', label: 'Restricted competency (Block)', group: 'Blocked' },
    { key: 'covertMissing', label: 'Covert authorisation missing', group: 'Blocked' },
    { key: 'cdNoWitness', label: 'Controlled dose — no eligible witness', group: 'Second person' },
    { key: 'alone', label: 'Alone on shift', group: 'Second person' },
];
export type AllergyRule = 'warn' | 'confirm';

/* ───────────── dose states (P00 v5 DOSE vocabulary) ───────────── */
export type Outcome =
    | 'given'
    | 'prompted'
    | 'assisted'
    | 'selfmanaged'
    | 'reoffered'
    | 'refused'
    | 'withheld'
    | 'away';
export type DoseState =
    | 'notdue'
    | 'due'
    | 'late'
    | Outcome
    | 'sending'
    | 'queued'
    | 'rejected'
    | 'uncertain';
export const STATE: Record<DoseState, { label: string; variant: StatusVariant }> = {
    notdue: { label: 'Not yet due', variant: 'neutral' },
    due: { label: 'Due', variant: 'info' },
    late: { label: 'Late', variant: 'warning' },
    given: { label: 'Given', variant: 'success' },
    prompted: { label: 'Taken with prompting', variant: 'success' },
    assisted: { label: 'Taken with assistance', variant: 'success' },
    selfmanaged: { label: 'Self-managed', variant: 'neutral' },
    reoffered: { label: 'Given after re-offer', variant: 'success' },
    refused: { label: 'Refused', variant: 'warning' },
    withheld: { label: 'Withheld', variant: 'warning' },
    away: { label: 'Away', variant: 'neutral' },
    sending: { label: 'Sending…', variant: 'info' },
    queued: { label: 'Saved on this device', variant: 'warning' },
    rejected: { label: 'Not recorded', variant: 'critical' },
    uncertain: { label: 'Not confirmed', variant: 'warning' },
};
export const GIVEN_LIKE: Outcome[] = ['given', 'prompted', 'assisted', 'reoffered'];
export const RECORDED: DoseState[] = ['given', 'prompted', 'assisted', 'reoffered', 'refused', 'withheld', 'away'];

/* ───────────── the dose window (config/medications.php kept — Stephan D4) ───────────── */
export const WINDOW_BEFORE = 30;
export const WINDOW_AFTER = 60;
export function windowOf(slotMin: number) {
    const start = slotMin - WINDOW_BEFORE;
    const end = slotMin + WINDOW_AFTER;
    return { start, end, label: `${labelOf(start)}–${labelOf(end)}` };
}
export function windowState(slotMin: number, now = NOW_MIN): 'notdue' | 'due' | 'late' {
    const w = windowOf(slotMin);
    if (now < w.start) return 'notdue';
    if (now <= w.end) return 'due';
    return 'late';
}
export function lateBy(slotMin: number, now = NOW_MIN) {
    const m = now - slotMin;
    const h = Math.floor(m / 60);
    return h ? `${h} h ${m % 60} min` : `${m} min`;
}

/* ───────────── blocked reasons (P00 v5 BLOCKS; NF-07) ─────────────
 * Tokens: {p} preferred name · {med} medicine · {NC} "Not configured" chip · **bold**. */
export type BlockKey =
    | 'notClockedIn'
    | 'notOnShift'
    | 'siteNotApproved'
    | 'competencyExpired'
    | 'restrictedBlock'
    | 'awaitingVerification'
    | 'covertMissing'
    | 'noWitness'
    | 'prnLimit'
    | 'allergyNotConfirmed';
export interface Block {
    title: string;
    text: string;
    next: string[];
    /** What can still be recorded: none | not-given outcomes | withheld only | free text. */
    still: 'none' | 'notgiven' | 'withheld' | 'withheld-always' | string;
    stillText?: string;
    tone: 'warning' | 'critical';
    safety?: boolean;
    roster?: boolean;
    match?: boolean;
    action?: { label: string; act: 'clock-in' | 'eligibility' | 'ovr-request' | 'message-lead' };
    /** Which organisation decisions this reason depends on and which findings it answers. */
    depends: string[];
    fixes: string[];
}
export const BLOCKS: Record<BlockKey, Block> = {
    notClockedIn: {
        title: 'You’re not clocked in',
        text: 'To record for {p}, you need to be clocked in on a shift that includes {p}.',
        next: ['Clock in from the top bar.', 'Can’t clock in? Contact the coordinator on call: {NC}'],
        still: 'none',
        stillText: 'Nothing can be recorded until you’re clocked in. You can still read the instructions.',
        tone: 'warning',
        action: { label: 'Clock in', act: 'clock-in' },
        depends: ['D2', 'D12'],
        fixes: ['NF-07'],
    },
    notOnShift: {
        title: '{p} isn’t on your shift',
        text: 'You’re clocked in at Kōwhai House (7:00 am–3:00 pm), but your shift doesn’t include {p}.',
        next: ['Ask the house lead to add {p} to your shift. Jordan Tipene is on shift today.', 'Coordinator on call: {NC}'],
        still: 'none',
        stillText: 'You can’t record for {p} until {p} is on your shift.',
        tone: 'warning',
        action: { label: 'Message Jordan Tipene', act: 'message-lead' },
        depends: ['D2', 'D12'],
        fixes: ['NF-07'],
    },
    siteNotApproved: {
        title: 'Your access doesn’t include Rimu House',
        text: '{p} moved to Rimu House at 8:30 am. Your account is approved for Kōwhai House only.',
        next: ['If you work at Rimu House, ask your manager to approve it for your account.', 'Tell the Rimu House lead that {p}’s 9:00 am dose is still to be recorded.'],
        still: 'none',
        stillText: 'You can’t record for people at houses outside your access.',
        tone: 'warning',
        depends: ['D2'],
        fixes: ['NF-07'],
    },
    competencyExpired: {
        title: 'Your medication competency expired on 14 September 2026',
        text: 'You can’t sign doses as given until you’re reassessed.',
        next: ['Ask a competent colleague to give this dose. On shift now and able to give it: **Daniel Ahn**, **Jordan Tipene** (from the roster and who is clocked in).', 'Book a reassessment with your assessor, Hana Kereama.'],
        still: 'notgiven',
        tone: 'critical',
        action: { label: 'View my eligibility', act: 'eligibility' },
        depends: ['D3'],
        fixes: ['EM-03', 'NF-03'],
    },
    restrictedBlock: {
        title: 'You can’t sign doses as given',
        text: 'Your medication competency is restricted (Hana Kereama, 2 March 2026: “supervised practice until reassessed”). A competent colleague must give doses.',
        next: ['Ask a competent colleague to give this dose. On shift now and able to give it: **Daniel Ahn**, **Jordan Tipene** (from the roster and who is clocked in).', 'Ask a competency assessor to review the restriction.'],
        still: 'notgiven',
        tone: 'critical',
        action: { label: 'View my eligibility', act: 'eligibility' },
        depends: ['D3'],
        fixes: ['NF-03'],
    },
    awaitingVerification: {
        title: 'This order is waiting to be checked',
        text: '{med} is a new order from Dr Lena Chen on 27 September. Someone who can check orders must verify it before it’s given.',
        next: ['Ask the house lead to check it. Jordan Tipene is on shift today.'],
        still: 'withheld',
        tone: 'warning',
        action: { label: 'Message Jordan Tipene', act: 'message-lead' },
        depends: ['D2'],
        fixes: ['EM-25'],
    },
    covertMissing: {
        title: 'No current covert plan',
        text: '{med} for {p} is marked to be given covertly (hidden in food or drink, without {p} knowing), but there’s no current authorisation on file.',
        next: ['Don’t give it covertly.', 'Contact the clinical lead, Hana Kereama.'],
        still: 'withheld',
        tone: 'critical',
        depends: ['D2', 'D13'],
        fixes: ['EM-25'],
    },
    noWitness: {
        title: 'No eligible witness on shift',
        text: '{med} is a controlled medicine and needs a witness: a different person, clocked in on a shift covering Kōwhai House now, with controlled-medicine witness competency and a witness PIN set. Checked against the roster and clock-ins at 9:12 am: nobody meets all of them.',
        next: ['Ask a manager for a witness override. They see the same roster and can allow it for a limited time.', 'Or contact the coordinator on call: {NC}', 'Don’t ask someone who isn’t eligible to witness.'],
        still: 'withheld',
        tone: 'warning',
        roster: true,
        action: { label: 'Ask a manager for a witness override', act: 'ovr-request' },
        depends: ['D8', 'D12'],
        fixes: ['EM-03', 'EM-25'],
    },
    prnLimit: {
        title: 'As-needed limit reached',
        text: '{med}: the prescription allows 4 doses in 24 hours. 4 have been given in the last 24 hours — the most recent at 8:05 am.',
        next: ['Don’t give another dose.', 'If {p} still needs relief, contact the prescriber or on-call contact: {NC}', 'If the prescriber advises another dose, record their advice first — who, when and how. Only then can someone authorised to override the limit allow it. A colleague’s PIN is not authority for another dose.'],
        still: 'Add a note to the shift notes about what {p} asked for.',
        tone: 'critical',
        depends: ['D4', 'D12'],
        fixes: ['EM-08', 'EM-26', 'EM-02'],
    },
    allergyNotConfirmed: {
        title: 'Allergy match — the prescriber hasn’t confirmed it',
        text: 'Your organisation allows this only when the prescriber has confirmed the allergy on the order. There’s no confirmation on this order.',
        next: ['Ask the prescriber, Dr Lena Chen, to confirm the allergy on the order — or change the order.', 'Or ask someone authorised to override it. An override needs a reason and is reviewed afterwards.', 'Record this dose as withheld.'],
        still: 'withheld-always',
        tone: 'critical',
        safety: true,
        match: true,
        depends: ['D5', 'D2'],
        fixes: ['EM-07', 'NF-06'],
    },
};
export const STILL: Record<string, string | null> = {
    none: null,
    notgiven: 'You can still record a refusal, a withhold or an absence.',
    withheld: 'You can still record this dose as withheld and say why.',
    'withheld-always': 'You can always record this dose as withheld.',
};
export const fill = (s: string, p: string, med: string) =>
    s.replace(/\{p\}/g, p).replace(/\{med\}/g, med);

/* ───────────── context the contract reads ───────────── */
export interface Ctx {
    persona: PersonaId;
    scenario: Scenario;
    allergyRule: AllergyRule;
    clockedIn: boolean;
    override: 'none' | 'waiting' | 'approved' | 'declined';
}
export const rosterFor = (s: Scenario): Staff[] =>
    s === 'cdNoWitness' ? ROSTERS.nowitness : s === 'alone' ? ROSTERS.alone : ROSTERS.normal;
export function competencyOf(s: Scenario): 'current' | 'expired' | 'restricted' {
    return s === 'competencyExpired' ? 'expired' : s === 'restrictedBlock' ? 'restricted' : 'current';
}

/* Who can be the second person (witness / co-sign / amount confirmation). */
export type SecondKind = 'witness' | 'countersign' | 'amount';
export interface Candidate {
    staff: Staff;
    ok: boolean;
    why: string;
    fallbackOk: boolean;
}
export function candidates(ctx: Ctx, kind: SecondKind): Candidate[] {
    return rosterFor(ctx.scenario)
        .filter((s) => !s.self && s.onShiftNow)
        .map((s) => {
            const reasons: string[] = [];
            if (s.competency !== 'current') reasons.push('competency not current');
            if (kind === 'witness' && !s.witnessCd) reasons.push('no controlled-medicine witness competency');
            const pinOk = s.pin === 'set';
            if (!pinOk) reasons.push(s.pin === 'notset' ? 'no witness PIN set' : s.pin === 'locked' ? 'PIN locked' : 'PIN reset — must set a new one');
            const base = reasons.filter((r) => !r.includes('PIN'));
            return {
                staff: s,
                ok: reasons.length === 0,
                why: reasons.length ? reasons.join(' · ') : 'on shift now · PIN set',
                // Forgotten-PIN fallback: never for controlled drugs (Stephan, D8).
                fallbackOk: kind !== 'witness' && base.length === 0,
            };
        });
}
export const PIN_RULES = {
    attempts: 5,
    lockoutMin: 15,
    confirmWithinMin: 30,
    resetBy: 'house leads and clinical leads',
    fallback: 'Allowed, except for controlled drugs',
    source: 'Stephan’s answers, 29 September 2026 (D8)',
};

/* ───────────── the requirement set for one dose ───────────── */
export interface Requirements {
    kind: 'scheduled' | 'prn';
    window: 'notdue' | 'due' | 'late' | 'prn';
    windowLabel: string;
    /** Stops every outcome (nothing can be recorded). */
    blockAll: BlockKey | null;
    /** Stops "given" (not-given outcomes stay open unless blockAll). */
    blockGiven: BlockKey | null;
    competency: 'current' | 'expired' | 'restricted';
    witness: 'none' | 'needed' | 'override';
    countersignRule: MedRule | null;
    observationRules: MedRule[];
    allergy: { status: string; match: null | { med: string; allergen: string; source: string }; rule: AllergyRule };
    covert: 'none' | 'active' | 'missing';
    variable: boolean;
    support: Dose['support'];
    /** MAR "Mark given" is offered only when every item here is empty. */
    notSimple: string[];
}
const ALLERGY_MATCH: Record<string, { med: string; allergen: string; source: string }> = {
    r3: { med: 'Amoxicillin', allergen: 'penicillin', source: 'health profile' },
};

export function requirementsFor(d: Dose, ctx: Ctx): Requirements {
    const person = PEOPLE[d.pid];
    const win = windowState(d.slotMin);
    const competency = competencyOf(ctx.scenario);
    let blockAll: BlockKey | null = null;
    if (ctx.scenario === 'notClockedIn' && !ctx.clockedIn) blockAll = 'notClockedIn';
    else if (d.pid === 'hine') blockAll = 'notOnShift';
    else if (d.pid === 'ben') blockAll = 'siteNotApproved';

    const covert: Requirements['covert'] = !d.covert ? 'none' : ctx.scenario === 'covertMissing' ? 'missing' : 'active';
    const witnessNeeded = !!d.cd;
    const eligibleWitness = candidates(ctx, 'witness').some((c) => c.ok);
    const overrideOn = witnessNeeded && ctx.scenario === 'cdNoWitness' && ctx.override === 'approved';
    const match = ALLERGY_MATCH[d.id] ?? null;

    let blockGiven: BlockKey | null = null;
    if (!d.order.verified) blockGiven = 'awaitingVerification';
    else if (covert === 'missing') blockGiven = 'covertMissing';
    else if (match && ctx.allergyRule === 'confirm') blockGiven = 'allergyNotConfirmed';
    else if (witnessNeeded && !eligibleWitness && !overrideOn) blockGiven = 'noWitness';

    const rules = (d.rules ?? []).map((r) => RULES[r]);
    const req: Requirements = {
        kind: 'scheduled',
        window: win,
        windowLabel: windowOf(d.slotMin).label,
        blockAll,
        blockGiven,
        competency: d.support === 'administer' ? competency : 'current',
        witness: !witnessNeeded ? 'none' : overrideOn ? 'override' : 'needed',
        countersignRule: rules.find((r) => r.kind === 'countersign') ?? null,
        observationRules: rules.filter((r) => r.kind === 'observation'),
        allergy: { status: person.allergy.status, match, rule: ctx.allergyRule },
        covert,
        variable: !!d.amount.range,
        support: d.support,
        notSimple: [],
    };
    req.notSimple = notSimpleReasons(d, req);
    return req;
}

export function prnRequirements(o: PrnOrder, ctx: Ctx): Requirements {
    const person = PEOPLE[o.pid];
    const competency = competencyOf(ctx.scenario);
    const blockAll: BlockKey | null =
        ctx.scenario === 'notClockedIn' && !ctx.clockedIn ? 'notClockedIn' : o.last24h.length >= o.maxPer24h ? 'prnLimit' : null;
    const eligibleWitness = candidates(ctx, 'witness').some((c) => c.ok);
    return {
        kind: 'prn',
        window: 'prn',
        windowLabel: 'When needed',
        blockAll,
        blockGiven: o.cd && !eligibleWitness ? 'noWitness' : null,
        competency,
        witness: o.cd ? 'needed' : 'none',
        countersignRule: null,
        observationRules: [],
        allergy: { status: person.allergy.status, match: null, rule: ctx.allergyRule },
        covert: 'none',
        variable: !!o.amount.range,
        support: 'administer',
        notSimple: ['As-needed doses always use the full record'],
    };
}

/** Why a dose can't take the MAR one-click "Mark given" (plain words, first reason shown). */
export function notSimpleReasons(d: Dose, r: Requirements): string[] {
    const out: string[] = [];
    if (r.blockAll) out.push(fill(BLOCKS[r.blockAll].title, PEOPLE[d.pid].pref, d.med));
    if (r.blockGiven) out.push(fill(BLOCKS[r.blockGiven].title, PEOPLE[d.pid].pref, d.med));
    if (r.window === 'notdue') out.push(`Window opens ${labelOf(windowOf(d.slotMin).start)}`);
    if (r.window === 'late') out.push('Outside today’s window — needs a reason');
    if (r.competency !== 'current') out.push('Your competency doesn’t cover “given”');
    if (d.cd) out.push('Controlled medicine — needs a witness');
    if (r.observationRules.length || r.countersignRule) out.push('Medication rules ask for a reading and a second person');
    if (d.support !== 'administer') out.push(`Support is ${d.support === 'prompt' ? 'Prompt' : d.support === 'assist' ? 'Assist' : 'Independent'} — record how it was taken`);
    if (r.variable) out.push('Variable amount — choose the amount');
    if (r.covert !== 'none') out.push('Covert — check the covert plan');
    if (r.allergy.match) out.push('Possible allergy match — check first');
    else if (r.allergy.status === 'none') out.push('No allergies recorded — check the health profile first');
    else if (r.allergy.status === 'unavailable') out.push('Allergy record couldn’t be loaded');
    if (d.photo.state === 'changed') out.push('Pack or brand changed — check the label');
    return out;
}

/* ───────────── who on shift can give it (restricted / expired competency) ───────────── */
export function whoCanGive(ctx: Ctx): string[] {
    return rosterFor(ctx.scenario)
        .filter((s) => !s.self && s.onShiftNow && s.competency === 'current')
        .map((s) => s.name);
}

export const personaName = (p: PersonaId) => PERSONAS[p].name;
export const recordsDoses = (p: PersonaId) => can(p, 'administer');

/* ───────────── amounts ───────────── */
export function fmtAmount(n: number | null, a: { unit: string; plural: string; per?: string }): string {
    if (n == null || !Number.isFinite(n)) return '—';
    const whole = Math.floor(n);
    const half = Math.abs(n - whole - 0.5) < 1e-9;
    const num = half ? (whole ? `${whole}½` : '½') : String(n);
    const unit = n <= 1 ? a.unit : a.plural;
    const mg = a.per ? /^(\d+(?:\.\d+)?)\s*(.*)$/.exec(a.per) : null;
    const strength = mg ? ` (${+(n * Number(mg[1])).toFixed(3)} ${mg[2]})` : '';
    return `${num} ${unit}${strength}`;
}
export const AMOUNT_REASONS = ['Only part taken', 'Dropped or spilled', 'Vomited soon after', 'Other'];

/* ───────────── one reason list (NotGivenReason enum, plain labels) ───────────── */
export const WITHHELD_REASONS = [
    { value: 'doctors_instruction', label: 'Doctor’s instruction' },
    { value: 'fasting', label: 'Fasting' },
    { value: 'vomit_or_nausea', label: 'Vomit or nausea' },
    { value: 'medication_unavailable', label: 'Medication unavailable' },
    { value: 'withheld', label: 'Safety concern — not safe to give' },
    { value: 'other', label: 'Other (say what happened)' },
];
export const AWAY_REASONS = [
    { value: 'absent', label: 'Out (day programme, appointment or with family)' },
    { value: 'social_leave', label: 'Social leave' },
    { value: 'hospitalised', label: 'In hospital' },
    { value: 'transferred', label: 'Transferred' },
];
export const LATE_REASONS = [
    'Person was out or asleep at the time',
    'Waiting for a second person',
    'Staff were supporting someone else',
    'Other',
];
