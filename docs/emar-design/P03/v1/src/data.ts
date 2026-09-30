/* Synthetic fixtures for eMAR P03 v1 — Support & self-administration. No real
 * people, staff, medicines or clinical values. People, houses, staff and
 * medicines come from the approved P00 v5 / P01 v2 / P02 v1 fixtures so the
 * designs read the same; the support plans, assessments, agreements and consent
 * changes are P03's. Scores and checks are today's assessment fields
 * (MedicationSelfAdminAssessment); nothing here becomes policy by being shown.
 * The day: Monday 28 September 2026; the moment: 9:12 am NZDT. */

/* ───────────── personas (seeded roles; P02 v1 names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'administer' | 'cd.view' | 'orders.manage' | 'audit.view';
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
const LEAD: Perm[] = [...FRONTLINE, 'orders.manage', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no controlled-medicine access', houses: ['kowhai', 'rimu'], perms: ['view', 'orders.manage', 'audit.view'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', houses: ['kowhai', 'rimu'], perms: LEAD },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
/** Frontline = no lead or manager capability (plan §2.1). */
export const frontline = (p: PersonaId) => !(['orders.manage', 'audit.view'] as Perm[]).some((k) => has(p, k));

/* ───────────── the four support words (P00 v5 / P01 v2, Main 30 Sep) ───────────── */
export type Support = 'selfmanaged' | 'prompt' | 'assist' | 'administer';
/** Most independent first. */
export const SUPPORT_ORDER: Support[] = ['selfmanaged', 'prompt', 'assist', 'administer'];
export const SUPPORT: Record<Support, { label: string; desc: string; recorded: string }> = {
    selfmanaged: { label: 'Self-managed', desc: 'The person manages it', recorded: 'Listed for information · nothing to record' },
    prompt: { label: 'Prompt', desc: 'Staff remind, the person takes it', recorded: 'Recorded as “Taken with prompting”' },
    assist: { label: 'Assist', desc: 'Staff help, the person takes it', recorded: 'Recorded as “Taken with assistance”' },
    administer: { label: 'Administer', desc: 'Staff give the medicine', recorded: 'Recorded as “Given”' },
};
/** Today's assessment result (MedicationSelfAdminAssessment::computeOutcome) in the four words (Q2). */
export type Outcome = 'independent' | 'prompted' | 'supervised' | 'administered';
export const OUTCOME_CAP: Record<Outcome, Support> = { independent: 'selfmanaged', prompted: 'prompt', supervised: 'assist', administered: 'administer' };

