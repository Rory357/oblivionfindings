/* Synthetic fixtures for eMAR P06 v1 — Stock & pharmacy. No real people,
 * pharmacies, batches or clinical values: quantities, batches and expiries are
 * synthetic, and nothing here becomes policy by being shown. People, houses and
 * staff come from the approved P02 / P03 / P04 fixtures (Hine is on respite; Ben
 * moved to Rimu House this morning). The day: Monday 28 September 2026; the
 * moment: 9:12 am NZDT. */

/* ───────────── personas (seeded roles; P03 / P04 names) — Main, Q5 ───────────── */
export type PersonaId = 'sw' | 'lead' | 'clinical' | 'auditor' | 'pm' | 'rimu';
export type Perm = 'view' | 'administer' | 'cd.view' | 'cd.record' | 'cd.witness' | 'stock.receive' | 'stock.update' | 'audit.view';
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
const FRONTLINE: Perm[] = ['view', 'administer', 'cd.view', 'cd.record', 'cd.witness', 'stock.receive'];
const LEAD: Perm[] = [...FRONTLINE, 'stock.update', 'audit.view'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker · Kōwhai House', houses: ['kowhai'], perms: FRONTLINE },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead · Kōwhai House', houses: ['kowhai'], perms: LEAD },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead · no stock or controlled-medicine access', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    auditor: { id: 'auditor', name: 'Mereana Walsh', initials: 'MW', role: 'Auditor · read only', houses: ['kowhai', 'rimu'], perms: ['view', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', houses: ['kowhai', 'rimu'], perms: LEAD },
    rimu: { id: 'rimu', name: 'Sione Taufa', initials: 'ST', role: 'House lead · Rimu House', houses: ['rimu'], perms: LEAD },
};
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const frontline = (p: PersonaId) => has(p, 'administer') && !has(p, 'stock.update');
export const STAFF_ON_SHIFT = ['Priya Shah', 'Daniel Ahn', 'Mere Kahu', 'Jordan Tipene'];

