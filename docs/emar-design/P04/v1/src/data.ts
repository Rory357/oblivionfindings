/* Synthetic fixtures for eMAR P04 v1 — Orders, changes & reconciliation. No
 * real people, prescribers, medicines or clinical values: amounts and
 * instructions are synthetic ORDER data from the prescriber, and nothing here
 * becomes policy by being shown. People, houses and staff come from the approved
 * P00 v5 / P01 v2 / P02 v1 / P03 v1 fixtures; Hine (respite, P01) arrives today.
 * The day: Monday 28 September 2026; the moment: 9:12 am NZDT. */

/* ───────────── personas (seeded roles; P02 / P03 names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'administer' | 'cd.view' | 'orders.manage' | 'orders.verify' | 'audit.view';
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
const LEAD: Perm[] = [...FRONTLINE, 'orders.manage', 'orders.verify', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no controlled-medicine access', houses: ['kowhai', 'rimu'], perms: ['view', 'orders.manage', 'orders.verify', 'audit.view'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', houses: ['kowhai', 'rimu'], perms: LEAD },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => !(['orders.manage', 'orders.verify', 'audit.view'] as Perm[]).some((k) => has(p, k));
/** Staff who can check orders (orders.verify), for “who else can check this”. */
export const CHECKERS: { name: string; role: string; houses: House[] }[] = [
    { name: 'Jordan Tipene', role: 'House lead', houses: ['kowhai'] },
    { name: 'Hana Kereama', role: 'Clinical lead', houses: ['kowhai', 'rimu'] },
    { name: 'Rangi Parata', role: 'Provider manager', houses: ['kowhai', 'rimu'] },
    { name: 'Sione Taufa', role: 'House lead', houses: ['rimu'] },
];
export const STAFF_ON_SHIFT = ['Priya Shah', 'Daniel Ahn', 'Mere Kahu', 'Jordan Tipene'];

