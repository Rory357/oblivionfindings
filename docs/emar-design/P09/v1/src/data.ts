/* Synthetic fixtures for eMAR P09 v1 — Reports & audit. No real people,
 * prescribers or clinical values: medicines, doses and times are synthetic
 * ORDER data, and nothing here becomes policy by being shown. People, houses,
 * staff and orders come from the approved P02–P08b fixtures; errors from
 * P08b v1.1; incidents and registers from P07b v1.1.
 * The day: Monday 28 September 2026; the moment: 9:12 am NZDT. Dose slots are
 * generated for July–September from the orders (one rule, `slotOutcome`), so
 * every report, the audit trail and the builder read the same records. */

/* ───────────── personas (seeded roles; P02–P08b names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu' | 'finance';
/** Mockup permission names; the build keys are in README build note 5. */
export type Perm = 'record' | 'cd.view' | 'reports.view' | 'reports.export' | 'audit.view' | 'audit.export' | 'stock.only' | 'errors.manage' | 'incidents.approve' | 'settings.org';
export type House = 'kowhai' | 'rimu';
export const HOUSES: Record<House, string> = { kowhai: 'Kōwhai House', rimu: 'Rimu House' };
export interface Persona {
    id: PersonaId;
    name: string;
    initials: string;
    role: string;
    houses: House[];
    perms: Perm[];
}
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: ['record', 'cd.view'] },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House · views reports', houses: ['kowhai'], perms: ['record', 'cd.view', 'reports.view', 'errors.manage'] },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no controlled-medicine keys', houses: ['kowhai', 'rimu'], perms: ['record', 'reports.view', 'audit.view', 'errors.manage', 'settings.org'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only, exports the audit trail', houses: ['kowhai', 'rimu'], perms: ['reports.view', 'audit.view', 'audit.export'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager · exports and closes incidents', houses: ['kowhai', 'rimu'], perms: ['record', 'cd.view', 'reports.view', 'reports.export', 'audit.view', 'audit.export', 'errors.manage', 'incidents.approve', 'settings.org'] },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: ['record', 'cd.view', 'reports.view', 'errors.manage'] },
    finance: { id: 'finance', name: 'Leilani Faleolo', initials: 'LF', role: 'Finance · stock only', houses: ['kowhai', 'rimu'], perms: ['stock.only', 'reports.export'] },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => !has(p, 'reports.view') && !has(p, 'stock.only');