/* ───────────── people ───────────── */
export type PersonId = 'aroha' | 'tama' | 'mele' | 'grace' | 'sam' | 'hine' | 'ben';
export const PEOPLE_ORDER: PersonId[] = ['aroha', 'tama', 'mele', 'grace', 'sam', 'hine', 'ben'];
export interface Person {
    id: PersonId;
    pref: string;
    legal: string;
    surname: string;
    house: House;
    note?: string;
}
export const PEOPLE: Record<PersonId, Person> = {
    aroha: { id: 'aroha', pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', house: 'kowhai' },
    tama: { id: 'tama', pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', house: 'kowhai' },
    mele: { id: 'mele', pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', house: 'kowhai' },
    grace: { id: 'grace', pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', house: 'kowhai' },
    sam: { id: 'sam', pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', house: 'kowhai' },
    hine: { id: 'hine', pref: 'Hine', legal: 'Hine Rāwiri', surname: 'Rāwiri', house: 'kowhai', note: 'Respite until Friday 2 October' },
    ben: { id: 'ben', pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', house: 'rimu', note: 'Moved from Kōwhai House today' },
};

/* ───────────── lots (Main: EM-10 → lot-level stock; Q2) ───────────── */
export type LotSource = 'pharmacy' | 'broughtIn' | 'cameBack';
export const SOURCE_LABEL: Record<LotSource, string> = { pharmacy: 'Pharmacy delivery', broughtIn: 'Brought in by the person or whānau', cameBack: 'Came back with the person' };
export interface Lot {
    id: string;
    /** null = “Not printed on the pack”, recorded on purpose. */
    batch: string | null;
    /** Expiry as printed (month/year), or null = “Not printed on the pack”. */
    expiry: string | null;
    expiryIso: string | null;
    qty: number;
    received: number;
    source: LotSource;
    ref?: string;
    receivedBy: string;
    receivedAt: string;
    photo?: string;
    reasonShortExpiry?: string;
}
export type Unit = 'tablets' | 'capsules' | 'pens' | 'inhalers';
export interface StockItem {
    id: string;
    pid: PersonId;
    med: string;
    strength: string;
    unit: Unit;
    /** Regular doses a day; null = as-needed (the reorder level is used — Q8). */
    perDay: number | null;
    reorderLevel?: number;
    /** When supply is only needed until a date (a course, a respite stay). */
    until?: { label: string; iso: string; why: string };
    cd?: boolean;
    selfManaged?: boolean;
    coldChain?: boolean;
    covert?: boolean;
    lots: Lot[];
    lastCount?: string;
}
const L = (id: string, batch: string | null, expiry: string | null, expiryIso: string | null, qty: number, received: number, source: LotSource, receivedBy: string, receivedAt: string, extra: Partial<Lot> = {}): Lot => ({ id, batch, expiry, expiryIso, qty, received, source, receivedBy, receivedAt, ...extra });
export const ITEMS: StockItem[] = [
    // Aroha
    { id: 'st-metformin', pid: 'aroha', med: 'Metformin', strength: '500 mg tablet', unit: 'tablets', perDay: 2, lots: [L('lot-mf2208', 'MF2208', '03/2027', '2027-03-31', 12, 56, 'pharmacy', 'Priya Shah', 'Mon 24 Aug', { ref: 'PO-1033', photo: 'ph-mf-aug' }), L('lot-mf2311', 'MF2311', '08/2027', '2027-08-31', 56, 56, 'pharmacy', 'Priya Shah', 'Thu 24 Sep', { ref: 'PO-1038', photo: 'ph-mf-sep' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-losartan', pid: 'aroha', med: 'Losartan', strength: '50 mg tablet', unit: 'tablets', perDay: 1, lots: [L('lot-ls4410', 'LS4410', '04/2027', '2027-04-30', 5, 28, 'pharmacy', 'Daniel Ahn', 'Tue 1 Sep', { ref: 'PO-1034' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-insulin', pid: 'aroha', med: 'Insulin glargine', strength: '100 units/mL pen', unit: 'pens', perDay: 1 / 30, coldChain: true, lots: [L('lot-ig7781', 'IG7781', '10/2026', '2026-10-20', 1, 3, 'pharmacy', 'Jordan Tipene', 'Tue 18 Aug', { ref: 'PO-1031' }), L('lot-ig8120', 'IG8120', '01/2027', '2027-01-31', 2, 2, 'pharmacy', 'Priya Shah', 'Thu 24 Sep', { ref: 'PO-1038' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-methylphenidate', pid: 'aroha', med: 'Methylphenidate', strength: '10 mg tablet', unit: 'tablets', perDay: 1, cd: true, lots: [L('lot-mp3301', 'MP3301', '06/2027', '2027-06-30', 18, 30, 'pharmacy', 'Jordan Tipene', 'Mon 31 Aug', { ref: 'PO-1030' })], lastCount: 'Mon 28 Sep, 7:00 am' },
    { id: 'st-cefalexin', pid: 'aroha', med: 'Cefalexin', strength: '500 mg capsule', unit: 'capsules', perDay: 3, until: { label: 'Sun 4 Oct', iso: '2026-10-04', why: 'the course ends' }, lots: [] },
    // Tama
    { id: 'st-levetiracetam', pid: 'tama', med: 'Levetiracetam', strength: '500 mg tablet', unit: 'tablets', perDay: 2, lots: [L('lot-lv101', 'LV101', '10/2026', '2026-10-05', 6, 56, 'pharmacy', 'Mere Kahu', 'Mon 10 Aug', { ref: 'PO-1029' }), L('lot-lv220', 'LV220', '05/2027', '2027-05-31', 56, 56, 'pharmacy', 'Priya Shah', 'Fri 25 Sep', { ref: 'PO-1039', photo: 'ph-lv-sep' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-loratadine', pid: 'tama', med: 'Loratadine', strength: '10 mg tablet', unit: 'tablets', perDay: 1, lots: [L('lot-lr-home', null, '01/2027', '2027-01-31', 9, 10, 'broughtIn', 'Jordan Tipene', 'Sun 27 Sep', { photo: 'ph-lr' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    // Mele
    { id: 'st-omeprazole', pid: 'mele', med: 'Omeprazole', strength: '20 mg capsule', unit: 'capsules', perDay: 1, lots: [] },
    { id: 'st-amoxicillin', pid: 'mele', med: 'Amoxicillin', strength: '500 mg capsule', unit: 'capsules', perDay: 3, until: { label: 'Thu 1 Oct', iso: '2026-10-01', why: 'the course ends' }, lots: [L('lot-am9902', 'AM9902', '12/2026', '2026-12-31', 11, 15, 'pharmacy', 'Jordan Tipene', 'Sun 27 Sep', { ref: 'PO-1035', photo: 'ph-am' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-paracetamol-mele', pid: 'mele', med: 'Paracetamol', strength: '500 mg tablet', unit: 'tablets', perDay: null, reorderLevel: 16, lots: [L('lot-pc1177', 'PC1177', '08/2028', '2028-08-31', 12, 32, 'pharmacy', 'Daniel Ahn', 'Wed 2 Sep', { ref: 'PO-1032' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    // Grace
    { id: 'st-sertraline', pid: 'grace', med: 'Sertraline', strength: '50 mg tablet', unit: 'tablets', perDay: 1, lots: [L('lot-se5510', 'SE5510', '03/2027', '2027-03-31', 28, 56, 'pharmacy', 'Priya Shah', 'Mon 31 Aug', { ref: 'PO-1030' })], lastCount: 'Mon 28 Sep, 7:40 am' },
    { id: 'st-levothyroxine', pid: 'grace', med: 'Levothyroxine', strength: '50 microgram tablet', unit: 'tablets', perDay: 1, covert: true, lots: [L('lot-lt0712', 'LT0712', '09/2026', '2026-09-20', 4, 90, 'pharmacy', 'Mere Kahu', 'Mon 22 Jun', { ref: 'PO-1021' }), L('lot-lt0955', 'LT0955', '07/2027', '2027-07-31', 60, 90, 'pharmacy', 'Priya Shah', 'Mon 31 Aug', { ref: 'PO-1030' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-clonazepam', pid: 'grace', med: 'Clonazepam', strength: '0.5 mg tablet', unit: 'tablets', perDay: 1, cd: true, lots: [L('lot-cz2240', 'CZ2240', '03/2027', '2027-03-31', 22, 30, 'pharmacy', 'Jordan Tipene', 'Mon 31 Aug', { ref: 'PO-1030' })], lastCount: 'Mon 28 Sep, 7:00 am' },
    // Sam
    { id: 'st-melatonin', pid: 'sam', med: 'Melatonin', strength: '3 mg modified-release tablet', unit: 'tablets', perDay: 1, lots: [L('lot-me6620', 'ME6620', '11/2026', '2026-11-30', 20, 30, 'pharmacy', 'Daniel Ahn', 'Tue 8 Sep', { ref: 'PO-1027' })], lastCount: 'Sun 27 Sep, 8:00 pm' },
    { id: 'st-cetirizine', pid: 'sam', med: 'Cetirizine', strength: '10 mg tablet', unit: 'tablets', perDay: 1, selfManaged: true, lots: [] },
    // Hine (respite)
    { id: 'st-citalopram', pid: 'hine', med: 'Citalopram', strength: '20 mg tablet', unit: 'tablets', perDay: 1, until: { label: 'Fri 2 Oct', iso: '2026-10-02', why: 'respite ends' }, lots: [L('lot-ct3312', 'CT3312', '05/2027', '2027-05-31', 5, 5, 'broughtIn', 'Jordan Tipene', 'Mon 28 Sep, 8:40 am', { photo: 'ph-ct' })] },
    { id: 'st-salbutamol', pid: 'hine', med: 'Salbutamol inhaler', strength: '100 microgram per puff', unit: 'inhalers', perDay: null, until: { label: 'Fri 2 Oct', iso: '2026-10-02', why: 'respite ends' }, lots: [L('lot-sb7710', 'SB7710', '09/2027', '2027-09-30', 1, 1, 'broughtIn', 'Jordan Tipene', 'Mon 28 Sep, 8:40 am')] },
    // Ben (Rimu)
    { id: 'st-amlodipine', pid: 'ben', med: 'Amlodipine', strength: '5 mg tablet', unit: 'tablets', perDay: 1, lots: [L('lot-ad2231', 'AD2231', '01/2027', '2027-01-31', 26, 28, 'cameBack', 'Sione Taufa', 'Mon 28 Sep, 8:35 am', { ref: 'moved from Kōwhai House' })], lastCount: 'Mon 28 Sep, 8:50 am' },
    { id: 'st-paracetamol-ben', pid: 'ben', med: 'Paracetamol', strength: '500 mg tablet', unit: 'tablets', perDay: null, reorderLevel: 16, lots: [L('lot-pc1180', 'PC1180', '08/2028', '2028-08-31', 30, 32, 'cameBack', 'Sione Taufa', 'Mon 28 Sep, 8:35 am', { ref: 'moved from Kōwhai House' })], lastCount: 'Mon 28 Sep, 8:50 am' },
];

/* ───────────── pack photos (Stephan’s decision; Q6) ───────────── */
export interface Photo {
    id: string;
    itemId: string;
    lotId: string;
    takenBy: string;
    takenAt: string;
    pack: string;
}
export const PHOTOS: Photo[] = [
    { id: 'ph-mf-aug', itemId: 'st-metformin', lotId: 'lot-mf2208', takenBy: 'Priya Shah', takenAt: 'Mon 24 Aug', pack: 'Blister pack · Kōwhai Pharmacy label · white round tablets' },
    { id: 'ph-mf-sep', itemId: 'st-metformin', lotId: 'lot-mf2311', takenBy: 'Priya Shah', takenAt: 'Thu 24 Sep', pack: 'Blister pack · Kōwhai Pharmacy label · white round tablets' },
    { id: 'ph-lv-sep', itemId: 'st-levetiracetam', lotId: 'lot-lv220', takenBy: 'Priya Shah', takenAt: 'Fri 25 Sep', pack: 'Box of 56 · blue oval tablets — a different brand from August' },
    { id: 'ph-lr', itemId: 'st-loratadine', lotId: 'lot-lr-home', takenBy: 'Jordan Tipene', takenAt: 'Sun 27 Sep', pack: 'Chemist box brought from home · no batch printed' },
    { id: 'ph-am', itemId: 'st-amoxicillin', lotId: 'lot-am9902', takenBy: 'Jordan Tipene', takenAt: 'Sun 27 Sep', pack: 'Bottle of 15 capsules · red and yellow' },
    { id: 'ph-ct', itemId: 'st-citalopram', lotId: 'lot-ct3312', takenBy: 'Jordan Tipene', takenAt: 'Mon 28 Sep, 8:40 am', pack: 'Hine’s pharmacy blister strip · 5 tablets left' },
];

/* ───────────── pharmacy orders: one supply record each (Q4) ───────────── */
export type OrderState = 'draft' | 'sent' | 'dispensed' | 'part' | 'received' | 'closedShort' | 'cancelled';
export interface PharmacyOrder {
    id: string;
    house: House;
    itemId: string;
    qty: number;
    pharmacy: string;
    state: OrderState;
    createdBy: string;
    createdAt: string;
    sentAt?: string;
    neededBy?: string;
    dispensed?: { batch: string | null; expiry: string | null; qty: number; at: string; recordedBy: string };
    receipts?: { qty: number; at: string; by: string; lotId: string }[];
    stillToCome?: number;
    expected?: string;
    ended?: { reason: string; by: string; at: string };
    cd?: boolean;
}
export const PHARMACY = 'Kōwhai Pharmacy';
export const ORDERS: PharmacyOrder[] = [
    { id: 'PO-1040', house: 'kowhai', itemId: 'st-cefalexin', qty: 21, pharmacy: PHARMACY, state: 'dispensed', createdBy: 'Jordan Tipene', createdAt: 'Mon 28 Sep, 8:10 am', sentAt: 'Mon 28 Sep, 8:12 am', dispensed: { batch: 'CX5521', expiry: '02/2027', qty: 21, at: 'Mon 28 Sep, 9:00 am', recordedBy: 'Jordan Tipene' }, expected: 'today by 12:00 pm' },
    { id: 'PO-1036', house: 'kowhai', itemId: 'st-methylphenidate', qty: 30, pharmacy: PHARMACY, state: 'dispensed', cd: true, createdBy: 'Jordan Tipene', createdAt: 'Fri 25 Sep, 3:00 pm', sentAt: 'Fri 25 Sep, 3:05 pm', dispensed: { batch: 'MP3390', expiry: '08/2027', qty: 30, at: 'Mon 28 Sep, 9:00 am', recordedBy: 'Jordan Tipene' }, expected: 'today by 12:00 pm' },
    { id: 'PO-1039', house: 'kowhai', itemId: 'st-levetiracetam', qty: 112, pharmacy: PHARMACY, state: 'part', createdBy: 'Jordan Tipene', createdAt: 'Wed 23 Sep, 10:00 am', sentAt: 'Wed 23 Sep, 10:02 am', dispensed: { batch: 'LV220', expiry: '05/2027', qty: 56, at: 'Fri 25 Sep, 11:00 am', recordedBy: 'Jordan Tipene' }, receipts: [{ qty: 56, at: 'Fri 25 Sep, 2:10 pm', by: 'Priya Shah', lotId: 'lot-lv220' }], stillToCome: 56, expected: 'Wed 30 Sep' },
    { id: 'PO-1041', house: 'kowhai', itemId: 'st-losartan', qty: 28, pharmacy: PHARMACY, state: 'sent', createdBy: 'Jordan Tipene', createdAt: 'Mon 28 Sep, 8:28 am', sentAt: 'Mon 28 Sep, 8:30 am', neededBy: 'Wed 30 Sep' },
    { id: 'PO-1042', house: 'kowhai', itemId: 'st-paracetamol-mele', qty: 32, pharmacy: PHARMACY, state: 'draft', createdBy: 'Jordan Tipene', createdAt: 'Mon 28 Sep, 8:50 am' },
    { id: 'PO-1038', house: 'kowhai', itemId: 'st-metformin', qty: 56, pharmacy: PHARMACY, state: 'received', createdBy: 'Jordan Tipene', createdAt: 'Mon 21 Sep, 9:00 am', sentAt: 'Mon 21 Sep, 9:02 am', dispensed: { batch: 'MF2311', expiry: '08/2027', qty: 56, at: 'Thu 24 Sep, 10:30 am', recordedBy: 'Jordan Tipene' }, receipts: [{ qty: 56, at: 'Thu 24 Sep, 2:10 pm', by: 'Priya Shah', lotId: 'lot-mf2311' }] },
    { id: 'PO-1037', house: 'kowhai', itemId: 'st-melatonin', qty: 30, pharmacy: PHARMACY, state: 'cancelled', createdBy: 'Jordan Tipene', createdAt: 'Tue 22 Sep, 4:00 pm', sentAt: 'Tue 22 Sep, 4:05 pm', ended: { reason: 'Dr Chen is changing the dose — wait for the new prescription', by: 'Jordan Tipene', at: 'Wed 23 Sep, 9:15 am' } },
    { id: 'PO-2011', house: 'rimu', itemId: 'st-amlodipine', qty: 28, pharmacy: PHARMACY, state: 'sent', createdBy: 'Sione Taufa', createdAt: 'Sun 27 Sep, 5:00 pm', sentAt: 'Sun 27 Sep, 5:02 pm', neededBy: 'Thu 1 Oct' },
];

/* ───────────── counts (Q7: blind, with reasons; differences go to the house lead) ───────────── */
export interface CountLine {
    itemId: string;
    expected: number;
    counted: number;
    reason?: string;
    note?: string;
}
export interface Count {
    id: string;
    house: House;
    scope: 'house' | 'one';
    by: string;
    at: string;
    lines: CountLine[];
    signOff?: { by: string; at: string; outcome: 'accepted' | 'recount' } | null;
    followUpDue?: string;
}
export const COUNTS: Count[] = [
    { id: 'cnt-12', house: 'kowhai', scope: 'one', by: 'Priya Shah', at: 'Mon 28 Sep, 7:40 am', lines: [{ itemId: 'st-sertraline', expected: 28, counted: 26, reason: 'Dropped or lost', note: 'Grace dropped two at breakfast yesterday; one found crushed, one not found.' }], signOff: null, followUpDue: 'end of today' },
    { id: 'cnt-11', house: 'kowhai', scope: 'house', by: 'Mere Kahu', at: 'Sun 27 Sep, 8:00 pm', lines: ['st-metformin', 'st-losartan', 'st-insulin', 'st-levetiracetam', 'st-loratadine', 'st-amoxicillin', 'st-paracetamol-mele', 'st-levothyroxine', 'st-melatonin'].map((id) => ({ itemId: id, expected: 1, counted: 1 })), signOff: { by: 'no differences', at: 'Sun 27 Sep, 8:00 pm', outcome: 'accepted' } },
    { id: 'cnt-r3', house: 'rimu', scope: 'house', by: 'Sione Taufa', at: 'Mon 28 Sep, 8:50 am', lines: [{ itemId: 'st-amlodipine', expected: 26, counted: 26 }, { itemId: 'st-paracetamol-ben', expected: 30, counted: 30 }], signOff: { by: 'no differences', at: 'Mon 28 Sep, 8:50 am', outcome: 'accepted' } },
];

/* ───────────── stock movements (Q7: a record each, with reasons — replaces the audit-log history) ───────────── */
export type MoveKind = 'received' | 'dose' | 'counted' | 'removed' | 'returned' | 'damaged' | 'found' | 'correction' | 'out' | 'back';
export const MOVE_LABEL: Record<MoveKind, string> = { received: 'Received', dose: 'Doses given', counted: 'Counted', removed: 'Expired — removed', returned: 'Returned to the pharmacy', damaged: 'Damaged or dropped', found: 'Found', correction: 'Count correction', out: 'Went out with the person', back: 'Came back with the person' };
export interface Movement {
    id: string;
    itemId: string;
    at: string;
    kind: MoveKind;
    qty: number;
    lotId?: string;
    by: string;
    note?: string;
    open?: boolean;
}
export const MOVEMENTS: Movement[] = [
    { id: 'mv-1', itemId: 'st-levetiracetam', at: 'Mon 28 Sep, 8:30 am', kind: 'out', qty: -1, lotId: 'lot-lv101', by: 'Priya Shah', note: 'To the day programme with Tama · handed to Losa (programme staff) · due back 3:00 pm', open: true },
    { id: 'mv-2', itemId: 'st-metformin', at: 'Mon 28 Sep, 8:04 am', kind: 'dose', qty: -1, lotId: 'lot-mf2208', by: 'Priya Shah', note: '8:00 am dose' },
    { id: 'mv-3', itemId: 'st-levetiracetam', at: 'Mon 28 Sep, 8:02 am', kind: 'dose', qty: -1, lotId: 'lot-lv101', by: 'Priya Shah', note: '8:00 am dose' },
    { id: 'mv-4', itemId: 'st-citalopram', at: 'Mon 28 Sep, 8:40 am', kind: 'received', qty: 5, lotId: 'lot-ct3312', by: 'Jordan Tipene', note: 'Brought in by Hine — respite reconciliation' },
    { id: 'mv-5', itemId: 'st-sertraline', at: 'Mon 28 Sep, 7:40 am', kind: 'counted', qty: 0, lotId: 'lot-se5510', by: 'Priya Shah', note: 'Counted 26, expected 28 — waiting for the house lead to sign off' },
    { id: 'mv-6', itemId: 'st-paracetamol-mele', at: 'Sat 26 Sep, 6:10 pm', kind: 'damaged', qty: -2, lotId: 'lot-pc1177', by: 'Daniel Ahn', note: 'Dropped on the bathroom floor' },
    { id: 'mv-7', itemId: 'st-levetiracetam', at: 'Fri 25 Sep, 2:10 pm', kind: 'received', qty: 56, lotId: 'lot-lv220', by: 'Priya Shah', note: 'PO-1039 — part delivery, 56 still to come' },
    { id: 'mv-8', itemId: 'st-metformin', at: 'Thu 24 Sep, 2:10 pm', kind: 'received', qty: 56, lotId: 'lot-mf2311', by: 'Priya Shah', note: 'PO-1038' },
    { id: 'mv-9', itemId: 'st-melatonin', at: 'Mon 21 Sep, 10:00 am', kind: 'found', qty: 1, lotId: 'lot-me6620', by: 'Mere Kahu', note: 'Found in Sam’s jacket pocket — returned to the cabinet' },
    { id: 'mv-10', itemId: 'st-losartan', at: 'Fri 21 Aug, 3:30 pm', kind: 'returned', qty: -3, by: 'Jordan Tipene', note: 'The old 25 mg tablets, returned after Dr Chen changed the dose to 50 mg' },
];
export const RECORD = (pid: PersonId) => PEOPLE[pid].legal;
