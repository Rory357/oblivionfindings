/* P07b rules — the append-only controlled register, reasoned changes,
 * discrepancy resolution, losses, one destruction path and the witness
 * overrides view. Decisions: Main’s answers under Stephan’s delegation, 30
 * September (README Q1–Q10), P07a v1 (cadence, discrepancy ownership, the
 * override follow-up), P06 Q9 (the controlled receipt is P06’s register
 * entry) and PIN-1 (the witness PIN, now on main). Synthetic only. */
import { DESTRUCTIONS, DISCREPANCIES, ENTRIES, LOSSES, MEDS, OVERRIDES, PEOPLE, PERSONAS, WITNESSES, type CdMedicine, type Destruction, type Discrepancy, type Entry, type House, type Loss, type Override, type PersonaId, type Witness } from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'Nothing in the register yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export interface Runtime {
    entries: Entry[];
    voids: Record<string, Entry['voided']>;
    classes: Record<string, CdMedicine['cls']>;
    discrepancies: Record<string, Partial<Discrepancy>>;
    losses: Loss[];
    lossPatch: Record<string, Partial<Loss>>;
    destructions: Destruction[];
    destructionPatch: Record<string, Partial<Destruction>>;
    overridePatch: Record<string, Partial<Override>>;
}
export const EMPTY_RT: Runtime = { entries: [], voids: {}, classes: {}, discrepancies: {}, losses: [], lossPatch: {}, destructions: [], destructionPatch: {}, overridePatch: {} };

/* ───────────── who can do what (Main, Q10) ───────────── */
const can = (p: PersonaId, k: Parameters<typeof PERSONAS.sw.perms.includes>[0]) => PERSONAS[p].perms.includes(k);
export const cdView = (p: PersonaId) => can(p, 'cd.view');
/** Record entries, witness, report a loss, return for destruction: controlled.record. */
export const canRecord = (p: PersonaId) => can(p, 'cd.record');
/** Resolve, void, reasoned adjustment, destruction sign-off: the new medications.controlled.manage. */
export const canManage = (p: PersonaId) => can(p, 'cd.manage') && cdView(p);
/** Close a loss: managers. */
export const canCloseLoss = (p: PersonaId) => can(p, 'manager');
/** Grant a witness override: controlled.override, and nothing else (Q7). */
export const canGrantOverride = (p: PersonaId) => can(p, 'cd.override');
export const WHO_MANAGES = 'house leads, clinical leads and managers (controlled.manage)';

/* ───────────── the register ───────────── */
export const medOf = (id: string) => MEDS.find((m) => m.id === id)!;
export const classOf = (m: CdMedicine, rt: Runtime) => (m.id in rt.classes ? rt.classes[m.id] : m.cls);
/** “Mon 28 Sep, 7:00 am” → a sortable number (the fixtures are all 2026). */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function whenKey(at: string): number {
    const m = /(\d+) (\w{3}), (\d+):(\d+) (am|pm)/.exec(at);
    if (!m) return 0;
    const h = (Number(m[3]) % 12) + (m[5] === 'pm' ? 12 : 0);
    return ((MONTHS.indexOf(m[2]) * 31 + Number(m[1])) * 24 + h) * 60 + Number(m[4]);
}
/** Newest first, across medicines. */
export const allEntries = (rt: Runtime): Entry[] => [...rt.entries, ...ENTRIES].map((e) => (rt.voids[e.id] ? { ...e, voided: rt.voids[e.id] } : e)).sort((a, b) => whenKey(b.at) - whenKey(a.at));
export const entriesOf = (m: CdMedicine, rt: Runtime) => allEntries(rt).filter((e) => e.medId === m.id);
/** The balance after the newest entry that isn’t voided. */
export const balanceOf = (m: CdMedicine, rt: Runtime) => entriesOf(m, rt).find((e) => !e.voided)?.after ?? 0;
export const medsIn = (houses: House[]) => MEDS.filter((m) => houses.includes(PEOPLE[m.pid].house));
/** +2 · −1 · 0, with a real minus sign. */
export const signed = (n: number) => (n > 0 ? `+${n}` : n < 0 ? `−${Math.abs(n)}` : '0');
export const unitLabel = (m: CdMedicine, n: number) => `${n} ${Math.abs(n) === 1 ? m.unit.replace(/s$/, '') : m.unit}`;

