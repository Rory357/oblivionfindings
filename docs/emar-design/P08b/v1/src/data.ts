/* Synthetic fixtures for eMAR P08b v1 — Medication errors & incidents. No real
 * people, prescribers or clinical values: medicines and doses are synthetic
 * ORDER data, and nothing here becomes policy by being shown. People, houses,
 * staff and orders come from the approved P02–P05 fixtures; the controlled
 * discrepancy and loss incidents come from P07b v1.1.
 * The day: Monday 28 September 2026; the moment: 9:12 am NZDT. */

/* ───────────── personas (seeded roles; P02–P05 names) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'record' | 'cd.view' | 'errors.manage' | 'incidents.approve' | 'audit.view' | 'settings.org';
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
const FRONTLINE: Perm[] = ['view', 'record', 'cd.view'];
/** The new medications.errors.manage (Main, Q7): team_lead, clinical_lead, coordinator, provider_manager. */
const LEAD: Perm[] = [...FRONTLINE, 'errors.manage', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no controlled-medicine keys', houses: ['kowhai', 'rimu'], perms: ['view', 'record', 'errors.manage', 'audit.view', 'settings.org'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager · closes incidents', houses: ['kowhai', 'rimu'], perms: [...LEAD, 'incidents.approve', 'settings.org'] },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => !(['errors.manage', 'audit.view'] as Perm[]).some((k) => has(p, k));
/** Staff who own an error’s investigation (errors.manage). */
export const OWNERS: { name: string; role: string; houses: House[] }[] = [
    { name: 'Jordan Tipene', role: 'House lead', houses: ['kowhai'] },
    { name: 'Hana Kereama', role: 'Clinical lead', houses: ['kowhai', 'rimu'] },
    { name: 'Rangi Parata', role: 'Provider manager', houses: ['kowhai', 'rimu'] },
    { name: 'Sione Taufa', role: 'House lead', houses: ['rimu'] },
];

