/* Synthetic fixtures for eMAR P01 v1 — no real people, staff, medicines or
 * clinical values. Names, houses, roster and wording are carried over from the
 * approved P00 v5 contract (commit ff3bff860) so the two designs read the same.
 * Order amounts, limits and intervals are synthetic ORDER data, not clinical
 * rules; nothing here becomes policy by being displayed. */
import { minutesOf } from './clock';

/* ───────────── personas (seeded roles, plan §3) ───────────── */
export type PersonaId = 'sw' | 'daniel' | 'lead' | 'pm';
export type Perm =
    | 'view'
    | 'administer'
    | 'correct'
    | 'cd.view'
    | 'cd.record'
    | 'cd.witness'
    | 'orders.verify'
    | 'orders.manage'
    | 'stock.update'
    | 'audit.view'
    | 'reports.export'
    | 'settings.manage'
    | 'breakglass'
    | 'override'
    | 'cd.override';

export interface Persona {
    id: PersonaId;
    userId: number;
    name: string;
    short: string;
    initials: string;
    role: string;
    perms: Perm[];
}
const FRONTLINE: Perm[] = ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness'];
const LEAD: Perm[] = [
    ...FRONTLINE,
    'orders.verify',
    'orders.manage',
    'stock.update',
    'audit.view',
    'reports.export',
    'settings.manage',
];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', userId: 11, name: 'Priya Shah', short: 'Priya S.', initials: 'PS', role: 'Support worker', perms: FRONTLINE },
    daniel: { id: 'daniel', userId: 12, name: 'Daniel Ahn', short: 'Daniel A.', initials: 'DA', role: 'Support worker', perms: FRONTLINE },
    lead: { id: 'lead', userId: 13, name: 'Jordan Tipene', short: 'Jordan T.', initials: 'JT', role: 'House lead', perms: LEAD },
    pm: { id: 'pm', userId: 14, name: 'Rangi Parata', short: 'Rangi P.', initials: 'RP', role: 'Provider manager', perms: [...LEAD, 'breakglass', 'override', 'cd.override'] },
};
export const can = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
/** Frontline = no lead or manager capability (plan §2.1). CD record/witness alone never promotes. */
export const isFrontline = (p: PersonaId) =>
    !(['orders.manage', 'orders.verify', 'stock.update', 'audit.view', 'reports.export', 'settings.manage', 'breakglass'] as Perm[]).some((k) => can(p, k));