/* ───────────── discrepancies (Q4) ───────────── */
export const allDiscrepancies = (rt: Runtime): Discrepancy[] => DISCREPANCIES.map((d) => ({ ...d, ...rt.discrepancies[d.id] }) as Discrepancy);
/** Never resolved by someone who did the count (Q4). */
export function whyCantResolve(d: Discrepancy, p: PersonaId): string | null {
    const me = PERSONAS[p].name;
    if (!canManage(p)) return `Discrepancies are resolved by ${WHO_MANAGES}.`;
    if (d.countedBy === me || d.witness === me) return `You ${d.countedBy === me ? 'counted' : 'witnessed the count'} — someone who didn’t resolves it.`;
    return null;
}
export const OUTCOME_LABEL: Record<NonNullable<Discrepancy['resolved']>['outcome'], string> = {
    recount: 'Recount matched — a counting slip',
    recording: 'Recording error — entry voided and corrected',
    found: 'Stock found — added back',
    loss: 'Unexplained loss — loss report',
    escalate: 'Escalated to a manager',
};
/** A loss is never shown as a success (Main, 30 Sep): warning for a loss, neutral for the rest. */
export const OUTCOME_TONE: Record<NonNullable<Discrepancy['resolved']>['outcome'], 'warning' | 'neutral'> = {
    recount: 'neutral',
    recording: 'neutral',
    found: 'neutral',
    loss: 'warning',
    escalate: 'neutral',
};

/* ───────────── losses (Q5) ───────────── */
export const allLosses = (rt: Runtime): Loss[] => [...rt.losses, ...LOSSES].map((l) => ({ ...l, ...rt.lossPatch[l.id] }) as Loss);
export const LOSS_STATE: Record<Loss['status'], { label: string; variant: 'warning' | 'info' | 'neutral' }> = {
    investigating: { label: 'Investigating', variant: 'warning' },
    awaitingClose: { label: 'Waiting for a manager to close', variant: 'info' },
    closed: { label: 'Closed', variant: 'neutral' },
};

/* ───────────── destructions (Q6) ───────────── */
export const allDestructions = (rt: Runtime): Destruction[] => [...rt.destructions, ...DESTRUCTIONS].map((d) => ({ ...d, ...rt.destructionPatch[d.id] }) as Destruction);
export function destructionState(d: Destruction): { label: string; variant: 'warning' | 'neutral' } {
    if (d.voided) return { label: 'Voided', variant: 'neutral' };
    if (d.method === 'return' && !d.received) return { label: 'Waiting for the pharmacist’s receipt', variant: 'warning' };
    return { label: d.method === 'return' ? 'Received by the pharmacy' : 'Destroyed on site', variant: 'neutral' };
}

/* ───────────── witness overrides (Q7) ───────────── */
export const allOverrides = (rt: Runtime): Override[] => OVERRIDES.map((o) => ({ ...o, ...rt.overridePatch[o.id] }) as Override);
export function overrideState(o: Override): { label: string; variant: 'critical' | 'warning' | 'info' | 'success' | 'neutral' } {
    if (o.decision.state === 'waiting') return { label: 'Waiting for a manager', variant: 'warning' };
    if (o.decision.state === 'declined') return { label: 'Declined', variant: 'neutral' };
    if (o.followUp?.done) return { label: 'Signed off', variant: 'success' };
    if (o.followUp?.overdue) return { label: 'Sign-off overdue', variant: 'critical' };
    return { label: 'Count and sign-off due', variant: 'info' };
}

/* ───────────── witnesses (Q8, PIN-1) ───────────── */
export function witnessProblem(w: Witness, recorder: string): string | null {
    if (w.name === recorder) return 'you can’t witness your own entry';
    if (w.restricted) return 'restricted competency — can’t witness controlled medicines';
    if (w.pin === 'locked') return 'witness PIN locked';
    if (w.pin === 'not_set') return 'no witness PIN set';
    return null;
}
export const witnessesAt = (h: House) => WITNESSES.filter((w) => w.house === h);
