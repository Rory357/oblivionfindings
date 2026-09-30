/* Synthetic fixtures for eMAR P05 v1 — Medication review. No real people,
 * prescribers, pharmacists, practices or clinical values: medicines, doses and
 * recommendations are synthetic ORDER data, and nothing here becomes policy
 * by being shown. People, houses, staff and orders come from the approved
 * P02 v1 / P03 v1 / P04 v1 fixtures. Wiremu (left the service) is new.
 * The day: Monday 28 September 2026; the moment: 9:12 am NZDT. */

/* ───────────── personas (seeded roles; P02–P07b names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'administer' | 'cd.view' | 'reviews.manage' | 'orders.manage' | 'orders.verify' | 'audit.view' | 'settings.org';
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
const FRONTLINE: Perm[] = ['view', 'administer', 'cd.view'];
/** P04 v1’s LEAD, plus the new medications.reviews.manage (Main, Q9). */
const LEAD: Perm[] = [...FRONTLINE, 'reviews.manage', 'orders.manage', 'orders.verify', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no controlled-medicine keys', houses: ['kowhai', 'rimu'], perms: ['view', 'reviews.manage', 'orders.manage', 'orders.verify', 'audit.view', 'settings.org'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', houses: ['kowhai', 'rimu'], perms: [...LEAD, 'settings.org'] },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => !(['reviews.manage', 'orders.manage', 'orders.verify', 'audit.view'] as Perm[]).some((k) => has(p, k));
/** Staff who own reviews (reviews.manage) — the owner picker. */
export const OWNERS: { name: string; role: string; houses: House[] }[] = [
    { name: 'Jordan Tipene', role: 'House lead', houses: ['kowhai'] },
    { name: 'Hana Kereama', role: 'Clinical lead', houses: ['kowhai', 'rimu'] },
    { name: 'Rangi Parata', role: 'Provider manager', houses: ['kowhai', 'rimu'] },
    { name: 'Sione Taufa', role: 'House lead', houses: ['rimu'] },
];

