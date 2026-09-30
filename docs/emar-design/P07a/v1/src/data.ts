/* Synthetic fixtures for eMAR P07a v1 — no real people, staff, medicines or
 * clinical values. People, staff and houses are carried over from the approved
 * P00 v5, P01 v1 and P11 v5 designs (same names, the P11 v5 competency and PIN
 * states). Balances and amounts are synthetic ORDER/STOCK data, not clinical
 * rules. The day: Monday 28 September 2026; the moment: 2:48 pm NZDT. */

/* ───────────── personas (seeded roles, plan §3) ───────────── */
export type PersonaId = 'sw' | 'mere' | 'tomasi' | 'lead' | 'pm';
export type Perm =
    | 'view'
    | 'administer'
    | 'cd.view'
    | 'cd.record'
    | 'cd.witness'
    | 'orders.verify'
    | 'stock.update'
    | 'audit.view'
    | 'settings.manage'
    | 'witness_override';
export type HouseKey = 'kowhai' | 'rimu';
export const HOUSES: Record<HouseKey, string> = { kowhai: 'Kōwhai House', rimu: 'Rimu House' };

export interface Persona {
    id: PersonaId;
    userId: number;
    name: string;
    short: string;
    initials: string;
    role: string;
    perms: Perm[];
    houses: HouseKey[];
    staffId: string;
    shift: string | null;
}
const CD: Perm[] = ['cd.view', 'cd.record', 'cd.witness'];
const FRONTLINE: Perm[] = ['view', 'administer', ...CD];
const LEAD: Perm[] = [...FRONTLINE, 'orders.verify', 'stock.update', 'audit.view', 'settings.manage'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', userId: 11, name: 'Priya Shah', short: 'Priya S.', initials: 'PS', role: 'Support worker', perms: FRONTLINE, houses: ['kowhai'], staffId: 'priya', shift: '7:00 am–3:00 pm' },
    mere: { id: 'mere', userId: 15, name: 'Mere Kahu', short: 'Mere K.', initials: 'MK', role: 'Support worker', perms: FRONTLINE, houses: ['kowhai'], staffId: 'mere', shift: '7:00 am–3:00 pm' },
    tomasi: { id: 'tomasi', userId: 16, name: 'Tomasi Vea', short: 'Tomasi V.', initials: 'TV', role: 'Support worker (no controlled-medicine access)', perms: ['view', 'administer'], houses: ['kowhai'], staffId: 'tomasi', shift: '3:00 pm–11:00 pm' },
    lead: { id: 'lead', userId: 13, name: 'Jordan Tipene', short: 'Jordan T.', initials: 'JT', role: 'House lead', perms: LEAD, houses: ['kowhai'], staffId: 'jordan', shift: '3:00 pm–11:00 pm' },
    pm: { id: 'pm', userId: 14, name: 'Rangi Parata', short: 'Rangi P.', initials: 'RP', role: 'Provider manager', perms: [...LEAD, 'witness_override'], houses: ['kowhai', 'rimu'], staffId: 'rangi', shift: null },
};
export const can = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
/** Frontline = no lead or manager capability (plan §2.1). Controlled record/witness alone never promotes. */
export const isFrontline = (p: PersonaId) =>
    !(['orders.verify', 'stock.update', 'audit.view', 'settings.manage', 'witness_override'] as Perm[]).some((k) => can(p, k));
/** Controlled checks is shown to people who can record or witness controlled medicines (plan §2.1). */
export const seesControlledChecks = (p: PersonaId) => can(p, 'cd.record') || can(p, 'cd.witness');

