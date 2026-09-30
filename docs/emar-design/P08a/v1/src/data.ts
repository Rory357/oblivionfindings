/* Synthetic fixtures for eMAR P08a v1 — no real people, staff, medicines or
 * clinical values. People, staff and houses are carried over from the approved
 * P00 v5, P01 v2, P11 v5 and P07a v1 designs; the follow-ups are the ones those
 * packages create (P01 v2's night-shift ibuprofen check and Grace's sertraline
 * refusal, P07a's override doses). Amounts are synthetic ORDER data. The day:
 * Monday 28 September 2026; the moment: 9:12 am NZDT. */

/* ───────────── personas ───────────── */
export type PersonaId = 'sw' | 'daniel' | 'lead' | 'clinical' | 'pm';
export type Perm = 'view' | 'administer' | 'correct' | 'cd.view' | 'cd.record' | 'cd.witness' | 'followups.manage' | 'orders.verify' | 'audit.view' | 'settings.manage';
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
    shift: string | null;
    clockedIn: string | null;
}
const FRONTLINE: Perm[] = ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness'];
export const PERSONAS: Record<PersonaId, Persona> = {
    sw: { id: 'sw', userId: 11, name: 'Priya Shah', short: 'Priya S.', initials: 'PS', role: 'Support worker', perms: FRONTLINE, houses: ['kowhai'], shift: '7:00 am–3:00 pm', clockedIn: '7:02 am' },
    daniel: { id: 'daniel', userId: 12, name: 'Daniel Ahn', short: 'Daniel A.', initials: 'DA', role: 'Support worker', perms: FRONTLINE, houses: ['kowhai'], shift: '7:00 am–3:00 pm', clockedIn: '6:55 am' },
    lead: { id: 'lead', userId: 13, name: 'Jordan Tipene', short: 'Jordan T.', initials: 'JT', role: 'House lead', perms: [...FRONTLINE, 'followups.manage', 'orders.verify', 'audit.view'], houses: ['kowhai'], shift: '8:00 am–4:30 pm', clockedIn: '8:04 am' },
    clinical: { id: 'clinical', userId: 17, name: 'Hana Kereama', short: 'Hana K.', initials: 'HK', role: 'Clinical lead (no controlled-medicine access)', perms: ['view', 'followups.manage', 'orders.verify', 'audit.view', 'settings.manage'], houses: ['kowhai', 'rimu'], shift: null, clockedIn: null },
    pm: { id: 'pm', userId: 14, name: 'Rangi Parata', short: 'Rangi P.', initials: 'RP', role: 'Provider manager', perms: [...FRONTLINE, 'followups.manage', 'orders.verify', 'audit.view', 'settings.manage'], houses: ['kowhai', 'rimu'], shift: null, clockedIn: null },
};
export const can = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
/** Frontline = no lead or manager capability (plan §2.1). */
export const isFrontline = (p: PersonaId) => !(['followups.manage', 'orders.verify', 'audit.view', 'settings.manage'] as Perm[]).some((k) => can(p, k));

/* ───────────── people supported ───────────── */
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