/* ───────────── people ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'hine' | 'ben';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'hine', 'ben'];
export interface Person {
    id: PersonId;
    clientId: number;
    pref: string;
    legal: string;
    surname: string;
    house: House;
    /** Allergy status on the health profile (P02: unknown is never “none”). */
    allergy: { state: 'recorded' | 'none' | 'unavailable' | 'nkda'; list: string[] };
    note?: string;
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', clientId: 201, pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', house: 'kowhai', allergy: { state: 'recorded', list: ['Penicillin (severe)', 'Latex (mild)'] } },
    tama: { id: 'tama', clientId: 202, pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', house: 'kowhai', allergy: { state: 'none', list: [] } },
    mele: { id: 'mele', clientId: 203, pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', house: 'kowhai', allergy: { state: 'recorded', list: ['Penicillin (severity not recorded)'] } },
    grace: { id: 'grace', clientId: 204, pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', house: 'kowhai', allergy: { state: 'unavailable', list: [] } },
    sam: { id: 'sam', clientId: 205, pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', house: 'kowhai', allergy: { state: 'nkda', list: [] } },
    hine: { id: 'hine', clientId: 206, pref: 'Hine', legal: 'Hine Rāwiri', surname: 'Rāwiri', house: 'kowhai', allergy: { state: 'recorded', list: ['Codeine (vomiting — from the GP list)'] }, note: 'Respite until Friday · arrived 8:30 am' },
    ben: { id: 'ben', clientId: 207, pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', house: 'rimu', allergy: { state: 'none', list: [] }, note: 'Moved from Kōwhai House today, 8:30 am' },
};
export const personByClientId = (id: number) => PEOPLE_ORDER.find((p) => PEOPLE[p].clientId === id) ?? null;

/* ───────────── orders: one order per medicine, with kept versions (Q1) ───────────── */
export type SourceType = 'written' | 'verbal' | 'phone' | 'reconciliation';
export const SOURCE_LABEL: Record<SourceType, string> = { written: 'Written prescription', verbal: 'Verbal order', phone: 'Phone order', reconciliation: 'Medicines reconciliation' };
export interface Source {
    type: SourceType;
    prescriber: string;
    at: string;
    doc?: string;
    readBack?: { witness: string; at: string };
}
export type VersionState = 'waiting' | 'checked' | 'lone' | 'sentBack';
export interface Version {
    v: number;
    kind: 'new' | 'change' | 'stop';
    source: Source;
    dose: string;
    when: string;
    /** What changed from the previous version, in plain words. */
    changed?: string;
    enteredBy: string;
    enteredAt: string;
    state: VersionState;
    checked?: { by: string; at: string; lone?: { reason: string; secondBy: string; secondDue: string } };
    sentBack?: { by: string; at: string; reason: string };
    /** Verbal and phone orders: the prescriber’s written confirmation (Q3). */
    written?: { due: string; dueIso: string; done?: { by: string; at: string; how: string; file?: string } };
    /** Allergy match at ordering (Q4). */
    allergy?: { match: string; confirmed?: { prescriber: string; by: string; at: string; how: string; note: string } };
}
export interface Order {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    route: string;
    prn?: string;
    indication: string;
    start: string;
    end?: string;
    endIso?: string;
    cd?: boolean;
    covert?: boolean;
    status: 'active' | 'stopped';
    stopped?: { on: string; reason: string };
    /** Oldest first; the current version is the last one that is checked (or lone-checked). */
    versions: Version[];
    supply?: string;
}
const DR = 'Dr Lena Chen';
const W = (dose: string, when: string, enteredBy: string, enteredAt: string, checkedBy: string, checkedAt: string, at = '1 August 2026'): Version => ({ v: 1, kind: 'new', source: { type: 'written', prescriber: DR, at, doc: 'Prescription (scanned)' }, dose, when, enteredBy, enteredAt, state: 'checked', checked: { by: checkedBy, at: checkedAt } });
export const ORDERS: Order[] = [
    // Aroha
    { id: 'o-metformin', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', route: 'By mouth', indication: 'Type 2 diabetes', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '8:00 am and 12:00 pm, with food', 'Jordan Tipene', '1 Aug, 2:10 pm', 'Hana Kereama', '1 Aug, 3:00 pm')], supply: 'Blister packs · Kōwhai Pharmacy' },
    { id: 'o-losartan', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', route: 'By mouth', indication: 'Blood pressure', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '9:00 am', 'Jordan Tipene', '1 Aug, 2:12 pm', 'Hana Kereama', '1 Aug, 3:02 pm')] },
    { id: 'o-insulin', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', route: 'Subcutaneous injection', indication: 'Type 2 diabetes', start: '1 August 2026', status: 'active', versions: [W('10 units', '9:00 am · rotate the site', 'Jordan Tipene', '1 Aug, 2:15 pm', 'Hana Kereama', '1 Aug, 3:04 pm')] },
    { id: 'o-methylphenidate', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', route: 'By mouth', indication: 'ADHD', start: '1 August 2026', cd: true, status: 'active', versions: [W('1 tablet', '12:00 pm · needs a witness', 'Jordan Tipene', '1 Aug, 2:18 pm', 'Rangi Parata', '1 Aug, 4:00 pm')] },
    // Aroha — a new order that matches her penicillin allergy: it can’t be checked until the prescriber confirms (Q4)
    {
        id: 'o-cefalexin', pid: 'aroha', med: 'Cefalexin', strength: '500 mg capsule', route: 'By mouth', indication: 'Skin infection (left shin)', start: '28 September 2026', end: '4 October 2026', endIso: '2026-10-04', status: 'active',
        versions: [{ v: 1, kind: 'new', source: { type: 'written', prescriber: DR, at: 'Mon 28 Sep, 8:05 am', doc: 'Prescription — Aroha Ngata — cefalexin.pdf' }, dose: '1 capsule', when: '8:00 am, 2:00 pm, 8:00 pm for 7 days', enteredBy: 'Hana Kereama', enteredAt: 'Mon 28 Sep, 8:20 am', state: 'waiting', allergy: { match: 'Penicillin (severe) — cefalexin is a cephalosporin; some people with a penicillin allergy react to it' } }],
    },
    { id: 'o-prednisone', pid: 'aroha', med: 'Prednisone', strength: '5 mg tablet', route: 'By mouth', indication: 'Chest infection', start: '15 August 2026', end: '20 August 2026', status: 'stopped', stopped: { on: '20 August 2026', reason: 'Course finished' }, versions: [W('1 tablet', '8:00 am for 5 days', 'Jordan Tipene', '14 Aug, 4:00 pm', 'Hana Kereama', '14 Aug, 4:30 pm', '14 August 2026')] },
    // Tama — a phone order last night, checked, written confirmation due today (Q3)
    { id: 'o-levetiracetam', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', route: 'By mouth', indication: 'Epilepsy', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '8:00 am and 8:00 pm', 'Hana Kereama', '1 Aug, 11:00 am', 'Jordan Tipene', '1 Aug, 2:00 pm')] },
    {
        id: 'o-loratadine', pid: 'tama', med: 'Loratadine', strength: '10 mg tablet', route: 'By mouth', indication: 'Hay fever', start: '28 September 2026', status: 'active',
        versions: [{ v: 1, kind: 'new', source: { type: 'phone', prescriber: DR, at: 'Sun 27 Sep, 4:40 pm', readBack: { witness: 'Daniel Ahn', at: 'Sun 27 Sep, 4:42 pm' } }, dose: '1 tablet', when: '8:00 am, once a day', enteredBy: 'Jordan Tipene', enteredAt: 'Sun 27 Sep, 4:45 pm', state: 'checked', checked: { by: 'Hana Kereama', at: 'Sun 27 Sep, 5:10 pm' }, written: { due: 'end of today (Monday 28 September)', dueIso: '2026-09-28' } }],
    },
    // Mele — a written order waiting to be checked (entered by Jordan, so someone else checks it — Q2)
    {
        id: 'o-omeprazole', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', route: 'By mouth', indication: 'Reflux', start: '28 September 2026', status: 'active',
        versions: [{ v: 1, kind: 'new', source: { type: 'written', prescriber: DR, at: 'Sun 27 Sep', doc: 'Prescription — Mele Fifita — 27 Sep.pdf' }, dose: '1 capsule', when: '9:00 am, before breakfast', enteredBy: 'Jordan Tipene', enteredAt: 'Sun 27 Sep, 4:10 pm', state: 'waiting' }],
    },
    // Mele — penicillin allergy on the profile; the prescriber confirmed it’s safe before it was checked (Q4)
    {
        id: 'o-amoxicillin', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', route: 'By mouth', indication: 'Chest infection', start: '27 September 2026', end: '1 October 2026', endIso: '2026-10-01', status: 'active',
        versions: [{ v: 1, kind: 'new', source: { type: 'written', prescriber: DR, at: 'Sun 27 Sep', doc: 'Prescription — Mele Fifita — amoxicillin.pdf' }, dose: '1 capsule', when: '8:00 am, 2:00 pm, 8:00 pm for 5 days', enteredBy: 'Jordan Tipene', enteredAt: 'Sun 27 Sep, 3:40 pm', state: 'checked', checked: { by: 'Hana Kereama', at: 'Sun 27 Sep, 4:30 pm' }, allergy: { match: 'Penicillin (severity not recorded) — amoxicillin is a penicillin', confirmed: { prescriber: DR, by: 'Jordan Tipene', at: 'Sun 27 Sep, 3:55 pm', how: 'Phone call', note: 'Dr Chen checked the hospital record: the 2019 rash was viral, not an allergy. Safe to give. Health profile to be updated.' } } }],
    },
    { id: 'o-paracetamol-mele', pid: 'mele', med: 'Paracetamol', strength: '500 mg tablet', route: 'By mouth', prn: 'For pain · up to 4 doses in 24 hours', indication: 'Pain', start: '1 August 2026', status: 'active', versions: [W('2 tablets', 'When needed', 'Hana Kereama', '1 Aug, 11:20 am', 'Jordan Tipene', '1 Aug, 2:05 pm')] },
    // Grace — covert levothyroxine; controlled clonazepam
    { id: 'o-sertraline', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', route: 'By mouth', indication: 'Low mood', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '8:00 am', 'Jordan Tipene', '1 Aug, 2:30 pm', 'Hana Kereama', '1 Aug, 3:10 pm')] },
    { id: 'o-levothyroxine', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', route: 'By mouth', indication: 'Underactive thyroid', start: '1 June 2026', covert: true, status: 'active', versions: [W('1 tablet', '9:00 am · covert plan', 'Hana Kereama', '1 Jun, 10:00 am', 'Jordan Tipene', '1 Jun, 11:00 am', '1 June 2026')] },
    { id: 'o-clonazepam', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', route: 'By mouth', indication: 'Anxiety', start: '1 August 2026', cd: true, status: 'active', versions: [W('1 tablet', '9:00 am · needs a witness', 'Jordan Tipene', '1 Aug, 2:35 pm', 'Rangi Parata', '1 Aug, 4:05 pm')] },
    // Sam — a change sent back by the checker (Q2)
    {
        id: 'o-melatonin', pid: 'sam', med: 'Melatonin', strength: '3 mg modified-release tablet', route: 'By mouth', indication: 'Sleep', start: '1 September 2025', end: '10 October 2026', endIso: '2026-10-10', status: 'active',
        versions: [
            W('1 tablet', '8:30 pm', 'Jordan Tipene', '1 Sep 2025', 'Hana Kereama', '1 Sep 2025', '1 September 2025'),
            { v: 2, kind: 'change', source: { type: 'written', prescriber: DR, at: 'Fri 25 Sep', doc: 'Letter from Dr Chen — 25 Sep.pdf' }, dose: '2 tablets (6 mg)', when: '8:30 pm', changed: 'Dose 1 tablet → 2 tablets', enteredBy: 'Jordan Tipene', enteredAt: 'Mon 28 Sep, 8:40 am', state: 'sentBack', sentBack: { by: 'Hana Kereama', at: 'Mon 28 Sep, 9:02 am', reason: 'Dr Chen’s letter says 3 mg — the 6 mg is from the old discharge summary. Check with Dr Chen before re-entering.' } },
        ],
    },
    { id: 'o-cetirizine', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', route: 'By mouth', indication: 'Hay fever', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '8:00 am', 'Jordan Tipene', '1 Aug, 2:40 pm', 'Hana Kereama', '1 Aug, 3:15 pm')] },
    // Ben — Rimu: a phone order checked alone overnight; second check due today (Q2)
    { id: 'o-amlodipine', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', route: 'By mouth', indication: 'Blood pressure', start: '1 August 2026', status: 'active', versions: [W('1 tablet', '9:00 am', 'Sione Taufa', '1 Aug, 10:00 am', 'Hana Kereama', '1 Aug, 1:00 pm')] },
    {
        id: 'o-paracetamol-ben', pid: 'ben', med: 'Paracetamol', strength: '500 mg tablet', route: 'By mouth', prn: 'For pain · up to 4 doses in 24 hours, at least 4 hours apart', indication: 'Toothache', start: '27 September 2026', status: 'active',
        versions: [{ v: 1, kind: 'new', source: { type: 'phone', prescriber: 'Dr Arun Patel (after-hours GP)', at: 'Sun 27 Sep, 9:05 pm', readBack: { witness: 'Ana Lemalu', at: 'Sun 27 Sep, 9:07 pm' } }, dose: '2 tablets', when: 'When needed', enteredBy: 'Sione Taufa', enteredAt: 'Sun 27 Sep, 9:10 pm', state: 'lone', checked: { by: 'Sione Taufa', at: 'Sun 27 Sep, 9:12 pm', lone: { reason: 'Nobody else who can check was on shift or on call — Ben was in pain.', secondBy: 'a house or clinical lead', secondDue: 'end of today (Monday 28 September)' } }, written: { due: 'end of today (Monday 28 September)', dueIso: '2026-09-28' } }],
    },
];
export const ordersOf = (pid: PersonId) => ORDERS.filter((o) => o.pid === pid);

/** The P01 dose-only phone instruction (countersigned in P08a’s dialog). */
export const PHONE_INSTRUCTION = { pid: 'aroha' as PersonId, med: 'Insulin glargine', what: '8 units today instead of 10', prescriber: DR, at: 'Mon 28 Sep, 8:55 am', recordedBy: 'Priya Shah', due: 'end of tomorrow (Tuesday 29 September)' };

/* ───────────── covert authorisations (Q6) ───────────── */
export interface Covert {
    id: string;
    pid: PersonId;
    orderId: string;
    status: 'active' | 'replaced' | 'revoked';
    capacity: { by: string; on: string; outcome: string };
    consulted: { name: string; role: string; on: string; view: string }[];
    pharmacist: { name: string; on: string; advice: string };
    gp: { name: string; on: string; file?: string };
    method: string;
    authorised: string;
    review: string;
    reviewIso: string;
    revoked?: { by: string; on: string; reason: string };
}
export const COVERT: Covert[] = [
    {
        id: 'cv-grace-2026-06', pid: 'grace', orderId: 'o-levothyroxine', status: 'active',
        capacity: { by: 'Hana Kereama', on: '1 June 2026', outcome: 'Grace can’t currently weigh up taking levothyroxine — decision-specific assessment' },
        consulted: [{ name: 'Wei Liu', role: 'Welfare guardian', on: '1 June 2026', view: 'Agrees it’s in Grace’s best interests; wants a review every 6 months' }],
        pharmacist: { name: 'Kōwhai Pharmacy', on: '30 May 2026', advice: 'Can be crushed and mixed with a spoonful of yoghurt. Give at the same time each day; don’t mix with iron.' },
        gp: { name: 'Dr Lena Chen', on: '1 June 2026', file: 'Covert authorisation — Grace — signed.pdf' },
        method: 'Crushed into a spoonful of yoghurt at breakfast, after offering the tablet openly first',
        authorised: '1 June 2026', review: '1 December 2026', reviewIso: '2026-12-01',
    },
    {
        id: 'cv-grace-2026-03', pid: 'grace', orderId: 'o-levothyroxine', status: 'replaced',
        capacity: { by: 'Hana Kereama', on: '1 March 2026', outcome: 'Lacked capacity for this decision' },
        consulted: [{ name: 'Wei Liu', role: 'Welfare guardian', on: '1 March 2026', view: 'Agreed' }],
        pharmacist: { name: 'Kōwhai Pharmacy', on: '28 February 2026', advice: 'Can be crushed.' },
        gp: { name: 'Dr Lena Chen', on: '1 March 2026' },
        method: 'Crushed into yoghurt', authorised: '1 March 2026', review: '1 June 2026', reviewIso: '2026-06-01',
    },
];

/* ───────────── medicines reconciliation (Q5) ───────────── */
export type RecReason = 'movein' | 'hospital' | 'respiteIn' | 'respiteOut' | 'move';
export const REC_REASON: Record<RecReason, string> = { movein: 'Moving in', hospital: 'Back from hospital', respiteIn: 'Respite — arriving', respiteOut: 'Respite — leaving', move: 'House move' };
export type RecDecision = 'continue' | 'change' | 'stop' | 'new' | 'ask';
export interface RecItem {
    key: string;
    med: string;
    detail: string;
    inPack: boolean;
    onList: boolean;
    onChart?: string;
    decision: RecDecision | null;
    note?: string;
    allergy?: string;
}
export interface Reconciliation {
    id: string;
    pid: PersonId;
    reason: RecReason;
    sources: string[];
    started: string;
    by: string;
    due: string;
    status: 'open' | 'signedOff';
    signedOff?: { by: string; at: string };
    items: RecItem[];
}
export const RECONCILIATIONS: Reconciliation[] = [
    {
        id: 'rec-hine', pid: 'hine', reason: 'respiteIn', sources: ['Pharmacy pack brought with Hine', 'GP medication list (faxed 9:00 am)'], started: 'Mon 28 Sep, 8:40 am', by: 'Jordan Tipene', due: 'before 8:00 pm today (citalopram)', status: 'open',
        items: [
            { key: 'citalopram', med: 'Citalopram 20 mg tablet', detail: '1 tablet at 8:00 pm', inPack: true, onList: true, decision: 'new' },
            { key: 'salbutamol', med: 'Salbutamol inhaler 100 microgram per puff', detail: '2 puffs when needed', inPack: true, onList: true, decision: 'new' },
            { key: 'codeine', med: 'Paracetamol with codeine 500 mg/8 mg tablet', detail: '1–2 tablets when needed, up to 8 in 24 hours', inPack: true, onList: true, decision: null, allergy: 'Codeine (vomiting — from the GP list)' },
            { key: 'b12', med: 'Vitamin B12 1000 microgram tablet', detail: '1 tablet in the morning', inPack: true, onList: false, decision: null, note: 'In the pack but not on the GP list' },
        ],
    },
    {
        id: 'rec-grace', pid: 'grace', reason: 'hospital', sources: ['Discharge summary — Waikato Hospital, 25 Sep'], started: 'Fri 25 Sep, 4:20 pm', by: 'Jordan Tipene', due: 'before 8:00 pm, Fri 25 Sep', status: 'signedOff', signedOff: { by: 'Jordan Tipene', at: 'Fri 25 Sep, 5:40 pm' },
        items: [
            { key: 's', med: 'Sertraline 50 mg tablet', detail: 'Unchanged', inPack: true, onList: true, onChart: 'o-sertraline', decision: 'continue' },
            { key: 'l', med: 'Levothyroxine 50 microgram tablet', detail: 'Unchanged', inPack: true, onList: true, onChart: 'o-levothyroxine', decision: 'continue' },
            { key: 'c', med: 'Clonazepam 0.5 mg tablet', detail: 'Unchanged', inPack: true, onList: true, onChart: 'o-clonazepam', decision: 'continue' },
        ],
    },
    {
        id: 'rec-ben', pid: 'ben', reason: 'move', sources: ['Chart and supplies moved from Kōwhai House'], started: 'Mon 28 Sep, 8:35 am', by: 'Sione Taufa', due: 'before 9:00 am (amlodipine)', status: 'signedOff', signedOff: { by: 'Sione Taufa', at: 'Mon 28 Sep, 8:50 am' },
        items: [
            { key: 'a', med: 'Amlodipine 5 mg tablet', detail: 'Unchanged · 26 tablets moved', inPack: true, onList: true, onChart: 'o-amlodipine', decision: 'continue' },
            { key: 'p', med: 'Paracetamol 500 mg tablet', detail: 'Unchanged · bottle moved', inPack: true, onList: true, onChart: 'o-paracetamol-ben', decision: 'continue' },
        ],
    },
];

/* ───────────── changes (kept, never deleted) ───────────── */
export interface OrderEvent {
    id: string;
    at: string;
    pid: PersonId;
    what: string;
    who: string;
    cd?: boolean;
}
export const EVENTS: OrderEvent[] = [
    { id: 'e1', at: 'Mon 28 Sep, 9:02 am', pid: 'sam', what: 'Change sent back — Melatonin version 2 (“the letter says 3 mg”)', who: 'Hana Kereama' },
    { id: 'e2', at: 'Mon 28 Sep, 8:50 am', pid: 'ben', what: 'Reconciliation signed off — house move', who: 'Sione Taufa' },
    { id: 'e3', at: 'Mon 28 Sep, 8:40 am', pid: 'hine', what: 'Reconciliation started — respite, arriving', who: 'Jordan Tipene' },
    { id: 'e4', at: 'Mon 28 Sep, 8:40 am', pid: 'sam', what: 'Change entered — Melatonin 2 tablets (version 2)', who: 'Jordan Tipene' },
    { id: 'e4b', at: 'Mon 28 Sep, 8:20 am', pid: 'aroha', what: 'Written order entered — Cefalexin · allergy match, waiting for the prescriber', who: 'Hana Kereama' },
    { id: 'e5', at: 'Sun 27 Sep, 9:12 pm', pid: 'ben', what: 'Checked alone — Paracetamol (nobody else could check)', who: 'Sione Taufa' },
    { id: 'e6', at: 'Sun 27 Sep, 5:10 pm', pid: 'tama', what: 'Order checked — Loratadine (phone order)', who: 'Hana Kereama' },
    { id: 'e7', at: 'Sun 27 Sep, 4:45 pm', pid: 'tama', what: 'Phone order entered — Loratadine · read back to Dr Chen, witnessed by Daniel Ahn', who: 'Jordan Tipene' },
    { id: 'e8', at: 'Sun 27 Sep, 4:30 pm', pid: 'mele', what: 'Order checked — Amoxicillin (prescriber confirmed the allergy match)', who: 'Hana Kereama' },
    { id: 'e9', at: 'Sun 27 Sep, 4:10 pm', pid: 'mele', what: 'Written order entered — Omeprazole', who: 'Jordan Tipene' },
    { id: 'e10', at: 'Sun 27 Sep, 3:55 pm', pid: 'mele', what: 'Prescriber confirmed it’s safe — Amoxicillin and the penicillin allergy', who: 'Jordan Tipene' },
    { id: 'e11', at: 'Fri 25 Sep, 5:40 pm', pid: 'grace', what: 'Reconciliation signed off — back from hospital', who: 'Jordan Tipene' },
    { id: 'e12', at: 'Sat 1 Aug, 4:05 pm', pid: 'grace', what: 'Order checked — Clonazepam', who: 'Rangi Parata', cd: true },
];