/* ───────────── people ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'ben';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'ben'];
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
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', clientId: 201, pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', initials: 'AN', house: 'kowhai', age: 39, nhi: 'ZAA0024', keyWorker: 'Priya Shah' },
    tama: { id: 'tama', clientId: 202, pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', initials: 'TW', house: 'kowhai', age: 47, nhi: 'ZAB0033', keyWorker: 'Daniel Ahn' },
    mele: { id: 'mele', clientId: 203, pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', initials: 'MF', house: 'kowhai', age: 33, nhi: 'ZAC0041', keyWorker: 'Mere Kahu' },
    grace: { id: 'grace', clientId: 204, pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', initials: 'GL', house: 'kowhai', age: 75, nhi: 'ZAD0058', keyWorker: 'Jordan Tipene' },
    sam: { id: 'sam', clientId: 205, pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', initials: 'ST', house: 'kowhai', age: 25, nhi: 'ZAE0066', keyWorker: 'Priya Shah' },
    ben: { id: 'ben', clientId: 207, pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', initials: 'BC', house: 'rimu', age: 58, nhi: 'ZAF0075', keyWorker: 'Sione Taufa' },
};
export const personByClientId = (id: number) => PEOPLE_ORDER.find((p) => PEOPLE[p].clientId === id) ?? null;

/* ───────────── medicines (current orders; P02 v1 fixtures) ───────────── */
export interface Medicine {
    key: string;
    pid: PersonId;
    name: string;
    strength: string;
    when: string;
    prn?: boolean;
    cd?: boolean;
    covert?: boolean;
    /** Current support (the latest assessment or a later recorded change). null = not set yet (new order). */
    support: Support | null;
    /** Order added after the last assessment. */
    newOrder?: string;
}
export const MEDICINES: Medicine[] = [
    { key: 'metformin', pid: 'aroha', name: 'Metformin', strength: '500 mg tablet', when: '8:00 am and 12:00 pm', support: 'administer' },
    { key: 'losartan', pid: 'aroha', name: 'Losartan', strength: '50 mg tablet', when: '9:00 am', support: 'administer' },
    { key: 'vitd', pid: 'aroha', name: 'Vitamin D (colecalciferol)', strength: '1.25 mg capsule', when: '9:00 am, monthly', support: 'administer' },
    { key: 'insulin', pid: 'aroha', name: 'Insulin glargine', strength: '100 units/mL pen', when: '9:00 am', support: 'administer' },
    { key: 'methylphenidate', pid: 'aroha', name: 'Methylphenidate', strength: '10 mg tablet', when: '12:00 pm', cd: true, support: 'administer' },
    { key: 'warfarin', pid: 'aroha', name: 'Warfarin', strength: '1 mg tablet', when: '5:00 pm', support: 'administer' },
    { key: 'omega3', pid: 'aroha', name: 'Omega-3 fish oil', strength: '1000 mg capsule', when: '8:00 am', support: null, newOrder: '26 September 2026' },
    { key: 'paracetamol', pid: 'aroha', name: 'Paracetamol', strength: '500 mg tablet', when: 'When needed', prn: true, support: 'administer' },
    { key: 'levetiracetam', pid: 'tama', name: 'Levetiracetam', strength: '500 mg tablet', when: '8:00 am and 8:00 pm', support: 'administer' },
    { key: 'macrogol', pid: 'tama', name: 'Macrogol', strength: 'sachet', when: '9:00 am', support: 'assist' },
    { key: 'ibuprofen', pid: 'tama', name: 'Ibuprofen', strength: '200 mg tablet', when: 'When needed', prn: true, support: 'administer' },
    { key: 'amoxicillin', pid: 'mele', name: 'Amoxicillin', strength: '500 mg capsule', when: '8:00 am, 2:00 pm, 8:00 pm', support: null, newOrder: '27 September 2026' },
    { key: 'paracetamol-mele', pid: 'mele', name: 'Paracetamol', strength: '500 mg tablet', when: 'When needed', prn: true, support: null },
    { key: 'sertraline', pid: 'grace', name: 'Sertraline', strength: '50 mg tablet', when: '8:00 am', support: 'administer' },
    { key: 'clonazepam', pid: 'grace', name: 'Clonazepam', strength: '0.5 mg tablet', when: '9:00 am', cd: true, support: 'administer' },
    { key: 'levothyroxine', pid: 'grace', name: 'Levothyroxine', strength: '50 microgram tablet', when: '9:00 am', covert: true, support: 'administer' },
    { key: 'lorazepam', pid: 'grace', name: 'Lorazepam', strength: '0.5 mg tablet', when: 'When needed', prn: true, cd: true, support: 'administer' },
    { key: 'cetirizine', pid: 'sam', name: 'Cetirizine', strength: '10 mg tablet', when: '8:00 am', support: 'selfmanaged' },
    { key: 'melatonin', pid: 'sam', name: 'Melatonin', strength: '3 mg modified-release tablet', when: '8:30 pm', support: 'prompt' },
    { key: 'salbutamol', pid: 'sam', name: 'Salbutamol inhaler', strength: '100 microgram per puff', when: 'When needed', prn: true, support: 'selfmanaged' },
    { key: 'amlodipine', pid: 'ben', name: 'Amlodipine', strength: '5 mg tablet', when: '9:00 am', support: 'administer' },
];
export const medsOf = (pid: PersonId) => MEDICINES.filter((m) => m.pid === pid);

/* ───────────── the self-administration assessment (today's fields, plain words) ───────────── */
export const SCORES = [
    { key: 'cognitive', label: 'Understanding and memory', help: 'Remembers what each medicine is for and when to take it' },
    { key: 'dexterity', label: 'Hands and grip', help: 'Can open packs, press out tablets, use an inhaler or pen' },
    { key: 'vision', label: 'Eyesight', help: 'Can read the label and tell medicines apart' },
    { key: 'swallowing', label: 'Swallowing', help: 'Can swallow tablets or capsules safely' },
    { key: 'understanding', label: 'Knows the routine', help: 'Knows the times and what to do if a dose is missed' },
] as const;
export type ScoreKey = (typeof SCORES)[number]['key'];
export const SCORE_WORDS = ['', 'Not at all', 'With a lot of help', 'With some help', 'Mostly', 'Fully'];
export const CHECKS = [
    { key: 'identify', label: 'Knows which medicine is which' },
    { key: 'labels', label: 'Can read and follow the label' },
    { key: 'packaging', label: 'Can open the packaging' },
    { key: 'timing', label: 'Takes it at the right times' },
    { key: 'storage', label: 'Keeps it stored safely' },
    { key: 'willing', label: 'Wants to do it and is engaged' },
] as const;
export type CheckKey = (typeof CHECKS)[number]['key'];
export const INVOLVED = ['The person', 'Whānau or family', 'Welfare guardian or EPOA', 'GP', 'Pharmacist', 'Key worker'];
export const STORAGE = [
    { key: 'own_drawer', label: 'Their own locked drawer' },
    { key: 'own_room', label: 'In their room (not locked)' },
    { key: 'office', label: 'Locked cupboard in the office' },
    { key: 'cd_cupboard', label: 'Controlled-drug cupboard' },
];
export const TRIGGERS = [
    { key: 'hospital', label: 'Back from hospital' },
    { key: 'error', label: 'A medication error or incident' },
    { key: 'order', label: 'A new or changed order' },
    { key: 'refusals', label: 'Refusals or missed doses (3 in 7 days)' },
    { key: 'asked', label: 'The person asked' },
    { key: 'decline', label: 'A change in health or ability' },
] as const;
export type TriggerKey = (typeof TRIGGERS)[number]['key'];