/* ───────────── staff rostered on a covering shift now (for owner and reassign pickers) ───────────── */
export interface Staff {
    id: string;
    name: string;
    initials: string;
    role: string;
    house: HouseKey;
    shift: string;
    now: string;
    onShift: boolean;
}
export const STAFF: Staff[] = [
    { id: 'priya', name: 'Priya Shah', initials: 'PS', role: 'Support worker', house: 'kowhai', shift: '7:00 am–3:00 pm', now: 'clocked in 7:02 am', onShift: true },
    { id: 'daniel', name: 'Daniel Ahn', initials: 'DA', role: 'Support worker', house: 'kowhai', shift: '7:00 am–3:00 pm', now: 'clocked in 6:55 am', onShift: true },
    { id: 'mere', name: 'Mere Kahu', initials: 'MK', role: 'Support worker', house: 'kowhai', shift: '7:00 am–3:00 pm', now: 'at the day programme with Sam until 11:00 am', onShift: true },
    { id: 'jordan', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', house: 'kowhai', shift: '8:00 am–4:30 pm', now: 'clocked in 8:04 am', onShift: true },
    { id: 'wiremu', name: 'Wiremu Hēnare', initials: 'WH', role: 'Support worker', house: 'kowhai', shift: '11:00 pm–7:00 am (night)', now: 'clocked out 7:10 am', onShift: false },
    { id: 'sione', name: 'Sione Taufa', initials: 'ST', role: 'House lead', house: 'rimu', shift: '7:00 am–3:00 pm', now: 'clocked in 6:55 am', onShift: true },
    { id: 'losa', name: 'Losa Tuilagi', initials: 'LT', role: 'Support worker', house: 'rimu', shift: '7:00 am–3:00 pm', now: 'clocked in 7:01 am', onShift: true },
    { id: 'ana', name: 'Ana Lemalu', initials: 'AL', role: 'Support worker', house: 'rimu', shift: '7:00 am–3:00 pm', now: 'clocked in 6:59 am', onShift: true },
];

/* ───────────── follow-ups — every type the approved packages create ───────────── */
export type FuType = 'effect' | 'reoffer' | 'confirm' | 'unconfirmed' | 'partial' | 'disputed' | 'override' | 'countersign' | 'handover';
export const TYPE_LABEL: Record<FuType, string> = {
    effect: 'As-needed effect check',
    reoffer: 'Refusal follow-up',
    confirm: 'Were you there?',
    unconfirmed: 'Not confirmed by a second person',
    partial: 'Partial dose not confirmed',
    disputed: 'Second person disputed',
    override: 'Doses given without a witness',
    countersign: 'Phone instruction to countersign',
    handover: 'Handover not acknowledged',
};
/** Lead follow-ups are closed by house or clinical leads only (Main, 30 Sep, Q4). */
export const LEAD_TYPES: FuType[] = ['unconfirmed', 'partial', 'disputed', 'override', 'countersign', 'handover'];
export const FROM_PACKAGE: Record<FuType, string> = {
    effect: 'P01 — recorded with every as-needed dose',
    reoffer: 'P01 — recorded with every refusal',
    confirm: 'P01 — forgotten-PIN fallback',
    unconfirmed: 'P01 v2 — a medication rule needed a second person and nobody was available',
    partial: 'P00 v4 / P01 — less than ordered, nobody to confirm',
    disputed: 'P00 v3 — the named colleague said “I wasn’t there” or didn’t answer',
    override: 'P07a — a dose recorded under a witness override',
    countersign: 'P00 v5 / P01 — a prescriber’s phone instruction',
    handover: 'P08a — the incoming worker hasn’t acknowledged 1 hour into the shift',
};

export interface FuEvent {
    at: string;
    what: string;
    who: string;
}
export interface FollowUp {
    id: string;
    type: FuType;
    house: HouseKey;
    pid: string | null;
    title: string;
    source: string;
    owner: string | null;
    /** Minutes relative to today’s midnight (negative = Sunday, >1440 = Tuesday). */
    dueMin: number;
    due: string;
    cd?: boolean;
    done?: { at: string; by: string; outcome: string; late?: string };
    couldnt?: { at: string; by: string; reason: string; again: string };
    reassigned?: { from: string; by: string; at: string; why: string };
    carried?: { from: string; at: string; ownerSetAt: string };
    escalated?: string;
    prn?: { med: string; amount: string; reason: string; given: string; by: string };
    refusal?: { med: string; at: string; by: string; said: string; count7: number; history: string[] };
    events: FuEvent[];
}
export const FOLLOW_UPS: FollowUp[] = [
    {
        id: 'fu-ibu', type: 'effect', house: 'kowhai', pid: 'tama', title: 'Check whether ibuprofen helped', source: 'As-needed dose given 11:00 pm Sunday · night shift',
        owner: 'Priya Shah', dueMin: -30, due: '11:30 pm Sunday',
        prn: { med: 'Ibuprofen 200 mg tablet', amount: '2 tablets', reason: 'Pain', given: '11:00 pm Sunday', by: 'Wiremu Hēnare' },
        carried: { from: 'the night shift (Wiremu Hēnare)', at: '7:00 am', ownerSetAt: '7:05 am' },
        events: [
            { at: '11:00 pm Sunday', what: 'Created with the as-needed dose · check at 11:30 pm', who: 'Wiremu Hēnare (owner)' },
            { at: '11:30 pm Sunday', what: 'Overdue', who: 'Everyone rostered and the house lead can see it' },
            { at: '7:00 am', what: 'Carried over to the day shift — the night shift ended with it open', who: 'Automatic' },
            { at: '7:05 am', what: 'Owner set when the handover was acknowledged', who: 'Priya Shah' },
        ],
    },
    {
        id: 'fu-para-mele', type: 'effect', house: 'kowhai', pid: 'mele', title: 'Check whether paracetamol helped', source: 'As-needed dose given 8:05 am',
        owner: 'Priya Shah', dueMin: 9 * 60 + 30, due: '9:30 am',
        prn: { med: 'Paracetamol 500 mg tablet', amount: '2 tablets', reason: 'Pain', given: '8:05 am', by: 'Mere Kahu' },
        reassigned: { from: 'Mere Kahu', by: 'Jordan Tipene', at: '8:40 am', why: 'Mere is supporting Sam at the day programme until 11:00 am' },
        couldnt: { at: '8:45 am', by: 'Priya Shah', reason: 'Asleep', again: '9:30 am' },
        events: [
            { at: '8:05 am', what: 'Created with the as-needed dose · check at 8:35 am', who: 'Mere Kahu (owner)' },
            { at: '8:40 am', what: 'Reassigned to Priya Shah — “Mere is supporting Sam at the day programme until 11:00 am”', who: 'Jordan Tipene' },
            { at: '8:45 am', what: 'Couldn’t check — Mele was asleep · check again at 9:30 am', who: 'Priya Shah' },
        ],
    },
    {
        id: 'fu-para-aroha', type: 'effect', house: 'kowhai', pid: 'aroha', title: 'Check whether paracetamol helped', source: 'As-needed dose given 8:50 am',
        owner: 'Priya Shah', dueMin: 9 * 60 + 50, due: '9:50 am',
        prn: { med: 'Paracetamol 500 mg tablet', amount: '2 tablets', reason: 'Headache', given: '8:50 am', by: 'Priya Shah' },
        events: [{ at: '8:50 am', what: 'Created with the as-needed dose · check at 9:50 am', who: 'Priya Shah (owner)' }],
    },
    {
        id: 'fu-sertraline', type: 'reoffer', house: 'kowhai', pid: 'grace', title: 'Follow up Grace’s refusal of sertraline', source: 'Refused 8:10 am · 3rd refusal in 7 days',
        owner: 'Priya Shah', dueMin: 12 * 60, due: '12:00 pm',
        refusal: { med: 'Sertraline 50 mg tablet', at: '8:10 am', by: 'Priya Shah', said: 'Grace said no', count7: 3, history: ['Saturday 8:05 am — refused', 'Sunday 8:12 am — refused', 'Today 8:10 am — refused'] },
        escalated: 'The 3-in-7-days refusal rule was reached at 8:10 am — Jordan Tipene (house lead) and Hana Kereama (clinical lead) were told',
        events: [
            { at: '8:10 am', what: 'Created with the refusal · offer again by 12:00 pm', who: 'Priya Shah (owner)' },
            { at: '8:10 am', what: '3 refusals in 7 days — the house lead and clinical lead were told (Repeated refusals alert)', who: 'Automatic' },
        ],
    },
    {
        id: 'fu-losartan', type: 'reoffer', house: 'kowhai', pid: 'aroha', title: 'Follow up Aroha’s refusal of losartan', source: 'Refused 9:08 am · first refusal this week',
        owner: 'Priya Shah', dueMin: 10 * 60, due: '10:00 am',
        refusal: { med: 'Losartan 50 mg tablet', at: '9:08 am', by: 'Priya Shah', said: 'Aroha wants it after breakfast', count7: 1, history: ['Today 9:08 am — refused'] },
        events: [{ at: '9:08 am', what: 'Created with the refusal · offer again by 10:00 am', who: 'Priya Shah (owner)' }],
    },
    {
        id: 'fu-were', type: 'confirm', house: 'kowhai', pid: 'aroha', title: 'Were you there? Aroha’s insulin', source: 'Priya Shah named Daniel Ahn as witness at 9:05 am — PIN forgotten',
        owner: 'Daniel Ahn', dueMin: 9 * 60 + 35, due: '9:35 am',
        events: [{ at: '9:05 am', what: 'Priya Shah recorded Aroha’s 9:00 am insulin and named Daniel Ahn as the second person (PIN forgotten) · answer by 9:35 am', who: 'Priya Shah' }],
    },
    {
        id: 'fu-partial', type: 'partial', house: 'kowhai', pid: 'tama', title: 'Partial dose not confirmed — Tama', source: 'Levetiracetam 8:00 am · ½ tablet of 1 taken · recorded 8:20 am by Priya Shah',
        owner: 'Jordan Tipene', dueMin: 23 * 60, due: '11:00 pm',
        events: [
            { at: '8:20 am', what: 'Recorded ½ tablet of 1 (Tama spat half out) · nobody else free to confirm — Daniel and Mere were supporting others', who: 'Priya Shah' },
            { at: '8:20 am', what: 'Follow-up for the house lead · due by the end of the next shift', who: 'Automatic' },
        ],
    },
    {
        id: 'fu-disputed', type: 'disputed', house: 'kowhai', pid: 'tama', title: 'Second person disputed — Tama', source: 'Levetiracetam 8:00 pm Sunday · Wiremu Hēnare answered “I wasn’t there”',
        owner: 'Jordan Tipene', dueMin: 7 * 60, due: '7:00 am',
        events: [
            { at: '8:05 pm Sunday', what: 'Mere Kahu recorded Tama’s 8:00 pm levetiracetam and named Wiremu Hēnare as the second person (PIN forgotten)', who: 'Mere Kahu' },
            { at: '8:22 pm Sunday', what: 'Answered “I wasn’t there”', who: 'Wiremu Hēnare' },
            { at: '8:22 pm Sunday', what: 'Follow-up for the house lead · due by the end of the next shift (7:00 am)', who: 'Automatic' },
            { at: '7:00 am', what: 'Overdue', who: 'Everyone rostered and the house lead can see it' },
        ],
    },
    {
        id: 'fu-override', type: 'override', house: 'kowhai', pid: null, title: 'Check doses given without a witness', source: '2 controlled doses under Rangi Parata’s override (7:41 am – 3:00 pm)',
        owner: 'Jordan Tipene', dueMin: 23 * 60, due: '11:00 pm', cd: true,
        events: [
            { at: '8:06 am', what: 'Aroha’s methylphenidate recorded without a witness under the override', who: 'Priya Shah' },
            { at: '9:04 am', what: 'Grace’s clonazepam recorded without a witness under the override', who: 'Priya Shah' },
        ],
    },
    {
        id: 'fu-phone', type: 'countersign', house: 'kowhai', pid: 'aroha', title: 'Countersign a phone instruction — Aroha', source: 'Insulin glargine 9:00 am · Dr Lena Chen by phone 8:55 am: 8 units today (ordered 10)',
        owner: 'Jordan Tipene', dueMin: 1440 + 24 * 60 - 1, due: 'the end of tomorrow',
        events: [
            { at: '8:55 am', what: 'Dr Lena Chen asked by phone for 8 units today instead of 10 · read back and confirmed', who: 'Priya Shah' },
            { at: '9:05 am', what: 'Dose recorded under the phone instruction · countersign due by the end of the next day', who: 'Priya Shah' },
        ],
    },
    {
        id: 'fu-rimu-unconf', type: 'unconfirmed', house: 'rimu', pid: 'ben', title: 'Not confirmed by a second person — Ben', source: 'Warfarin 5 mg 8:00 am · recorded 8:03 am by Losa Tuilagi · nobody else on shift could confirm',
        owner: 'Sione Taufa', dueMin: 23 * 60, due: '11:00 pm',
        events: [{ at: '8:03 am', what: 'The Rimu House warfarin rule asks for a second person; nobody else on shift had a witness PIN (from the roster) · follow-up for the house lead', who: 'Losa Tuilagi' }],
    },
    {
        id: 'fu-rimu-handover', type: 'handover', house: 'rimu', pid: null, title: 'Handover not acknowledged — Rimu House', source: 'Night → day handover submitted 6:58 am by Hemi Walker · Ana Lemalu hasn’t acknowledged',
        owner: 'Sione Taufa', dueMin: 8 * 60, due: '8:00 am',
        events: [
            { at: '6:58 am', what: 'Night handover submitted to Ana Lemalu', who: 'Hemi Walker' },
            { at: '8:00 am', what: 'Still not acknowledged 1 hour into the shift — heads-up for the house lead', who: 'Automatic' },
        ],
    },
    {
        id: 'fu-done-late', type: 'effect', house: 'kowhai', pid: 'aroha', title: 'Check whether paracetamol helped', source: 'As-needed dose given 11:40 pm Sunday',
        owner: 'Wiremu Hēnare', dueMin: 40, due: '12:40 am',
        prn: { med: 'Paracetamol 500 mg tablet', amount: '2 tablets', reason: 'Pain', given: '11:40 pm Sunday', by: 'Wiremu Hēnare' },
        done: { at: '12:52 am', by: 'Wiremu Hēnare', outcome: 'Helped — settled and slept', late: '12 minutes late' },
        events: [
            { at: '11:40 pm Sunday', what: 'Created with the as-needed dose · check at 12:40 am', who: 'Wiremu Hēnare (owner)' },
            { at: '12:52 am', what: 'Done — helped (12 minutes after the check time)', who: 'Wiremu Hēnare' },
        ],
    },
    {
        id: 'fu-done-reoffer', type: 'reoffer', house: 'kowhai', pid: 'tama', title: 'Follow up Tama’s refusal of levetiracetam', source: 'Refused 7:45 am · asleep',
        owner: 'Priya Shah', dueMin: 8 * 60 + 45, due: '8:45 am',
        refusal: { med: 'Levetiracetam 500 mg tablet', at: '7:45 am', by: 'Priya Shah', said: 'Tama was asleep', count7: 1, history: ['Today 7:45 am — not taken, asleep'] },
        done: { at: '8:18 am', by: 'Priya Shah', outcome: 'Offered again — taken (½ tablet, see the partial-dose follow-up)' },
        events: [
            { at: '7:45 am', what: 'Created with the refusal · offer again by 8:45 am', who: 'Priya Shah (owner)' },
            { at: '8:18 am', what: 'Done — offered again and taken', who: 'Priya Shah' },
        ],
    },
];
export const fuById = (id: string) => FOLLOW_UPS.find((f) => f.id === id);

/* ───────────── the handover register (last 24 hours) ───────────── */
export interface Handover {
    id: string;
    house: HouseKey;
    label: string;
    change: string;
    from: string;
    to: string;
    submitted: string;
    ack: { by: string; at: string } | null;
    carried: string[];
    noOutcome: string[];
    cdCount: string;
    supply: string | null;
    notes: string;
}
export const HANDOVERS: Handover[] = [
    { id: 'h-kow-am', house: 'kowhai', label: 'Night → day', change: '7:00 am today', from: 'Wiremu Hēnare', to: 'Priya Shah', submitted: '6:52 am', ack: { by: 'Priya Shah', at: '7:05 am' }, carried: ['fu-ibu'], noOutcome: [], cdCount: 'Counted 7:04 am · matches (Wiremu Hēnare with Priya Shah)', supply: 'Paracetamol for Mele — 6 tablets left (below the reorder level)', notes: 'Quiet night. Tama woke at 10:50 pm with a sore knee — ibuprofen at 11:00 pm, settled by midnight but I didn’t record the effect check. Aroha had paracetamol at 11:40 pm for pain.' },
    { id: 'h-rimu-am', house: 'rimu', label: 'Night → day', change: '7:00 am today', from: 'Hemi Walker', to: 'Ana Lemalu', submitted: '6:58 am', ack: null, carried: [], noOutcome: [], cdCount: 'Counted 7:03 am · matches (Hemi Walker with Sione Taufa)', supply: null, notes: 'Ben slept well. Nothing outstanding.' },
    { id: 'h-kow-pm', house: 'kowhai', label: 'Evening → night', change: '11:00 pm Sunday', from: 'Mere Kahu', to: 'Wiremu Hēnare', submitted: '10:52 pm Sunday', ack: { by: 'Wiremu Hēnare', at: '11:04 pm Sunday' }, carried: [], noOutcome: [], cdCount: 'Counted 11:03 pm · matches (Mere Kahu with Wiremu Hēnare)', supply: null, notes: 'Tama’s 8:00 pm dose — the second person question is with Jordan.' },
    { id: 'h-kow-day', house: 'kowhai', label: 'Day → evening', change: '3:00 pm Sunday', from: 'Jordan Tipene', to: 'Mere Kahu', submitted: '2:55 pm Sunday', ack: { by: 'Mere Kahu', at: '3:02 pm Sunday' }, carried: [], noOutcome: [], cdCount: 'Counted 3:05 pm · matches (Jordan Tipene with Priya Shah)', supply: null, notes: 'Aroha back from her whānau at 5:40 pm with 5 methylphenidate tablets.' },
];
export const hoById = (id: string) => HANDOVERS.find((h) => h.id === id);