/* ───────────── people ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'ben' | 'hemi';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'ben', 'hemi'];
export interface Person {
    id: PersonId;
    pref: string;
    legal: string;
    house: House;
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', pref: 'Aroha', legal: 'Aroha Mere Ngata', house: 'kowhai' },
    tama: { id: 'tama', pref: 'Tama', legal: 'Tamati James Walker', house: 'kowhai' },
    mele: { id: 'mele', pref: 'Mele', legal: 'Mele Fifita', house: 'kowhai' },
    grace: { id: 'grace', pref: 'Grace', legal: 'Grace Liu', house: 'kowhai' },
    sam: { id: 'sam', pref: 'Sam', legal: 'Samuel Tuilagi', house: 'kowhai' },
    ben: { id: 'ben', pref: 'Ben', legal: 'Benjamin Clarke', house: 'rimu' },
    hemi: { id: 'hemi', pref: 'Hemi', legal: 'Hemi Rāwiri', house: 'rimu' },
};

/* ───────────── orders (P04 v1; times as ordered) ───────────── */
export interface Order {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    times: string[];
    cd?: boolean;
    prn?: boolean;
    from?: string;
    to?: string;
    ceased?: string;
}
export const ORDERS: Order[] = [
    { id: 'o-metformin', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', times: ['08:00', '12:00'] },
    { id: 'o-losartan', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', times: ['09:00'] },
    { id: 'o-insulin', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', times: ['09:00'] },
    { id: 'o-methylphenidate', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', times: ['12:00'], cd: true },
    { id: 'o-levetiracetam', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', times: ['08:00', '20:00'] },
    { id: 'o-midazolam', pid: 'tama', med: 'Midazolam', strength: '5 mg/mL buccal syringe', times: [], cd: true, prn: true },
    { id: 'o-omeprazole', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', times: ['09:00'] },
    { id: 'o-amoxicillin', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', times: ['08:00', '14:00', '20:00'] },
    { id: 'o-sertraline', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', times: ['08:00'] },
    { id: 'o-levothyroxine', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', times: ['09:00'] },
    { id: 'o-clonazepam', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', times: ['09:00'], cd: true },
    { id: 'o-melatonin', pid: 'sam', med: 'Melatonin', strength: '3 mg modified-release tablet', times: ['20:30'] },
    { id: 'o-cetirizine', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', times: ['08:00'] },
    { id: 'o-amlodipine', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', times: ['09:00'] },
    { id: 'o-oxycodone', pid: 'ben', med: 'Oxycodone', strength: '5 mg tablet', times: ['07:00', '20:00'], cd: true },
    { id: 'o-paracetamol-ben', pid: 'ben', med: 'Paracetamol', strength: '500 mg tablet', times: [], prn: true },
    { id: 'o-paracetamol-hemi', pid: 'hemi', med: 'Paracetamol', strength: '500 mg tablet', times: [], prn: true },
    // Ceased in August — still on August’s MAR (Q8: ceased medicines stay on historical MARs)
    { id: 'o-ferrous', pid: 'aroha', med: 'Ferrous sulfate', strength: '325 mg tablet', times: ['08:00'], to: '2026-08-20', ceased: 'Stopped 20 Aug by Dr Lena Chen (synthetic)' },
];
export const orderOf = (id: string) => ORDERS.find((o) => o.id === id)!;

/* ───────────── dose slots (the one projection every number reads — Q2) ───────────── */
/** A scheduled dose’s outcome. `notRecorded` = its window ended with nothing recorded (an unrecorded dose). */
export type Outcome = 'given' | 'refused' | 'withheld' | 'missed' | 'notRecorded';
export const STAFF_KOWHAI = ['Priya Shah', 'Daniel Ahn', 'Mere Kahu', 'Jordan Tipene'];
export const STAFF_RIMU = ['Ana Lemalu', 'Sione Taufa'];
/** A deterministic hash (FNV-1a) — synthetic outcomes, the same on every screen. */
export function fnv(s: string) {
    let h = 0x811c9dc5;
    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 0x01000193) >>> 0;
    }
    return h >>> 0;
}
/** Recorded exceptions that the P08a/P08b fixtures describe. */
export const SLOT_OVERRIDES: Record<string, { outcome: Outcome; late?: boolean; note?: string; by?: string; at?: string; unwitnessed?: boolean }> = {
    'o-levetiracetam|2026-09-27|20:00': { outcome: 'missed', by: 'Daniel Ahn', at: '21:35', note: 'Reported as MED-0047' },
    'o-cetirizine|2026-09-21|08:00': { outcome: 'given', late: true, by: 'Priya Shah', at: '11:00', note: 'Given at 8:00, recorded at 11:00 — MED-0044' },
    'o-melatonin|2026-09-16|20:30': { outcome: 'missed', by: 'Mere Kahu', at: '21:10', note: 'Reported as MED-0041' },
    'o-amlodipine|2026-08-19|09:00': { outcome: 'missed', by: 'Ana Lemalu', at: '10:15', note: 'Reported as MED-0037' },
    'o-levetiracetam|2026-09-10|08:00': { outcome: 'given', late: true, by: 'Mere Kahu', at: '09:20', note: 'Given at the wrong time — MED-0040' },
    'o-clonazepam|2026-09-26|09:00': { outcome: 'given', by: 'Mere Kahu', at: '09:02' },
    'o-clonazepam|2026-09-27|09:00': { outcome: 'given', by: 'Priya Shah', at: '09:00' },
    'o-methylphenidate|2026-09-24|12:00': { outcome: 'given', by: 'Mere Kahu', at: '12:00' },
    'o-methylphenidate|2026-09-25|12:00': { outcome: 'given', by: 'Priya Shah', at: '12:10', note: 'Recorded as 2 tablets; corrected to 1 at 4:30 pm' },
    'o-methylphenidate|2026-09-26|12:00': { outcome: 'given', by: 'Mere Kahu', at: '12:05' },
    'o-oxycodone|2026-09-27|20:00': { outcome: 'given', by: 'Ana Lemalu', at: '20:00' },
    'o-oxycodone|2026-09-28|07:00': { outcome: 'given', by: 'Sione Taufa', at: '07:05', unwitnessed: true, note: 'Given under witness override OV-9 — no second person on shift' },
    'o-losartan|2026-09-14|09:00': { outcome: 'notRecorded' },
    'o-sertraline|2026-09-24|08:00': { outcome: 'notRecorded' },
    'o-amlodipine|2026-09-19|09:00': { outcome: 'notRecorded' },
    'o-melatonin|2026-08-11|20:30': { outcome: 'notRecorded' },
};
export function slotOutcome(orderId: string, day: string, time: string): { outcome: Outcome; late: boolean; by: string; at?: string; unwitnessed?: boolean; note?: string } {
    const key = `${orderId}|${day}|${time}`;
    const h = fnv(key);
    const house = PEOPLE[orderOf(orderId).pid].house;
    const staff = house === 'kowhai' ? STAFF_KOWHAI : STAFF_RIMU;
    const by = staff[h % staff.length];
    const o = SLOT_OVERRIDES[key];
    if (o) return { outcome: o.outcome, late: !!o.late, by: o.by ?? by, at: o.at, unwitnessed: o.unwitnessed, note: o.note };
    const r = h % 1000;
    const outcome: Outcome = r < 962 ? 'given' : r < 983 ? 'refused' : 'withheld';
    return { outcome, late: outcome === 'given' && h % 31 === 0, by };
}

/* ───────────── as-needed doses (PRN) ───────────── */
export interface PrnDose {
    orderId: string;
    day: string;
    at: string;
    by: string;
    effect?: string;
}
export const PRN_DOSES: PrnDose[] = [
    { orderId: 'o-midazolam', day: '2026-09-12', at: '3:40 pm', by: 'Daniel Ahn', effect: 'Seizure stopped within 4 minutes' },
    { orderId: 'o-paracetamol-ben', day: '2026-09-03', at: '7:10 pm', by: 'Ana Lemalu', effect: 'Headache eased' },
    { orderId: 'o-paracetamol-ben', day: '2026-09-15', at: '10:20 am', by: 'Ana Lemalu', effect: 'Knee pain eased' },
    { orderId: 'o-paracetamol-ben', day: '2026-09-26', at: '2:05 pm', by: 'Sione Taufa' },
    { orderId: 'o-paracetamol-hemi', day: '2026-09-09', at: '9:30 pm', by: 'Ana Lemalu', effect: 'Toothache eased' },
    { orderId: 'o-paracetamol-hemi', day: '2026-08-22', at: '8:15 am', by: 'Sione Taufa', effect: 'Settled' },
];

/* ───────────── controlled medicines (P07a / P07b) ───────────── */
export const CD_EVENTS = {
    /** Witnessed counts at each shift change (P07b: 7:00 am and 7:00 pm; Rimu ten minutes later). */
    counts: { kowhai: { times: ['07:00', '19:00'], by: ['Priya Shah', 'Daniel Ahn'] }, rimu: { times: ['07:10', '19:10'], by: ['Ana Lemalu', 'Sione Taufa'] } },
    discrepancies: [
        { id: 'D-14', orderId: 'o-clonazepam', day: '2026-09-28', hm: '07:00', by: 'Priya Shah', state: 'open' as const, what: 'Counted 21, expected 22 — witnessed by Daniel Ahn' },
        { id: 'D-12', orderId: 'o-midazolam', day: '2026-09-25', hm: '15:30', by: 'Mere Kahu', state: 'closed' as const, what: 'Counted 5, expected 6 — closed as loss L-7' },
        { id: 'D-9', orderId: 'o-methylphenidate', day: '2026-09-24', hm: '15:00', by: 'Jordan Tipene', state: 'closed' as const, what: 'Recount matched — a counting slip' },
    ],
    losses: [{ id: 'L-7', orderId: 'o-midazolam', day: '2026-09-25', hm: '16:10', by: 'Jordan Tipene', state: 'waiting' as const, what: 'One syringe missing at the 3:30 pm count — unexplained' }],
    destructions: [
        { id: 'DS-21', orderId: 'o-midazolam', day: '2026-09-28', hm: '08:45', by: 'Jordan Tipene', what: '1 expired syringe returned to Kōwhai Pharmacy' },
        { id: 'DS-19', orderId: 'o-clonazepam', day: '2026-08-25', hm: '14:00', by: 'Jordan Tipene', what: '6 tablets returned to Kōwhai Pharmacy — stopped by the prescriber' },
    ],
};

/* ───────────── medication errors (P08b v1.1 fixtures, plus MED-0049) ───────────── */
export type ErrType = 'wrongTime' | 'omission' | 'wrongDose' | 'wrongMedicine' | 'wrongPerson' | 'wrongRoute' | 'recorded' | 'other';
export const TYPE_LABEL: Record<ErrType, string> = {
    wrongTime: 'Given at the wrong time',
    omission: 'A dose was missed',
    wrongDose: 'The wrong amount',
    wrongMedicine: 'The wrong medicine',
    wrongPerson: 'Given to the wrong person',
    wrongRoute: 'Given the wrong way',
    recorded: 'Recorded wrongly',
    other: 'Something else',
};
export type Reach = 'no' | 'yes' | 'unsure';
export type Harm = 'none' | 'minor' | 'moderate' | 'severe' | 'death' | 'unknown';
export const HARM_LABEL: Record<Harm, string> = { none: 'No harm', minor: 'Minor or temporary harm', moderate: 'Moderate harm', severe: 'Severe or permanent harm', death: 'Death', unknown: 'Harm not known yet' };
export type Sac = 1 | 2 | 3 | 4;
export interface MedError {
    id: string;
    pid: PersonId;
    orderIds: string[];
    type: ErrType;
    occurred: string;
    occurredIso: string;
    reached: Reach;
    harm: Harm;
    stage: 'triage' | 'investigating' | 'actions' | 'closed';
    owner?: string;
    reportedBy: string;
    /** When it was reported — P08b’s time, NZ. */
    reportedIso: string;
    reportedHm: string;
    openActions: number;
    told: boolean;
    incident?: string;
    closed?: { by: string; at: string; note: string; sac?: Sac };
}
export const ERRORS: MedError[] = [
    { id: 'MED-0048', pid: 'grace', orderIds: ['o-clonazepam'], type: 'wrongTime', occurred: 'Mon 28 Sep, 8:05 am', occurredIso: '2026-09-28', reached: 'yes', harm: 'none', stage: 'triage', reportedBy: 'Priya Shah', reportedIso: '2026-09-28', reportedHm: '08:40', openActions: 0, told: false },
    { id: 'MED-0047', pid: 'tama', orderIds: ['o-levetiracetam'], type: 'omission', occurred: 'Sun 27 Sep, 8:00 pm', occurredIso: '2026-09-27', reached: 'yes', harm: 'minor', stage: 'investigating', owner: 'Jordan Tipene', reportedBy: 'Daniel Ahn', reportedIso: '2026-09-27', reportedHm: '21:40', openActions: 0, told: true },
    { id: 'MED-0045', pid: 'aroha', orderIds: ['o-insulin'], type: 'wrongDose', occurred: 'Fri 25 Sep, 9:05 am', occurredIso: '2026-09-25', reached: 'yes', harm: 'moderate', stage: 'actions', owner: 'Jordan Tipene', reportedBy: 'Priya Shah', reportedIso: '2026-09-25', reportedHm: '09:10', openActions: 1, told: true, incident: 'INC-2236' },
    { id: 'MED-0044', pid: 'sam', orderIds: ['o-cetirizine'], type: 'recorded', occurred: 'Mon 21 Sep, 8:00 am', occurredIso: '2026-09-21', reached: 'yes', harm: 'none', stage: 'actions', owner: 'Jordan Tipene', reportedBy: 'Priya Shah', reportedIso: '2026-09-21', reportedHm: '11:05', openActions: 0, told: true },
    { id: 'MED-0043', pid: 'mele', orderIds: [], type: 'wrongPerson', occurred: 'Mon 21 Sep, 8:00 am', occurredIso: '2026-09-21', reached: 'no', harm: 'none', stage: 'closed', owner: 'Jordan Tipene', reportedBy: 'Mere Kahu', reportedIso: '2026-09-21', reportedHm: '08:15', openActions: 0, told: false, closed: { by: 'Jordan Tipene', at: 'Tue 22 Sep, 11:30 am', note: 'Near miss — caught at the name check. Shelves separated.' } },
    { id: 'MED-0042', pid: 'aroha', orderIds: ['o-metformin', 'o-losartan'], type: 'wrongMedicine', occurred: 'Mon 21 Sep, 12:00 pm', occurredIso: '2026-09-21', reached: 'yes', harm: 'moderate', stage: 'closed', owner: 'Jordan Tipene', reportedBy: 'Mere Kahu', reportedIso: '2026-09-21', reportedHm: '12:20', openActions: 0, told: true, incident: 'INC-2229', closed: { by: 'Jordan Tipene', at: 'Sat 26 Sep, 10:00 am', note: 'Packs stored in time order; no further harm; Aroha and Wiki told.' } },
    { id: 'MED-0046', pid: 'ben', orderIds: ['o-amlodipine'], type: 'recorded', occurred: 'Sat 26 Sep, 9:00 am', occurredIso: '2026-09-26', reached: 'yes', harm: 'none', stage: 'investigating', owner: 'Sione Taufa', reportedBy: 'Sione Taufa', reportedIso: '2026-09-26', reportedHm: '18:00', openActions: 0, told: false },
    // P09’s addition: a severe-harm error, ready to close, to show the SAC choice (Main, Q11)
    { id: 'MED-0049', pid: 'ben', orderIds: ['o-amlodipine'], type: 'wrongDose', occurred: 'Tue 15 Sep, 9:00 am', occurredIso: '2026-09-15', reached: 'yes', harm: 'severe', stage: 'actions', owner: 'Sione Taufa', reportedBy: 'Ana Lemalu', reportedIso: '2026-09-15', reportedHm: '09:30', openActions: 0, told: true, incident: 'INC-2226' },
    // Older closed reports (P08b)
    { id: 'MED-0041', pid: 'sam', orderIds: ['o-melatonin'], type: 'omission', occurred: 'Wed 16 Sep, 8:30 pm', occurredIso: '2026-09-16', reached: 'yes', harm: 'none', stage: 'closed', reportedBy: 'Mere Kahu', reportedIso: '2026-09-16', reportedHm: '20:30', openActions: 0, told: true, closed: { by: 'Jordan Tipene', at: 'Fri 18 Sep, 3:00 pm', note: 'Looked into and closed. (Synthetic.)' } },
    { id: 'MED-0040', pid: 'tama', orderIds: ['o-levetiracetam'], type: 'wrongTime', occurred: 'Thu 10 Sep, 8:00 am', occurredIso: '2026-09-10', reached: 'yes', harm: 'none', stage: 'closed', reportedBy: 'Mere Kahu', reportedIso: '2026-09-10', reportedHm: '08:00', openActions: 0, told: true, closed: { by: 'Jordan Tipene', at: 'Mon 14 Sep, 10:00 am', note: 'Looked into and closed. (Synthetic.)' } },
    { id: 'MED-0039', pid: 'mele', orderIds: ['o-amoxicillin'], type: 'recorded', occurred: 'Fri 4 Sep, 2:00 pm', occurredIso: '2026-09-04', reached: 'no', harm: 'none', stage: 'closed', reportedBy: 'Mere Kahu', reportedIso: '2026-09-04', reportedHm: '14:00', openActions: 0, told: false, closed: { by: 'Jordan Tipene', at: 'Mon 7 Sep, 11:00 am', note: 'Looked into and closed. (Synthetic.)' } },
    { id: 'MED-0038', pid: 'aroha', orderIds: ['o-metformin'], type: 'wrongDose', occurred: 'Thu 27 Aug, 12:00 pm', occurredIso: '2026-08-27', reached: 'yes', harm: 'minor', stage: 'closed', reportedBy: 'Mere Kahu', reportedIso: '2026-08-27', reportedHm: '12:00', openActions: 0, told: true, closed: { by: 'Jordan Tipene', at: 'Wed 2 Sep, 4:00 pm', note: 'Looked into and closed. (Synthetic.)' } },
    { id: 'MED-0037', pid: 'ben', orderIds: ['o-amlodipine'], type: 'omission', occurred: 'Wed 19 Aug, 9:00 am', occurredIso: '2026-08-19', reached: 'yes', harm: 'none', stage: 'closed', reportedBy: 'Ana Lemalu', reportedIso: '2026-08-19', reportedHm: '09:00', openActions: 0, told: true, closed: { by: 'Sione Taufa', at: 'Fri 21 Aug, 9:30 am', note: 'Looked into and closed. (Synthetic.)' } },
    { id: 'MED-0036', pid: 'grace', orderIds: ['o-sertraline'], type: 'wrongTime', occurred: 'Wed 12 Aug, 8:00 am', occurredIso: '2026-08-12', reached: 'no', harm: 'none', stage: 'closed', reportedBy: 'Mere Kahu', reportedIso: '2026-08-12', reportedHm: '08:00', openActions: 0, told: false, closed: { by: 'Jordan Tipene', at: 'Thu 13 Aug, 2:00 pm', note: 'Looked into and closed. (Synthetic.)' } },
];

/* ───────────── medication reviews (P05 v1.1) ───────────── */
export interface Review {
    id: string;
    pid: PersonId;
    kind: 'Regular' | 'Triggered';
    dueIso: string;
    due: string;
    state: 'booked' | 'recorded';
    doneIso?: string;
    changesWaiting: number;
}
export const REVIEWS: Review[] = [
    { id: 'R-24', pid: 'sam', kind: 'Regular', dueIso: '2026-09-20', due: 'Sun 20 Sep', state: 'booked', changesWaiting: 0 },
    { id: 'R-27', pid: 'mele', kind: 'Triggered', dueIso: '2026-09-28', due: 'Mon 28 Sep', state: 'booked', changesWaiting: 0 },
    { id: 'R-28', pid: 'grace', kind: 'Regular', dueIso: '2026-09-14', due: 'Mon 14 Sep', state: 'recorded', doneIso: '2026-09-14', changesWaiting: 2 },
    { id: 'R-26', pid: 'tama', kind: 'Regular', dueIso: '2026-09-08', due: 'Tue 8 Sep', state: 'recorded', doneIso: '2026-09-07', changesWaiting: 1 },
    { id: 'R-31', pid: 'aroha', kind: 'Regular', dueIso: '2026-10-19', due: 'Mon 19 Oct', state: 'booked', changesWaiting: 0 },
    { id: 'R-22', pid: 'ben', kind: 'Regular', dueIso: '2026-09-02', due: 'Wed 2 Sep', state: 'recorded', doneIso: '2026-09-02', changesWaiting: 0 },
    { id: 'R-19', pid: 'aroha', kind: 'Regular', dueIso: '2026-08-10', due: 'Mon 10 Aug', state: 'recorded', doneIso: '2026-08-11', changesWaiting: 0 },
];

/* ───────────── stock (P06 v1, as at today) ───────────── */
export interface StockLine {
    orderId: string;
    onHand: number;
    unit: string;
    perDay: number;
    reorderAt: number;
    expires: string;
    expiresIso: string;
}
export const STOCK: StockLine[] = [
    { orderId: 'o-metformin', onHand: 44, unit: 'tablets', perDay: 2, reorderAt: 28, expires: 'Mar 2027', expiresIso: '2027-03-31' },
    { orderId: 'o-losartan', onHand: 9, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Jan 2027', expiresIso: '2027-01-31' },
    { orderId: 'o-insulin', onHand: 2, unit: 'pens', perDay: 0.1, reorderAt: 1, expires: '20 Oct 2026', expiresIso: '2026-10-20' },
    { orderId: 'o-methylphenidate', onHand: 18, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Jun 2027', expiresIso: '2027-06-30' },
    { orderId: 'o-levetiracetam', onHand: 5, unit: 'tablets', perDay: 2, reorderAt: 28, expires: 'Feb 2027', expiresIso: '2027-02-28' },
    { orderId: 'o-midazolam', onHand: 4, unit: 'syringes', perDay: 0, reorderAt: 2, expires: '12 Oct 2026', expiresIso: '2026-10-12' },
    { orderId: 'o-omeprazole', onHand: 31, unit: 'capsules', perDay: 1, reorderAt: 14, expires: 'Apr 2027', expiresIso: '2027-04-30' },
    { orderId: 'o-sertraline', onHand: 27, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'May 2027', expiresIso: '2027-05-31' },
    { orderId: 'o-levothyroxine', onHand: 60, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Nov 2026', expiresIso: '2026-11-30' },
    { orderId: 'o-clonazepam', onHand: 20, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Aug 2027', expiresIso: '2027-08-31' },
    { orderId: 'o-melatonin', onHand: 12, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Dec 2026', expiresIso: '2026-12-31' },
    { orderId: 'o-cetirizine', onHand: 40, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Sep 2027', expiresIso: '2027-09-30' },
    { orderId: 'o-amlodipine', onHand: 21, unit: 'tablets', perDay: 1, reorderAt: 14, expires: 'Jul 2027', expiresIso: '2027-07-31' },
    { orderId: 'o-oxycodone', onHand: 9, unit: 'tablets', perDay: 2, reorderAt: 14, expires: 'May 2027', expiresIso: '2027-05-31' },
    { orderId: 'o-paracetamol-ben', onHand: 38, unit: 'tablets', perDay: 0, reorderAt: 10, expires: 'Oct 2027', expiresIso: '2027-10-31' },
    { orderId: 'o-paracetamol-hemi', onHand: 20, unit: 'tablets', perDay: 0, reorderAt: 10, expires: 'Oct 2027', expiresIso: '2027-10-31' },
];
/** What finance sees per line: cost only, no person (Main, Q5). */
export const UNIT_COST: Record<string, number> = { 'o-metformin': 0.06, 'o-losartan': 0.11, 'o-insulin': 21.4, 'o-methylphenidate': 0.52, 'o-levetiracetam': 0.35, 'o-midazolam': 18.9, 'o-omeprazole': 0.09, 'o-sertraline': 0.12, 'o-levothyroxine': 0.07, 'o-clonazepam': 0.14, 'o-melatonin': 0.48, 'o-cetirizine': 0.05, 'o-amlodipine': 0.04, 'o-oxycodone': 0.85, 'o-paracetamol-ben': 0.02, 'o-paracetamol-hemi': 0.02 };

/* ───────────── staff who give medicines (P11 eligibility) ───────────── */
export interface Staff {
    name: string;
    house: House;
    status: 'current' | 'renewal' | 'expired' | 'exempt';
    until: string;
    untilIso: string;
}
export type StaffStatus = Staff['status'];
export const STAFF: Staff[] = [
    { name: 'Priya Shah', house: 'kowhai', status: 'current', until: '14 Mar 2027', untilIso: '2027-03-14' },
    { name: 'Daniel Ahn', house: 'kowhai', status: 'renewal', until: '9 Oct 2026', untilIso: '2026-10-09' },
    { name: 'Mere Kahu', house: 'kowhai', status: 'current', until: '2 Feb 2027', untilIso: '2027-02-02' },
    { name: 'Jordan Tipene', house: 'kowhai', status: 'current', until: '30 Jun 2027', untilIso: '2027-06-30' },
    { name: 'Tui Morgan', house: 'kowhai', status: 'expired', until: '18 Sep 2026', untilIso: '2026-09-18' },
    { name: 'Ana Lemalu', house: 'rimu', status: 'current', until: '11 Jan 2027', untilIso: '2027-01-11' },
    { name: 'Sione Taufa', house: 'rimu', status: 'current', until: '5 May 2027', untilIso: '2027-05-05' },
    { name: 'Kiri Hohaia', house: 'rimu', status: 'exempt', until: '12 Oct 2026', untilIso: '2026-10-12' },
];

/* ───────────── exports made (the audit trail’s “Exports made”) ───────────── */
export interface ExportMade {
    id: string;
    /** The time it was made, NZ (24-hour) — the audit event’s time. */
    hm?: string;
    what: string;
    detail: string;
    by: string;
    at: string;
    day: string;
    purpose: string;
    house: House;
}
export const EXPORTS: ExportMade[] = [
    { id: 'EX-118', what: 'MAR (PDF)', detail: 'Aroha Mere Ngata · August 2026', by: 'Rangi Parata', at: 'Fri 25 Sep, 2:14 pm', day: '2026-09-25', hm: '14:14', purpose: 'Asked for by the person or whānau', house: 'kowhai' },
    { id: 'EX-117', what: 'Audit trail (CSV)', detail: 'Kōwhai House · 1–21 Sep', by: 'Mereana Walsh', at: 'Tue 22 Sep, 10:02 am', day: '2026-09-22', hm: '10:02', purpose: 'Audit or inspection', house: 'kowhai' },
    { id: 'EX-116', what: 'Controlled drug register (PDF)', detail: 'Clonazepam 0.5 mg · Grace Liu', by: 'Rangi Parata', at: 'Mon 21 Sep, 4:40 pm', day: '2026-09-21', hm: '16:40', purpose: 'Looking into an incident', house: 'kowhai' },
    { id: 'EX-115', what: 'Stock (CSV)', detail: 'Both houses · as at 1 Sep', by: 'Leilani Faleolo', at: 'Tue 1 Sep, 9:30 am', day: '2026-09-01', hm: '09:30', purpose: 'Costs and budgeting', house: 'kowhai' },
];

/* ───────────── P11 (the Settings frame) ───────────── */
/** Main, Q7: retention is a P11 addition, built with P09. */
export const RETENTION_LABEL = { y10: '10 years after the person’s last service', y15: '15 years after the person’s last service', y20: '20 years after the person’s last service' };
/** Main, Q11: SAC ratings, off by default; the organisation’s mapping preselects the closer’s choice. */
export const SAC_DEFAULT = { death: 1 as Sac, moderate: 3 as Sac, minor: 4 as Sac, none: 4 as Sac };
/** P11 v5’s change history (All changes), as at P09’s clock — rows dated after 28 Sep are left out. */
export const P11_HISTORY = [
    { id: 'h-6', what: 'Medicine rule paused', from: 'Digoxin — record pulse (active)', to: 'Paused', who: 'Hana Kereama', when: '20 Sep 2026 10:41 am', scope: 'All houses', area: 'Medication rules' },
    { id: 'h-5', what: 'Round template paused — Weekend late breakfast', from: 'Active', to: 'Paused', who: 'Jordan Tipene', when: '14 Sep 2026 4:02 pm', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-4', what: 'Round template retired — Night round', from: 'Active', to: 'Retired', who: 'Jordan Tipene', when: '1 Sep 2026 9:30 am', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-3', what: 'Round template — Morning round, default staff', from: 'Everyone rostered', to: 'Sione Taufa', who: 'Sione Taufa', when: '22 Aug 2026 11:15 am', scope: 'Rimu House', area: 'Rounds & timing' },
];