/* ───────────── people (P03 v1; Wiremu is new) ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'ben' | 'wiremu';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'ben', 'wiremu'];
export interface Person {
    id: PersonId;
    clientId: number;
    pref: string;
    legal: string;
    surname: string;
    initials: string;
    house: House;
    age: number;
    nhi: string;
    keyWorker: string;
    /** Own review interval in months (Q2); none = the organisation default. */
    interval?: number;
    intervalSet?: string;
    /** NF-13 — away doesn’t pause the due date (Main, Q8). */
    away?: string;
    left?: string;
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', clientId: 201, pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', initials: 'AN', house: 'kowhai', age: 39, nhi: 'ZAA0024', keyWorker: 'Priya Shah' },
    tama: { id: 'tama', clientId: 202, pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', initials: 'TW', house: 'kowhai', age: 47, nhi: 'ZAB0033', keyWorker: 'Daniel Ahn' },
    mele: { id: 'mele', clientId: 203, pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', initials: 'MF', house: 'kowhai', age: 33, nhi: 'ZAC0041', keyWorker: 'Mere Kahu' },
    grace: { id: 'grace', clientId: 204, pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', initials: 'GL', house: 'kowhai', age: 75, nhi: 'ZAD0058', keyWorker: 'Jordan Tipene' },
    sam: { id: 'sam', clientId: 205, pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', initials: 'ST', house: 'kowhai', age: 25, nhi: 'ZAE0066', keyWorker: 'Priya Shah', away: 'With whānau in Rotorua until Sat 3 Oct' },
    ben: { id: 'ben', clientId: 207, pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', initials: 'BC', house: 'rimu', age: 58, nhi: 'ZAF0075', keyWorker: 'Sione Taufa', interval: 6, intervalSet: 'Sione Taufa, 14 Sep — asked for by Dr Arun Patel' },
    wiremu: { id: 'wiremu', clientId: 208, pref: 'Wiremu', legal: 'Wiremu Hēnare', surname: 'Hēnare', initials: 'WH', house: 'kowhai', age: 62, nhi: 'ZAG0083', keyWorker: 'Mere Kahu', left: 'Left the service on Sun 20 Sep — moved to another provider' },
};
export const personByClientId = (id: number) => PEOPLE_ORDER.find((p) => PEOPLE[p].clientId === id) ?? null;

/** P02 v1 record header reference numbers (P03 v1 fixtures, unchanged). */
export const RECORD_REF: Partial<Record<PersonId, { due: number; dueCap: string; late: number; lateCap: string; rec: [number, number] | null; allergy: string; allergyCap: string; allergyTone: 'critical' | 'warning' | 'brand'; inr?: { value: string; on: string; cap: string } }>> = {
    aroha: { due: 3, dueCap: 'Until 10:00 am', late: 0, lateCap: 'None late', rec: [1, 4], allergy: '2 recorded', allergyCap: 'Reviewed 12 August', allergyTone: 'critical', inr: { value: '2.4', on: '21 Sep', cap: 'Target 2.0–3.0 · next 5 Oct' } },
    tama: { due: 1, dueCap: 'Until 10:00 am', late: 1, lateCap: 'Oldest due 8:00 am', rec: [0, 2], allergy: 'Not recorded', allergyCap: 'Not reviewed', allergyTone: 'warning' },
    mele: { due: 1, dueCap: 'Until 10:00 am', late: 1, lateCap: 'Oldest due 8:00 am', rec: [0, 2], allergy: '1 recorded', allergyCap: 'Not reviewed', allergyTone: 'critical' },
    grace: { due: 2, dueCap: 'Until 10:00 am', late: 0, lateCap: 'None late', rec: [1, 3], allergy: 'Unavailable', allergyCap: 'Check the health profile', allergyTone: 'warning' },
    sam: { due: 0, dueCap: 'Nothing due right now', late: 0, lateCap: 'None late', rec: null, allergy: 'None known', allergyCap: 'Reviewed 12 August', allergyTone: 'brand' },
    ben: { due: 0, dueCap: 'Nothing due right now', late: 0, lateCap: 'None late', rec: [1, 1], allergy: 'Not recorded', allergyCap: 'Not reviewed', allergyTone: 'warning' },
};

/* ───────────── current orders (P04 v1, as a review lists them) ───────────── */
export interface Order {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    dose: string;
    when: string;
    cd?: boolean;
    prn?: boolean;
    covert?: boolean;
    course?: string;
}
export const ORDERS: Order[] = [
    { id: 'o-metformin', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', dose: '1 tablet', when: '8:00 am and 12:00 pm, with food' },
    { id: 'o-losartan', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', dose: '1 tablet', when: '9:00 am' },
    { id: 'o-insulin', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', dose: '10 units', when: '9:00 am' },
    { id: 'o-methylphenidate', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', dose: '1 tablet', when: '12:00 pm', cd: true },
    { id: 'o-cefalexin', pid: 'aroha', med: 'Cefalexin', strength: '500 mg capsule', dose: '1 capsule', when: '8:00 am, 2:00 pm, 8:00 pm', course: 'Until 4 October' },
    { id: 'o-levetiracetam', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', dose: '1 tablet', when: '8:00 am and 8:00 pm' },
    { id: 'o-midazolam', pid: 'tama', med: 'Midazolam', strength: '5 mg/mL buccal syringe', dose: 'As in the seizure plan', when: 'When needed', cd: true, prn: true },
    { id: 'o-omeprazole', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', dose: '1 capsule', when: '9:00 am, before breakfast' },
    { id: 'o-amoxicillin', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', dose: '1 capsule', when: '8:00 am, 2:00 pm, 8:00 pm', course: 'Until 1 October' },
    { id: 'o-paracetamol-mele', pid: 'mele', med: 'Paracetamol', strength: '500 mg tablet', dose: '2 tablets', when: 'When needed', prn: true },
    { id: 'o-sertraline', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', dose: '1 tablet', when: '8:00 am' },
    { id: 'o-levothyroxine', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', dose: '1 tablet', when: '9:00 am · covert plan', covert: true },
    { id: 'o-clonazepam', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', dose: '1 tablet', when: '9:00 am', cd: true },
    { id: 'o-melatonin', pid: 'sam', med: 'Melatonin', strength: '3 mg modified-release tablet', dose: '1 tablet', when: '8:30 pm' },
    { id: 'o-cetirizine', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', dose: '1 tablet', when: '8:00 am' },
    { id: 'o-amlodipine', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', dose: '1 tablet', when: '9:00 am' },
    { id: 'o-atorvastatin', pid: 'wiremu', med: 'Atorvastatin', strength: '20 mg tablet', dose: '1 tablet', when: '8:00 pm' },
];
export const ordersOf = (pid: PersonId) => ORDERS.filter((o) => o.pid === pid);
export const orderOf = (id: string) => ORDERS.find((o) => o.id === id)!;

/* ───────────── reviews (Main, Q1–Q9) ───────────── */
export type Kind = 'regular' | 'triggered';
/** Q3 — a fixed list of what starts a triggered review. */
export type Trigger = 'hospital' | 'fall' | 'error' | 'health' | 'asked' | 'refusals' | 'other';
export const TRIGGER_LABEL: Record<Trigger, string> = {
    hospital: 'Back from hospital or respite',
    fall: 'A fall or injury',
    error: 'A medication error',
    health: 'A change in health or behaviour',
    asked: 'The person, whānau or GP asked',
    refusals: 'The refusal pattern — 3 in 7 days',
    other: 'Other',
};
/** Q4 — the clinician who does the review, usually from outside. */
export type ClinRole = 'GP' | 'Pharmacist' | 'Nurse practitioner' | 'Specialist';
export interface Clinician {
    name: string;
    role: ClinRole;
    practice: string;
    reg?: string;
}
export const CLINICIANS: Clinician[] = [
    { name: 'Dr Lena Chen', role: 'GP', practice: 'Ngā Hau Medical Centre (synthetic)' },
    { name: 'Sarah Wong', role: 'Pharmacist', practice: 'Kōwhai Pharmacy (synthetic)', reg: 'Reg. 90-1234 (synthetic)' },
    { name: 'Dr Arun Patel', role: 'GP', practice: 'Rimu Road Health (synthetic)' },
];
export type Where = 'house' | 'practice' | 'phone' | 'video';
export const WHERE_LABEL: Record<Where, string> = { house: 'At the house', practice: 'At the practice', phone: 'By phone', video: 'By video' };
/** Q8 — why a review moved. */
export type MoveReason = 'clinician' | 'unwell' | 'hospital' | 'whanau' | 'other';
export const MOVE_LABEL: Record<MoveReason, string> = {
    clinician: 'The clinician couldn’t make it',
    unwell: 'The person was unwell or away',
    hospital: 'The person is in hospital',
    whanau: 'Whānau asked for another time',
    other: 'Other',
};
/** Q6 — an outcome for each current order. */
export type Outcome = 'continue' | 'change' | 'stop' | 'start' | 'swap' | 'watch';
export const OUTCOME_LABEL: Record<Outcome, string> = {
    continue: 'Continue',
    change: 'Change the dose or times',
    stop: 'Stop',
    start: 'Start something new',
    swap: 'Swap',
    watch: 'Watch for something',
};
export type DecisionHow = 'writing' | 'phone' | 'review' | 'person';
export const DECISION_HOW: Record<DecisionHow, string> = {
    writing: 'In writing',
    phone: 'By phone',
    review: 'At the review — the prescriber did it',
    person: 'In person',
};
export interface Decision {
    state: 'waiting' | 'agreed' | 'notAgreed';
    asked?: { how: string; at: string; by: string };
    prescriber?: string;
    at?: string;
    how?: DecisionHow;
    file?: string;
    note?: string;
    by?: string;
}
/** Once agreed, the change is entered in Orders (P04) and checked there. */
export interface Entered {
    at: string;
    by: string;
    version: string;
    /** Phone: read back with a witness PIN; the written confirmation comes before the check (Main, Q6 addition). */
    phone?: { witness: string; writtenDue: string; written?: { at: string; by: string; file: string } };
    checked?: { by: string; at: string };
}
export interface Item {
    orderId: string;
    outcome: Outcome;
    /** Recorded by someone without controlled-medicine view: a house lead adds this outcome (Main, Q9 addition). */
    pendingCd?: boolean;
    what?: string;
    decision?: Decision;
    entered?: Entered;
    watch?: { what: string; until: string; followUp: string; done?: { at: string; by: string; note: string } };
}
export interface Took {
    person: 'yes' | 'no';
    personWhy?: string;
    whanau: 'took' | 'told' | 'none';
    whanauWho?: string;
    whanauWhy?: string;
    how: Where;
}
export interface Recorded {
    at: string;
    by: string;
    recordedAt: string;
    clinicians: Clinician[];
    took: Took;
    summary: string;
    letter?: string;
    figures?: { dbi?: string; falls?: string };
    items: Item[];
    next?: { id: string; due: string; earlier?: boolean };
}
export interface Move {
    from: string;
    to: string;
    reason: MoveReason;
    note?: string;
    by: string;
    at: string;
}
export interface Review {
    id: string;
    pid: PersonId;
    kind: Kind;
    trigger?: Trigger;
    triggerNote?: string;
    due: string;
    dueIso: string;
    booked?: { with: Clinician; at: string; iso: string; where: Where };
    owner: string;
    made: { by: string; at: string; auto?: string };
    moves: Move[];
    state: 'booked' | 'recorded' | 'cancelled' | 'closed';
    recorded?: Recorded;
    cancelled?: { by: string; at: string; reason: string };
    closed?: { at: string; reason: string };
}
const [CHEN, WONG, PATEL] = CLINICIANS;
export const REVIEWS: Review[] = [
    // Mele — triggered by a fall; the pharmacist came this morning: record the outcome
    {
        id: 'R-27', pid: 'mele', kind: 'triggered', trigger: 'fall', triggerNote: 'Fell in the bathroom on Sat 19 Sep — no injury found', due: 'Wed 30 Sep', dueIso: '2026-09-30',
        booked: { with: WONG, at: 'Mon 28 Sep, 8:30 am', iso: '2026-09-28', where: 'house' }, owner: 'Jordan Tipene', made: { by: 'Jordan Tipene', at: 'Mon 21 Sep, 10:05 am' }, moves: [], state: 'booked',
    },
    // Aroha — regular, booked with the GP
    {
        id: 'R-31', pid: 'aroha', kind: 'regular', due: 'Mon 5 Oct', dueIso: '2026-10-05',
        booked: { with: CHEN, at: 'Mon 5 Oct, 10:30 am', iso: '2026-10-05', where: 'practice' }, owner: 'Jordan Tipene', made: { by: 'System', at: 'Mon 6 Jul, 3:40 pm', auto: 'Booked automatically when R-19 was recorded' }, moves: [], state: 'booked',
    },
    // Sam — regular, overdue; moved once; away with whānau (NF-13)
    {
        id: 'R-24', pid: 'sam', kind: 'regular', due: 'Fri 18 Sep', dueIso: '2026-09-18', owner: 'Jordan Tipene', made: { by: 'System', at: 'Wed 18 Mar, 11:00 am', auto: 'Booked automatically when R-9 was recorded' },
        moves: [{ from: 'Fri 11 Sep', to: 'Fri 18 Sep', reason: 'clinician', note: 'Dr Chen was on leave', by: 'Jordan Tipene', at: 'Thu 10 Sep, 3:00 pm' }], state: 'booked',
    },
    // Grace — regular, booked later (no clinician yet)
    { id: 'R-30', pid: 'grace', kind: 'regular', due: 'Mon 16 Nov', dueIso: '2026-11-16', owner: 'Jordan Tipene', made: { by: 'System', at: 'Mon 17 Aug, 2:00 pm', auto: 'Booked automatically when R-16 was recorded' }, moves: [], state: 'booked' },
    // Mele — her regular cycle carries on; a triggered review doesn’t change it (Q2)
    { id: 'R-29', pid: 'mele', kind: 'regular', due: 'Mon 7 Dec', dueIso: '2026-12-07', owner: 'Jordan Tipene', made: { by: 'System', at: 'Mon 7 Sep, 2:00 pm', auto: 'Booked automatically when R-23 was recorded' }, moves: [], state: 'booked' },
    // Tama — the next regular review, booked automatically when R-26 was recorded (Q2)
    { id: 'R-32', pid: 'tama', kind: 'regular', due: 'Wed 23 Dec', dueIso: '2026-12-23', owner: 'Jordan Tipene', made: { by: 'System', at: 'Wed 23 Sep, 4:20 pm', auto: 'Booked automatically when R-26 was recorded' }, moves: [], state: 'booked' },
    // Ben — his own 6-month interval (Rimu)
    { id: 'R-35', pid: 'ben', kind: 'regular', due: 'Sun 14 Mar 2027', dueIso: '2027-03-14', owner: 'Sione Taufa', made: { by: 'System', at: 'Mon 14 Sep, 5:00 pm', auto: 'Booked automatically when R-22 was recorded' }, moves: [], state: 'booked' },
    // Grace — triggered after hospital, recorded; three changes in different states
    {
        id: 'R-28', pid: 'grace', kind: 'triggered', trigger: 'hospital', triggerNote: 'Back from hospital on Thu 24 Sep', due: 'Fri 25 Sep', dueIso: '2026-09-25',
        booked: { with: WONG, at: 'Fri 25 Sep, 2:00 pm', iso: '2026-09-25', where: 'house' }, owner: 'Jordan Tipene', made: { by: 'Jordan Tipene', at: 'Thu 24 Sep, 4:30 pm' }, moves: [], state: 'recorded',
        recorded: {
            at: 'Fri 25 Sep, 2:00 pm', by: 'Jordan Tipene', recordedAt: 'Fri 25 Sep, 3:10 pm', clinicians: [WONG],
            took: { person: 'yes', whanau: 'told', whanauWho: 'Ruby Liu — sister, welfare guardian', how: 'house' },
            summary: 'Reviewed after the hospital stay. Grace is sleepy in the mornings since she came home. Suggest moving sertraline to the evening and asking the GP about a lower clonazepam dose for four weeks. Thyroid blood test due in six weeks. (Synthetic.)',
            letter: 'Pharmacist review — Grace Liu — 25 Sep.pdf',
            figures: { falls: '0 in the last 3 months' },
            items: [
                { orderId: 'o-sertraline', outcome: 'change', what: 'Take at 8:00 pm instead of 8:00 am', decision: { state: 'agreed', asked: { how: 'Email to Dr Lena Chen', at: 'Fri 25 Sep, 3:15 pm', by: 'Jordan Tipene' }, prescriber: 'Dr Lena Chen', at: 'Mon 28 Sep, 8:50 am', how: 'writing', file: 'Email from Dr Chen — 28 Sep.pdf', by: 'Jordan Tipene' } },
                { orderId: 'o-levothyroxine', outcome: 'watch', watch: { what: 'Tiredness or weight change; the thyroid blood test in 6 weeks', until: 'Fri 6 Nov', followUp: 'FU-88' } },
                { orderId: 'o-clonazepam', outcome: 'change', what: '0.25 mg instead of 0.5 mg at 9:00 am, for 4 weeks, then review', decision: { state: 'waiting', asked: { how: 'Email to Dr Lena Chen', at: 'Fri 25 Sep, 3:15 pm', by: 'Jordan Tipene' } } },
            ],
        },
    },
    // Tama — regular, recorded; the GP agreed by phone, so the entered change waits for her written confirmation (Q6 addition)
    {
        id: 'R-26', pid: 'tama', kind: 'regular', due: 'Wed 23 Sep', dueIso: '2026-09-23',
        booked: { with: WONG, at: 'Wed 23 Sep, 3:00 pm', iso: '2026-09-23', where: 'house' }, owner: 'Jordan Tipene', made: { by: 'System', at: 'Tue 23 Jun, 4:00 pm', auto: 'Booked automatically when R-12 was recorded' }, moves: [], state: 'recorded',
        recorded: {
            at: 'Wed 23 Sep, 3:00 pm', by: 'Jordan Tipene', recordedAt: 'Wed 23 Sep, 4:20 pm', clinicians: [WONG],
            took: { person: 'yes', whanau: 'took', whanauWho: 'Moana Walker — mum', how: 'house' },
            summary: 'Seizure diary reviewed with Tama and his mum. Suggest the GP considers a higher levetiracetam dose. Midazolam plan unchanged. (Synthetic.)',
            letter: 'Pharmacist review — Tama Walker — 23 Sep.pdf',
            items: [
                {
                    orderId: 'o-levetiracetam', outcome: 'change', what: '750 mg twice a day instead of 500 mg',
                    decision: { state: 'agreed', asked: { how: 'Phone call to Dr Lena Chen', at: 'Thu 24 Sep, 9:00 am', by: 'Jordan Tipene' }, prescriber: 'Dr Lena Chen', at: 'Sun 27 Sep, 10:15 am', how: 'phone', by: 'Jordan Tipene' },
                    entered: { at: 'Sun 27 Sep, 10:30 am', by: 'Jordan Tipene', version: 'v2', phone: { witness: 'Mere Kahu', writtenDue: 'by the end of today (Mon 28 Sep)' } },
                },
                { orderId: 'o-midazolam', outcome: 'continue' },
            ],
            next: { id: 'R-32', due: 'Wed 23 Dec' },
        },
    },
    // Ben — regular, recorded by the GP; watched for 2 weeks, done (Rimu)
    {
        id: 'R-22', pid: 'ben', kind: 'regular', due: 'Mon 14 Sep', dueIso: '2026-09-14',
        booked: { with: PATEL, at: 'Mon 14 Sep, 11:00 am', iso: '2026-09-14', where: 'practice' }, owner: 'Sione Taufa', made: { by: 'System', at: 'Sat 14 Mar, 9:00 am', auto: 'Booked automatically when R-7 was recorded' }, moves: [], state: 'recorded',
        recorded: {
            at: 'Mon 14 Sep, 11:00 am', by: 'Sione Taufa', recordedAt: 'Mon 14 Sep, 1:30 pm', clinicians: [PATEL],
            took: { person: 'yes', whanau: 'none', whanauWhy: 'Ben asked for it to be just him', how: 'practice' },
            summary: 'Stable. Home blood pressure readings for two weeks, then no change. Next review in six months. (Synthetic.)',
            items: [{ orderId: 'o-amlodipine', outcome: 'watch', watch: { what: 'Home blood pressure readings, twice a day', until: 'Mon 28 Sep', followUp: 'FU-71', done: { at: 'Mon 28 Sep, 8:00 am', by: 'Sione Taufa', note: 'Readings recorded and sent to Dr Patel. No change.' } } }],
            next: { id: 'R-35', due: 'Sun 14 Mar 2027' },
        },
    },
    // Aroha — the last regular review: one change not agreed
    {
        id: 'R-19', pid: 'aroha', kind: 'regular', due: 'Mon 6 Jul', dueIso: '2026-07-06',
        booked: { with: CHEN, at: 'Mon 6 Jul, 10:30 am', iso: '2026-07-06', where: 'practice' }, owner: 'Jordan Tipene', made: { by: 'System', at: 'Mon 6 Apr, 10:00 am', auto: 'Booked automatically' }, moves: [], state: 'recorded',
        recorded: {
            at: 'Mon 6 Jul, 10:30 am', by: 'Jordan Tipene', recordedAt: 'Mon 6 Jul, 3:40 pm', clinicians: [CHEN],
            took: { person: 'yes', whanau: 'none', whanauWhy: 'Aroha chose not to involve whānau this time', how: 'practice' },
            summary: 'All medicines continue. Discussed moving metformin to breakfast and dinner; kept with lunch. (Synthetic.)',
            items: [
                { orderId: 'o-metformin', outcome: 'change', what: 'With breakfast and dinner instead of 8:00 am and 12:00 pm', decision: { state: 'notAgreed', prescriber: 'Dr Lena Chen', at: 'Mon 6 Jul, 10:30 am', how: 'review', note: 'Keep 12:00 pm — lunch is her main meal.', by: 'Jordan Tipene' } },
                { orderId: 'o-losartan', outcome: 'continue' },
                { orderId: 'o-insulin', outcome: 'continue' },
                { orderId: 'o-methylphenidate', outcome: 'continue' },
            ],
            next: { id: 'R-31', due: 'Mon 5 Oct' },
        },
    },
    // Mele — a triggered review cancelled with its reason (Q8)
    { id: 'R-25', pid: 'mele', kind: 'triggered', trigger: 'asked', triggerNote: 'Mele asked about her reflux medicine', due: 'Wed 16 Sep', dueIso: '2026-09-16', owner: 'Jordan Tipene', made: { by: 'Jordan Tipene', at: 'Mon 14 Sep, 9:30 am' }, moves: [], state: 'cancelled', cancelled: { by: 'Jordan Tipene', at: 'Tue 15 Sep, 11:00 am', reason: 'No longer needed — the GP answered it at her appointment on 14 Sep' } },
    // Wiremu — left the service: his open review closed automatically (Main, Q8 addition)
    { id: 'R-20', pid: 'wiremu', kind: 'regular', due: 'Tue 6 Oct', dueIso: '2026-10-06', owner: 'Jordan Tipene', made: { by: 'System', at: 'Mon 6 Jul, 9:00 am', auto: 'Booked automatically' }, moves: [], state: 'closed', closed: { at: 'Sun 20 Sep, 12:05 am', reason: 'Closed automatically — Wiremu left the service (moved to another provider)' } },
];

/** P11 — the organisation default (Main, Q2 addition: drawn in P11 v5’s pattern). */
export const ORG_DEFAULT = { months: 3, reviewed: false };
/** P11 v5’s change history (All changes), as at P05’s clock — rows dated after 28 Sep are left out. */
export const P11_HISTORY = [
    { id: 'h-6', what: 'Medicine rule paused', from: 'Digoxin — record pulse (active)', to: 'Paused', who: 'Hana Kereama', when: '20 Sep 2026 10:41 am', scope: 'All houses', area: 'Medication rules' },
    { id: 'h-5', what: 'Round template paused — Weekend late breakfast', from: 'Active', to: 'Paused', who: 'Jordan Tipene', when: '14 Sep 2026 4:02 pm', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-4', what: 'Round template retired — Night round', from: 'Active', to: 'Retired', who: 'Jordan Tipene', when: '1 Sep 2026 9:30 am', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-3', what: 'Round template — Morning round, default staff', from: 'Everyone rostered', to: 'Sione Taufa', who: 'Sione Taufa', when: '22 Aug 2026 11:15 am', scope: 'Rimu House', area: 'Rounds & timing' },
];
