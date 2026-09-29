/* P11 v4 — synthetic fixtures. Design only: no application API is called.
 * Wording marked “P00 v5” is copied verbatim from the approved P00 v5 mockup
 * (commit ff3bff860) — reuse-check.mjs proves it is unchanged. */

export type PersonaId = 'sw' | 'lead' | 'clinical' | 'pm' | 'auditor' | 'finance';
export type Perm =
    | 'view' | 'administer' | 'correct' | 'cd.view' | 'cd.record' | 'cd.witness'
    | 'orders.manage' | 'orders.verify' | 'stock.update' | 'audit.view'
    | 'reports.export' | 'settings.manage' | 'breakglass' | 'override' | 'cd.override';
const ALL: Perm[] = ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness', 'orders.manage', 'orders.verify', 'stock.update', 'audit.view', 'reports.export', 'settings.manage', 'breakglass', 'override'];
export const PERSONAS: Record<PersonaId, { id: PersonaId; name: string; initials: string; role: string; perms: Perm[] }> = {
    sw: { id: 'sw', name: 'Priya Shah', initials: 'PS', role: 'Support worker', perms: ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness'] },
    lead: { id: 'lead', name: 'Jordan Tipene', initials: 'JT', role: 'House lead', perms: ALL.filter((p) => p !== 'override') },
    clinical: { id: 'clinical', name: 'Hana Kereama', initials: 'HK', role: 'Clinical lead', perms: ['view', 'orders.manage', 'orders.verify', 'settings.manage', 'override', 'audit.view'] },
    pm: { id: 'pm', name: 'Rangi Parata', initials: 'RP', role: 'Provider manager', perms: [...ALL, 'cd.override'] },
    auditor: { id: 'auditor', name: 'Alex Morgan', initials: 'AM', role: 'Auditor', perms: ['view', 'audit.view'] },
    finance: { id: 'finance', name: 'Kiri Thompson', initials: 'KT', role: 'Finance', perms: ['view', 'reports.export', 'stock.update'] },
};

export type HouseKey = 'kowhai' | 'rimu';
export const HOUSE_KEYS: HouseKey[] = ['kowhai', 'rimu'];
export const HOUSES: Record<'all' | HouseKey, string> = { all: 'All houses', kowhai: 'Kōwhai House', rimu: 'Rimu House' };
export const HOUSE_LEAD: Record<HouseKey, string> = { kowhai: 'Jordan Tipene', rimu: 'Sione Taufa' };
/** Fixed synthetic clock, Pacific/Auckland. */
export const NOW_LABEL = '9:12 am NZDT';
export const TODAY = '2026-09-29';
export const TODAY_LABEL = 'Tuesday 29 September 2026';

/* ── P00 v5: organisation-wide safety rules (wording unchanged) ── */
export type Opt = [string, string];
export const SAFETY_RULES: { key: string; label: string; help: string; opts: Opt[]; def: string }[] = [
    { key: 'profileAllergy', label: 'When a medicine matches a recorded allergy', help: 'The specific match is shown before signing in every mode. Built today: Warn and Block for health-profile matches; severe matches in the medication allergy list always block. The third option is new and not built.', opts: [['warn', 'Warn — show the match; “given” can still be recorded'], ['block', 'Block — contact the prescriber or an authorised override'], ['confirm', 'Block unless the prescriber has confirmed this allergy on the order (new)']], def: 'warn' },
    { key: 'restricted', label: 'A worker’s medication competency is marked restricted', help: 'Refusals and withheld doses can always be recorded. The co-signer confirms with their own witness PIN — never their login password.', opts: [['off', 'Off — no extra check'], ['block', 'Block — they can’t sign as given; the dialog shows who on shift can give it'], ['cosigner', 'Co-signer with witness PIN (recommended) — a present, qualified colleague confirms each dose']], def: 'off' },
    { key: 'area', label: 'The controlled-drug or covert area wasn’t passed', help: 'Applies to controlled-drug orders and orders with an active covert authorisation. Insulin isn’t covered yet: orders don’t record whether a medicine is insulin.', opts: [['off', 'Off — no extra check'], ['failed', 'Block when the area was failed'], ['failed_or_not_seen', 'Block when failed or not seen at assessment']], def: 'off' },
    { key: 'phoneRx', label: 'Who can record a prescriber’s phone instruction for one dose', help: 'For a different dose the prescriber gives by phone. It applies to that dose only and never changes the order; the person reads it back to the prescriber. Stephan’s default: support workers can, and a lead countersigns it.', opts: [['sw', 'Support workers and leads — a lead countersigns it'], ['leads', 'Leads only (people who can check orders)'], ['none', 'Nobody — the order is changed in Prescriptions first']], def: 'sw' },
    { key: 'phoneRxBy', label: 'When a lead countersigns a phone instruction', help: 'Until then the dose shows “waiting for a lead to countersign” and the house lead has a follow-up.', opts: [['nextday', 'By the end of the next day'], ['shift', 'Before the end of the same shift']], def: 'nextday' },
    { key: 'amount', label: 'Less than the ordered amount is given', help: 'A reason is always required. A colleague on shift confirms with their witness PIN; if nobody is available the dose is marked “Not confirmed by a second person” with a house-lead follow-up. A missing second person never blocks recording what actually happened. More than ordered is never a normal choice — it is recorded as a medication error with a linked incident.', opts: [['avail', 'If someone is available — never blocks'], ['always', 'Always — the house lead confirms later if nobody is on shift'], ['no', 'Not needed — the reason is enough']], def: 'avail' },
];
/** Switch mapping for the P00 options: which option means “off”, and the choices shown under an on switch. */
export const SAFETY_SWITCH: Record<string, { off: string; on: Opt[] } | undefined> = {
    restricted: { off: 'off', on: [['block', 'Block'], ['cosigner', 'Co-signer with witness PIN']] },
    area: { off: 'off', on: [['failed', 'When the area was failed'], ['failed_or_not_seen', 'When failed or not seen']] },
    phoneRx: { off: 'none', on: [['sw', 'Support workers and leads'], ['leads', 'Leads only']] },
    amount: { off: 'no', on: [['avail', 'If someone is available'], ['always', 'Always']] },
};

/* ── P00 v5: controlled drugs — witness (wording unchanged) ── */
export const CDW_OPTS: Record<string, Opt[]> = {
    org: [['on', 'On — witness required'], ['off', 'Off — not required']],
    house: [['org', 'Follow the organisation setting'], ['on', 'Always required at this house'], ['off', 'Not required at this house']],
    suggest: [['on', 'Show managers a heads-up (recommended)'], ['off', 'Don’t show']],
    longest: [['shift', 'One rostered shift (default)'], ['24h', 'Up to 24 hours'], ['7d', 'Up to 7 days']],
};
export const CDW_LABEL: Record<string, string> = { org: 'Witness required for controlled drugs', kowhai: 'Kōwhai House', rimu: 'Rimu House', suggest: 'Heads-up before single staffing', longest: 'Longest witness override' };

/* ── P00 v5: witness PIN rules (wording unchanged) ── */
export const PIN_RULES: { key: string; label: string; help: string; type: 'number' | 'select'; unit?: string; opts?: Opt[] }[] = [
    { key: 'attempts', label: 'Wrong attempts before a PIN locks', help: 'Counts wrong PINs typed for one person, across all screens.', type: 'number', unit: 'attempts' },
    { key: 'lockout', label: 'How long a locked PIN stays locked', help: 'Until then, only the owner (or an allowed reset) unlocks it.', type: 'number', unit: 'minutes' },
    { key: 'renewal', label: 'PIN renewal (optional)', help: 'Leave empty for no renewal. When set, people are asked to choose a new PIN after this many months.', type: 'number', unit: 'months' },
    { key: 'fallback', label: 'Forgotten-PIN fallback', help: 'Lets the recorder name a colleague who has forgotten their PIN. The dose is marked “second person not verified”, and the colleague confirms from their own login.', type: 'select', opts: [['nc', 'Not configured — not allowed'], ['yes', 'Allowed'], ['no', 'Not allowed']] },
    { key: 'fallbackCd', label: 'Forgotten-PIN fallback for controlled drugs', help: 'Separate switch, because a controlled-drug witness must be physically present (D8).', type: 'select', opts: [['nc', 'Not configured — not allowed'], ['yes', 'Allowed'], ['no', 'Not allowed']] },
    { key: 'resetRoles', label: 'Who can reset another person’s PIN', help: 'A reset never shows or sets the PIN. The owner must choose a new one before they can co-sign or witness.', type: 'select', opts: [['nc', 'Not configured — nobody'], ['lead', 'House leads'], ['clinical', 'Clinical leads'], ['both', 'House leads and clinical leads']] },
    { key: 'confirmLimit', label: 'Time limit for a named colleague to confirm', help: 'After this, a missing answer raises a follow-up for the house lead.', type: 'number', unit: 'minutes' },
    { key: 'routeTo', label: 'Who gets the follow-up', help: 'When the colleague answers “I wasn’t there” or doesn’t answer in time.', type: 'select', opts: [['nc', 'Not configured'], ['lead-on-shift', 'House lead on shift'], ['lead-house', 'House lead for the house']] },
];

/* ── P00 v5: medicine rules (builder wording unchanged) ── */
export const OBS: Record<string, string> = { pulse: 'pulse', bsl: 'blood sugar (BSL)', bp: 'blood pressure' };
export const MATCH: Record<string, { l: string; d: string; isNew?: string }> = {
    name: { l: 'Medicine name', d: 'For example insulin glargine' },
    route: { l: 'Route', d: 'For example subcutaneous injection' },
    nzulm: { l: 'NZULM code', d: 'One exact product' },
    cls: { l: 'Type or class', d: 'For example anticoagulants', isNew: 'New — needs a medicine classification on orders (P04)' },
    controlled: { l: 'Controlled status', d: 'Any controlled medicine', isNew: 'New — uses the order’s controlled flag' },
};
export const ROUTES = ['Oral', 'Subcutaneous injection', 'Inhaled', 'Topical', 'Rectal'];
export const CLASSES = ['Anticoagulants', 'Insulins', 'Cardiac glycosides', 'Benzodiazepines', 'Opioid analgesics'];
export const NZULM: [string, string][] = [['9000031', 'Digoxin 62.5 microgram tablet'], ['9000047', 'Enoxaparin 40 mg/0.4 mL injection'], ['9000052', 'Insulin glargine 100 units/mL pen']];
export const PEOPLE: Record<string, { pref: string; surname: string; house: string }> = {
    aroha: { pref: 'Aroha', surname: 'Ngata', house: 'Kōwhai House' },
    tama: { pref: 'Tama', surname: 'Walker', house: 'Kōwhai House' },
    grace: { pref: 'Grace', surname: 'Liu', house: 'Kōwhai House' },
    ben: { pref: 'Ben', surname: 'Clarke', house: 'Rimu House' },
};
export type Med = { med: string; route: string; nzulm?: string; cls: string; pid: string; house: HouseKey; cd?: boolean };
export const MEDLIST: Med[] = [
    { med: 'Insulin glargine', route: 'Subcutaneous injection', nzulm: '9000052', cls: 'Insulins', pid: 'aroha', house: 'kowhai' },
    { med: 'Insulin glargine', route: 'Subcutaneous injection', nzulm: '9000052', cls: 'Insulins', pid: 'ben', house: 'rimu' },
    { med: 'Enoxaparin', route: 'Subcutaneous injection', nzulm: '9000047', cls: 'Anticoagulants', pid: 'tama', house: 'kowhai' },
    { med: 'Digoxin', route: 'Oral', nzulm: '9000031', cls: 'Cardiac glycosides', pid: 'grace', house: 'kowhai' },
    { med: 'Metformin', route: 'Oral', cls: 'Biguanides', pid: 'aroha', house: 'kowhai' },
    { med: 'Levetiracetam', route: 'Oral', cls: 'Antiepileptics', pid: 'tama', house: 'kowhai' },
    { med: 'Salbutamol', route: 'Inhaled', cls: 'Bronchodilators', pid: 'aroha', house: 'kowhai' },
    { med: 'Methylphenidate', route: 'Oral', cls: 'Stimulants', pid: 'aroha', house: 'kowhai', cd: true },
    { med: 'Clonazepam', route: 'Oral', cls: 'Benzodiazepines', pid: 'grace', house: 'kowhai', cd: true },
];
export type Rule = { id: string; match: string; value: string; scope: 'all' | HouseKey; countersign: boolean; obs: string[]; active: boolean; by: string; when: string; paused?: string };
export const RULES_SEED: Rule[] = [
    { id: 'mr1', match: 'name', value: 'Insulin glargine', scope: 'all', countersign: false, obs: ['bsl'], active: true, by: 'Hana Kereama', when: '3 Aug 2026' },
    { id: 'mr2', match: 'route', value: 'Subcutaneous injection', scope: 'kowhai', countersign: true, obs: [], active: true, by: 'Hana Kereama', when: '19 Aug 2026' },
    { id: 'mr3', match: 'nzulm', value: '9000031', scope: 'all', countersign: false, obs: ['pulse'], active: false, by: 'Hana Kereama', when: '2 Sep 2026', paused: 'Paused 20 Sep 2026 by Hana Kereama' },
];

/* ── P00 v5 staff PIN list, continued by P11 so the register and PIN status agree ── */
export type PinState = 'set' | 'notset' | 'locked' | 'adminreset';
export const STAFF_PINS: { name: string; role: string; house: string; pin: PinState; changed?: string }[] = [
    { name: 'Daniel Ahn', role: 'Support worker', house: 'Kōwhai House', pin: 'set', changed: '3 Sep 2026' },
    { name: 'Jordan Tipene', role: 'House lead', house: 'Kōwhai House', pin: 'set', changed: '12 Aug 2026' },
    { name: 'Mere Kahu', role: 'Support worker', house: 'Kōwhai House', pin: 'notset' },
    { name: 'Priya Shah', role: 'Support worker', house: 'Kōwhai House', pin: 'set', changed: '9 Sep 2026' },
    { name: 'Leilani Faleolo', role: 'Support worker', house: 'Rimu House', pin: 'locked', changed: '1 Sep 2026' },
    { name: 'Hemi Walker', role: 'Support worker', house: 'Rimu House', pin: 'adminreset' },
    { name: 'Aisha Rahman', role: 'Support worker', house: 'Kōwhai House', pin: 'set', changed: '20 Jul 2026' },
    { name: 'Tomasi Vea', role: 'Support worker', house: 'Kōwhai House', pin: 'notset' },
    { name: 'Ana Lemalu', role: 'Support worker', house: 'Rimu House', pin: 'set', changed: '22 Sep 2026' },
    { name: 'Sione Taufa', role: 'House lead', house: 'Rimu House', pin: 'set', changed: '5 Aug 2026' },
];

/* ── The 12 areas on today’s assessment form (_competency-dialogs.tsx), in plain language ── */
export type Area = { k: string; l: string; core?: boolean; d?: string; rule?: 'area' | 'notyet' };
export const AREAS: Area[] = [
    { k: 'knowledge', l: 'Medication knowledge', core: true },
    { k: 'rights', l: 'The five rights', core: true, d: 'Right person, medicine, dose, time and route' },
    { k: 'safety', l: 'Safety checks', core: true },
    { k: 'docs', l: 'Documentation', core: true },
    { k: 'cd', l: 'Controlled drugs', rule: 'area' },
    { k: 'prn', l: 'As-needed (PRN) assessment' },
    { k: 'insulin', l: 'Insulin', rule: 'notyet' },
    { k: 'inhaler', l: 'Inhaler technique' },
    { k: 'topical', l: 'Topical medicines' },
    { k: 'covert', l: 'Covert administration', rule: 'area' },
    { k: 'errors', l: 'Error reporting', core: true },
    { k: 'allergy', l: 'Allergy awareness', core: true },
];

/* ── Staff (synthetic) ── */
export type CompSt = 'current' | 'expired' | 'none' | 'restricted' | 'failed' | 'ack';
export type Staff = {
    id: string; name: string; role: string; house: HouseKey; st: CompSt; assessed?: string; until?: string; days?: number; by?: string; type?: string;
    res?: Record<string, 'yes' | 'no' | 'unseen'>; obs?: number; unsup?: boolean; witness?: boolean; ack?: string | null; shift: string;
    started?: string; restricted?: string | null; prevValid?: string | null; prev?: string;
};
export const STAFF: Staff[] = [
    { id: 'priya', name: 'Priya Shah', role: 'Support worker', house: 'kowhai', st: 'current', assessed: '14 Mar 2026', until: '14 Mar 2027', days: 166, by: 'Hana Kereama', type: 'Renewal', res: { insulin: 'no', covert: 'unseen' }, obs: 8, unsup: true, witness: true, ack: '14 Mar 2026', shift: 'Clocked in 7:02 am · until 3:00 pm' },
    { id: 'mere', name: 'Mere Kahu', role: 'Support worker', house: 'kowhai', st: 'current', assessed: '2 Feb 2026', until: '2 Feb 2027', days: 126, by: 'Hana Kereama', type: 'First assessment', res: { cd: 'no', covert: 'unseen' }, obs: 6, unsup: true, witness: false, ack: '3 Feb 2026', shift: 'Clocked in 6:58 am · until 3:00 pm' },
    { id: 'daniel', name: 'Daniel Ahn', role: 'Support worker', house: 'kowhai', st: 'current', assessed: '6 Oct 2025', until: '6 Oct 2026', days: 7, by: 'Hana Kereama', type: 'First assessment', res: { insulin: 'unseen' }, obs: 7, unsup: true, witness: true, ack: '6 Oct 2025', shift: 'Rostered 3:00 pm–11:00 pm' },
    { id: 'jordan', name: 'Jordan Tipene', role: 'House lead', house: 'kowhai', st: 'current', assessed: '12 Jun 2026', until: '12 Jun 2027', days: 256, by: 'Hana Kereama', type: 'Renewal', res: {}, obs: 9, unsup: true, witness: true, ack: '12 Jun 2026', shift: 'Rostered 3:00 pm–11:00 pm' },
    { id: 'aisha', name: 'Aisha Rahman', role: 'Support worker', house: 'kowhai', st: 'expired', assessed: '20 Sep 2025', until: '20 Sep 2026', days: -9, by: 'Jordan Tipene', type: 'First assessment', res: { insulin: 'unseen', covert: 'unseen' }, obs: 6, unsup: true, witness: false, ack: '21 Sep 2025', shift: 'Rostered 7:00 am–3:00 pm tomorrow' },
    { id: 'tomasi', name: 'Tomasi Vea', role: 'Support worker', house: 'kowhai', st: 'none', started: '21 Sep 2026', shift: 'Rostered 3:00 pm–11:00 pm' },
    { id: 'leilani', name: 'Leilani Faleolo', role: 'Support worker', house: 'rimu', st: 'restricted', assessed: '10 Sep 2026', until: '10 Sep 2027', days: 346, by: 'Hana Kereama', type: 'First assessment', res: { insulin: 'unseen', covert: 'unseen' }, obs: 6, unsup: false, witness: false, ack: '11 Sep 2026', restricted: 'Supervised practice until reassessed', shift: 'Clocked in 7:05 am · until 3:00 pm' },
    { id: 'hemi', name: 'Hemi Walker', role: 'Support worker', house: 'rimu', st: 'failed', assessed: '20 Sep 2026', by: 'Hana Kereama', type: 'Renewal', res: { safety: 'no', insulin: 'unseen', covert: 'unseen' }, obs: 5, unsup: false, witness: false, ack: '21 Sep 2026', shift: 'Clocked in 6:55 am · until 3:00 pm' },
    { id: 'ana', name: 'Ana Lemalu', role: 'Support worker', house: 'rimu', st: 'ack', assessed: '28 Sep 2026', until: '28 Sep 2027', days: 364, by: 'Hana Kereama', type: 'First assessment', res: { insulin: 'unseen', covert: 'unseen' }, obs: 8, unsup: true, witness: true, ack: null, shift: 'Rostered 3:00 pm–11:00 pm', prev: 'None — first assessment' },
    { id: 'sione', name: 'Sione Taufa', role: 'House lead', house: 'rimu', st: 'current', assessed: '20 Oct 2025', until: '20 Oct 2026', days: 21, by: 'Hana Kereama', type: 'Renewal', res: {}, obs: 10, unsup: true, witness: true, ack: '20 Oct 2025', shift: 'Rostered 3:00 pm–11:00 pm' },
];
export const ONSHIFT_NOW = ['priya', 'mere', 'leilani', 'hemi'];

/* ── Round templates (today: one time ± window, days, house, default staff; retired, never deleted) ── */
export type Tpl = { id: string; name: string; house: HouseKey; time: string; win: number; days: string[]; who: string | null; status: 'active' | 'paused' | 'retired'; by: string; when: string; doses: number; people: number };
export const DAYS: [string, string][] = [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']];
export const TEMPLATES_SEED: Tpl[] = [
    { id: 't1', name: 'Morning round', house: 'kowhai', time: '08:00', win: 60, days: [], who: null, status: 'active', by: 'Jordan Tipene', when: '4 Aug 2026', doses: 7, people: 5 },
    { id: 't2', name: 'Midday round', house: 'kowhai', time: '12:00', win: 30, days: [], who: null, status: 'active', by: 'Jordan Tipene', when: '4 Aug 2026', doses: 2, people: 1 },
    { id: 't3', name: 'Evening round', house: 'kowhai', time: '17:00', win: 60, days: [], who: null, status: 'active', by: 'Jordan Tipene', when: '4 Aug 2026', doses: 5, people: 4 },
    { id: 't4', name: 'Bedtime round', house: 'kowhai', time: '20:30', win: 30, days: [], who: null, status: 'active', by: 'Jordan Tipene', when: '11 Aug 2026', doses: 3, people: 3 },
    { id: 't5', name: 'Weekend late breakfast', house: 'kowhai', time: '10:00', win: 30, days: ['6', '7'], who: null, status: 'paused', by: 'Jordan Tipene', when: '14 Sep 2026', doses: 0, people: 0 },
    { id: 't6', name: 'Night round', house: 'kowhai', time: '22:00', win: 60, days: [], who: null, status: 'retired', by: 'Jordan Tipene', when: '1 Sep 2026', doses: 0, people: 0 },
    { id: 't7', name: 'Morning round', house: 'rimu', time: '08:00', win: 60, days: [], who: 'Sione Taufa', status: 'active', by: 'Sione Taufa', when: '22 Aug 2026', doses: 2, people: 1 },
    { id: 't8', name: 'Evening round', house: 'rimu', time: '18:00', win: 60, days: [], who: null, status: 'active', by: 'Sione Taufa', when: '22 Aug 2026', doses: 1, people: 1 },
];

/* ── Alerts. Stephan, 29 Sep 2026: managers set who gets each alert (reverses answer 15) — one set for every
 * house (all-sites authority) plus extra people a house manager adds for their own house. The routing he
 * decided earlier (D12) stays locked on. In-app only; controlled-medicine alerts only reach people with
 * controlled-medicine access, whoever is switched on. ── */
export const ROSTERED = 'Everyone rostered on a covering shift';
export const RECIPIENT_GROUPS: Record<string, { l: string; d: string }> = {
    rostered: { l: ROSTERED, d: 'Checked against the roster at the time of the alert' },
    houseLead: { l: 'House lead', d: 'The lead for the house the alert is about' },
    clinicalLead: { l: 'Clinical lead', d: 'Clinical leads with access to the house' },
    providerManager: { l: 'Provider manager', d: 'Provider managers with access to the house' },
    stockStaff: { l: 'People who update stock here', d: 'Anyone with “update stock” at the house' },
    overrideGranters: { l: 'People who can grant witness overrides', d: 'The new witness-override permission, at the house' },
    eaReviewers: { l: 'People who review emergency access here', d: 'Anyone who reviews emergency access at the house' },
    staffMember: { l: 'The staff member', d: 'The person the alert is about' },
    onCall: { l: 'On-call person (from the roster)', d: 'Whoever is the house’s on-call contact at the time' }, // Q9: off on every alert until a manager turns it on
};
export type AlertDef = { k: string; l: string; sub?: string; groups: string[]; locked?: string[]; def: string[]; decided?: string; until: string; today: string; check?: boolean };
export const ALERTS: AlertDef[] = [
    { k: 'overdue', l: 'Overdue doses', sub: 'A scheduled dose passes its late time with no outcome', groups: ['rostered', 'houseLead', 'onCall', 'clinicalLead', 'providerManager'], locked: ['rostered', 'houseLead'], def: ['rostered', 'houseLead'], decided: 'Stephan’s decision, 29 Sep 2026', until: 'Until every dose has an outcome', today: 'Only the person assigned to the round covering that time. Nobody, if there’s no round or no assignee.' },
    { k: 'followups', l: 'Follow-ups overdue', sub: 'Effect checks, refusals, doses not confirmed by a second person, phone instructions to countersign', groups: ['rostered', 'houseLead', 'onCall', 'clinicalLead'], locked: ['rostered', 'houseLead'], def: ['rostered', 'houseLead'], decided: 'Stephan’s decision, 29 Sep 2026', until: 'Until resolved · due by the end of the next shift', today: 'No reminders. Follow-ups are only listed.' },
    { k: 'override', l: 'Witness override requests', sub: 'Someone on shift asks for a controlled-drug witness override', groups: ['overrideGranters', 'houseLead', 'onCall', 'clinicalLead', 'providerManager'], locked: ['overrideGranters', 'houseLead'], def: ['overrideGranters', 'houseLead'], decided: 'Stephan’s decision, 29 Sep 2026 (P00 v5)', until: 'Until a manager answers', today: 'New — built with PIN-2.' },
    { k: 'stock', l: 'Stock running low', sub: 'A medicine drops below its reorder level', groups: ['houseLead', 'stockStaff', 'clinicalLead', 'rostered'], def: ['houseLead', 'stockStaff'], until: 'Until restocked', today: 'Everyone with medication access at the house. Controlled stock: only people with controlled-medicine access.' },
    { k: 'expiry', l: 'Stock expiring', sub: '30 days before, urgent at 7 days', groups: ['stockStaff', 'houseLead', 'clinicalLead'], def: ['stockStaff'], until: 'Until removed or replaced', today: 'A dashboard alert only — nobody is told.' },
    { k: 'refusals', l: 'Repeated refusals', sub: 'From the escalation setting in Rounds & timing', groups: ['houseLead', 'clinicalLead', 'providerManager', 'rostered'], def: ['houseLead', 'clinicalLead'], until: 'Until someone acknowledges', today: 'Meant for team leaders. The role match looks broken, so it may reach nobody — a fix task is running.', check: true },
    { k: 'renewals', l: 'Competency renewals due', sub: 'From the renewal reminder in Staff & PINs', groups: ['staffMember', 'houseLead', 'clinicalLead'], def: ['staffMember', 'houseLead'], until: 'Until renewed', today: 'The staff member only — and it may repeat every 15 minutes. A fix task is running.', check: true },
    { k: 'errors', l: 'Medication errors reported', sub: 'Someone records a medication error', groups: ['houseLead', 'onCall', 'clinicalLead', 'providerManager'], def: ['houseLead', 'clinicalLead'], until: 'Until triaged', today: 'A Control Room signal only. Nobody is told directly.' },
    { k: 'breakglass', l: 'Emergency access used (daily report)', sub: 'A daily summary of emergency access grants', groups: ['eaReviewers', 'providerManager', 'clinicalLead'], def: ['eaReviewers'], until: 'Until reviewed', today: 'Every manager role across the organisation — including HR and finance, not scoped to houses. The report’s routing doesn’t match its setting. A fix task is running.', check: true },
    // Found in the 29 Sep audit: medication alerts that exist today but only as Control Room signals or dashboard tiles.
    { k: 'cdDiscrepancy', l: 'Controlled-drug count doesn’t match', sub: 'A balance check or transfer finds a difference, or a loss is reported', groups: ['houseLead', 'onCall', 'clinicalLead', 'providerManager'], def: ['houseLead', 'clinicalLead'], until: 'Until investigated', today: 'A Control Room signal. Its seeded rule names groups (“managers_core”, “coordinators”) that match no role, so it probably reaches nobody.', check: true },
    { k: 'prnLimit', l: 'As-needed dose over the limit', sub: 'An as-needed dose goes past the order’s daily limit', groups: ['houseLead', 'clinicalLead', 'rostered'], def: ['houseLead', 'clinicalLead'], until: 'Until someone acknowledges', today: 'A Control Room signal only. “Nearly at the limit” (75 %) shows on the dashboard.' },
    { k: 'outOfStock', l: 'Out of stock or expired stock', sub: 'From the 6:00 am stock check', groups: ['stockStaff', 'houseLead', 'clinicalLead'], def: ['stockStaff', 'houseLead'], until: 'Until restocked or removed', today: 'A Control Room signal. The same check can stop “Stock running low” for 24 hours.' },
    { k: 'cdCheck', l: 'Controlled-drug balance check overdue', sub: 'No balance check for 7 days', groups: ['houseLead', 'clinicalLead'], def: ['houseLead'], until: 'Until the check is done', today: 'A dashboard alert only (7:30 am) — nobody is told.' },
    { k: 'reviewDue', l: 'Medication review due', sub: 'Chart or medicine review within 7 days; INR within 3 days', groups: ['clinicalLead', 'houseLead'], def: ['clinicalLead'], until: 'Until reviewed', today: 'A dashboard alert only — nobody is told.' },
];
/** Alerts that need someone to attend: proposed for Follow up (re-alert / escalate). Nothing re-alerts until a
 * manager turns re-alerting on — today each alert is sent once. */
export const FOLLOW_UP_DEFAULT = ['overdue', 'followups', 'override', 'errors', 'cdDiscrepancy'];
/* ── On-call (Stephan, 29 Sep 2026): the on-call contact is an employed staff member, and it follows the
 * roster. Rostering already marks on-call shifts per site (shifts.is_on_call / shift_type 'on_call') and has a
 * team_lead role; there is no separate “on-call manager” field. Synthetic roster for this preview. ── */
/** One night of the roster for a house: who is on an on-call shift, and which team lead is working. */
export type RosterNight = { day: string; hours: string; onCall: string | null; teamLead: string | null };
export const ONCALL_ROSTER: Record<HouseKey, RosterNight[]> = {
    kowhai: [
        { day: 'Tonight', hours: '5:00 pm – 7:00 am', onCall: 'jordan', teamLead: 'jordan' },
        { day: 'Wed 30 Sep', hours: '5:00 pm – 7:00 am', onCall: null, teamLead: 'priya' },
        { day: 'Thu 1 Oct', hours: '5:00 pm – 7:00 am', onCall: null, teamLead: null },
    ],
    rimu: [
        { day: 'Tonight', hours: '5:00 pm – 7:00 am', onCall: null, teamLead: null },
        { day: 'Wed 30 Sep', hours: '5:00 pm – 7:00 am', onCall: 'sione', teamLead: null },
        { day: 'Thu 1 Oct', hours: '5:00 pm – 7:00 am', onCall: null, teamLead: null },
    ],
};
export type Employed = {
    id: string; name: string; role: string; houses: string;
    /** From the staff record. Q10 (Stephan, 29 Sep 2026): the work phone, else the personal cellphone — but only if the person agreed. */
    workPhone: string; cell: string; cellOk: string | null;
    workEmail: boolean; push: boolean;
    /** Recipient groups they belong to (synthetic). */
    groups: string[];
    /** Approved leave from the Leave hub, as roster nights. */
    leave?: { nights: string[]; text: string };
};
/** Employed staff, with contact details from their staff record (synthetic). */
export const EMPLOYED: Employed[] = [
    { id: 'hana', name: 'Hana Kereama', role: 'Clinical lead', houses: 'All houses', workPhone: '021 555 0142', cell: '', cellOk: null, workEmail: true, push: true, groups: ['clinicalLead', 'overrideGranters', 'eaReviewers'], leave: { nights: ['Thu 1 Oct'], text: 'On leave Thu 1 – Mon 5 Oct (Leave hub)' } },
    { id: 'rangi', name: 'Rangi Parata', role: 'Provider manager', houses: 'All houses', workPhone: '021 555 0118', cell: '', cellOk: null, workEmail: true, push: true, groups: ['providerManager', 'eaReviewers'] },
    { id: 'jordan', name: 'Jordan Tipene', role: 'House lead · team lead', houses: 'Kōwhai House', workPhone: '021 555 0163', cell: '', cellOk: null, workEmail: true, push: true, groups: ['houseLead', 'rostered', 'overrideGranters', 'stockStaff'] },
    { id: 'priya', name: 'Priya Shah', role: 'Support worker · team lead', houses: 'Kōwhai House', workPhone: '', cell: '022 555 0134', cellOk: '12 Sep 2026', workEmail: true, push: true, groups: ['rostered', 'stockStaff'] },
    { id: 'mere', name: 'Mere Kahu', role: 'Support worker', houses: 'Kōwhai House', workPhone: '', cell: '022 555 0129', cellOk: null, workEmail: false, push: true, groups: ['rostered'] },
    { id: 'sione', name: 'Sione Taufa', role: 'House lead', houses: 'Rimu House', workPhone: '021 555 0177', cell: '', cellOk: null, workEmail: true, push: false, groups: ['houseLead', 'rostered', 'stockStaff'] },
    { id: 'leilani', name: 'Leilani Faleolo', role: 'Support worker', houses: 'Rimu House', workPhone: '', cell: '', cellOk: null, workEmail: true, push: false, groups: ['rostered'] },
];
export const employedAt = (h: HouseKey) => EMPLOYED.filter((x) => x.houses === 'All houses' || x.houses === HOUSES[h]);
export const employee = (id: string) => EMPLOYED.find((x) => x.id === id);
/** The number staff see for someone on call. */
export function phoneOf(e: Employed): { number: string; kind: 'work' | 'cell' | null; why?: string } {
    if (e.workPhone) return { number: e.workPhone, kind: 'work' };
    if (e.cell && e.cellOk) return { number: e.cell, kind: 'cell' };
    if (e.cell) return { number: '', kind: null, why: 'no work phone, and they haven’t agreed to show their cellphone' };
    return { number: '', kind: null, why: 'no phone number on their staff record' };
}
export const phoneText = (e: Employed) => { const p = phoneOf(e); return p.kind === 'cell' ? `${p.number} (personal cellphone)` : p.number; };
/** How a house decides its on-call contact. */
export type OnCallRule = { mode: 'roster' | 'fixed'; teamLead: boolean; person: string };
/** Who staff see for one night: the on-call shift, then (optionally) the team lead on shift, then the backup —
 * unless the backup is on leave that night (Leave hub), when nobody is shown. */
export function resolveOnCall(r: OnCallRule, n: RosterNight): { who: Employed | undefined; how: string; warn?: string } {
    if (r.mode === 'roster' && n.onCall) return { who: employee(n.onCall), how: 'On an on-call shift' };
    if (r.mode === 'roster' && r.teamLead && n.teamLead) return { who: employee(n.teamLead), how: 'Team lead on shift' };
    const b = employee(r.person);
    if (b?.leave?.nights.includes(n.day)) return { who: undefined, how: 'Backup on leave', warn: `Nobody — ${b.name} is on leave` };
    return { who: b, how: r.mode === 'roster' ? 'Backup — nobody rostered' : 'Always this person' };
}
export const describeOnCall = (r: OnCallRule) => {
    const p = employee(r.person)?.name ?? 'nobody';
    return r.mode === 'roster' ? `Follows the roster${r.teamLead ? ', then the team lead on shift' : ''} · backup ${p}` : `Always ${p}`;
};

/* ── Alert log: examples of how the log reads once P11 is built (synthetic). Consistent with today’s settings:
 * re-alerting and escalation are off, so each alert is sent once. ── */
export type LogEvent = { at: string; what: string; who: string };
export type AlertLogRow = { id: string; k: string; house: HouseKey; about: string; sent: string; sort: number; to: string[]; via: string[]; status: 'open' | 'attended' | 'resolved'; waited?: string; by?: string; at?: string; afterHours?: boolean; events: LogEvent[] };
export const ALERT_LOG: AlertLogRow[] = [
    { id: 'l1', k: 'overdue', house: 'kowhai', about: 'Aroha N. — Metformin, 8:00 am dose', sent: 'Today 9:00 am', sort: 202609290900, to: ['Mere Kahu', 'Jordan Tipene'], via: ['in-app'], status: 'open', waited: '12 min', events: [{ at: '9:00 am', what: 'Sent', who: 'Mere Kahu (rostered), Jordan Tipene (house lead) · in-app' }] },
    { id: 'l2', k: 'followups', house: 'kowhai', about: 'Tama W. — effect check after as-needed paracetamol', sent: 'Today 8:40 am', sort: 202609290840, to: ['Priya Shah', 'Jordan Tipene'], via: ['in-app'], status: 'resolved', by: 'Priya Shah', at: '9:05 am', events: [{ at: '8:40 am', what: 'Sent', who: 'Priya Shah (rostered), Jordan Tipene (house lead) · in-app' }, { at: '8:52 am', what: 'Acknowledged', who: 'Priya Shah' }, { at: '9:05 am', what: 'Dealt with — effect recorded', who: 'Priya Shah' }] },
    { id: 'l3', k: 'override', house: 'rimu', about: 'Witness override request — Sione Taufa', sent: 'Today 7:55 am', sort: 202609290755, to: ['Hana Kereama', 'Sione Taufa'], via: ['in-app'], status: 'resolved', by: 'Hana Kereama', at: '8:01 am', events: [{ at: '7:55 am', what: 'Sent', who: 'Hana Kereama (can grant overrides), Sione Taufa (house lead) · in-app' }, { at: '8:01 am', what: 'Dealt with — override granted until end of shift', who: 'Hana Kereama' }] },
    { id: 'l4', k: 'stock', house: 'kowhai', about: 'Salbutamol inhaler — below its reorder level', sent: 'Today 6:00 am', sort: 202609290600, to: ['Priya Shah', 'Jordan Tipene'], via: ['in-app'], status: 'open', waited: '3 h 12 min', events: [{ at: '6:00 am', what: 'Sent', who: 'Priya Shah (updates stock), Jordan Tipene (house lead) · in-app' }] },
    { id: 'l5', k: 'errors', house: 'rimu', about: 'Medication error reported — dose given at the wrong time', sent: 'Yesterday 9:40 pm', sort: 202609282140, to: ['Sione Taufa', 'Hana Kereama'], via: ['in-app'], status: 'attended', by: 'Hana Kereama', at: '7:15 am', afterHours: true, events: [{ at: '9:40 pm', what: 'Sent', who: 'Sione Taufa (house lead), Hana Kereama (clinical lead) · in-app' }, { at: '7:15 am', what: 'Acknowledged — 9 h 35 min later', who: 'Hana Kereama' }] },
    { id: 'l6', k: 'cdDiscrepancy', house: 'kowhai', about: 'Controlled-drug count doesn’t match — Methylphenidate', sent: 'Yesterday 8:10 pm', sort: 202609282010, to: ['Jordan Tipene', 'Hana Kereama'], via: ['in-app'], status: 'resolved', by: 'Jordan Tipene', at: '9:02 pm', afterHours: true, events: [{ at: '8:10 pm', what: 'Sent', who: 'Jordan Tipene (house lead), Hana Kereama (clinical lead) · in-app' }, { at: '8:26 pm', what: 'Acknowledged', who: 'Jordan Tipene' }, { at: '9:02 pm', what: 'Dealt with — recount matched', who: 'Jordan Tipene' }] },
    { id: 'l7', k: 'renewals', house: 'kowhai', about: 'Competency renewal due — Daniel Ahn, 14 Oct', sent: 'Yesterday 6:00 am', sort: 202609280600, to: ['Daniel Ahn', 'Jordan Tipene'], via: ['in-app'], status: 'attended', by: 'Daniel Ahn', at: '7:48 am', events: [{ at: '6:00 am', what: 'Sent', who: 'Daniel Ahn (the staff member), Jordan Tipene (house lead) · in-app' }, { at: '7:48 am', what: 'Opened', who: 'Daniel Ahn' }] },
    { id: 'l8', k: 'overdue', house: 'rimu', about: 'Ben C. — Insulin glargine, 9:00 pm dose', sent: 'Sun 27 Sep 10:00 pm', sort: 202609272200, to: ['Leilani Faleolo', 'Sione Taufa'], via: ['in-app'], status: 'resolved', by: 'Leilani Faleolo', at: '10:06 pm', afterHours: true, events: [{ at: '10:00 pm', what: 'Sent', who: 'Leilani Faleolo (rostered), Sione Taufa (house lead) · in-app' }, { at: '10:06 pm', what: 'Dealt with — dose recorded as given, late', who: 'Leilani Faleolo' }] },
];
/** People who can be named on an alert (synthetic: the staff above plus the managers). */
export const ALERT_PEOPLE: { name: string; role: string; houses: string }[] = [
    { name: 'Hana Kereama', role: 'Clinical lead', houses: 'All houses' },
    { name: 'Rangi Parata', role: 'Provider manager', houses: 'All houses' },
    { name: 'Jordan Tipene', role: 'House lead', houses: 'Kōwhai House' },
    { name: 'Sione Taufa', role: 'House lead', houses: 'Rimu House' },
    { name: 'Priya Shah', role: 'Support worker', houses: 'Kōwhai House' },
    { name: 'Mere Kahu', role: 'Support worker', houses: 'Kōwhai House' },
    { name: 'Daniel Ahn', role: 'Support worker', houses: 'Kōwhai House' },
    { name: 'Leilani Faleolo', role: 'Support worker', houses: 'Rimu House' },
    { name: 'Ana Lemalu', role: 'Support worker', houses: 'Rimu House' },
];

/* ── Seed change history (synthetic) ── */
export type Hist = { id: string; when: string; sort: number; who: string; area: 'rules' | 'rounds' | 'staff' | 'alerts'; sec: string; scope: string; what: string; from: string; to: string; ev: string; note?: string; fresh?: boolean;
    /** For a single setting: which one, and its value before the change — so it can be put back into a draft. */
    g?: string; k?: string; fromV?: unknown };
export const HIST_SEED: Hist[] = [
    { id: 'h1', when: '29 Sep 2026 8:05 am', sort: 202609290805, who: 'Hana Kereama', area: 'staff', sec: 'pins', scope: 'All houses', what: 'Second-person confirmation rules', from: 'Not configured', to: '5 wrong attempts · 15 minutes · no renewal · house and clinical leads reset · fallback allowed except controlled drugs · 30 minutes · follow-up to the house lead', ev: 'medications.witness_pin_policy.updated (new with PIN-1)', note: 'Stephan’s decision, 29 Sep 2026' },
    { id: 'h2', when: '29 Sep 2026 8:02 am', sort: 202609290802, who: 'Demo Admin', area: 'rules', sec: 'safety', scope: 'All houses', what: 'Safety check — a medicine matches a recorded allergy', from: 'Warn (default — not yet reviewed)', to: 'Warn', ev: 'medications.safety_policy.updated', note: 'Stephan’s decision, 29 Sep 2026' },
    { id: 'h3', when: '29 Sep 2026 8:01 am', sort: 202609290801, who: 'Demo Admin', area: 'rules', sec: 'safety', scope: 'All houses', what: 'Safety check — competency restricted', from: 'Off', to: 'Block', ev: 'medications.safety_policy.updated', note: 'Stephan’s decision, 29 Sep 2026', g: 'safety', k: 'restricted', fromV: 'off' },
    { id: 'h4', when: '29 Sep 2026 8:00 am', sort: 202609290800, who: 'Demo Admin', area: 'rules', sec: 'safety', scope: 'All houses', what: 'Safety check — controlled-drug or covert area not passed', from: 'Off', to: 'Block when the area was failed', ev: 'medications.safety_policy.updated', note: 'Stephan’s decision, 29 Sep 2026', g: 'safety', k: 'area', fromV: 'off' },
    { id: 'h5', when: '20 Sep 2026 10:41 am', sort: 202609201041, who: 'Hana Kereama', area: 'rules', sec: 'medicines', scope: 'All houses', what: 'Medicine rule paused', from: 'Digoxin — record pulse (active)', to: 'Paused', ev: 'medicationadminrule.update' },
    { id: 'h6', when: '14 Sep 2026 4:02 pm', sort: 202609141602, who: 'Jordan Tipene', area: 'rounds', sec: 'templates', scope: 'Kōwhai House', what: 'Round template paused — Weekend late breakfast', from: 'Active', to: 'Paused', ev: 'medications.round_template.updated (audit needed — not recorded today)' },
    { id: 'h7', when: '1 Sep 2026 9:30 am', sort: 202609010930, who: 'Jordan Tipene', area: 'rounds', sec: 'templates', scope: 'Kōwhai House', what: 'Round template retired — Night round', from: 'Active', to: 'Retired', ev: 'medications.round_template.retired' },
    { id: 'h8', when: '22 Aug 2026 11:15 am', sort: 202608221115, who: 'Sione Taufa', area: 'rounds', sec: 'templates', scope: 'Rimu House', what: 'Round template — Morning round, default staff', from: 'Everyone rostered', to: 'Sione Taufa', ev: 'medications.round_template.updated (audit needed — not recorded today)' },
    { id: 'h9', when: '19 Aug 2026 3:20 pm', sort: 202608191520, who: 'Hana Kereama', area: 'rules', sec: 'medicines', scope: 'Kōwhai House', what: 'Medicine rule added', from: '—', to: 'Subcutaneous injection at Kōwhai House — a second person confirms', ev: 'medicationadminrule.create' },
    { id: 'h10', when: '3 Aug 2026 9:05 am', sort: 202608030905, who: 'Hana Kereama', area: 'rules', sec: 'medicines', scope: 'All houses', what: 'Medicine rule added', from: '—', to: 'Insulin glargine — record blood sugar (BSL)', ev: 'medicationadminrule.create' },
];

/* ── Stephan’s 21 answers, relayed 29 Sep 2026 (docs/emar-design/P11/v1/APPROVAL.md) ── */
export const ANSWERS: [string, string, string][] = [
    ['1', 'House rules (D2)', 'Restore today’s capability: house managers add and change medicine rules for their own houses. Organisation-wide rules still need all-sites authority.'],
    ['2', 'Emergency access policy editors', 'Keep today’s role check: admins and provider managers.'],
    ['3', 'Round templates gate', 'Keep today’s rule: people who manage orders at the house.'],
    ['4', 'Who records assessments', 'Keep today’s rule (manages orders at the house) for now.'],
    ['5', 'Competency register', 'Leads only. Each worker sees their own status in My eligibility.'],
    ['6', 'Auditors', 'Get read-only access to Settings.'],
    ['7', 'Competency values', '12 months, pass mark 10 of 12, every core area must pass, 30-day renewal reminder — defaults for the clinical lead to review. Observed administrations stay “Not configured”.'],
    ['8', 'Longest exemption', '30 days, shown as “Default — not yet reviewed” for the clinical lead to confirm.'],
    ['9', 'During an exemption', 'The restricted and area rules still apply.'],
    ['10', 'Witness tick', '“Can witness controlled drugs” is only allowed when the controlled drugs area is passed.'],
    ['11', 'Restricted workers', 'Can’t witness controlled drugs while restricted.'],
    ['12', 'Acknowledgement', 'Only from the worker’s own login. The assessor’s tick box is removed.'],
    ['13', 'Dose timing', 'Stays in Settings › Rounds & timing.'],
    ['14', 'Fixed-in-code values', 'The late-dose incident threshold (120 min) and refusal escalation (3 in 7 days) become settings, “Default — not yet reviewed”.'],
    ['15', 'Alert recipients', 'Don’t build configurable recipients yet: the decided routing plus the per-house on-call contact; the rest stay proposals.'],
    ['16', 'Proposed recipients', 'Agreed as proposals.'],
    ['17', 'Suspected alert faults', 'Fix task raised (separate session).'],
    ['18', 'Medicine photos', 'Anyone who can receive stock; prompt, never required; on the Medication rules page.'],
    ['19', 'Emergency access proposals', 'Approved for P10.'],
    ['20', 'P00 wording updates', 'Empty PIN renewal reads “No renewal”; the locked-PIN message uses the set limits; house leads reset PINs for their houses; the heads-up badge shows Stephan’s decision.'],
    ['21', '“More” overflow', 'Acceptable at 1440 and 1280 px.'],
];