export interface Assessment {
    id: string;
    pid: PersonId;
    assessed: string;
    assessedIso: string;
    by: string;
    with: string[];
    wishes: boolean;
    scores: Record<ScoreKey, number>;
    checks: Record<CheckKey, boolean>;
    outcome: Outcome;
    storage: string;
    intervalMonths: 3 | 6 | 12;
    reassessBy: string;
    reassessIso: string;
    notes: string;
    supersedes?: string;
}
export interface Agreement {
    pid: PersonId;
    agreedBy: string;
    role: 'person' | 'guardian' | 'epoa';
    how: 'signed' | 'verbal';
    witness?: string;
    file?: string;
    staff: string;
    on: string;
    ordering: 'person' | 'service' | 'pharmacy';
    personDoes: string;
    staffDo: string;
}
export interface ConsentChange {
    id: string;
    pid: PersonId;
    at: string;
    med: string;
    direction: 'less' | 'more';
    said: string;
    recordedBy: string;
    effect: string;
}
export interface TriggerEvent {
    id: string;
    pid: PersonId;
    kind: TriggerKey;
    at: string;
    detail: string;
    due: string;
    owner: string;
}
export interface ChangeEvent {
    id: string;
    pid: PersonId;
    at: string;
    what: string;
    who: string;
    before?: string;
    after?: string;
    cd?: boolean;
}

const full = (n: number) => ({ cognitive: n, dexterity: n, vision: n, swallowing: n, understanding: n });
const allChecks = (willing = true) => ({ identify: true, labels: true, packaging: true, timing: true, storage: true, willing });