/* ───────────── people (P03 v1) ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'ben';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'ben'];
export interface Person {
    id: PersonId;
    pref: string;
    legal: string;
    house: House;
    whanau: string;
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', pref: 'Aroha', legal: 'Aroha Mere Ngata', house: 'kowhai', whanau: 'Wiki Ngata — sister' },
    tama: { id: 'tama', pref: 'Tama', legal: 'Tamati James Walker', house: 'kowhai', whanau: 'Moana Walker — mum' },
    mele: { id: 'mele', pref: 'Mele', legal: 'Mele Fifita', house: 'kowhai', whanau: 'Salote Fifita — sister' },
    grace: { id: 'grace', pref: 'Grace', legal: 'Grace Liu', house: 'kowhai', whanau: 'Ruby Liu — sister, welfare guardian' },
    sam: { id: 'sam', pref: 'Sam', legal: 'Samuel Tuilagi', house: 'kowhai', whanau: 'Lena Tuilagi — mum' },
    ben: { id: 'ben', pref: 'Ben', legal: 'Benjamin Clarke', house: 'rimu', whanau: 'Tom Clarke — brother' },
};

/* ───────────── current orders (P04 v1, as an error report lists them) ───────────── */
export interface Order {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    dose: string;
    when: string;
    cd?: boolean;
    prn?: boolean;
}
export const ORDERS: Order[] = [
    { id: 'o-metformin', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', dose: '1 tablet', when: '8:00 am and 12:00 pm, with food' },
    { id: 'o-losartan', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', dose: '1 tablet', when: '9:00 am' },
    { id: 'o-insulin', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', dose: '10 units', when: '9:00 am' },
    { id: 'o-methylphenidate', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', dose: '1 tablet', when: '12:00 pm', cd: true },
    { id: 'o-levetiracetam', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', dose: '1 tablet', when: '8:00 am and 8:00 pm' },
    { id: 'o-midazolam', pid: 'tama', med: 'Midazolam', strength: '5 mg/mL buccal syringe', dose: 'As in the seizure plan', when: 'When needed', cd: true, prn: true },
    { id: 'o-omeprazole', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', dose: '1 capsule', when: '9:00 am, before breakfast' },
    { id: 'o-amoxicillin', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', dose: '1 capsule', when: '8:00 am, 2:00 pm, 8:00 pm' },
    { id: 'o-sertraline', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', dose: '1 tablet', when: '8:00 am' },
    { id: 'o-levothyroxine', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', dose: '1 tablet', when: '9:00 am · covert plan' },
    { id: 'o-clonazepam', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', dose: '1 tablet', when: '9:00 am', cd: true },
    { id: 'o-melatonin', pid: 'sam', med: 'Melatonin', strength: '3 mg modified-release tablet', dose: '1 tablet', when: '8:30 pm' },
    { id: 'o-cetirizine', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', dose: '1 tablet', when: '8:00 am' },
    { id: 'o-amlodipine', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', dose: '1 tablet', when: '9:00 am' },
];
export const ordersOf = (pid: PersonId) => ORDERS.filter((o) => o.pid === pid);
export const orderOf = (id: string) => ORDERS.find((o) => o.id === id)!;

/* ───────────── errors (Main, Q1–Q10) ───────────── */
/** A fixed list of what went wrong; it drives the neutral summary used outside the error (Q4). */
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
/** Q2 — two plain questions: did it reach the person, and how much harm. */
export type Reach = 'no' | 'yes' | 'unsure';
export const REACH_LABEL: Record<Reach, string> = { no: 'Didn’t reach the person — a near miss', yes: 'Reached the person', unsure: 'Not sure yet' };
export type Harm = 'none' | 'minor' | 'moderate' | 'severe' | 'death' | 'unknown';
export const HARM_LABEL: Record<Harm, string> = { none: 'No harm', minor: 'Minor or temporary harm', moderate: 'Moderate harm', severe: 'Severe or permanent harm', death: 'Death', unknown: 'Harm not known yet' };
export type Source = 'page' | 'dose' | 'notRight' | 'more';
export const SOURCE_LABEL: Record<Source, string> = { page: 'Reported from Medication errors', dose: 'Reported from the dose', notRight: 'From a check — “Not right”', more: '“More than ordered — this already happened”' };
export type Stage = 'triage' | 'investigating' | 'actions' | 'closed';
export interface Entry {
    by: string;
    at: string;
    text: string;
}
export interface Action {
    id: string;
    what: string;
    owner: string;
    due: string;
    dueIso: string;
    done?: { by: string; at: string; note: string };
}
/** Q8 — telling the person (open disclosure), structured and updatable. */
export interface Disclosure {
    state: 'told' | 'notYet';
    who?: string[];
    whanauName?: string;
    by?: string;
    at?: string;
    how?: string;
    why?: string;
}
export interface MedError {
    id: string;
    pid: PersonId;
    orderIds: string[];
    notAbout?: 'none' | 'offChart';
    offChart?: string;
    type: ErrType;
    source: Source;
    occurred: string;
    occurredIso: string;
    reported: { by: string; at: string };
    reach: Reach;
    harm: Harm;
    /** Append-only accounts: the reporter’s, then anyone who added theirs (Q9). */
    accounts: Entry[];
    immediate: string;
    contributing?: string;
    stage: Stage;
    triageDue: string;
    triageDueIso: string;
    triaged?: { by: string; at: string };
    owner?: string;
    investigateDue?: string;
    investigateDueIso?: string;
    notes: Entry[];
    actions: Action[];
    disclosure?: Disclosure;
    incident?: string;
    closed?: { by: string; at: string; note: string };
    reopened?: { by: string; at: string; reason: string }[];
    /** What was done in this session, for the history (append-only). */
    log?: { at: string; text: string }[];
}

export const ERRORS: MedError[] = [
    // Grace — a controlled medicine given early; reported this morning, to triage
    {
        id: 'MED-0048', pid: 'grace', orderIds: ['o-clonazepam'], type: 'wrongTime', source: 'dose', occurred: 'Mon 28 Sep, 8:05 am', occurredIso: '2026-09-28', reported: { by: 'Priya Shah', at: 'Mon 28 Sep, 8:40 am' },
        reach: 'yes', harm: 'none', accounts: [{ by: 'Priya Shah', at: 'Mon 28 Sep, 8:40 am', text: 'Gave Grace her 9:00 am clonazepam at 8:05 with her breakfast medicines — I read the wrong row on the round. Daniel witnessed. (Synthetic.)' }],
        immediate: 'Told Jordan. Checked on Grace at 8:30 — settled, no change.', contributing: 'Two 9:00 am medicines listed next to the 8:00 am round.', stage: 'triage', triageDue: 'by the end of tomorrow (Tue 29 Sep)', triageDueIso: '2026-09-29', notes: [], actions: [],
    },
    // Tama — an evening dose missed; triaged, investigating
    {
        id: 'MED-0047', pid: 'tama', orderIds: ['o-levetiracetam'], type: 'omission', source: 'page', occurred: 'Sun 27 Sep, 8:00 pm', occurredIso: '2026-09-27', reported: { by: 'Daniel Ahn', at: 'Sun 27 Sep, 9:40 pm' },
        reach: 'yes', harm: 'minor', accounts: [{ by: 'Daniel Ahn', at: 'Sun 27 Sep, 9:40 pm', text: 'Tama’s 8:00 pm levetiracetam wasn’t given — the evening round was interrupted by a fire alarm and his dose was missed. (Synthetic.)' }],
        immediate: 'Rang the after-hours GP at 9:45 pm: give the next dose as usual in the morning, watch for seizures overnight. None seen.', stage: 'investigating', triageDue: 'by the end of Mon 28 Sep', triageDueIso: '2026-09-28',
        triaged: { by: 'Jordan Tipene', at: 'Mon 28 Sep, 8:15 am' }, owner: 'Jordan Tipene', investigateDue: 'Wed 30 Sep', investigateDueIso: '2026-09-30',
        notes: [{ by: 'Jordan Tipene', at: 'Mon 28 Sep, 8:20 am', text: 'Spoke with Daniel. The round restarted after the alarm but from the next person. Checking how the round shows who’s been done.' }],
        actions: [], disclosure: { state: 'told', who: ['person', 'whanau'], whanauName: 'Moana Walker — mum', by: 'Jordan Tipene', at: 'Mon 28 Sep, 8:30 am', how: 'In person with Tama; Moana by phone' },
    },
    // Aroha — “More than ordered — this already happened”: an error plus one incident, automatically
    {
        id: 'MED-0045', pid: 'aroha', orderIds: ['o-insulin'], type: 'wrongDose', source: 'more', occurred: 'Fri 25 Sep, 9:05 am', occurredIso: '2026-09-25', reported: { by: 'Priya Shah', at: 'Fri 25 Sep, 9:10 am' },
        reach: 'yes', harm: 'moderate', accounts: [{ by: 'Priya Shah', at: 'Fri 25 Sep, 9:10 am', text: 'Recorded as “More than ordered — this already happened”: 12 units given instead of 10. The pen dial was turned past 10. (Synthetic.)' }],
        immediate: 'Rang the GP. Aroha’s blood sugar went low later that morning; treated as in her diabetes plan. GP informed.', contributing: 'A new pen with a stiffer dial.', stage: 'actions', triageDue: 'by the end of Sat 26 Sep', triageDueIso: '2026-09-26',
        triaged: { by: 'Jordan Tipene', at: 'Fri 25 Sep, 10:00 am' }, owner: 'Jordan Tipene', investigateDue: 'Fri 2 Oct', investigateDueIso: '2026-10-02',
        notes: [{ by: 'Jordan Tipene', at: 'Fri 25 Sep, 2:00 pm', text: 'Pharmacy confirmed the new pen model. Two staff now check the dial for Aroha’s insulin.' }],
        actions: [
            { id: 'A-31', what: 'Two staff check the insulin pen dial until the old pen model is back', owner: 'Jordan Tipene', due: 'Fri 2 Oct', dueIso: '2026-10-02' },
            { id: 'A-30', what: 'Pharmacy to print the dose in large type on the MAR label', owner: 'Jordan Tipene', due: 'Mon 28 Sep', dueIso: '2026-09-28', done: { by: 'Jordan Tipene', at: 'Mon 28 Sep, 8:00 am', note: 'New labels in the cupboard.' } },
        ],
        disclosure: { state: 'told', who: ['person', 'whanau'], whanauName: 'Wiki Ngata — sister', by: 'Jordan Tipene', at: 'Fri 25 Sep, 11:30 am', how: 'In person with Aroha; Wiki by phone' },
        incident: 'INC-2236',
    },
    // Sam — recorded late; everything done, ready to close
    {
        id: 'MED-0044', pid: 'sam', orderIds: ['o-cetirizine'], type: 'recorded', source: 'page', occurred: 'Mon 21 Sep, 8:00 am', occurredIso: '2026-09-21', reported: { by: 'Priya Shah', at: 'Mon 21 Sep, 11:05 am' },
        reach: 'yes', harm: 'none', accounts: [{ by: 'Priya Shah', at: 'Mon 21 Sep, 11:05 am', text: 'Gave Sam’s cetirizine at 8:00 but didn’t record it until 11:00 — the tablet was offline. (Synthetic.)' }],
        immediate: 'Recorded it as soon as I noticed. Checked no one else had given a second dose.', stage: 'actions', triageDue: 'by the end of Tue 22 Sep', triageDueIso: '2026-09-22', triaged: { by: 'Jordan Tipene', at: 'Mon 21 Sep, 1:00 pm' }, owner: 'Jordan Tipene', investigateDue: 'Fri 25 Sep', investigateDueIso: '2026-09-25',
        notes: [{ by: 'Jordan Tipene', at: 'Tue 22 Sep, 9:00 am', text: 'The tablet had lost its connection; the offline banner was showing.' }],
        actions: [{ id: 'A-28', what: 'Reminder at handover: record at the time, even offline — it queues', owner: 'Jordan Tipene', due: 'Wed 23 Sep', dueIso: '2026-09-23', done: { by: 'Jordan Tipene', at: 'Wed 23 Sep, 3:00 pm', note: 'Covered at both handovers.' } }],
        disclosure: { state: 'told', who: ['person'], by: 'Priya Shah', at: 'Mon 21 Sep, 11:10 am', how: 'In person' },
    },
    // Aroha — the wrong medicine; closed by Jordan, so its incident waits for someone who closes incidents
    {
        id: 'MED-0042', pid: 'aroha', orderIds: ['o-metformin', 'o-losartan'], type: 'wrongMedicine', source: 'page', occurred: 'Mon 21 Sep, 12:00 pm', occurredIso: '2026-09-21', reported: { by: 'Mere Kahu', at: 'Mon 21 Sep, 12:20 pm' },
        reach: 'yes', harm: 'moderate', accounts: [{ by: 'Mere Kahu', at: 'Mon 21 Sep, 12:20 pm', text: 'Gave losartan at 12:00 instead of metformin — picked the 9:00 am pack. (Synthetic.)' }],
        immediate: 'Rang the GP; checked Aroha’s blood pressure that afternoon as the GP asked. Metformin given at 12:30.', stage: 'closed', triageDue: 'by the end of Tue 22 Sep', triageDueIso: '2026-09-22', triaged: { by: 'Jordan Tipene', at: 'Mon 21 Sep, 1:30 pm' }, owner: 'Jordan Tipene', investigateDue: 'Fri 25 Sep', investigateDueIso: '2026-09-25',
        notes: [{ by: 'Jordan Tipene', at: 'Wed 23 Sep, 10:00 am', text: 'Packs now stored in time order, labelled on the front.' }],
        actions: [{ id: 'A-27', what: 'Store packs in time order with front labels', owner: 'Jordan Tipene', due: 'Thu 24 Sep', dueIso: '2026-09-24', done: { by: 'Jordan Tipene', at: 'Thu 24 Sep, 9:00 am', note: 'Done at Kōwhai House.' } }],
        disclosure: { state: 'told', who: ['person', 'whanau'], whanauName: 'Wiki Ngata — sister', by: 'Jordan Tipene', at: 'Mon 21 Sep, 2:00 pm', how: 'In person; Wiki by phone' },
        incident: 'INC-2229', closed: { by: 'Jordan Tipene', at: 'Sat 26 Sep, 10:00 am', note: 'Packs stored in time order; no further harm; Aroha and Wiki told.' },
    },
    // Mele — a near miss; closed
    {
        id: 'MED-0043', pid: 'mele', orderIds: [], notAbout: 'none', type: 'wrongPerson', source: 'page', occurred: 'Mon 21 Sep, 8:00 am', occurredIso: '2026-09-21', reported: { by: 'Mere Kahu', at: 'Mon 21 Sep, 8:15 am' },
        reach: 'no', harm: 'none', accounts: [{ by: 'Mere Kahu', at: 'Mon 21 Sep, 8:15 am', text: 'Picked up Grace’s pack for Mele’s round — noticed at the name check before giving. (Synthetic.)' }],
        immediate: 'Put the pack back; gave Mele’s from her own pack.', stage: 'closed', triageDue: 'by the end of Tue 22 Sep', triageDueIso: '2026-09-22', triaged: { by: 'Jordan Tipene', at: 'Mon 21 Sep, 10:00 am' }, owner: 'Jordan Tipene',
        notes: [], actions: [{ id: 'A-26', what: 'Separate shelves per person, named', owner: 'Jordan Tipene', due: 'Tue 22 Sep', dueIso: '2026-09-22', done: { by: 'Jordan Tipene', at: 'Tue 22 Sep, 11:00 am', note: 'Done.' } }],
        closed: { by: 'Jordan Tipene', at: 'Tue 22 Sep, 11:30 am', note: 'Near miss — caught at the name check. Shelves separated.' },
    },
    // Ben — Rimu House; recorded wrongly, investigating; the house lead reported it, so someone else closes it
    {
        id: 'MED-0046', pid: 'ben', orderIds: ['o-amlodipine'], type: 'recorded', source: 'page', occurred: 'Sat 26 Sep, 9:00 am', occurredIso: '2026-09-26', reported: { by: 'Sione Taufa', at: 'Sat 26 Sep, 6:00 pm' },
        reach: 'yes', harm: 'none', accounts: [{ by: 'Sione Taufa', at: 'Sat 26 Sep, 6:00 pm', text: 'Ben’s 9:00 am amlodipine shows as given twice — Ana gave it, and it was signed again on the round. He had one tablet. (Synthetic.)' }],
        immediate: 'Checked the blister: one tablet out. Asked for the record to be corrected.', stage: 'investigating', triageDue: 'by the end of Sun 27 Sep', triageDueIso: '2026-09-27', triaged: { by: 'Sione Taufa', at: 'Sun 27 Sep, 10:00 am' }, owner: 'Sione Taufa', investigateDue: 'Thu 1 Oct', investigateDueIso: '2026-10-01', notes: [], actions: [],
        disclosure: { state: 'notYet', why: 'Ben was at the day programme — telling him on Monday afternoon.' },
    },
    // Older closed reports (the last 90 days), so Closed, the weekly trend and the counts agree
    ...([
        ['MED-0041', 'sam', 'o-melatonin', 'omission', 'yes', 'none', '2026-09-16', 'Wed 16 Sep, 8:30 pm', 'Fri 18 Sep, 3:00 pm'],
        ['MED-0040', 'tama', 'o-levetiracetam', 'wrongTime', 'yes', 'none', '2026-09-10', 'Thu 10 Sep, 8:00 am', 'Mon 14 Sep, 10:00 am'],
        ['MED-0039', 'mele', 'o-amoxicillin', 'recorded', 'no', 'none', '2026-09-04', 'Fri 4 Sep, 2:00 pm', 'Mon 7 Sep, 11:00 am'],
        ['MED-0038', 'aroha', 'o-metformin', 'wrongDose', 'yes', 'minor', '2026-08-27', 'Thu 27 Aug, 12:00 pm', 'Wed 2 Sep, 4:00 pm'],
        ['MED-0037', 'ben', 'o-amlodipine', 'omission', 'yes', 'none', '2026-08-19', 'Wed 19 Aug, 9:00 am', 'Fri 21 Aug, 9:30 am'],
        ['MED-0036', 'grace', 'o-sertraline', 'wrongTime', 'no', 'none', '2026-08-12', 'Wed 12 Aug, 8:00 am', 'Thu 13 Aug, 2:00 pm'],
    ] as const).map(([id, pid, order, type, reach, harm, iso, occurred, closedAt]): MedError => {
        const lead = pid === 'ben' ? 'Sione Taufa' : 'Jordan Tipene';
        return {
            id, pid, orderIds: [order], type, source: 'page', occurred, occurredIso: iso, reported: { by: pid === 'ben' ? 'Ana Lemalu' : 'Mere Kahu', at: occurred },
            reach, harm, accounts: [{ by: pid === 'ben' ? 'Ana Lemalu' : 'Mere Kahu', at: occurred, text: 'An earlier report, kept in full. (Synthetic.)' }], immediate: 'Told the house lead.', stage: 'closed', triageDue: '', triageDueIso: iso,
            triaged: { by: lead, at: occurred }, owner: lead, notes: [], actions: [],
            disclosure: reach === 'no' ? undefined : { state: 'told', who: ['person'], by: lead, at: occurred, how: 'In person' },
            closed: { by: lead, at: closedAt, note: 'Looked into and closed. (Synthetic.)' },
        };
    }),
];

/** Where a report starts with its details filled in (Q1): the dose time for P01’s dose menu, P08a’s check for “Not right”. */
export const DOSE_TIME: Record<string, string> = { 'o-clonazepam': '2026-09-28T09:00', 'o-levetiracetam': '2026-09-28T08:00', 'o-metformin': '2026-09-28T08:00', 'o-levothyroxine': '2026-09-28T09:00', 'o-omeprazole': '2026-09-28T09:00', 'o-insulin': '2026-09-28T09:00' };
export const NOT_RIGHT: Record<string, { check: string; by: string; at: string; note: string }> = {
    'o-omeprazole': { check: 'Morning check', by: 'Jordan Tipene', at: 'Mon 28 Sep, 9:05 am', note: 'Mele’s 9:00 am dose isn’t signed and the capsule is still in the pack.' },
};
export const MORE_THAN: Record<string, { given: string; ordered: string; by: string }> = {
    'o-metformin': { given: '2 tablets', ordered: '1 tablet', by: 'Priya Shah' },
};

/* ───────────── incidents the medication side closes (Q6; P07b Q4) ───────────── */
export type IncSource = 'error' | 'discrepancy' | 'loss';
export interface MedIncident {
    id: string;
    source: IncSource;
    ref: string;
    pid: PersonId;
    cd?: boolean;
    /** The Incidents module’s own status: submitted → reviewed → closed. */
    status: 'submitted' | 'reviewed' | 'closed';
    /** Set when the medication side closed and someone without incident close rights did it. */
    ready?: { by: string; at: string; note: string };
    closed?: { by: string; at: string; outcome: string; note: string };
    sourceOpen?: string;
}
export const INCIDENTS: MedIncident[] = [
    { id: 'INC-2236', source: 'error', ref: 'MED-0045', pid: 'aroha', status: 'submitted' },
    { id: 'INC-2229', source: 'error', ref: 'MED-0042', pid: 'aroha', status: 'submitted', ready: { by: 'Jordan Tipene', at: 'Sat 26 Sep, 10:00 am', note: 'Packs stored in time order; no further harm; Aroha and Wiki told.' } },
    // P07b v1.1 — the controlled count discrepancy (open) and the loss (waiting for a manager)
    { id: 'INC-2231', source: 'discrepancy', ref: 'D-14', pid: 'grace', cd: true, status: 'submitted', sourceOpen: 'The discrepancy is still open' },
    { id: 'INC-2224', source: 'loss', ref: 'L-7', pid: 'tama', cd: true, status: 'submitted', sourceOpen: 'The loss is waiting for a manager to close it' },
    { id: 'INC-2219', source: 'discrepancy', ref: 'D-9', pid: 'aroha', cd: true, status: 'submitted', ready: { by: 'Jordan Tipene', at: 'Thu 24 Sep, 4:00 pm', note: 'Recount matched — a counting slip.' } },
];

/** P11 — the organisation setting for when a reported error must be triaged (Main, Q5 addition). */
export const TRIAGE_DEFAULT = { value: 'nextDay' as 'fourHours' | 'endOfDay' | 'nextDay', reviewed: false };
export const TRIAGE_LABEL = { fourHours: 'Within 4 hours', endOfDay: 'By the end of the day', nextDay: 'By the end of the next day' };
/** P11 v5’s change history (All changes), as at P08b’s clock — rows dated after 28 Sep are left out. */
export const P11_HISTORY = [
    { id: 'h-6', what: 'Medicine rule paused', from: 'Digoxin — record pulse (active)', to: 'Paused', who: 'Hana Kereama', when: '20 Sep 2026 10:41 am', scope: 'All houses', area: 'Medication rules' },
    { id: 'h-5', what: 'Round template paused — Weekend late breakfast', from: 'Active', to: 'Paused', who: 'Jordan Tipene', when: '14 Sep 2026 4:02 pm', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-4', what: 'Round template retired — Night round', from: 'Active', to: 'Retired', who: 'Jordan Tipene', when: '1 Sep 2026 9:30 am', scope: 'Kōwhai House', area: 'Rounds & timing' },
    { id: 'h-3', what: 'Round template — Morning round, default staff', from: 'Everyone rostered', to: 'Sione Taufa', who: 'Sione Taufa', when: '22 Aug 2026 11:15 am', scope: 'Rimu House', area: 'Rounds & timing' },
];