/* ───────────── people supported (synthetic) ───────────── */
export interface Person {
    id: string;
    pref: string;
    legal: string;
    surname: string;
    house: HouseKey;
}
export const PEOPLE: Record<string, Person> = {
    aroha: { id: 'aroha', pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', house: 'kowhai' },
    tama: { id: 'tama', pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', house: 'kowhai' },
    mele: { id: 'mele', pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', house: 'kowhai' },
    grace: { id: 'grace', pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', house: 'kowhai' },
    ben: { id: 'ben', pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', house: 'rimu' },
};

/* ───────────── staff on the roster (P11 v5 competency and PIN states) ───────────── */
export type PinState = 'set' | 'notset' | 'locked' | 'reset';
export interface Staff {
    id: string;
    name: string;
    initials: string;
    role: string;
    house: HouseKey;
    shift: string;
    now: string;
    onShiftNow: boolean;
    /** Holds the controlled-medicine witness permission. */
    witnessAccess: boolean;
    competency: 'current' | 'restricted' | 'expired' | 'none' | 'ack';
    /** Passed the controlled drugs area with "can witness controlled drugs" ticked. */
    cdArea: boolean;
    pin: PinState;
    note?: string;
}
export const STAFF: Staff[] = [
    { id: 'priya', name: 'Priya Shah', initials: 'PS', role: 'Support worker', house: 'kowhai', shift: '7:00 am–3:00 pm', now: 'clocked in 7:02 am', onShiftNow: true, witnessAccess: true, competency: 'current', cdArea: true, pin: 'set' },
    { id: 'mere', name: 'Mere Kahu', initials: 'MK', role: 'Support worker', house: 'kowhai', shift: '7:00 am–3:00 pm', now: 'clocked in 6:58 am', onShiftNow: true, witnessAccess: true, competency: 'current', cdArea: false, pin: 'notset' },
    { id: 'jordan', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', house: 'kowhai', shift: '3:00 pm–11:00 pm', now: 'clocked in 2:45 pm', onShiftNow: true, witnessAccess: true, competency: 'current', cdArea: true, pin: 'set' },
    { id: 'tomasi', name: 'Tomasi Vea', initials: 'TV', role: 'Support worker', house: 'kowhai', shift: '3:00 pm–11:00 pm', now: 'clocked in 2:46 pm', onShiftNow: true, witnessAccess: false, competency: 'none', cdArea: false, pin: 'notset' },
    { id: 'leilani', name: 'Leilani Faleolo', initials: 'LF', role: 'Support worker', house: 'kowhai', shift: '3:00 pm–11:00 pm (cover from Rimu House)', now: 'clocked in 2:40 pm', onShiftNow: true, witnessAccess: true, competency: 'restricted', cdArea: true, pin: 'locked', note: 'Supervised practice until reassessed' },
    { id: 'daniel', name: 'Daniel Ahn', initials: 'DA', role: 'Support worker', house: 'kowhai', shift: '11:00 pm–7:00 am (night)', now: 'clocked out 7:08 am', onShiftNow: false, witnessAccess: true, competency: 'current', cdArea: true, pin: 'set' },
    { id: 'sione', name: 'Sione Taufa', initials: 'ST', role: 'House lead', house: 'rimu', shift: '7:00 am–3:00 pm', now: 'clocked in 6:55 am', onShiftNow: true, witnessAccess: true, competency: 'current', cdArea: true, pin: 'set' },
    { id: 'losa', name: 'Losa Tuilagi', initials: 'LT', role: 'Support worker', house: 'rimu', shift: '7:00 am–3:00 pm', now: 'clocked in 7:01 am', onShiftNow: true, witnessAccess: true, competency: 'current', cdArea: true, pin: 'set' },
];
export const staffById = (id: string) => STAFF.find((s) => s.id === id)!;

/* ───────────── controlled medicines held at each house ───────────── */
export interface CdMed {
    id: string;
    house: HouseKey;
    pid: string;
    med: string;
    strength: string;
    unit: string;
    plural: string;
    /** Register balance at 2:48 pm (synthetic stock data). */
    balance: number;
    use: string;
}
export const CD_MEDS: CdMed[] = [
    { id: 'cd1', house: 'kowhai', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', unit: 'tablet', plural: 'tablets', balance: 27, use: 'Scheduled 8:00 am and 2:30 pm' },
    { id: 'cd2', house: 'kowhai', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', unit: 'tablet', plural: 'tablets', balance: 41, use: 'Scheduled 9:00 am and 9:00 pm' },
    { id: 'cd3', house: 'kowhai', pid: 'grace', med: 'Lorazepam', strength: '0.5 mg tablet', unit: 'tablet', plural: 'tablets', balance: 19, use: 'As needed' },
    { id: 'cd4', house: 'rimu', pid: 'ben', med: 'Oxycodone', strength: '5 mg capsule', unit: 'capsule', plural: 'capsules', balance: 12, use: 'Scheduled 8:00 am and 8:00 pm' },
];
export const cdById = (id: string) => CD_MEDS.find((m) => m.id === id)!;
export const qty = (n: number, m: Pick<CdMed, 'unit' | 'plural'>) => `${n} ${n === 1 ? m.unit : m.plural}`;

/* ───────────── shift changes (from each house's roster pattern) ───────────── */
export const SHIFT_CHANGES: Record<HouseKey, string[]> = {
    kowhai: ['7:00 am', '3:00 pm', '11:00 pm'],
    rimu: ['7:00 am', '3:00 pm', '11:00 pm'],
};

/* ───────────── today's doses (schedule, for the reference Schedule view and controlled doses) ───────────── */
export type DoseState = 'given' | 'refused' | 'due' | 'notdue';
export interface Dose {
    id: string;
    house: HouseKey;
    slot: string;
    pid: string;
    med: string;
    strength: string;
    cdMed?: string;
    state: DoseState;
    line: string;
    by?: string;
    at?: string;
    window?: string;
}
export const DOSES: Dose[] = [
    { id: 'd1', house: 'kowhai', slot: '8:00 am', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', state: 'given', at: '8:05 am', by: 'Priya Shah', line: 'Given 8:05 am · Priya S.' },
    { id: 'd2', house: 'kowhai', slot: '8:00 am', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', cdMed: 'cd1', state: 'given', at: '8:06 am', by: 'Priya Shah', line: 'Given 8:06 am · Priya S.' },
    { id: 'd3', house: 'kowhai', slot: '8:00 am', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', state: 'given', at: '8:10 am', by: 'Mere Kahu', line: 'Given 8:10 am · Mere K.' },
    { id: 'd4', house: 'kowhai', slot: '9:00 am', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', cdMed: 'cd2', state: 'given', at: '9:04 am', by: 'Priya Shah', line: 'Given 9:04 am · Priya S.' },
    { id: 'd5', house: 'kowhai', slot: '9:00 am', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', state: 'refused', at: '9:06 am', by: 'Priya Shah', line: 'Refused 9:06 am · Grace said no · follow-up due 3:00 pm' },
    { id: 'd6', house: 'kowhai', slot: '12:00 pm', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', state: 'given', at: '12:04 pm', by: 'Mere Kahu', line: 'Given 12:04 pm · Mere K.' },
    { id: 'd7', house: 'kowhai', slot: '2:00 pm', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', state: 'due', window: '1:30–3:00 pm', line: 'Due now · 2:00 pm · window until 3:00 pm' },
    { id: 'd8', house: 'kowhai', slot: '2:30 pm', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', cdMed: 'cd1', state: 'due', window: '2:00–3:30 pm', line: 'Due now · 2:30 pm · window until 3:30 pm' },
    { id: 'd9', house: 'kowhai', slot: '5:00 pm', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', state: 'notdue', line: 'Due 5:00 pm · window opens 4:30 pm' },
    { id: 'd10', house: 'kowhai', slot: '8:00 pm', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', state: 'notdue', line: 'Due 8:00 pm · window opens 7:30 pm' },
    { id: 'd11', house: 'kowhai', slot: '9:00 pm', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', cdMed: 'cd2', state: 'notdue', line: 'Due 9:00 pm · window opens 8:30 pm' },
    { id: 'r1', house: 'rimu', slot: '8:00 am', pid: 'ben', med: 'Oxycodone', strength: '5 mg capsule', cdMed: 'cd4', state: 'given', at: '8:03 am', by: 'Sione Taufa', line: 'Given 8:03 am · Sione T.' },
    { id: 'r2', house: 'rimu', slot: '8:00 pm', pid: 'ben', med: 'Oxycodone', strength: '5 mg capsule', cdMed: 'cd4', state: 'notdue', line: 'Due 8:00 pm · window opens 7:30 pm' },
];
export const doseById = (id: string) => DOSES.find((d) => d.id === id)!;
/** Grace's as-needed lorazepam — controlled, 0 of 2 in the last 24 hours (synthetic order data). */
export const PRN_CD = { id: 'p1', pid: 'grace', med: 'Lorazepam', strength: '0.5 mg tablet', cdMed: 'cd3', line: 'As needed · 0 of 2 in the last 24 hours' };

/* ───────────── the register (movements), last 24 hours ───────────── */
export type EntryKind = 'count' | 'dose' | 'out' | 'in';
export interface Entry {
    id: string;
    cdMed: string;
    at: string;
    day: 'Today' | 'Sunday';
    kind: EntryKind;
    change: number;
    after: number;
    by: string;
    witness: string | null;
    detail: string;
}
export const ENTRIES: Entry[] = [
    { id: 'e1', cdMed: 'cd2', at: '9:04 am', day: 'Today', kind: 'dose', change: -1, after: 41, by: 'Priya Shah', witness: null, detail: 'Grace’s 9:00 am dose · no witness — override by Rangi Parata' },
    { id: 'e2', cdMed: 'cd1', at: '8:06 am', day: 'Today', kind: 'dose', change: -1, after: 27, by: 'Priya Shah', witness: null, detail: 'Aroha’s 8:00 am dose · no witness — override by Rangi Parata' },
    { id: 'e3', cdMed: 'cd1', at: '7:04 am', day: 'Today', kind: 'count', change: 0, after: 28, by: 'Daniel Ahn', witness: 'Priya Shah', detail: '7:00 am shift-change count · matches' },
    { id: 'e4', cdMed: 'cd2', at: '7:04 am', day: 'Today', kind: 'count', change: 0, after: 42, by: 'Daniel Ahn', witness: 'Priya Shah', detail: '7:00 am shift-change count · matches' },
    { id: 'e5', cdMed: 'cd3', at: '7:04 am', day: 'Today', kind: 'count', change: 0, after: 19, by: 'Daniel Ahn', witness: 'Priya Shah', detail: '7:00 am shift-change count · matches' },
    { id: 'e6', cdMed: 'cd1', at: '11:03 pm', day: 'Sunday', kind: 'count', change: 0, after: 28, by: 'Daniel Ahn', witness: 'Jordan Tipene', detail: '11:00 pm shift-change count · matches' },
    { id: 'e7', cdMed: 'cd2', at: '11:03 pm', day: 'Sunday', kind: 'count', change: 0, after: 42, by: 'Daniel Ahn', witness: 'Jordan Tipene', detail: '11:00 pm shift-change count · matches' },
    { id: 'e8', cdMed: 'cd3', at: '11:03 pm', day: 'Sunday', kind: 'count', change: 0, after: 19, by: 'Daniel Ahn', witness: 'Jordan Tipene', detail: '11:00 pm shift-change count · matches' },
    { id: 'e9', cdMed: 'cd2', at: '9:02 pm', day: 'Sunday', kind: 'dose', change: -1, after: 42, by: 'Jordan Tipene', witness: 'Daniel Ahn', detail: 'Grace’s 9:00 pm dose' },
    { id: 'e10', cdMed: 'cd1', at: '5:40 pm', day: 'Sunday', kind: 'in', change: 5, after: 28, by: 'Jordan Tipene', witness: 'Mere Kahu', detail: 'Came back with Aroha from her whānau · 5 tablets returned' },
    { id: 'e11', cdMed: 'cd1', at: '3:05 pm', day: 'Sunday', kind: 'count', change: 0, after: 23, by: 'Jordan Tipene', witness: 'Priya Shah', detail: '3:00 pm shift-change count · matches' },
    { id: 'e12', cdMed: 'cd2', at: '3:05 pm', day: 'Sunday', kind: 'count', change: 0, after: 43, by: 'Jordan Tipene', witness: 'Priya Shah', detail: '3:00 pm shift-change count · matches' },
    { id: 'e13', cdMed: 'cd3', at: '3:05 pm', day: 'Sunday', kind: 'count', change: 0, after: 19, by: 'Jordan Tipene', witness: 'Priya Shah', detail: '3:00 pm shift-change count · matches' },
    { id: 'e20', cdMed: 'cd4', at: '8:03 am', day: 'Today', kind: 'dose', change: -1, after: 12, by: 'Sione Taufa', witness: 'Losa Tuilagi', detail: 'Ben’s 8:00 am dose' },
    { id: 'e21', cdMed: 'cd4', at: '11:04 pm', day: 'Sunday', kind: 'count', change: 0, after: 13, by: 'Hemi Walker', witness: 'Sione Taufa', detail: '11:00 pm shift-change count · matches' },
];

/* ───────────── the morning override (P01 flow, approved by Rangi Parata) ───────────── */
export const MORNING_OVERRIDE = {
    by: 'Rangi Parata',
    approvedAt: '7:41 am',
    requestedBy: 'Priya Shah',
    requestedAt: '7:36 am',
    from: '7:41 am',
    until: '3:00 pm',
    covers: 'All controlled doses at Kōwhai House',
    reason: 'Nobody else on shift can witness (from the roster)',
    note: 'Mere is on shift but hasn’t passed the controlled drugs area.',
    doses: ['d2', 'd4'],
};

export const RECIPIENTS_TOLD = 'Jordan Tipene (house lead)';
