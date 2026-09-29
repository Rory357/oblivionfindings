/* Synthetic fixtures for eMAR P02 v1 — the person medication record. No real
 * people, staff, medicines or clinical values. People, houses, staff, today's
 * doses and wording come from the approved P00 v5 and P01 v1 fixtures (the P01
 * files are reused unchanged in src/p01/), so the three designs read the same.
 * Order amounts, INR targets and dose instructions are synthetic ORDER data
 * from the prescriber; nothing here becomes policy by being displayed. */
import { DOSES, PEOPLE, PRN, type Dose, type Support } from './p01/data';

/* ───────────── personas (seeded roles, plan §3; P11 v4 names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu' | 'hr';
export type Perm =
    | 'view'
    | 'administer'
    | 'correct'
    | 'cd.view'
    | 'orders.manage'
    | 'orders.verify'
    | 'audit.view'
    | 'reports.export'
    | 'clients.update'
    | 'breakglass';
export type House = 'kowhai' | 'rimu';
export const HOUSES: Record<House, string> = { kowhai: 'Kōwhai House', rimu: 'Rimu House' };
export interface P02Persona {
    id: PersonaId;
    name: string;
    initials: string;
    role: string;
    houses: House[];
    perms: Perm[];
}
const FRONTLINE: Perm[] = ['view', 'administer', 'correct', 'cd.view'];
const LEAD: Perm[] = [...FRONTLINE, 'orders.manage', 'orders.verify', 'audit.view', 'reports.export', 'clients.update'];
export const P02_PERSONAS: Record<PersonaId, P02Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: {
        id: 'clinical',
        name: 'Hana Kereama',
        initials: 'HK',
        role: 'Clinical lead · no controlled-medicine access',
        houses: ['kowhai', 'rimu'],
        perms: ['view', 'orders.manage', 'orders.verify', 'audit.view', 'reports.export', 'clients.update'],
    },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', houses: ['kowhai', 'rimu'], perms: [...LEAD, 'breakglass'] },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
    hr: { id: 'hr', name: 'Tui Morgan', initials: 'TM', role: 'HR advisor · no medication access', houses: ['kowhai', 'rimu'], perms: [] },
};
export const has = (p: PersonaId, k: Perm) => P02_PERSONAS[p].perms.includes(k);
/** Frontline = no lead or manager capability (plan §2.1): one sidebar entry, Meds today. */
export const frontline = (p: PersonaId) => !(['orders.manage', 'orders.verify', 'audit.view', 'reports.export', 'breakglass'] as Perm[]).some((k) => has(p, k));
/** Who records doses opens P01's dialog; only these personas exist in P01's store. */
export const recorderIn01 = (p: PersonaId): 'sw' | 'lead' | 'pm' | null => (p === 'sw' || p === 'lead' || p === 'pm' ? p : null);

/* ───────────── people: facts the record adds to the P01 identities ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'ben';
export const RECORD_PEOPLE: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'ben'];
export type AllergyState = 'recorded' | 'none' | 'unavailable' | 'nkda';
export interface AllergyEntry {
    allergen: string;
    reaction: string | null;
    severity: 'Severe' | 'Moderate' | 'Mild' | null;
    /** Today's two sources (EM-07 reads both); merged into the health profile at build (Stephan, 30 Sep). */
    source: 'Health profile' | 'Medication allergy list' | 'Both lists';
    recorded: string;
}
export interface PersonFacts {
    id: PersonId;
    clientId: number;
    age: number;
    house: House;
    service: string;
    status: 'Active' | 'Respite';
    allergy: AllergyState;
    entries: AllergyEntry[];
    /** Leads confirm the list (Stephan, 30 Sep). null = not reviewed. */
    reviewed: { by: string; on: string; how: string } | null;
    moved?: { from: House; at: string; atLong: string };
    keyWorker: string;
}
export const FACTS: Record<PersonId, PersonFacts> = {
    aroha: {
        id: 'aroha',
        clientId: 201,
        age: 39,
        house: 'kowhai',
        service: 'Supported living',
        status: 'Active',
        allergy: 'recorded',
        entries: [
            { allergen: 'Penicillin', reaction: 'Rash and swelling', severity: 'Severe', source: 'Both lists', recorded: 'Dr Lena Chen’s referral · 3 March 2024' },
            { allergen: 'Latex', reaction: 'Itchy skin', severity: 'Mild', source: 'Health profile', recorded: 'Jordan Tipene · 12 August 2026' },
        ],
        reviewed: { by: 'Jordan Tipene', on: '12 August 2026', how: 'checked with Aroha and her GP record' },
        keyWorker: 'Priya Shah',
    },
    tama: { id: 'tama', clientId: 202, age: 47, house: 'kowhai', service: 'Supported living', status: 'Active', allergy: 'none', entries: [], reviewed: null, keyWorker: 'Daniel Ahn' },
    mele: {
        id: 'mele',
        clientId: 203,
        age: 33,
        house: 'kowhai',
        service: 'Supported living',
        status: 'Active',
        allergy: 'recorded',
        entries: [{ allergen: 'Penicillin', reaction: null, severity: null, source: 'Health profile', recorded: 'Hana Kereama · 4 June 2026' }],
        reviewed: null,
        keyWorker: 'Mere Kahu',
    },
    grace: { id: 'grace', clientId: 204, age: 75, house: 'kowhai', service: 'Supported living', status: 'Active', allergy: 'unavailable', entries: [], reviewed: null, keyWorker: 'Jordan Tipene' },
    sam: { id: 'sam', clientId: 205, age: 25, house: 'kowhai', service: 'Supported living', status: 'Active', allergy: 'nkda', entries: [], reviewed: { by: 'Jordan Tipene', on: '12 August 2026', how: 'checked with Sam and his GP record' }, keyWorker: 'Priya Shah' },
    ben: {
        id: 'ben',
        clientId: 207,
        age: 58,
        house: 'rimu',
        service: 'Supported living',
        status: 'Active',
        allergy: 'none',
        entries: [],
        reviewed: null,
        moved: { from: 'kowhai', at: '8:30 am today', atLong: 'Monday 28 September 2026, 8:30 am' },
        keyWorker: 'Sione Taufa',
    },
};
export const personByClientId = (id: number) => RECORD_PEOPLE.find((p) => FACTS[p].clientId === id) ?? null;
export const pref = (pid: string) => PEOPLE[pid]?.pref ?? pid;