export const ASSESSMENTS: Assessment[] = [
    {
        id: 'as-aroha-2026', pid: 'aroha', assessed: '12 January 2026', assessedIso: '2026-01-12', by: 'Jordan Tipene', with: ['The person', 'Key worker'], wishes: true,
        scores: { cognitive: 4, dexterity: 3, vision: 4, swallowing: 4, understanding: 3 }, checks: { ...allChecks(), packaging: false },
        outcome: 'prompted', storage: 'Locked cupboard in the office · insulin in the fridge', intervalMonths: 12, reassessBy: '12 January 2027', reassessIso: '2027-01-12',
        notes: 'Aroha takes her monthly vitamin D with a reminder. Staff give the others; she finds blister packs hard to open.',
    },
    {
        id: 'as-tama-2026', pid: 'tama', assessed: '3 March 2026', assessedIso: '2026-03-03', by: 'Daniel Ahn', with: ['The person', 'Whānau or family'], wishes: true,
        scores: { cognitive: 3, dexterity: 3, vision: 3, swallowing: 3, understanding: 2 }, checks: { ...allChecks(), labels: false, timing: false },
        outcome: 'supervised', storage: 'Locked cupboard in the office', intervalMonths: 12, reassessBy: '3 March 2027', reassessIso: '2027-03-03',
        notes: 'Tama mixes his own macrogol sachet with a staff member beside him. Staff give his tablets.',
    },
    {
        id: 'as-grace-2026', pid: 'grace', assessed: '1 June 2026', assessedIso: '2026-06-01', by: 'Hana Kereama', with: ['Welfare guardian or EPOA', 'GP'], wishes: false,
        scores: { cognitive: 1, dexterity: 2, vision: 3, swallowing: 3, understanding: 1 }, checks: { identify: false, labels: false, packaging: false, timing: false, storage: false, willing: false },
        outcome: 'administered', storage: 'Locked cupboard; controlled-drug cupboard', intervalMonths: 6, reassessBy: '1 December 2026', reassessIso: '2026-12-01',
        notes: 'Grace’s welfare guardian agreed that staff give all her medicines. Covert plan for levothyroxine.',
    },
    {
        id: 'as-sam-2025', pid: 'sam', assessed: '1 September 2025', assessedIso: '2025-09-01', by: 'Jordan Tipene', with: ['The person', 'Pharmacist'], wishes: true,
        scores: full(5), checks: allChecks(), outcome: 'independent', storage: 'Sam’s own locked drawer', intervalMonths: 12, reassessBy: '1 September 2026', reassessIso: '2026-09-01',
        notes: 'Sam manages his morning cetirizine and his inhaler, and asked for a bedtime reminder for melatonin.',
    },
    {
        id: 'as-ben-2026', pid: 'ben', assessed: '5 May 2026', assessedIso: '2026-05-05', by: 'Sione Taufa', with: ['The person'], wishes: false,
        scores: { cognitive: 3, dexterity: 2, vision: 3, swallowing: 4, understanding: 2 }, checks: { ...allChecks(false), packaging: false }, outcome: 'administered',
        storage: 'Locked cupboard in the office (moved with Ben)', intervalMonths: 12, reassessBy: '5 May 2027', reassessIso: '2027-05-05', notes: 'Ben prefers staff to give his medicines.',
    },
    // An earlier assessment, superseded (history)
    {
        id: 'as-aroha-2025', pid: 'aroha', assessed: '9 January 2025', assessedIso: '2025-01-09', by: 'Jordan Tipene', with: ['The person'], wishes: true,
        scores: { cognitive: 4, dexterity: 3, vision: 4, swallowing: 4, understanding: 3 }, checks: { ...allChecks(), packaging: false },
        outcome: 'prompted', storage: 'Locked cupboard in the office', intervalMonths: 12, reassessBy: '9 January 2026', reassessIso: '2026-01-09', notes: '', supersedes: undefined,
    },
];
export const currentAssessment = (pid: PersonId) => ASSESSMENTS.filter((a) => a.pid === pid).sort((a, b) => b.assessedIso.localeCompare(a.assessedIso))[0] ?? null;
export const earlierAssessments = (pid: PersonId) => ASSESSMENTS.filter((a) => a.pid === pid).sort((a, b) => b.assessedIso.localeCompare(a.assessedIso)).slice(1);

export const AGREEMENTS: Agreement[] = [
    { pid: 'sam', agreedBy: 'Sam Tuilagi', role: 'person', how: 'signed', file: 'Self-administration agreement — Sam — signed.pdf', staff: 'Jordan Tipene', on: '1 September 2025', ordering: 'person', personDoes: 'Takes cetirizine each morning and his inhaler when needed. Keeps them in his locked drawer. Tells staff when a pack is nearly empty.', staffDo: 'Remind Sam about melatonin at bedtime. Check his drawer supply on Sundays.' },
    { pid: 'aroha', agreedBy: 'Aroha Ngata', role: 'person', how: 'verbal', witness: 'Priya Shah', staff: 'Jordan Tipene', on: '12 January 2026', ordering: 'service', personDoes: 'Takes her monthly vitamin D when reminded.', staffDo: 'Give her other medicines. Remind her about vitamin D on the first Monday.' },
    { pid: 'grace', agreedBy: 'Wei Liu (welfare guardian)', role: 'guardian', how: 'signed', file: 'Welfare guardian consent — Grace — 1 June 2026.pdf', staff: 'Hana Kereama', on: '1 June 2026', ordering: 'service', personDoes: '—', staffDo: 'Give all medicines, including the covert plan for levothyroxine.' },
];

export const CONSENT_CHANGES: ConsentChange[] = [
    { id: 'cc-aroha-vitd', pid: 'aroha', at: 'Sunday 27 September, 9:20 am', med: 'vitd', direction: 'less', said: '“I don’t want to remember it any more — can you just give it to me?”', recordedBy: 'Priya Shah', effect: 'Moved from Prompt to Administer straight away' },
    { id: 'cc-tama-macrogol', pid: 'tama', at: 'Saturday 26 September, 6:40 pm', med: 'macrogol', direction: 'more', said: '“I want to do my sachet myself, like at my brother’s.”', recordedBy: 'Daniel Ahn', effect: 'Stays Assist until the reassessment' },
];

