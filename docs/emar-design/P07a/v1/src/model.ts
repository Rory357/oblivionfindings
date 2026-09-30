/* P07a rules — what Controlled checks shows and what each dialog checks.
 * Wording is the approved P00 v5 / P01 v1 wording unless a P07a decision says
 * otherwise; witness PIN messages mirror PIN-1 (claude/emar-pin-1,
 * WitnessPinService + lib/witness-pin.ts). Synthetic only. */
import { NOW_MIN, minutesOf } from './clock';
import { CD_MEDS, PERSONAS, STAFF, type CdMed, type HouseKey, type PersonaId, type Staff } from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario =
    | 'normal'
    | 'loading'
    | 'noCd'
    | 'unavailable'
    | 'stale'
    | 'offline'
    | 'countOverdue'
    | 'cadenceNotSet'
    | 'discrepancyOpen'
    | 'balanceChanged'
    | 'noWitness'
    | 'notClockedIn';
export const SCENARIOS: { key: Scenario; label: string; group: string }[] = [
    { key: 'normal', label: '3:00 pm shift change (normal)', group: 'Counts' },
    { key: 'countOverdue', label: 'The 7:00 am count was missed', group: 'Counts' },
    { key: 'cadenceNotSet', label: 'Count cadence not configured', group: 'Counts' },
    { key: 'discrepancyOpen', label: 'Discrepancy started at 7:04 am', group: 'Counts' },
    { key: 'balanceChanged', label: 'A dose is recorded during the count', group: 'Counts' },
    { key: 'noWitness', label: 'Nobody on shift can witness', group: 'Witness' },
    { key: 'notClockedIn', label: 'Not clocked in', group: 'Witness' },
    { key: 'loading', label: 'Loading', group: 'Page' },
    { key: 'noCd', label: 'No controlled medicines at the house', group: 'Page' },
    { key: 'unavailable', label: 'Couldn’t load', group: 'Page' },
    { key: 'stale', label: 'Out of date', group: 'Page' },
    { key: 'offline', label: 'Offline', group: 'Page' },
];

/* ───────────── count cadence (Stephan, 30 Sep 2026: every shift change, overdue 1 hour after) ───────────── */
export const CADENCE = {
    label: 'Every shift change',
    overdueAfterMin: 60,
    /** When a shift-change count starts to show as due — the same 30 minutes the dose window opens early (P07a proposal). */
    dueBeforeMin: 30,
    decided: 'Stephan’s decision, 30 September 2026',
};
/** P00 v5 wording for a house with no cadence set. The second sentence ended "from the controlled register" in P00;
 * P07a moves counting into Controlled checks, so it now says "any time" (README deviation 6). */
export const CADENCE_NOT_SET = {
    line: 'How often controlled medicines are counted at Kōwhai House:',
    caption: 'No count is shown as due or overdue until the organisation sets the count cadence. Counts can still be recorded any time.',
};

export type CountState = 'due' | 'overdue' | 'counted' | 'notConfigured' | 'next';
export interface CountStatus {
    state: CountState;
    title: string;
    line: string;
    lastCount: string;
    lastResult: 'matched' | 'discrepancy';
}
/** The shift-change count status for one controlled medicine. */
export function countStatus(m: CdMed, scn: Scenario, counted: Record<string, { at: string; by: string; witness: string; result: 'matched' | 'discrepancy' }>): CountStatus {
    const done = counted[m.id];
    if (m.house === 'rimu') {
        if (done) return { state: 'counted', title: `Counted ${done.at}`, line: `${done.by} with ${done.witness} · ${done.result === 'matched' ? 'matches' : 'discrepancy started'}`, lastCount: `${done.at} · ${short(done.by)} counted, ${short(done.witness)} witnessed`, lastResult: done.result };
        return { state: 'overdue', title: 'Overdue since 8:00 am', line: 'The 7:00 am shift-change count wasn’t recorded · Sione Taufa (house lead) was told at 8:00 am', lastCount: '11:04 pm Sunday · Hemi W. counted, Sione T. witnessed', lastResult: 'matched' };
    }
    const disc = scn === 'discrepancyOpen' && m.id === 'cd1';
    const last = scn === 'countOverdue' ? '11:03 pm Sunday · Daniel A. counted, Jordan T. witnessed' : '7:04 am · Daniel A. counted, Priya S. witnessed';
    if (done) return { state: 'counted', title: `Counted ${done.at}`, line: `${done.by} with ${done.witness} · ${done.result === 'matched' ? 'matches the register' : 'discrepancy started'}`, lastCount: `${done.at} · ${short(done.by)} counted, ${short(done.witness)} witnessed`, lastResult: done.result };
    if (scn === 'cadenceNotSet') return { state: 'notConfigured', title: 'Not configured', line: 'No count is due until the cadence is set', lastCount: last, lastResult: disc ? 'discrepancy' : 'matched' };
    if (scn === 'countOverdue') return { state: 'overdue', title: 'Overdue since 8:00 am', line: 'The 7:00 am count wasn’t recorded · the 3:00 pm count is also due', lastCount: last, lastResult: 'matched' };
    return { state: 'due', title: 'Due now — 3:00 pm shift change', line: 'Overdue after 4:00 pm', lastCount: last, lastResult: disc ? 'discrepancy' : 'matched' };
}
export const short = (name: string) => {
    const [f, l] = name.split(' ');
    return l ? `${f} ${l[0]}.` : f;
};

/* ───────────── roster at a house, adjusted per scenario ───────────── */
export function rosterFor(house: HouseKey, scn: Scenario): Staff[] {
    return STAFF.filter((s) => s.house === house)
        // "Nobody on shift can witness": Leilani's cover shift was cancelled and Jordan hasn't clocked in yet.
        .filter((s) => !(scn === 'noWitness' && s.id === 'leilani'))
        .map((s) => (scn === 'noWitness' && s.id === 'jordan' ? { ...s, onShiftNow: false, now: 'not clocked in yet — running late, messaged 2:40 pm' } : s));
}

