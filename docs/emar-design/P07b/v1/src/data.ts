/* Synthetic fixtures for eMAR P07b v1 — Controlled register, loss &
 * destruction. No real people, pharmacists, registrations or clinical values:
 * balances, batches and events are synthetic, and nothing here becomes policy
 * by being shown. People, houses and staff come from the approved P02 / P03 /
 * P04 / P06 / P07a fixtures. The day: Monday 28 September 2026; the moment:
 * 9:12 am NZDT. */

/* ───────────── personas (Main, Q10: new key medications.controlled.manage) ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'administer' | 'cd.view' | 'cd.record' | 'cd.witness' | 'cd.manage' | 'cd.override' | 'manager' | 'audit.view';
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
const FRONTLINE: Perm[] = ['view', 'administer', 'cd.view', 'cd.record', 'cd.witness'];
const LEAD: Perm[] = [...FRONTLINE, 'cd.manage', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · controlled.manage, no controlled-medicine view', houses: ['kowhai', 'rimu'], perms: ['view', 'cd.manage', 'audit.view'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only, with controlled view', houses: ['kowhai', 'rimu'], perms: ['view', 'cd.view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager · grants overrides', houses: ['kowhai', 'rimu'], perms: [...LEAD, 'cd.override', 'manager'] },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => has(p, 'administer') && !has(p, 'cd.manage');

/* Witnesses on shift, with PIN-1 status and P11 eligibility (Main, Q8). */
export interface Witness {
    name: string;
    house: House;
    pin: 'set' | 'not_set' | 'locked';
    restricted?: boolean;
}
export const WITNESSES: Witness[] = [
    { name: 'Daniel Ahn', house: 'kowhai', pin: 'set' },
    { name: 'Mere Kahu', house: 'kowhai', pin: 'set' },
    { name: 'Priya Shah', house: 'kowhai', pin: 'set' },
    { name: 'Jordan Tipene', house: 'kowhai', pin: 'set' },
    { name: 'Tomasi Fonua', house: 'kowhai', pin: 'set', restricted: true },
    { name: 'Wiremu Hēnare', house: 'kowhai', pin: 'locked' },
    { name: 'Ana Lemalu', house: 'rimu', pin: 'set' },
    { name: 'Sione Taufa', house: 'rimu', pin: 'set' },
];