/* ───────────── people (synthetic) ───────────── */
export type AllergyStatus = 'recorded' | 'none' | 'unavailable' | 'nkda';
export interface Person {
    id: string;
    clientId: number;
    pref: string;
    legal: string;
    surname: string;
    initials: string;
    house: string;
    photo: boolean;
    born: string;
    nhi: string;
    prefs: string;
    allergy: { status: AllergyStatus; list?: string; source?: string; reviewed?: string };
}
export const PEOPLE: Record<string, Person> = {
    aroha: { id: 'aroha', clientId: 201, pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', initials: 'AN', house: 'Kōwhai House', photo: true, born: '14 March 1987', nhi: 'ZAA0024', prefs: 'Likes a quiet space for medicines. Te reo Māori greetings welcome.', allergy: { status: 'recorded', list: 'Penicillin (severe) · Latex (mild)', source: 'From the medication allergy list and the health profile', reviewed: 'reviewed 12 August 2026 by Jordan Tipene' } },
    tama: { id: 'tama', clientId: 202, pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', initials: 'TW', house: 'Kōwhai House', photo: false, born: '2 July 1979', nhi: 'ZAB0033', prefs: 'Tablets one at a time with water.', allergy: { status: 'none' } },
    mele: { id: 'mele', clientId: 203, pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', initials: 'MF', house: 'Kōwhai House', photo: false, born: '23 November 1992', nhi: 'ZAC0041', prefs: 'Prefers to hold the cup.', allergy: { status: 'recorded', list: 'Penicillin (health profile — severity not recorded)', source: 'From the health profile', reviewed: 'recorded 4 June 2026 by Hana Kereama' } },
    grace: { id: 'grace', clientId: 204, pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', initials: 'GL', house: 'Kōwhai House', photo: false, born: '8 May 1951', nhi: 'ZAD0058', prefs: 'Speaks Cantonese and English.', allergy: { status: 'unavailable' } },
    sam: { id: 'sam', clientId: 205, pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', initials: 'ST', house: 'Kōwhai House', photo: false, born: '30 January 2001', nhi: 'ZAE0066', prefs: 'Manages own morning medicines.', allergy: { status: 'nkda', source: 'Recorded on the health profile by Jordan Tipene, 12 August 2026.' } },
    hine: { id: 'hine', clientId: 206, pref: 'Hine', legal: 'Hine Rāwiri', surname: 'Rāwiri', initials: 'HR', house: 'Kōwhai House', photo: false, born: '19 October 1996', nhi: 'ZAG0082', prefs: 'Here for respite until Friday.', allergy: { status: 'none' } },
    ben: { id: 'ben', clientId: 207, pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', initials: 'BC', house: 'Rimu House', photo: false, born: '11 April 1968', nhi: 'ZAF0075', prefs: '', allergy: { status: 'none' } },
};

/* ───────────── staff and roster (Kōwhai House, checked 9:12 am NZDT) ───────────── */
export type PinState = 'set' | 'notset' | 'locked' | 'reset';
export interface Staff {
    id: string;
    name: string;
    initials: string;
    role: string;
    shift: string;
    now: string;
    onShiftNow: boolean;
    competency: 'current' | 'restricted' | 'expired';
    witnessCd: boolean;
    pin: PinState;
    self?: boolean;
}
const PRIYA: Staff = { id: 'priya', name: 'Priya Shah', initials: 'PS', role: 'Support worker', shift: '7:00 am–3:00 pm', now: 'clocked in 7:02 am', onShiftNow: true, competency: 'current', witnessCd: true, pin: 'set', self: true };
export const ROSTERS: Record<'normal' | 'nowitness' | 'alone', Staff[]> = {
    normal: [
        PRIYA,
        { id: 'daniel', name: 'Daniel Ahn', initials: 'DA', role: 'Support worker', shift: '7:00 am–3:00 pm', now: 'clocked in 6:55 am', onShiftNow: true, competency: 'current', witnessCd: true, pin: 'set' },
        { id: 'mere', name: 'Mere Kahu', initials: 'MK', role: 'Support worker', shift: '7:00 am–3:00 pm', now: 'clocked in 6:58 am', onShiftNow: true, competency: 'current', witnessCd: false, pin: 'notset' },
        { id: 'jordan', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', shift: '8:00 am–4:30 pm', now: 'clocked in 8:04 am', onShiftNow: true, competency: 'current', witnessCd: true, pin: 'set' },
    ],
    // P00 v5 ROSTER.now — nobody else can witness a controlled dose right now.
    nowitness: [
        PRIYA,
        { id: 'mere', name: 'Mere Kahu', initials: 'MK', role: 'Support worker', shift: '7:00 am–3:00 pm', now: 'clocked in 6:58 am', onShiftNow: true, competency: 'current', witnessCd: false, pin: 'notset' },
        { id: 'daniel', name: 'Daniel Ahn', initials: 'DA', role: 'Support worker', shift: '11:00 pm–9:00 am (night)', now: 'clocked out 9:03 am', onShiftNow: false, competency: 'current', witnessCd: true, pin: 'set' },
        { id: 'jordan', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', shift: '3:00 pm–11:00 pm', now: 'not started', onShiftNow: false, competency: 'current', witnessCd: true, pin: 'set' },
    ],
    alone: [
        PRIYA,
        { id: 'jordan', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', shift: '3:00 pm–11:00 pm', now: 'not started', onShiftNow: false, competency: 'current', witnessCd: true, pin: 'set' },
    ],
};

/* ───────────── medication rules (P00 v5 fixtures, Settings › Medication rules) ───────────── */
export interface MedRule {
    id: string;
    sentence: string;
    by: string;
    kind: 'observation' | 'countersign';
    obs?: { key: string; label: string; unit: string; example: string };
}
export const RULES: Record<string, MedRule> = {
    mr1: { id: 'mr1', sentence: 'Before saving a dose of insulin glargine at all houses: record a blood sugar (BSL) reading.', by: 'Hana Kereama, 3 Aug 2026', kind: 'observation', obs: { key: 'bsl', label: 'Blood sugar (BSL) reading', unit: 'mmol/L', example: 'e.g. 6.4' } },
    mr2: { id: 'mr2', sentence: 'Before saving a dose given by subcutaneous injection at Kōwhai House: a second person confirms with their witness PIN.', by: 'Hana Kereama, 19 Aug 2026', kind: 'countersign' },
};

/* ───────────── scheduled doses — Monday 28 September 2026 ───────────── */
export type Support = 'administer' | 'assist' | 'prompt' | 'independent';
export interface Amount {
    n: number;
    unit: string;
    plural: string;
    per?: string;
    step: number;
    range?: [number, number];
}
export interface MedPhoto {
    state: 'current' | 'changed' | 'none';
    taken?: string;
    pack?: string;
    nowPack?: string;
    file?: string;
}
export interface Dose {
    id: string;
    slot: string;
    slotMin: number;
    pid: string;
    med: string;
    strength: string;
    route: string;
    amount: Amount;
    instructions: string;
    support: Support;
    cd?: boolean;
    covert?: { plan: string; reviewDue: string };
    rules?: string[];
    order: { verified: boolean; by?: string; on?: string; prescriber: string; prescribed: string };
    photo: MedPhoto;
    transport?: { vehicle: string; trip: string; packed: string };
    newOrder?: boolean;
}
const tab = (n: number, mg?: string): Amount => ({ n, unit: 'tablet', plural: 'tablets', per: mg, step: 0.5 });
const cap = (n: number, mg?: string): Amount => ({ n, unit: 'capsule', plural: 'capsules', per: mg, step: 1 });
const V = (by = 'Jordan Tipene', on = '3 August 2026') => ({ verified: true, by, on, prescriber: 'Dr Lena Chen', prescribed: '1 August 2026' });

const D = (x: Omit<Dose, 'slotMin'>): Dose => ({ ...x, slotMin: minutesOf(x.slot) });
export const DOSES: Dose[] = [
    D({ id: 'r1', slot: '8:00 am', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', route: 'By mouth', amount: tab(1, '500 mg'), instructions: 'With breakfast', support: 'administer', order: V(), photo: { state: 'current', taken: '3 Sep 2026', pack: 'Pharmacy blister pack, Kōwhai Pharmacy', file: 'metformin.png' } }),
    D({ id: 'r2', slot: '8:00 am', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', route: 'By mouth', amount: tab(1, '500 mg'), instructions: 'Morning and evening', support: 'administer', order: V(), photo: { state: 'none' }, transport: { vehicle: 'Kōwhai van', trip: 'Tama to his 8:15 am physio appointment', packed: '8:05 am by Priya Shah' } }),
    D({ id: 'r3', slot: '8:00 am', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', route: 'By mouth', amount: cap(1, '500 mg'), instructions: 'Three times a day for 5 days', support: 'administer', order: V('Jordan Tipene', '27 September 2026'), photo: { state: 'none' } }),
    D({ id: 'r4', slot: '8:00 am', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', route: 'By mouth', amount: tab(1, '50 mg'), instructions: 'In the morning', support: 'administer', order: V(), photo: { state: 'current', taken: '9 Sep 2026', pack: 'Pharmacy blister pack', file: 'sertraline.png' } }),
    D({ id: 'r5', slot: '8:00 am', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', route: 'By mouth', amount: tab(1, '10 mg'), instructions: 'Once a day', support: 'independent', order: V(), photo: { state: 'none' } }),
    D({ id: 'r16', slot: '9:00 am', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', route: 'By mouth', amount: tab(1, '50 mg'), instructions: 'Once a day in the morning', support: 'administer', order: V(), photo: { state: 'current', taken: '3 Sep 2026', pack: 'Pharmacy blister pack, Kōwhai Pharmacy', file: 'losartan.png' } }),
    D({ id: 'r6', slot: '9:00 am', pid: 'aroha', med: 'Vitamin D (colecalciferol)', strength: '1.25 mg capsule', route: 'By mouth', amount: cap(1, '1.25 mg'), instructions: 'Monthly, first Monday', support: 'prompt', order: V(), photo: { state: 'changed', taken: '2 Jun 2026', pack: 'Brand A bottle', nowPack: 'the pack supplied on 21 Sep 2026 is a different brand', file: 'vitamin-d.png' } }),
    D({ id: 'r12', slot: '9:00 am', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', route: 'Subcutaneous injection', amount: { n: 10, unit: 'unit', plural: 'units', step: 1 }, instructions: 'Once a day in the morning. Rotate the injection site.', support: 'administer', rules: ['mr1', 'mr2'], order: V(), photo: { state: 'current', taken: '11 Sep 2026', pack: 'Pen from the fridge box', file: 'insulin.png' } }),
    D({ id: 'r7', slot: '9:00 am', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', route: 'By mouth', amount: cap(1, '20 mg'), instructions: 'Before breakfast', support: 'administer', order: { verified: false, prescriber: 'Dr Lena Chen', prescribed: '27 September 2026' }, newOrder: true, photo: { state: 'none' } }),
    D({ id: 'r8', slot: '9:00 am', pid: 'tama', med: 'Macrogol', strength: 'sachet', route: 'By mouth', amount: { n: 1, unit: 'sachet', plural: 'sachets', step: 1 }, instructions: 'Mixed in water', support: 'assist', order: V(), photo: { state: 'none' } }),
    D({ id: 'r11', slot: '9:00 am', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', route: 'By mouth', amount: tab(1, '0.5 mg'), instructions: 'In the morning · needs a witness', support: 'administer', cd: true, order: V(), photo: { state: 'current', taken: '9 Sep 2026', pack: 'Controlled-drug cupboard, pharmacy pack', file: 'clonazepam.png' } }),
    D({ id: 'r13', slot: '9:00 am', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', route: 'By mouth', amount: tab(1, '50 microgram'), instructions: 'Once a day', support: 'administer', covert: { plan: 'Given in a spoon of yoghurt, as set out in Grace’s covert plan (authorised by Dr Lena Chen and Grace’s welfare guardian, 1 June 2026).', reviewDue: '1 December 2026' }, order: V(), photo: { state: 'none' } }),
    D({ id: 'r9', slot: '12:00 pm', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', route: 'By mouth', amount: tab(1, '500 mg'), instructions: 'With lunch', support: 'administer', order: V(), photo: { state: 'current', taken: '3 Sep 2026', pack: 'Pharmacy blister pack, Kōwhai Pharmacy', file: 'metformin.png' } }),
    D({ id: 'r10', slot: '12:00 pm', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', route: 'By mouth', amount: tab(1, '10 mg'), instructions: 'Needs a witness', support: 'administer', cd: true, order: V(), photo: { state: 'none' } }),
];
export const doseById = (id: string) => DOSES.find((d) => d.id === id)!;

/** A respite guest who isn't on the worker's shift (scenario "notOnShift"). */
export const EXTRA_DOSES: Dose[] = [
    D({ id: 'r20', slot: '9:00 am', pid: 'hine', med: 'Sodium valproate', strength: '200 mg tablet', route: 'By mouth', amount: tab(1, '200 mg'), instructions: 'Morning and evening', support: 'administer', order: V('Jordan Tipene', '28 September 2026'), photo: { state: 'none' } }),
    D({ id: 'r21', slot: '9:00 am', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', route: 'By mouth', amount: tab(1, '5 mg'), instructions: 'Once a day', support: 'administer', order: V(), photo: { state: 'none' } }),
];
export const allDoseById = (id: string) => [...DOSES, ...EXTRA_DOSES].find((d) => d.id === id)!;

/* Outcomes already on the chart at 9:12 am. */
export interface Recorded {
    outcome: 'given' | 'prompted' | 'assisted' | 'selfmanaged' | 'reoffered' | 'refused' | 'withheld' | 'away';
    at: string;
    by: string;
    line: string;
    amount?: string;
    extra?: string[];
    warn?: string[];
}
export const SEEDED: Record<string, Recorded> = {
    r1: { outcome: 'given', at: '8:05 am', by: 'Priya Shah', line: 'Given 8:05 am · Priya S.', amount: '1 tablet (500 mg) as ordered' },
    r4: { outcome: 'refused', at: '8:10 am', by: 'Priya Shah', line: 'Refused 8:10 am · Grace said no', extra: ['Follow-up · Priya S. · offer again by 12:00 pm'] },
    r8: { outcome: 'assisted', at: '9:04 am', by: 'Priya Shah', line: 'Taken with assistance 9:04 am · Priya S. mixed the sachet' },
};

/* ───────────── as-needed orders ───────────── */
export interface PrnOrder {
    id: string;
    pid: string;
    med: string;
    strength: string;
    amount: Amount;
    reasons: string[];
    instructions: string;
    maxPer24h: number;
    minHours: number;
    last24h: { at: string; by: string; amount: string }[];
    nextAllowed?: string;
    cd?: boolean;
    photo: MedPhoto;
}
export const PRN: PrnOrder[] = [
    { id: 'p1', pid: 'aroha', med: 'Paracetamol', strength: '500 mg tablet', amount: { n: 2, unit: 'tablet', plural: 'tablets', per: '500 mg', step: 1, range: [1, 2] }, reasons: ['Pain', 'Headache', 'Fever'], instructions: 'For pain · 1 or 2 tablets, up to 4 doses in 24 hours, at least 4 hours apart (from the prescription)', maxPer24h: 4, minHours: 4, last24h: [{ at: '11:40 pm Sunday', by: 'Daniel Ahn', amount: '2 tablets' }], photo: { state: 'current', taken: '3 Sep 2026', pack: 'Pharmacy bottle', file: 'paracetamol.png' } },
    { id: 'p2', pid: 'tama', med: 'Ibuprofen', strength: '200 mg tablet', amount: { n: 2, unit: 'tablet', plural: 'tablets', per: '200 mg', step: 1 }, reasons: ['Pain'], instructions: 'For pain · 2 tablets with food, up to 3 doses in 24 hours, at least 6 hours apart (from the prescription)', maxPer24h: 3, minHours: 6, last24h: [{ at: '11:00 pm Sunday', by: 'Daniel Ahn', amount: '2 tablets' }], photo: { state: 'none' } },
    { id: 'p4', pid: 'mele', med: 'Paracetamol', strength: '500 mg tablet', amount: { n: 2, unit: 'tablet', plural: 'tablets', per: '500 mg', step: 1 }, reasons: ['Pain'], instructions: 'For pain · 2 tablets, up to 4 doses in 24 hours (from the prescription)', maxPer24h: 4, minHours: 4, last24h: [{ at: '8:05 am', by: 'Mere Kahu', amount: '2 tablets' }, { at: '2:10 am', by: 'Daniel Ahn', amount: '2 tablets' }, { at: '9:40 pm Sunday', by: 'Mere Kahu', amount: '2 tablets' }, { at: '4:15 pm Sunday', by: 'Jordan Tipene', amount: '2 tablets' }], photo: { state: 'none' } },
    { id: 'p5', pid: 'grace', med: 'Lorazepam', strength: '0.5 mg tablet', amount: { n: 1, unit: 'tablet', plural: 'tablets', per: '0.5 mg', step: 0.5 }, reasons: ['Distress described in Grace’s support plan'], instructions: 'For distress as described in the support plan · 1 tablet, up to 2 doses in 24 hours (from the prescription)', maxPer24h: 2, minHours: 6, last24h: [], cd: true, photo: { state: 'none' } },
];
export const prnById = (id: string) => PRN.find((p) => p.id === id)!;

/* ───────────── follow-ups owned by the worker (from P00 v5 FU) ───────────── */
export interface FollowUp {
    id: string;
    title: string;
    src: string;
    owner: string;
    due: string;
    state: 'due' | 'overdue' | 'waiting' | 'done';
    line: string;
}
export const FOLLOW_UPS: FollowUp[] = [
    { id: 'fu-overdue', title: 'Check whether ibuprofen helped — Tama', src: 'As-needed dose given 11:00 pm Sunday · night shift', owner: 'Priya Shah', due: 'Was due 11:30 pm Sunday', state: 'overdue', line: 'Carried over from the night shift · 9 h 42 min overdue' },
    { id: 'fu-refusal', title: 'Follow up Grace’s refusal of sertraline', src: 'Refused 8:10 am · Kōwhai House', owner: 'Priya Shah', due: 'Due 12:00 pm', state: 'due', line: 'Offer again, or record why not' },
];

/* ───────────── activity — last 24 hours at Kōwhai House (paginated) ───────────── */
export interface ActivityRow {
    id: string;
    at: string;
    day: 'Today' | 'Sunday';
    pid: string;
    med: string;
    outcome: 'Given' | 'Refused' | 'Withheld' | 'Taken with assistance' | 'Taken with prompting' | 'Given (as needed)';
    by: string;
    detail: string;
}
export const ACTIVITY: ActivityRow[] = [
    { id: 'a1', at: '9:04 am', day: 'Today', pid: 'tama', med: 'Macrogol sachet', outcome: 'Taken with assistance', by: 'Priya Shah', detail: 'Priya S. mixed the sachet' },
    { id: 'a2', at: '8:10 am', day: 'Today', pid: 'grace', med: 'Sertraline 50 mg', outcome: 'Refused', by: 'Priya Shah', detail: 'Grace said no · follow-up due 12:00 pm' },
    { id: 'a3', at: '8:05 am', day: 'Today', pid: 'aroha', med: 'Metformin 500 mg', outcome: 'Given', by: 'Priya Shah', detail: '1 tablet as ordered' },
    { id: 'a4', at: '8:05 am', day: 'Today', pid: 'mele', med: 'Paracetamol 500 mg', outcome: 'Given (as needed)', by: 'Mere Kahu', detail: '2 tablets · for pain · 4th dose in 24 hours' },
    { id: 'a5', at: '2:10 am', day: 'Today', pid: 'mele', med: 'Paracetamol 500 mg', outcome: 'Given (as needed)', by: 'Daniel Ahn', detail: '2 tablets · for pain' },
    { id: 'a6', at: '11:40 pm', day: 'Sunday', pid: 'aroha', med: 'Paracetamol 500 mg', outcome: 'Given (as needed)', by: 'Daniel Ahn', detail: '2 tablets · for pain' },
    { id: 'a7', at: '11:00 pm', day: 'Sunday', pid: 'tama', med: 'Ibuprofen 200 mg', outcome: 'Given (as needed)', by: 'Daniel Ahn', detail: '2 tablets · effect check overdue' },
    { id: 'a8', at: '9:40 pm', day: 'Sunday', pid: 'mele', med: 'Paracetamol 500 mg', outcome: 'Given (as needed)', by: 'Mere Kahu', detail: '2 tablets · for pain' },
    { id: 'a9', at: '8:05 pm', day: 'Sunday', pid: 'tama', med: 'Levetiracetam 500 mg', outcome: 'Given', by: 'Mere Kahu', detail: '1 tablet as ordered' },
    { id: 'a10', at: '8:02 pm', day: 'Sunday', pid: 'grace', med: 'Clonazepam 0.5 mg', outcome: 'Given', by: 'Mere Kahu', detail: 'Witnessed by Jordan Tipene (witness PIN)' },
    { id: 'a11', at: '8:00 pm', day: 'Sunday', pid: 'aroha', med: 'Metformin 500 mg', outcome: 'Given', by: 'Mere Kahu', detail: '1 tablet as ordered' },
    { id: 'a12', at: '5:20 pm', day: 'Sunday', pid: 'mele', med: 'Amoxicillin 500 mg', outcome: 'Withheld', by: 'Jordan Tipene', detail: 'Doctor’s instruction · allergy question sent to Dr Lena Chen' },
    { id: 'a13', at: '5:05 pm', day: 'Sunday', pid: 'sam', med: 'Vitamin B12 1 mg', outcome: 'Taken with prompting', by: 'Jordan Tipene', detail: 'Jordan T. reminded Sam' },
    { id: 'a14', at: '5:00 pm', day: 'Sunday', pid: 'aroha', med: 'Metformin 500 mg', outcome: 'Given', by: 'Jordan Tipene', detail: '1 tablet as ordered' },
    { id: 'a15', at: '4:15 pm', day: 'Sunday', pid: 'mele', med: 'Paracetamol 500 mg', outcome: 'Given (as needed)', by: 'Jordan Tipene', detail: '2 tablets · for pain' },
    { id: 'a16', at: '12:10 pm', day: 'Sunday', pid: 'aroha', med: 'Metformin 500 mg', outcome: 'Given', by: 'Jordan Tipene', detail: '1 tablet as ordered' },
    { id: 'a17', at: '12:10 pm', day: 'Sunday', pid: 'aroha', med: 'Methylphenidate 10 mg', outcome: 'Given', by: 'Jordan Tipene', detail: 'Witnessed by Mere Kahu (witness PIN)' },
    { id: 'a18', at: '12:00 pm', day: 'Sunday', pid: 'tama', med: 'Levetiracetam 500 mg', outcome: 'Refused', by: 'Jordan Tipene', detail: 'Tama was asleep · re-offered and given 12:40 pm' },
    { id: 'a19', at: '9:30 am', day: 'Sunday', pid: 'grace', med: 'Levothyroxine 50 microgram', outcome: 'Given', by: 'Jordan Tipene', detail: 'Covert plan followed' },
    { id: 'a20', at: '9:20 am', day: 'Sunday', pid: 'aroha', med: 'Insulin glargine 100 units/mL', outcome: 'Given', by: 'Jordan Tipene', detail: '10 units · BSL 6.1 mmol/L · confirmed by Mere Kahu (witness PIN)' },
];

export const HOUSE = 'Kōwhai House';
export const SHIFT = '7:00 am–3:00 pm';