/** The override Rangi Parata approved at 7:41 am (P01 flow). In "Nobody on shift can witness" it covered only the morning doses and ended at 9:30 am. */
export function morningOverride(scn: Scenario) {
    return scn === 'noWitness'
        ? { active: false, until: '9:30 am', covers: 'The 8:00 am and 9:00 am controlled doses at Kōwhai House' }
        : { active: true, until: '3:00 pm', covers: 'All controlled doses at Kōwhai House' };
}

/* ───────────── witness eligibility (P00 v5, P11 v5 answers 10–11, PIN-1) ───────────── */
export interface Candidate {
    staff: Staff;
    ok: boolean;
    self: boolean;
    reasons: string[];
    why: string;
}
/** Everyone on shift at the house now, with why each can or can't witness. */
export function candidates(persona: PersonaId, house: HouseKey, scn: Scenario): Candidate[] {
    const me = PERSONAS[persona].staffId;
    return rosterFor(house, scn)
        .filter((s) => s.onShiftNow)
        .map((s) => {
            const self = s.id === me;
            const reasons: string[] = [];
            if (self) reasons.push('you’re counting — the witness must be someone else');
            else {
                if (!s.witnessAccess) reasons.push('no controlled-medicine access');
                if (s.competency === 'none') reasons.push('no medication assessment yet');
                else if (s.competency === 'restricted') reasons.push('competency restricted');
                else if (s.competency === 'expired') reasons.push('competency expired');
                else if (!s.cdArea) reasons.push('controlled drugs area not passed');
                if (s.pin === 'notset') reasons.push('no witness PIN set');
                else if (s.pin === 'locked') reasons.push('witness PIN locked');
                else if (s.pin === 'reset') reasons.push('witness PIN reset — must set a new one');
            }
            return { staff: s, ok: reasons.length === 0, self, reasons, why: reasons.length ? reasons.join(' · ') : `on shift now · ${s.now} · PIN set` };
        });
}
export const eligibleWitnesses = (persona: PersonaId, house: HouseKey, scn: Scenario) => candidates(persona, house, scn).filter((c) => c.ok);

/** Can this persona count at the house right now? (Controlled-medicine record access + clocked in at the house.) */
export function countBlock(persona: PersonaId, scn: Scenario, clockedIn: boolean): null | { title: string; text: string; next: string[] } {
    const p = PERSONAS[persona];
    if (!p.perms.includes('cd.record')) return { title: 'You can’t record controlled-medicine counts', text: 'Your role doesn’t include recording controlled medicines.', next: ['Ask your manager if you need it for your work.'] };
    if (scn === 'notClockedIn' && !clockedIn && persona === 'sw') return { title: 'You’re not clocked in', text: 'To count controlled medicines at Kōwhai House, you need to be clocked in on a shift there.', next: ['Clock in from the top bar.', 'Can’t clock in? Contact the coordinator on call: {NC}'] };
    if (!p.shift) return { title: 'You’re not on shift at this house', text: 'Counts are recorded by staff clocked in at the house, with a witness who is also there.', next: ['The house lead on shift can count: Jordan Tipene (from 3:00 pm).'] };
    return null;
}

/* ───────────── witness PIN (PIN-1 wording, verbatim) ───────────── */
export const PIN = {
    label: 'Their witness PIN',
    help: 'Their own 6-digit witness PIN — not their login password. They type it here, at the medicine cupboard.',
    blank: 'Enter their 6-digit witness PIN.',
    incorrect: 'Incorrect PIN. Repeated wrong attempts lock the PIN.',
    locked: (name: string, at: string) => `${name}’s witness PIN is locked after too many wrong attempts. It unlocks at ${at}, or they can reset it in Settings › Witness PIN.`,
    notSet: (name: string) => `${name} hasn’t set a witness PIN yet. They can set one in Settings › Witness PIN, or choose someone else.`,
    sameServer: 'The witness must be a different eligible staff member.',
    forgottenNotAllowed: 'Not allowed for controlled drugs (your organisation’s setting, Settings › Second-person confirmation).',
    ownPrompt: 'Set your witness PIN so colleagues can choose you as a witness',
    rules: { attempts: 5, lockoutMin: 15, fallback: 'Allowed, except for controlled drugs', source: 'Stephan’s answers, 29 September 2026 (D8)' },
};

/* ───────────── discrepancy start (Stephan, 30 Sep 2026: whoever counts, after a recount) ───────────── */
export const DISCREPANCY = {
    owner: 'Jordan Tipene',
    ownerRole: 'house lead',
    told: 'Jordan Tipene (house lead) is told straight away, in-app. Controlled-medicine alerts only reach people with controlled-medicine access.',
    whatFoundPlaceholder: 'e.g. One strip has an empty blister that isn’t on the chart',
    didPlaceholder: 'What was done straight away to keep the person safe and secure the medicine?',
};

/* ───────────── house-lead follow-up (Stephan, 30 Sep 2026: witnessed count, then sign off) ───────────── */
export const FOLLOW_UP = {
    title: 'Check doses given without a witness',
    due: '11:00 pm',
    dueLine: 'By the end of the next shift (11:00 pm)',
    owner: 'Jordan Tipene',
};

/* ───────────── times ───────────── */
export const isAfter = (label: string) => NOW_MIN >= minutesOf(label);
export const housesOf = (p: PersonaId) => PERSONAS[p].houses;
export const cdAt = (h: HouseKey[]) => CD_MEDS.filter((m) => h.includes(m.house));