/* ───────────── people ───────────── */
export type PersonId = 'aroha' | 'tama' | 'grace' | 'ben';
export const PEOPLE: Record<PersonId, { pref: string; legal: string; surname: string; house: House }> = {
    aroha: { pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', house: 'kowhai' },
    tama: { pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', house: 'kowhai' },
    grace: { pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', house: 'kowhai' },
    ben: { pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', house: 'rimu' },
};

/* ───────────── the register (Main, Q2, Q3, Q9) ───────────── */
export type NzClass = 'A' | 'B' | 'C';
export interface CdMedicine {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    unit: string;
    cls: NzClass | null;
    /** Today’s UK-style value, kept for the lead’s review (Q9: not auto-mapped). */
    legacySchedule?: string;
    lastCount: string;
}
export const MEDS: CdMedicine[] = [
    { id: 'cd-mph', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', unit: 'tablets', cls: 'B', lastCount: 'Mon 28 Sep, 7:00 am · matched' },
    { id: 'cd-czp', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', unit: 'tablets', cls: 'C', lastCount: 'Mon 28 Sep, 7:00 am · 1 short' },
    { id: 'cd-mdz', pid: 'tama', med: 'Midazolam', strength: '5 mg/mL buccal syringe', unit: 'syringes', cls: null, legacySchedule: 'Schedule 3', lastCount: 'Mon 28 Sep, 7:00 am · matched' },
    { id: 'cd-oxy', pid: 'ben', med: 'Oxycodone', strength: '5 mg capsule', unit: 'capsules', cls: null, legacySchedule: 'Schedule 2', lastCount: 'Mon 28 Sep, 7:10 am · matched' },
];

export type EntryKind = 'receipt' | 'dose' | 'count' | 'out' | 'back' | 'loss' | 'breakage' | 'found' | 'returned' | 'destroyed' | 'correction';
export const ENTRY_LABEL: Record<EntryKind, string> = {
    receipt: 'Received',
    dose: 'Dose given',
    count: 'Count',
    out: 'Went out with the person',
    back: 'Came back',
    loss: 'Loss',
    breakage: 'Breakage or spillage',
    found: 'Stock found',
    returned: 'Returned to the pharmacy for destruction',
    destroyed: 'Destroyed on site',
    correction: 'Correcting entry',
};
export interface Entry {
    id: string;
    medId: string;
    at: string;
    kind: EntryKind;
    change: number;
    before: number;
    after: number;
    by: string;
    witnesses: string[];
    note?: string;
    link?: string;
    voided?: { by: string; witness: string; at: string; reason: string; correctedBy?: string };
    corrects?: string;
}
export const ENTRIES: Entry[] = [
    // Aroha — methylphenidate: a dose recorded as 2, voided and corrected (Q2)
    { id: 'en-mph-6', medId: 'cd-mph', at: 'Mon 28 Sep, 7:00 am', kind: 'count', change: 0, before: 18, after: 18, by: 'Priya Shah', witnesses: ['Daniel Ahn'], note: 'Shift-change count — matched' },
    { id: 'en-mph-5', medId: 'cd-mph', at: 'Sat 26 Sep, 12:05 pm', kind: 'dose', change: -1, before: 19, after: 18, by: 'Mere Kahu', witnesses: ['Daniel Ahn'] },
    { id: 'en-mph-4', medId: 'cd-mph', at: 'Fri 25 Sep, 4:30 pm', kind: 'correction', change: -1, before: 20, after: 19, by: 'Jordan Tipene', witnesses: ['Daniel Ahn'], corrects: 'en-mph-3', note: 'The 12:10 pm dose was one tablet' },
    { id: 'en-mph-3', medId: 'cd-mph', at: 'Fri 25 Sep, 12:10 pm', kind: 'dose', change: -2, before: 20, after: 18, by: 'Priya Shah', witnesses: ['Mere Kahu'], voided: { by: 'Jordan Tipene', witness: 'Daniel Ahn', at: 'Fri 25 Sep, 4:30 pm', reason: 'Wrong amount — one tablet was given, two were recorded', correctedBy: 'en-mph-4' } },
    { id: 'en-mph-2', medId: 'cd-mph', at: 'Thu 24 Sep, 12:00 pm', kind: 'dose', change: -1, before: 21, after: 20, by: 'Mere Kahu', witnesses: ['Priya Shah'] },
    // Grace — clonazepam: this morning’s count was one short (P07a Q3: the register follows the count)
    { id: 'en-czp-3', medId: 'cd-czp', at: 'Mon 28 Sep, 7:00 am', kind: 'count', change: -1, before: 22, after: 21, by: 'Priya Shah', witnesses: ['Daniel Ahn'], note: 'Counted 21, recounted 21 — discrepancy D-14 started', link: 'D-14' },
    { id: 'en-czp-2', medId: 'cd-czp', at: 'Sun 27 Sep, 9:00 am', kind: 'dose', change: -1, before: 23, after: 22, by: 'Priya Shah', witnesses: ['Mere Kahu'] },
    { id: 'en-czp-1', medId: 'cd-czp', at: 'Sat 26 Sep, 9:02 am', kind: 'dose', change: -1, before: 24, after: 23, by: 'Mere Kahu', witnesses: ['Daniel Ahn'] },
    // Tama — midazolam: last week’s missing syringe (D-12 → loss L-7), and an expired one returned today
    { id: 'en-mdz-4', medId: 'cd-mdz', at: 'Mon 28 Sep, 8:45 am', kind: 'returned', change: -1, before: 5, after: 4, by: 'Jordan Tipene', witnesses: ['Mere Kahu'], note: 'Expired 09/2026 — in the returns bag for Kōwhai Pharmacy', link: 'DS-21' },
    { id: 'en-mdz-3', medId: 'cd-mdz', at: 'Mon 28 Sep, 7:00 am', kind: 'count', change: 0, before: 5, after: 5, by: 'Priya Shah', witnesses: ['Daniel Ahn'], note: 'Shift-change count — matched' },
    { id: 'en-mdz-2', medId: 'cd-mdz', at: 'Fri 25 Sep, 4:10 pm', kind: 'loss', change: 0, before: 5, after: 5, by: 'Jordan Tipene', witnesses: ['Mere Kahu'], note: 'Unexplained loss of 1 syringe — the count on Fri 25 Sep had already moved the balance (D-12)', link: 'L-7' },
    { id: 'en-mdz-1', medId: 'cd-mdz', at: 'Fri 25 Sep, 3:30 pm', kind: 'count', change: -1, before: 6, after: 5, by: 'Mere Kahu', witnesses: ['Daniel Ahn'], note: 'Counted 5, recounted 5 — discrepancy D-12 started', link: 'D-12' },
    // Ben — oxycodone (Rimu); the class still says “Schedule 2”
    { id: 'en-oxy-3', medId: 'cd-oxy', at: 'Mon 28 Sep, 7:10 am', kind: 'count', change: 0, before: 9, after: 9, by: 'Ana Lemalu', witnesses: ['Sione Taufa'], note: 'Shift-change count — matched' },
    { id: 'en-oxy-2', medId: 'cd-oxy', at: 'Mon 28 Sep, 7:05 am', kind: 'dose', change: -1, before: 10, after: 9, by: 'Sione Taufa', witnesses: [], note: 'Given under witness override OV-9 (no second person on shift)', link: 'OV-9' },
    { id: 'en-oxy-1', medId: 'cd-oxy', at: 'Sun 27 Sep, 8:00 pm', kind: 'dose', change: -1, before: 11, after: 10, by: 'Ana Lemalu', witnesses: ['Sione Taufa'] },
];

/* ───────────── discrepancies (P07a starts them; P07b resolves — Q4) ───────────── */
export type Outcome = 'recount' | 'recording' | 'found' | 'loss' | 'escalate';
export interface Discrepancy {
    id: string;
    medId: string;
    started: string;
    countedBy: string;
    witness: string;
    expected: number;
    counted: number;
    owner: string;
    incident: string;
    status: 'open' | 'under_review' | 'closed';
    resolved?: { by: string; at: string; outcome: Outcome; note: string; link?: string };
    escalated?: { by: string; at: string; note: string };
}
export const DISCREPANCIES: Discrepancy[] = [
    { id: 'D-14', medId: 'cd-czp', started: 'Mon 28 Sep, 7:00 am', countedBy: 'Priya Shah', witness: 'Daniel Ahn', expected: 22, counted: 21, owner: 'Jordan Tipene', incident: 'INC-2231', status: 'open' },
    { id: 'D-12', medId: 'cd-mdz', started: 'Fri 25 Sep, 3:30 pm', countedBy: 'Mere Kahu', witness: 'Daniel Ahn', expected: 6, counted: 5, owner: 'Jordan Tipene', incident: 'INC-2224', status: 'closed', resolved: { by: 'Jordan Tipene', at: 'Fri 25 Sep, 4:10 pm', outcome: 'loss', note: 'Checked the day bag, the cupboard and the returns bag with Mere — not found.', link: 'L-7' } },
];

/* ───────────── losses (Q5) ───────────── */
export interface Loss {
    id: string;
    medId: string;
    qty: number;
    discovered: string;
    circumstances: string;
    immediate: string;
    reportedBy: string;
    witness: string;
    incident: string;
    fromDiscrepancy?: string;
    police?: { informed: boolean; ref?: string; at?: string; by?: string };
    regulator?: { informed: boolean; at?: string; by?: string };
    timeline: { at: string; by: string; note: string }[];
    status: 'investigating' | 'awaitingClose' | 'closed';
    closed?: { by: string; at: string; finding: string };
}
export const LOSSES: Loss[] = [
    {
        id: 'L-7', medId: 'cd-mdz', qty: 1, discovered: 'Fri 25 Sep, 3:30 pm', circumstances: 'One buccal midazolam syringe missing at the 3:30 pm count. Two went to the day programme with Tama that morning; both were recorded as coming back.', immediate: 'Searched the cupboard, the day bag and the returns bag with Mere; told Rangi (provider manager).', reportedBy: 'Jordan Tipene', witness: 'Mere Kahu', incident: 'INC-2224', fromDiscrepancy: 'D-12',
        police: { informed: false }, regulator: { informed: false },
        timeline: [
            { at: 'Fri 25 Sep, 4:10 pm', by: 'Jordan Tipene', note: 'Reported from discrepancy D-12 — unexplained loss of 1 syringe.' },
            { at: 'Sat 26 Sep, 10:20 am', by: 'Jordan Tipene', note: 'Rang the day programme: their log shows both syringes handed back to Priya at 2:50 pm.' },
            { at: 'Mon 28 Sep, 8:30 am', by: 'Jordan Tipene', note: 'Priya remembers one syringe; the handback sheet has one signature. Asked the day programme for their CCTV — not available.' },
        ],
        status: 'awaitingClose',
    },
    {
        id: 'L-5', medId: 'cd-czp', qty: 2, discovered: 'Tue 11 Aug, 9:00 pm', circumstances: 'Two tablets dropped down the bathroom drain while being popped from the blister.', immediate: 'Recorded as a loss with Mere as witness; told the house lead.', reportedBy: 'Daniel Ahn', witness: 'Mere Kahu', incident: 'INC-2107',
        police: { informed: false }, regulator: { informed: false },
        timeline: [{ at: 'Tue 11 Aug, 9:15 pm', by: 'Daniel Ahn', note: 'Reported, witnessed by Mere Kahu.' }],
        status: 'closed', closed: { by: 'Rangi Parata', at: 'Wed 12 Aug, 10:00 am', finding: 'Accidental — witnessed at the time. No further action.' },
    },
];

/* ───────────── destructions (Q6: one path — return to the pharmacy by default) ───────────── */
export type DestroyReason = 'expired' | 'stopped' | 'damaged' | 'left' | 'died' | 'surplus';
export const DESTROY_REASON: Record<DestroyReason, string> = { expired: 'Expired', stopped: 'Stopped by the prescriber', damaged: 'Damaged or contaminated', left: 'The person has left the service', died: 'The person has died', surplus: 'No longer needed (surplus)' };
export interface Destruction {
    id: string;
    medId: string;
    qty: number;
    reason: DestroyReason;
    method: 'return' | 'onsite';
    at: string;
    by: string;
    witnesses: string[];
    entry: string;
    pharmacy?: string;
    received?: { pharmacist: string; registration: string; at: string; recordedBy: string };
    voided?: { by: string; witness: string; at: string; reason: string };
    photo?: boolean;
}
export const DESTRUCTIONS: Destruction[] = [
    { id: 'DS-21', medId: 'cd-mdz', qty: 1, reason: 'expired', method: 'return', at: 'Mon 28 Sep, 8:45 am', by: 'Jordan Tipene', witnesses: ['Mere Kahu'], entry: 'en-mdz-4', pharmacy: 'Kōwhai Pharmacy', photo: true },
    { id: 'DS-19', medId: 'cd-czp', qty: 6, reason: 'stopped', method: 'return', at: 'Tue 25 Aug, 2:00 pm', by: 'Jordan Tipene', witnesses: ['Daniel Ahn'], entry: 'en-czp-old', pharmacy: 'Kōwhai Pharmacy', received: { pharmacist: 'Mele Havili (pharmacist, synthetic)', registration: 'S-1024', at: 'Wed 26 Aug, 11:30 am', recordedBy: 'Jordan Tipene' } },
    { id: 'DS-17', medId: 'cd-mph', qty: 4, reason: 'surplus', method: 'return', at: 'Mon 10 Aug, 3:00 pm', by: 'Mere Kahu', witnesses: ['Priya Shah'], entry: 'en-mph-old', pharmacy: 'Kōwhai Pharmacy', voided: { by: 'Rangi Parata', witness: 'Jordan Tipene', at: 'Mon 10 Aug, 4:20 pm', reason: 'Recorded against the wrong medicine — it was the stopped clonazepam, recorded again as DS-18' } },
];
export const ORG_SETTINGS = { onSiteDestruction: false, cadence: 'Every shift change' };

/* ───────────── witness overrides (Safety & oversight — Q7; P01 / P07a flow) ───────────── */
export interface Override {
    id: string;
    house: House;
    medId: string;
    requestedBy: string;
    requestedAt: string;
    why: string;
    decision: { state: 'approved' | 'declined' | 'waiting'; by?: string; at?: string; until?: string; note?: string };
    doses: { at: string; by: string; entry?: string }[];
    followUp?: { owner: string; due: string; overdue?: boolean; done?: { at: string; by: string; witness: string; note: string } };
}
export const OVERRIDES: Override[] = [
    { id: 'OV-9', house: 'rimu', medId: 'cd-oxy', requestedBy: 'Sione Taufa', requestedAt: 'Mon 28 Sep, 6:40 am', why: 'The sleepover staff member went home unwell at 6:15 am; nobody else on shift until 8:30 am.', decision: { state: 'approved', by: 'Rangi Parata', at: 'Mon 28 Sep, 6:45 am', until: '8:30 am' }, doses: [{ at: 'Mon 28 Sep, 7:05 am', by: 'Sione Taufa', entry: 'en-oxy-2' }], followUp: { owner: 'Sione Taufa', due: 'end of the next shift (3:30 pm today)' } },
    { id: 'OV-8', house: 'kowhai', medId: 'cd-czp', requestedBy: 'Daniel Ahn', requestedAt: 'Sun 27 Sep, 8:55 pm', why: 'Mere was busy with Tama.', decision: { state: 'declined', by: 'Rangi Parata', at: 'Sun 27 Sep, 8:58 pm', note: 'Mere is on shift until 10:00 pm — ask her when she’s free. The dose can wait 30 minutes.' }, doses: [] },
    { id: 'OV-7', house: 'kowhai', medId: 'cd-mdz', requestedBy: 'Mere Kahu', requestedAt: 'Fri 25 Sep, 5:50 am', why: 'Tama had a seizure longer than 5 minutes; only one person on the sleepover.', decision: { state: 'approved', by: 'Rangi Parata', at: 'Fri 25 Sep, 5:52 am', until: '6:30 am' }, doses: [{ at: 'Fri 25 Sep, 5:53 am', by: 'Mere Kahu' }], followUp: { owner: 'Jordan Tipene', due: 'end of the next shift (Fri 25 Sep, 3:30 pm)', overdue: true } },
    { id: 'OV-6', house: 'kowhai', medId: 'cd-mph', requestedBy: 'Priya Shah', requestedAt: 'Wed 23 Sep, 11:55 am', why: 'The second staff member was at the GP with Grace.', decision: { state: 'approved', by: 'Rangi Parata', at: 'Wed 23 Sep, 11:58 am', until: '12:30 pm' }, doses: [{ at: 'Wed 23 Sep, 12:02 pm', by: 'Priya Shah' }], followUp: { owner: 'Jordan Tipene', due: 'end of the next shift', done: { at: 'Wed 23 Sep, 2:10 pm', by: 'Jordan Tipene', witness: 'Mere Kahu', note: 'Count matched (22). Checked the dose against the chart.' } } },
];