export const TRIGGER_EVENTS: TriggerEvent[] = [
    { id: 'tr-grace-hospital', pid: 'grace', kind: 'hospital', at: 'Friday 25 September', detail: 'Back from Waikato Hospital after 4 nights (fall). Discharge summary lists no medicine changes.', due: 'Friday 2 October', owner: 'Jordan Tipene' },
    { id: 'tr-aroha-consent', pid: 'aroha', kind: 'asked', at: 'Sunday 27 September', detail: 'Aroha asked staff to give her vitamin D (support lowered at once).', due: 'Sunday 4 October', owner: 'Jordan Tipene' },
    { id: 'tr-tama-asked', pid: 'tama', kind: 'asked', at: 'Saturday 26 September', detail: 'Tama asked to mix his own macrogol sachet.', due: 'Saturday 3 October', owner: 'Jordan Tipene' },
];

export const CHANGES: ChangeEvent[] = [
    { id: 'ch4', pid: 'mele', at: 'Sun 27 Sep, 10:05 am', what: 'New order — Amoxicillin (support not set: staff give it)', who: 'Automatic', after: 'Administer until support is set' },
    { id: 'ch1', pid: 'aroha', at: 'Sun 27 Sep, 9:20 am', what: 'Support changed at Aroha’s request — Vitamin D', who: 'Priya Shah', before: 'Prompt', after: 'Administer' },
    { id: 'ch2', pid: 'tama', at: 'Sat 26 Sep, 6:40 pm', what: 'Request recorded — Tama wants to mix his own macrogol', who: 'Daniel Ahn', after: 'Reassessment due by 3 Oct' },
    { id: 'ch9', pid: 'aroha', at: 'Sat 26 Sep, 3:15 pm', what: 'New order — Omega-3 fish oil (support not set: staff give it)', who: 'Automatic', after: 'Administer until support is set' },
    { id: 'ch3', pid: 'grace', at: 'Fri 25 Sep, 4:10 pm', what: 'Reassessment needed — back from hospital', who: 'Automatic', after: 'Reassessment due by 2 Oct · Jordan Tipene' },
    { id: 'ch5', pid: 'sam', at: 'Tue 1 Sep, 12:00 am', what: 'Reassessment date passed', who: 'Automatic', after: 'Support stays as it is · review with Sam' },
    { id: 'ch8', pid: 'grace', at: 'Mon 1 Jun, 11:40 am', what: 'Support confirmed — Clonazepam', who: 'Jordan Tipene', after: 'Administer', cd: true },
    { id: 'ch7', pid: 'grace', at: 'Mon 1 Jun, 11:00 am', what: 'Assessment completed — staff give all medicines', who: 'Hana Kereama', after: 'Administer · welfare guardian agreed' },
    { id: 'ch6', pid: 'aroha', at: 'Mon 12 Jan, 2:30 pm', what: 'Assessment completed — most independence allowed: Prompt', who: 'Jordan Tipene', after: 'Vitamin D Prompt · others Administer' },
];

/* ───────────── P02 v1’s record header numbers (reference values — P03 doesn’t model doses) ───────────── */
export const RECORD_REF: Record<PersonId, { due: number; dueCap: string; late: number; lateCap: string; rec: [number, number] | null; allergy: string; allergyCap: string; allergyTone: 'critical' | 'warning' | 'brand'; inr?: { value: string; on: string; cap: string } }> = {
    aroha: { due: 3, dueCap: 'Until 10:00 am', late: 0, lateCap: 'None late', rec: [1, 4], allergy: '2 recorded', allergyCap: 'Reviewed 12 August', allergyTone: 'critical', inr: { value: '2.4', on: '21 Sep', cap: 'Target 2.0–3.0 · next 5 Oct' } },
    tama: { due: 1, dueCap: 'Until 10:00 am', late: 1, lateCap: 'Oldest due 8:00 am', rec: [0, 2], allergy: 'Not recorded', allergyCap: 'Not reviewed', allergyTone: 'warning' },
    mele: { due: 1, dueCap: 'Until 10:00 am', late: 1, lateCap: 'Oldest due 8:00 am', rec: [0, 2], allergy: '1 recorded', allergyCap: 'Not reviewed', allergyTone: 'critical' },
    grace: { due: 2, dueCap: 'Until 10:00 am', late: 0, lateCap: 'None late', rec: [1, 3], allergy: 'Unavailable', allergyCap: 'Check the health profile', allergyTone: 'warning' },
    sam: { due: 0, dueCap: 'Nothing due right now', late: 0, lateCap: 'None late', rec: null, allergy: 'None known', allergyCap: 'Reviewed 12 August', allergyTone: 'brand' },
    ben: { due: 0, dueCap: 'Nothing due right now', late: 0, lateCap: 'None late', rec: [1, 1], allergy: 'Not recorded', allergyCap: 'Not reviewed', allergyTone: 'warning' },
};