/* ───────────── medicines (orders) — the Medicines tab ───────────── */
export interface Medicine {
    key: string;
    pid: PersonId;
    name: string;
    strength: string;
    route: string;
    when: string;
    amount: string;
    instructions: string;
    support: Support;
    kind: 'scheduled' | 'prn';
    /** P01 as-needed order id (PRN medicines only). */
    prn?: string;
    status: 'active' | 'awaiting' | 'stopped';
    cd?: boolean;
    covert?: boolean;
    rules?: boolean;
    inr?: boolean;
    order: string;
    prescriber: string;
    started: string;
    stopped?: { on: string; by: string; reason: string };
    /** Today's P01 dose ids for this medicine (chart cells). */
    doseIds: string[];
    slots: string[];
    photos: { file: string; taken: string; by: string; pack: string; state: 'current' | 'replaced' | 'changed' }[];
    supply: string;
    review: string;
}
const V = 'Verified 3 August 2026 by Jordan Tipene';
export const MEDICINES: Medicine[] = [
    {
        key: 'metformin', pid: 'aroha', name: 'Metformin', strength: '500 mg tablet', route: 'By mouth', when: '8:00 am and 12:00 pm', amount: '1 tablet', instructions: 'With breakfast and with lunch', support: 'administer', kind: 'scheduled', status: 'active',
        order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r1', 'r9'], slots: ['8:00 am', '12:00 pm'],
        photos: [
            { file: 'metformin.png', taken: '3 Sep 2026', by: 'Jordan Tipene', pack: 'Pharmacy blister pack, Kōwhai Pharmacy', state: 'current' },
            { file: 'metformin-earlier.png', taken: '12 Mar 2026', by: 'Mere Kahu', pack: 'Pharmacy blister pack, earlier supplier', state: 'replaced' },
        ],
        supply: 'Blister packs from Kōwhai Pharmacy · stock is kept in P06', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'losartan', pid: 'aroha', name: 'Losartan', strength: '50 mg tablet', route: 'By mouth', when: '9:00 am', amount: '1 tablet', instructions: 'Once a day in the morning', support: 'administer', kind: 'scheduled', status: 'active',
        order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r16'], slots: ['9:00 am'],
        photos: [
            { file: 'losartan.png', taken: '3 Sep 2026', by: 'Jordan Tipene', pack: 'Pharmacy blister pack, Kōwhai Pharmacy', state: 'current' },
            { file: 'losartan-earlier.png', taken: '14 Apr 2026', by: 'Mere Kahu', pack: 'Bottle, earlier brand', state: 'replaced' },
        ],
        supply: 'Blister packs from Kōwhai Pharmacy', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'vitd', pid: 'aroha', name: 'Vitamin D (colecalciferol)', strength: '1.25 mg capsule', route: 'By mouth', when: '9:00 am, monthly', amount: '1 capsule', instructions: 'Monthly, first Monday', support: 'prompt', kind: 'scheduled', status: 'active',
        order: V, prescriber: 'Dr Lena Chen', started: '1 June 2026', doseIds: ['r6'], slots: ['9:00 am'],
        photos: [{ file: 'vitamin-d.png', taken: '2 Jun 2026', by: 'Mere Kahu', pack: 'Brand A bottle', state: 'changed' }],
        supply: 'Bottle · the pack supplied on 21 Sep 2026 is a different brand', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'insulin', pid: 'aroha', name: 'Insulin glargine', strength: '100 units/mL pen', route: 'Subcutaneous injection', when: '9:00 am', amount: '10 units', instructions: 'Once a day in the morning. Rotate the injection site.', support: 'administer', kind: 'scheduled', status: 'active', rules: true,
        order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r12'], slots: ['9:00 am'],
        photos: [{ file: 'insulin.png', taken: '11 Sep 2026', by: 'Jordan Tipene', pack: 'Pen from the fridge box', state: 'current' }],
        supply: 'Pens kept in the fridge', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'methylphenidate', pid: 'aroha', name: 'Methylphenidate', strength: '10 mg tablet', route: 'By mouth', when: '12:00 pm', amount: '1 tablet', instructions: 'Needs a witness', support: 'administer', kind: 'scheduled', status: 'active', cd: true,
        order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r10'], slots: ['12:00 pm'],
        photos: [], supply: 'Controlled-drug cupboard · balance in the controlled register', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'warfarin', pid: 'aroha', name: 'Warfarin', strength: '1 mg tablet', route: 'By mouth', when: '5:00 pm', amount: 'As instructed with each INR result — this week 3 tablets (3 mg)', instructions: 'Dose changes with each INR result. Follow the latest instruction on the Clinical tab.', support: 'administer', kind: 'scheduled', status: 'active', inr: true,
        order: 'Verified 18 August 2026 by Jordan Tipene', prescriber: 'Dr Lena Chen', started: '18 August 2026', doseIds: ['w1'], slots: ['5:00 pm'],
        photos: [{ file: 'warfarin.png', taken: '18 Aug 2026', by: 'Jordan Tipene', pack: 'Pharmacy bottle, Kōwhai Pharmacy', state: 'current' }],
        supply: 'Bottle from Kōwhai Pharmacy', review: 'INR-guided · reviewed with each result',
    },
    {
        key: 'paracetamol', pid: 'aroha', name: 'Paracetamol', strength: '500 mg tablet', route: 'By mouth', when: 'When needed', amount: '1 or 2 tablets', instructions: 'For pain · up to 4 doses in 24 hours, at least 4 hours apart (from the prescription)', support: 'administer', kind: 'prn', prn: 'p1', status: 'active',
        order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: [], slots: [],
        photos: [{ file: 'paracetamol.png', taken: '3 Sep 2026', by: 'Jordan Tipene', pack: 'Pharmacy bottle', state: 'current' }],
        supply: 'Bottle from Kōwhai Pharmacy', review: 'Medication review due 1 February 2027',
    },
    {
        key: 'prednisone', pid: 'aroha', name: 'Prednisone', strength: '5 mg tablet', route: 'By mouth', when: '8:00 am (course ended)', amount: '1 tablet', instructions: '5-day course', support: 'administer', kind: 'scheduled', status: 'stopped',
        order: 'Verified 14 August 2026 by Jordan Tipene', prescriber: 'Dr Lena Chen', started: '15 August 2026', stopped: { on: '20 August 2026', by: 'Dr Lena Chen', reason: 'Course finished' }, doseIds: [], slots: [],
        photos: [], supply: '—', review: '—',
    },
    // Other people (enough for the states)
    { key: 'levetiracetam', pid: 'tama', name: 'Levetiracetam', strength: '500 mg tablet', route: 'By mouth', when: '8:00 am and 8:00 pm', amount: '1 tablet', instructions: 'Morning and evening', support: 'administer', kind: 'scheduled', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r2'], slots: ['8:00 am'], photos: [], supply: 'Blister packs', review: 'Medication review due 1 February 2027' },
    { key: 'macrogol', pid: 'tama', name: 'Macrogol', strength: 'sachet', route: 'By mouth', when: '9:00 am', amount: '1 sachet', instructions: 'Mixed in water', support: 'assist', kind: 'scheduled', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r8'], slots: ['9:00 am'], photos: [], supply: 'Box of sachets', review: 'Medication review due 1 February 2027' },
    { key: 'amoxicillin', pid: 'mele', name: 'Amoxicillin', strength: '500 mg capsule', route: 'By mouth', when: '8:00 am, 2:00 pm, 8:00 pm', amount: '1 capsule', instructions: 'Three times a day for 5 days', support: 'administer', kind: 'scheduled', status: 'active', order: 'Verified 27 September 2026 by Jordan Tipene', prescriber: 'Dr Lena Chen', started: '27 September 2026', doseIds: ['r3'], slots: ['8:00 am'], photos: [], supply: 'Course from Kōwhai Pharmacy', review: 'Course ends 1 October 2026' },
    { key: 'omeprazole', pid: 'mele', name: 'Omeprazole', strength: '20 mg capsule', route: 'By mouth', when: '9:00 am', amount: '1 capsule', instructions: 'Before breakfast', support: 'administer', kind: 'scheduled', status: 'awaiting', order: 'Waiting to be checked', prescriber: 'Dr Lena Chen', started: '27 September 2026', doseIds: ['r7'], slots: ['9:00 am'], photos: [], supply: 'Not yet received', review: '—' },
    { key: 'sertraline', pid: 'grace', name: 'Sertraline', strength: '50 mg tablet', route: 'By mouth', when: '8:00 am', amount: '1 tablet', instructions: 'In the morning', support: 'administer', kind: 'scheduled', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r4'], slots: ['8:00 am'], photos: [{ file: 'sertraline.png', taken: '9 Sep 2026', by: 'Jordan Tipene', pack: 'Pharmacy blister pack', state: 'current' }], supply: 'Blister packs', review: 'Medication review due 1 February 2027' },
    { key: 'clonazepam', pid: 'grace', name: 'Clonazepam', strength: '0.5 mg tablet', route: 'By mouth', when: '9:00 am', amount: '1 tablet', instructions: 'In the morning · needs a witness', support: 'administer', kind: 'scheduled', status: 'active', cd: true, order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r11'], slots: ['9:00 am'], photos: [{ file: 'clonazepam.png', taken: '9 Sep 2026', by: 'Jordan Tipene', pack: 'Controlled-drug cupboard, pharmacy pack', state: 'current' }], supply: 'Controlled-drug cupboard', review: 'Medication review due 1 February 2027' },
    { key: 'levothyroxine', pid: 'grace', name: 'Levothyroxine', strength: '50 microgram tablet', route: 'By mouth', when: '9:00 am', amount: '1 tablet', instructions: 'Once a day', support: 'administer', kind: 'scheduled', status: 'active', covert: true, order: V, prescriber: 'Dr Lena Chen', started: '1 June 2026', doseIds: ['r13'], slots: ['9:00 am'], photos: [], supply: 'Blister packs', review: 'Covert plan review due 1 December 2026' },
    { key: 'cetirizine', pid: 'sam', name: 'Cetirizine', strength: '10 mg tablet', route: 'By mouth', when: '8:00 am', amount: '1 tablet', instructions: 'Once a day', support: 'independent', kind: 'scheduled', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r5'], slots: ['8:00 am'], photos: [], supply: 'Sam keeps his own pack', review: 'Medication review due 1 February 2027' },
    { key: 'ibuprofen', pid: 'tama', name: 'Ibuprofen', strength: '200 mg tablet', route: 'By mouth', when: 'When needed', amount: '2 tablets', instructions: 'For pain · 2 tablets with food, up to 3 doses in 24 hours, at least 6 hours apart (from the prescription)', support: 'administer', kind: 'prn', prn: 'p2', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: [], slots: [], photos: [], supply: 'Box from Kōwhai Pharmacy', review: 'Medication review due 1 February 2027' },
    { key: 'paracetamol-mele', pid: 'mele', name: 'Paracetamol', strength: '500 mg tablet', route: 'By mouth', when: 'When needed', amount: '2 tablets', instructions: 'For pain · 2 tablets, up to 4 doses in 24 hours (from the prescription)', support: 'administer', kind: 'prn', prn: 'p4', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: [], slots: [], photos: [], supply: 'Bottle', review: 'Medication review due 1 February 2027' },
    { key: 'lorazepam', pid: 'grace', name: 'Lorazepam', strength: '0.5 mg tablet', route: 'By mouth', when: 'When needed', amount: '1 tablet', instructions: 'For distress as described in the support plan · up to 2 doses in 24 hours (from the prescription)', support: 'administer', kind: 'prn', prn: 'p5', status: 'active', cd: true, order: V, prescriber: 'Dr Lena Chen', started: '1 June 2026', doseIds: [], slots: [], photos: [], supply: 'Controlled-drug cupboard', review: 'Medication review due 1 February 2027' },
    { key: 'amlodipine', pid: 'ben', name: 'Amlodipine', strength: '5 mg tablet', route: 'By mouth', when: '9:00 am', amount: '1 tablet', instructions: 'Once a day', support: 'administer', kind: 'scheduled', status: 'active', order: V, prescriber: 'Dr Lena Chen', started: '1 August 2026', doseIds: ['r21'], slots: ['9:00 am'], photos: [], supply: 'Blister packs — moved with Ben from Kōwhai House', review: 'Medication review due 1 February 2027' },
];
export const medsOf = (pid: string) => MEDICINES.filter((m) => m.pid === pid);
export const medByKey = (k: string) => MEDICINES.find((m) => m.key === k)!;
export const medForDose = (doseId: string) => MEDICINES.find((m) => m.doseIds.includes(doseId)) ?? null;
export const doseOf = (id: string): Dose | undefined => DOSES.find((d) => d.id === id);
export const prnOf = (pid: string) => PRN.filter((p) => p.pid === pid);

/** P02-only planned dose today (not due until 4:30 pm — never opened in P01's dialog). */
export const WARFARIN_TODAY = { id: 'w1', slot: '5:00 pm', med: 'warfarin', amount: '3 tablets (3 mg)', line: 'Window opens 4:30 pm' };

/* ───────────── 7-day history (Tue 22 – Sun 27 Sep; today comes from P01's store) ───────────── */
export type HOutcome = 'given' | 'prompted' | 'assisted' | 'reoffered' | 'refused' | 'withheld' | 'away' | 'selfmanaged';
export interface Correction {
    id: string;
    status: 'pending' | 'approved' | 'rejected';
    requestedBy: string;
    requestedAt: string;
    reason: string;
    from: { outcome: HOutcome; at: string; note?: string };
    to: { outcome: HOutcome; at: string; note?: string };
    decidedBy?: string;
    decidedAt?: string;
    rejectReason?: string;
}
export interface Admin {
    id: string;
    pid: PersonId;
    med: string;
    day: string;
    dayLabel: string;
    slot: string | null;
    at: string;
    outcome: HOutcome;
    by: string;
    house: House;
    amount?: string;
    second?: string;
    reading?: { label: string; value: string };
    note?: string;
    prn?: boolean;
    correction?: Correction;
}
export const DAYS: { iso: string; short: string; long: string }[] = [
    { iso: '2026-09-22', short: 'Tue 22', long: 'Tuesday 22 September' },
    { iso: '2026-09-23', short: 'Wed 23', long: 'Wednesday 23 September' },
    { iso: '2026-09-24', short: 'Thu 24', long: 'Thursday 24 September' },
    { iso: '2026-09-25', short: 'Fri 25', long: 'Friday 25 September' },
    { iso: '2026-09-26', short: 'Sat 26', long: 'Saturday 26 September' },
    { iso: '2026-09-27', short: 'Sun 27', long: 'Sunday 27 September' },
    { iso: '2026-09-28', short: 'Mon 28', long: 'Monday 28 September (today)' },
];
const STAFF_BY_DAY = ['Mere Kahu', 'Priya Shah', 'Daniel Ahn', 'Jordan Tipene', 'Mere Kahu', 'Jordan Tipene'];
const PLAN: { med: string; slot: string; at: (i: number) => string; amount: string; daily?: boolean; second?: boolean; reading?: boolean; cd?: boolean }[] = [
    { med: 'metformin', slot: '8:00 am', at: (i) => `8:0${(i * 3) % 9} am`, amount: '1 tablet (500 mg) as ordered' },
    { med: 'losartan', slot: '9:00 am', at: (i) => `9:0${(i * 2) % 9} am`, amount: '1 tablet (50 mg) as ordered' },
    { med: 'insulin', slot: '9:00 am', at: (i) => `9:1${i % 6} am`, amount: '10 units', second: true, reading: true },
    { med: 'metformin', slot: '12:00 pm', at: (i) => `12:0${(i * 4) % 9} pm`, amount: '1 tablet (500 mg) as ordered' },
    { med: 'methylphenidate', slot: '12:00 pm', at: (i) => `12:1${i % 5} pm`, amount: '1 tablet (10 mg)', second: true, cd: true },
    { med: 'warfarin', slot: '5:00 pm', at: (i) => `5:0${(i * 5) % 9} pm`, amount: '3 tablets (3 mg) as instructed' },
];
const READINGS = ['6.4', '7.2', '5.9', '6.8', '6.1', '6.6'];
function buildAroha(): Admin[] {
    const out: Admin[] = [];
    DAYS.slice(0, 6).forEach((d, i) => {
        PLAN.forEach((p, j) => {
            const by = p.med === 'warfarin' || p.slot === '12:00 pm' ? STAFF_BY_DAY[(i + 1) % 6] : STAFF_BY_DAY[i];
            const witness = by === 'Jordan Tipene' ? 'Mere Kahu' : 'Jordan Tipene';
            const a: Admin = {
                id: `h-${d.iso}-${j}`,
                pid: 'aroha',
                med: p.med,
                day: d.iso,
                dayLabel: d.short,
                slot: p.slot,
                at: p.at(i),
                outcome: 'given',
                by,
                house: 'kowhai',
                amount: p.amount,
                second: p.second ? `${witness} (witness PIN)` : undefined,
                reading: p.reading ? { label: 'Blood sugar (BSL)', value: `${READINGS[i]} mmol/L` } : undefined,
            };
            out.push(a);
        });
    });
    const find = (day: string, med: string, slot: string) => out.find((a) => a.day === day && a.med === med && a.slot === slot)!;
    // Thu 24: refused at 12:00, re-offered and given 12:40 (NF-11 re-offer, as P01 records it).
    Object.assign(find('2026-09-24', 'metformin', '12:00 pm'), { outcome: 'reoffered', at: '12:40 pm', note: 'Refused at 12:05 pm (not hungry yet) · re-offered after lunch and taken' });
    // Sat 26: out with family 10 am – 4 pm.
    Object.assign(find('2026-09-26', 'metformin', '12:00 pm'), { outcome: 'away', at: '12:00 pm', amount: undefined, note: 'Out with family 10:00 am – 4:00 pm · lunch dose sent with Aroha’s sister, as planned' });
    Object.assign(find('2026-09-26', 'methylphenidate', '12:00 pm'), { outcome: 'away', at: '12:00 pm', amount: undefined, second: undefined, note: 'Out with family · controlled medicine not sent — recorded as away' });
    // Wed 23: time corrected (approved chain).
    Object.assign(find('2026-09-23', 'losartan', '9:00 am'), {
        at: '9:05 am',
        correction: { id: 'c1', status: 'approved', requestedBy: 'Priya Shah', requestedAt: 'Wed 23 Sep, 10:20 am', reason: 'Wrong time entered — it was given at 9:05 am, not 9:40 am', from: { outcome: 'given', at: '9:40 am' }, to: { outcome: 'given', at: '9:05 am' }, decidedBy: 'Jordan Tipene', decidedAt: 'Wed 23 Sep, 11:02 am' },
    });
    // Fri 25: a correction that was rejected.
    Object.assign(find('2026-09-25', 'metformin', '8:00 am'), {
        correction: { id: 'c2', status: 'rejected', requestedBy: 'Daniel Ahn', requestedAt: 'Fri 25 Sep, 2:15 pm', reason: 'Recorded against the wrong person', from: { outcome: 'given', at: '8:06 am' }, to: { outcome: 'withheld', at: '8:06 am', note: 'Not given' }, decidedBy: 'Jordan Tipene', decidedAt: 'Fri 25 Sep, 3:40 pm', rejectReason: 'Checked the round sheet with Daniel — Aroha’s dose was given. The other record was fixed separately.' },
    });
    // Sun 27: refused recorded at 12:15; a correction to "given 12:35" is waiting for approval.
    Object.assign(find('2026-09-27', 'metformin', '12:00 pm'), {
        outcome: 'refused',
        at: '12:15 pm',
        by: 'Mere Kahu',
        amount: undefined,
        note: 'Said she’d already eaten',
        correction: { id: 'c3', status: 'pending', requestedBy: 'Mere Kahu', requestedAt: 'Sun 27 Sep, 12:48 pm', reason: 'Aroha took it after lunch at 12:35 pm — I recorded the refusal too early', from: { outcome: 'refused', at: '12:15 pm', note: 'Said she’d already eaten' }, to: { outcome: 'given', at: '12:35 pm', note: 'Taken after lunch' } },
    });
    // As-needed paracetamol (P01: 11:40 pm Sunday by Daniel Ahn).
    out.push({ id: 'h-prn-1', pid: 'aroha', med: 'paracetamol', day: '2026-09-27', dayLabel: 'Sun 27', slot: null, at: '11:40 pm', outcome: 'given', by: 'Daniel Ahn', house: 'kowhai', amount: '2 tablets (1,000 mg)', prn: true, note: 'For pain (headache) · effect check: “settled by 12:30 am”' });
    out.push({ id: 'h-prn-2', pid: 'aroha', med: 'paracetamol', day: '2026-09-24', dayLabel: 'Thu 24', slot: null, at: '3:20 pm', outcome: 'given', by: 'Priya Shah', house: 'kowhai', amount: '1 tablet (500 mg)', prn: true, note: 'For pain (back) · effect check: “pain eased”' });
    return out;
}
function buildBen(): Admin[] {
    // Ben lived at Kōwhai House until 8:30 am today; the history keeps the house of each dose.
    return DAYS.slice(0, 6).map((d, i) => ({ id: `b-${d.iso}`, pid: 'ben' as PersonId, med: 'amlodipine', day: d.iso, dayLabel: d.short, slot: '9:00 am', at: `9:0${i} am`, outcome: 'given' as HOutcome, by: STAFF_BY_DAY[i], house: 'kowhai' as House, amount: '1 tablet (5 mg) as ordered' }));
}
function buildOthers(): Admin[] {
    const rows: Admin[] = [];
    DAYS.slice(3, 6).forEach((d, i) => {
        rows.push({ id: `t-${d.iso}`, pid: 'tama', med: 'levetiracetam', day: d.iso, dayLabel: d.short, slot: '8:00 am', at: `8:0${i + 1} am`, outcome: 'given', by: STAFF_BY_DAY[i], house: 'kowhai', amount: '1 tablet (500 mg) as ordered' });
        rows.push({ id: `g-${d.iso}`, pid: 'grace', med: 'clonazepam', day: d.iso, dayLabel: d.short, slot: '9:00 am', at: `9:0${i + 2} am`, outcome: 'given', by: STAFF_BY_DAY[i], house: 'kowhai', amount: '1 tablet (0.5 mg)', second: 'Jordan Tipene (witness PIN)' });
        rows.push({ id: `s-${d.iso}`, pid: 'sam', med: 'cetirizine', day: d.iso, dayLabel: d.short, slot: '8:00 am', at: '—', outcome: 'selfmanaged', by: 'Sam', house: 'kowhai' });
        rows.push({ id: `m-${d.iso}`, pid: 'mele', med: 'amoxicillin', day: d.iso, dayLabel: d.short, slot: '8:00 am', at: `8:1${i} am`, outcome: i === 2 ? 'given' : 'given', by: STAFF_BY_DAY[i + 1], house: 'kowhai', amount: '1 capsule (500 mg)' });
    });
    return rows.filter((r) => !(r.pid === 'mele' && r.day < '2026-09-27'));
}
export const HISTORY: Admin[] = [...buildAroha(), ...buildBen(), ...buildOthers()];

/* ───────────── INR (Clinical › INR) ───────────── */
export interface InrReading {
    id: string;
    value: number;
    tested: string;
    testedIso: string;
    target: [number, number] | null;
    instruction: string;
    next: string | null;
    nextIso: string | null;
    by: string;
    source: string;
    linked: boolean;
    disabled?: { by: string; reason: string };
}
export const INR: InrReading[] = [
    { id: 'i4', value: 2.4, tested: '21 Sep 2026', testedIso: '2026-09-21', target: [2, 3], instruction: '3 mg a day this week', next: '5 Oct 2026', nextIso: '2026-10-05', by: 'Jordan Tipene', source: 'Anticoagulation clinic phone call, 21 Sep 2026 2:10 pm', linked: true },
    { id: 'i3', value: 3.4, tested: '14 Sep 2026', testedIso: '2026-09-14', target: [2, 3], instruction: '2 mg a day until the next test', next: '21 Sep 2026', nextIso: '2026-09-21', by: 'Jordan Tipene', source: 'Anticoagulation clinic phone call, 14 Sep 2026 3:05 pm', linked: true },
    { id: 'i2', value: 2.1, tested: '31 Aug 2026', testedIso: '2026-08-31', target: [2, 3], instruction: '3 mg a day', next: '14 Sep 2026', nextIso: '2026-09-14', by: 'Mere Kahu', source: 'Lab result letter, 1 Sep 2026', linked: true },
    { id: 'i1', value: 1.6, tested: '18 Aug 2026', testedIso: '2026-08-18', target: [2, 3], instruction: 'Start 3 mg a day', next: '31 Aug 2026', nextIso: '2026-08-31', by: 'Jordan Tipene', source: 'Dr Lena Chen, starting plan', linked: true },
];
/** Scenario "inrStale": the 21 Sep result was never entered; the next test was due 21 Sep. */
export const INR_STALE: InrReading[] = INR.slice(1);
/** Scenario "inrUnlinked" (NF-23): the latest result was saved from today's MAR page with no medicine. */
export const INR_UNLINKED: InrReading[] = [{ ...INR[0], linked: false, by: 'Priya Shah', source: 'Entered from the MAR chart’s Record INR (no medicine chosen)' }, ...INR.slice(1)];

/* ───────────── syringe driver (Clinical › Syringe driver) ───────────── */
export interface DriverCheck {
    at: string;
    by: string;
    running: boolean;
    site: string;
    remaining: string;
    note?: string;
}
export interface Driver {
    id: string;
    pid: PersonId;
    started: string;
    by: string;
    witness: string;
    rate: string;
    duration: string;
    site: string;
    contents: { med: string; dose: string; cd?: boolean }[];
    checks: DriverCheck[];
    plan: string;
}
/** Grace — scenario "driver": synthetic order data from her palliative plan (district nursing team). */
export const DRIVER: Driver = {
    id: 'sd1',
    pid: 'grace',
    started: 'Sun 27 Sep, 4:00 pm',
    by: 'District nursing team · entered by Jordan Tipene',
    witness: 'Mere Kahu (witness PIN)',
    rate: '0.83 mL an hour (from the plan)',
    duration: '24 hours',
    site: 'Left upper chest',
    contents: [
        { med: 'Midazolam', dose: 'as on the plan', cd: true },
        { med: 'Water for injection', dose: 'to 20 mL' },
    ],
    checks: [
        { at: '8:05 am', by: 'Jordan Tipene', running: true, site: 'No redness or swelling', remaining: '13 mL' },
        { at: '4:02 am', by: 'Daniel Ahn', running: true, site: 'No redness or swelling', remaining: '16.5 mL' },
        { at: '12:00 am', by: 'Daniel Ahn', running: true, site: 'No redness or swelling', remaining: '20 mL', note: 'New syringe at midnight' },
    ],
    plan: 'Checks every 4 hours, from Grace’s palliative care plan',
};

/* ───────────── observations taken with doses (Clinical › Observations) ───────────── */
export const OBSERVATIONS = HISTORY.filter((a) => a.reading).map((a) => ({ id: a.id, day: a.dayLabel, at: a.at, reading: a.reading!, med: 'Insulin glargine 10 units', by: a.by, rule: 'Medication rule: record a blood sugar before insulin (Hana Kereama, 3 Aug 2026)' }));

/* ───────────── chart alerts (Allergies & alerts › Chart alerts) ───────────── */
export interface ChartAlert {
    id: string;
    pid: PersonId;
    type: 'warfarin' | 'paper' | 'warning';
    title: string;
    detail: string;
    onOpen: boolean;
    by: string;
    on: string;
    resolved?: { by: string; on: string };
    cd?: boolean;
}
export const ALERTS: ChartAlert[] = [
    { id: 'a1', pid: 'aroha', type: 'warfarin', title: 'Warfarin — dose changes with each INR', detail: 'Check the latest INR instruction on the Clinical tab before the 5:00 pm dose.', onOpen: true, by: 'Jordan Tipene', on: '18 Aug 2026' },
    { id: 'a2', pid: 'aroha', type: 'paper', title: 'Paper prescription on file', detail: 'The signed prescription for insulin is in the red folder in the office.', onOpen: false, by: 'Jordan Tipene', on: '3 Aug 2026' },
    { id: 'a3', pid: 'aroha', type: 'warning', title: 'Swallowing — offer one tablet at a time', detail: 'From Aroha’s speech-language therapist letter, 2 July 2026.', onOpen: false, by: 'Mere Kahu', on: '2 Jul 2026', resolved: { by: 'Jordan Tipene', on: '20 Aug 2026' } },
    { id: 'a4', pid: 'aroha', type: 'warning', title: 'Controlled medicine kept in the controlled-drug cupboard', detail: 'Methylphenidate lunchtime dose needs a witness.', onOpen: false, by: 'Jordan Tipene', on: '3 Aug 2026', cd: true },
];

/* ───────────── recorded interactions (no interaction database — honest source) ───────────── */
export interface Interaction {
    id: string;
    pid: PersonId;
    a: string;
    b: string;
    severity: 'Moderate' | 'Minor' | 'Major';
    note: string;
    by: string;
    cd?: boolean;
}
export const INTERACTIONS: Interaction[] = [
    { id: 'x1', pid: 'aroha', a: 'Warfarin', b: 'Paracetamol', severity: 'Moderate', note: 'Check with the pharmacy before paracetamol is used every day.', by: 'Kōwhai Pharmacy, 18 Aug 2026' },
    { id: 'x2', pid: 'aroha', a: 'Methylphenidate', b: 'Losartan', severity: 'Minor', note: 'The pharmacy asked for blood pressure at each medication review.', by: 'Kōwhai Pharmacy, 3 Aug 2026', cd: true },
];

/* ───────────── support plan (from the self-administration assessment) ───────────── */
export const SUPPORT_ASSESSMENT: Record<string, { outcome: string; assessed: string; by: string; reassess: string; storage: string; agreement: string } | null> = {
    aroha: { outcome: 'Staff give most medicines; Aroha takes her monthly vitamin D with a prompt', assessed: '12 January 2026', by: 'Jordan Tipene with Aroha', reassess: '12 January 2027', storage: 'Locked cupboard in the office · insulin in the fridge', agreement: 'Signed by Aroha, 12 January 2026' },
    tama: { outcome: 'Staff give medicines; help with sachets', assessed: '3 March 2026', by: 'Daniel Ahn with Tama', reassess: '3 March 2027', storage: 'Locked cupboard in the office', agreement: 'Signed by Tama, 3 March 2026' },
    mele: null,
    grace: { outcome: 'Staff give all medicines; covert plan for levothyroxine', assessed: '1 June 2026', by: 'Hana Kereama with Grace’s welfare guardian', reassess: '1 December 2026', storage: 'Locked cupboard; controlled-drug cupboard', agreement: 'Welfare guardian, 1 June 2026' },
    sam: { outcome: 'Sam manages his own morning medicines', assessed: '20 February 2026', by: 'Jordan Tipene with Sam', reassess: '20 February 2027', storage: 'Sam’s own locked drawer', agreement: 'Signed by Sam, 20 February 2026' },
    ben: { outcome: 'Staff give medicines', assessed: '5 May 2026', by: 'Jordan Tipene with Ben', reassess: '5 May 2027', storage: 'Locked cupboard in the office (moved with Ben)', agreement: 'Signed by Ben, 5 May 2026' },
};

/* ───────────── audit events (History › All changes; MedicationEventDrawer absorbed) ───────────── */
export interface AuditEvent {
    id: string;
    at: string;
    what: string;
    who: string;
    kind: 'dose' | 'correction' | 'order' | 'allergy' | 'alert' | 'inr' | 'access';
    before?: string;
    after?: string;
    linked?: string;
    cd?: boolean;
    checked: 'unchanged' | 'not-checked';
}
export const EVENTS: AuditEvent[] = [
    { id: 'e9', at: 'Mon 28 Sep, 8:05 am', what: 'Dose recorded — Metformin 8:00 am given', who: 'Priya Shah', kind: 'dose', after: 'Given 8:05 am · 1 tablet (500 mg) as ordered', checked: 'unchanged' },
    { id: 'e8', at: 'Sun 27 Sep, 12:48 pm', what: 'Correction requested — Metformin 12:00 pm', who: 'Mere Kahu', kind: 'correction', before: 'Refused 12:15 pm', after: 'Given 12:35 pm (waiting for approval)', linked: 'Correction · waiting', checked: 'unchanged' },
    { id: 'e7', at: 'Sun 27 Sep, 12:14 pm', what: 'Dose recorded — Methylphenidate 12:00 pm given', who: 'Jordan Tipene', kind: 'dose', after: 'Given 12:14 pm · witnessed by Mere Kahu (witness PIN)', cd: true, checked: 'unchanged' },
    { id: 'e6', at: 'Mon 21 Sep, 2:14 pm', what: 'INR recorded — 2.4', who: 'Jordan Tipene', kind: 'inr', after: '2.4 · target 2.0–3.0 · 3 mg a day · next test 5 Oct', checked: 'unchanged' },
    { id: 'e5', at: 'Wed 23 Sep, 11:02 am', what: 'Correction approved — Losartan 9:00 am', who: 'Jordan Tipene', kind: 'correction', before: 'Given 9:40 am', after: 'Given 9:05 am', linked: 'Raised by Priya Shah', checked: 'unchanged' },
    { id: 'e4', at: 'Tue 18 Aug, 3:30 pm', what: 'Chart alert added — Warfarin', who: 'Jordan Tipene', kind: 'alert', after: 'Shown when the chart opens', checked: 'unchanged' },
    { id: 'e3', at: 'Wed 12 Aug, 10:40 am', what: 'Allergies reviewed on the health profile', who: 'Jordan Tipene', kind: 'allergy', after: 'Penicillin (severe) · Latex (mild) — checked with Aroha and her GP record', checked: 'unchanged' },
    { id: 'e2', at: 'Mon 3 Aug, 9:15 am', what: 'Order verified — Insulin glargine', who: 'Jordan Tipene', kind: 'order', after: '10 units, 9:00 am, subcutaneous', checked: 'unchanged' },
    { id: 'e1', at: 'Sat 1 Aug, 2:00 pm', what: 'Medication record opened with emergency access', who: 'Rangi Parata', kind: 'access', after: 'Reason: on-call check · reviewed by Mereana Walsh, 3 Aug', checked: 'not-checked' },
];

export const SLOT_ORDER = ['8:00 am', '9:00 am', '12:00 pm', '5:00 pm'];
