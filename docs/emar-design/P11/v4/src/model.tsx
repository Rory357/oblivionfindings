/* P11 v4 — preview state, permissions and the rules that decide what staff can do.
 * Status comes from the competency policy (evaluate()) plus the organisation rules,
 * never from permissions (EM-03). Decided 29 Sep 2026: the restricted and area rules
 * still apply during an exemption; “can witness” needs the controlled drugs area
 * passed; a restricted worker can’t witness; acknowledgement only from the worker’s login. */
import { createContext, useContext, type ReactNode } from 'react';
import {
    ALERTS, ALERT_PEOPLE, AREAS, CDW_LABEL, CDW_OPTS, HIST_SEED, RECIPIENT_GROUPS, HOUSES, HOUSE_KEYS, MEDLIST, NZULM, OBS, ONSHIFT_NOW, PERSONAS, PIN_RULES, RULES_SEED,
    SAFETY_RULES, STAFF, STAFF_PINS, TEMPLATES_SEED, type HouseKey, type Hist, type Perm, type PersonaId, type PinState, type Rule, type Staff, type Tpl, type OnCallRule, FOLLOW_UP_DEFAULT,
} from './data';

/* ── Permissions (today’s gates, plus answers 1–6) ── */
export const has = (p: PersonaId, k: Perm) => PERSONAS[p].perms.includes(k);
export const allSites = (p: PersonaId) => p === 'clinical' || p === 'pm';
export const leadCap = (p: PersonaId) => has(p, 'orders.verify') || has(p, 'orders.manage') || has(p, 'settings.manage');
export const myHouses = (p: PersonaId): HouseKey[] => (p === 'sw' ? ['kowhai'] : HOUSE_KEYS);
export const canOrg = (p: PersonaId) => has(p, 'settings.manage') && allSites(p);
export const canHouse = (h: HouseKey, p: PersonaId) => has(p, 'settings.manage') && (allSites(p) || myHouses(p).includes(h));
export const canTemplates = (h: HouseKey, p: PersonaId) => has(p, 'orders.manage') && myHouses(p).includes(h); // answer 3
export const canRuleScope = (scope: 'all' | HouseKey, p: PersonaId) => (scope === 'all' ? canOrg(p) : canHouse(scope, p)); // answer 1
export const readOnlyAudit = (p: PersonaId) => !has(p, 'settings.manage') && has(p, 'audit.view'); // answer 6
export const canSeeSettings = (p: PersonaId) => has(p, 'settings.manage') || has(p, 'audit.view');
export const canEaPolicy = (p: PersonaId) => p === 'pm'; // answer 2: admins and provider managers (today’s rule)
export const canAssess = (p: PersonaId) => has(p, 'orders.manage'); // answer 4
export const canExempt = (p: PersonaId) => p === 'clinical' || p === 'pm'; // seeded “Grant competency exemptions”
export const canSeeRegister = (p: PersonaId) => leadCap(p); // answer 5

/* ── Settings values: saved + draft (Fleet pattern — drafts survive moving between views) ── */
export type Settings = {
    safety: Record<string, string>;
    cdw: Record<string, string>;
    photos: Record<string, string>;
    timing: { early: string; late: string; soon: string; reoffer: string; lateIncident: string; escalN: string; escalDays: string; critical: { med: string; min: string }[] };
    elig: Record<string, string>;
    pin: Record<string, string>;
    ea: Record<string, string>;
    /** Who gets each alert, at every house: switched-on groups plus named people. */
    alerts: Record<string, AlertSetting>;
    /** Extra people a house manager adds for their own house, keyed `${house}.${alert}`. */
    alertExtra: Record<string, string[]>;
    /** How alerts are delivered and followed up until someone attends (organisation-wide). */
    delivery: DeliverySettings;
};
export type DeliverySettings = { realertEvery: string; realertMax: string; attended: string; escalateAfter: string; escalateTo: string[]; digest: string; private: string; copies: string; pinned: string; quietFrom: string; quietUntil: string };
export type GroupKey = keyof Settings;
/** How an alert is sent (organisation-wide) and who gets it. People can also add email copies for themselves. */
export type AlertSetting = { inapp: boolean; email: boolean; push: boolean; followUp: boolean; groups: string[]; people: string[] };
export const alertById = (k: string) => ALERTS.find((a) => a.k === k)!;
export { ALERT_PEOPLE, RECIPIENT_GROUPS };
export const INITIAL_SETTINGS: Settings = {
    safety: { profileAllergy: 'warn', restricted: 'block', area: 'failed', phoneRx: 'sw', phoneRxBy: 'nextday', amount: 'avail' },
    cdw: { org: 'on', kowhai: 'org', rimu: 'org', suggest: 'on', longest: 'shift' },
    photos: { who: 'stock', prompt: 'prompt' },
    // Stephan, 29 Sep 2026: keep today’s timing (config/medications.php) until the clinical lead reviews it.
    timing: { early: '30', late: '60', soon: '60', reoffer: '', lateIncident: '120', escalN: '3', escalDays: '7', critical: [] },
    // Answer 7 and 8: defaults for the clinical lead to review; observed administrations not configured.
    elig: { validity: '12', passMark: '10', coreMust: 'yes', reminder: '30', obsNeeded: '', longestEx: '30' },
    // Stephan’s PIN answers, 29 Sep 2026.
    pin: { attempts: '5', lockout: '15', renewal: '', fallback: 'yes', fallbackCd: 'no', resetRoles: 'both', confirmLimit: '30', routeTo: 'lead-house' },
    ea: { def: '60', max: '240', ext: '30', reason: 'yes', repeatN: '4', repeatDays: '7' },
    alerts: Object.fromEntries(ALERTS.map((a) => [a.k, { inapp: true, email: false, push: false, followUp: FOLLOW_UP_DEFAULT.includes(a.k), groups: [...a.def], people: [] }])),
    alertExtra: Object.fromEntries(HOUSE_KEYS.flatMap((h) => ALERTS.map((a) => [`${h}.${a.k}`, [] as string[]]))),
    // Today (verified on main): alerts are sent once, in-app only. Re-alerting and escalation are new and start off.
    delivery: { realertEvery: '', realertMax: '', attended: 'ack', escalateAfter: '', escalateTo: [], digest: 'no', private: 'yes', copies: 'yes', pinned: 'no', quietFrom: '', quietUntil: '' },
};
const S29 = 'Stephan’s decision, 29 Sep 2026';
export const INITIAL_SETBY: Record<GroupKey, Record<string, string>> = {
    safety: { profileAllergy: 'Stephan’s decision, 29 Sep 2026 — Warn for now', restricted: S29, area: S29, amount: S29 },
    cdw: { org: S29, suggest: S29, longest: 'Default chosen with Stephan’s go-ahead, 29 Sep 2026', kowhai: S29, rimu: S29 },
    photos: {},
    timing: {},
    elig: {},
    pin: Object.fromEntries(PIN_RULES.map((r) => [r.key, S29])),
    ea: {},
    alerts: {}, // Stephan, 29 Sep 2026: email starts off as a default not yet reviewed, so every alert is still to review
    alertExtra: {},
    delivery: { copies: S29 }, // Stephan: people can add email copies for themselves
};

const opt = (list: [string, string][], v: string) => (list.find((o) => o[0] === v) || ['', v])[1];
export const PHOTO_OPTS: Record<string, [string, string][]> = { who: [['stock', 'Anyone who can receive stock'], ['leads', 'Leads only'], ['recorders', 'Anyone who records doses']], prompt: [['prompt', 'Prompt, never required'], ['off', 'Don’t prompt']] };
export const PHOTO_LABEL: Record<string, string> = { who: 'Who can take or replace a medicine photo', prompt: 'Prompt for a photo when a new medicine or brand is received' };
export const TIMING_L: Record<string, [string, string]> = { early: ['Doses can be given from', 'minutes before the dose time'], late: ['Doses count as late', 'minutes after the dose time'], soon: ['Doses show as due soon', 'minutes before the dose time'], reoffer: ['Remind staff to offer again after a refusal', 'minutes after the refusal'], lateIncident: ['A late dose raises an incident', 'minutes after the dose time'], escalN: ['Repeated refusals escalate', 'refusals or withholds'], escalDays: ['Repeated refusals escalate — within', 'days'] };
export const ELIG_L: Record<string, [string, string]> = { validity: ['An assessment stays current for', 'months'], passMark: ['Pass mark', 'of the 12 areas passed'], coreMust: ['Every core area must be passed', ''], obsNeeded: ['Minimum observed administrations', 'observed administrations'], reminder: ['Renewal reminder', 'days before the end date'], longestEx: ['Longest exemption', 'days'] };
export const DELIVERY_L: Record<string, string> = { realertEvery: 'Re-alert until someone attends', realertMax: 'Most re-alerts', attended: 'An alert counts as attended when', escalateAfter: 'Escalate if still not attended', escalateTo: 'Escalate to', digest: 'Group emails into an hourly summary', private: 'Keep client names and medicines out of email and push', copies: 'People can add email copies for themselves', pinned: 'Keep unattended alerts at the top of the bell', quietFrom: 'Hold non-urgent alerts overnight', quietUntil: 'Quiet hours end' };
export const ATTENDED_OPTS: [string, string][] = [['open', 'Someone opens it'], ['ack', 'Someone acknowledges it'], ['done', 'It’s dealt with']];
export const EA_L: Record<string, string> = { def: 'A grant lasts', max: 'Longest grant', ext: 'Each extension adds', reason: 'A reason is required', repeatN: 'Flag repeat use — grants', repeatDays: 'Flag repeat use — within days' };
export const fmtMin = (v: string) => { const n = parseInt(v, 10); if (!Number.isFinite(n)) return 'Not configured'; if (n % 60 === 0 && n >= 60) return `${n / 60} ${n === 60 ? 'hour' : 'hours'}`; return `${n} minutes`; };

export type ViewKey = 'rules' | 'rounds' | 'staff' | 'alerts' | 'history';
type Group = { view: ViewKey; sec: (k: string) => string; keys: string[]; label: (k: string) => string; fmt: (k: string, v: unknown) => string; can: (p: PersonaId) => boolean; effect: string; ev: string };
const pinFmt = (k: string, v: string) => {
    const r = PIN_RULES.find((x) => x.key === k)!;
    if (k === 'renewal') return v ? `Every ${v} months` : 'No renewal';
    return r.type === 'number' ? (v ? `${v} ${r.unit}` : 'Not configured') : opt(r.opts!, v);
};
export const GROUPS: Record<GroupKey, Group> = {
    safety: { view: 'rules', sec: () => 'safety', keys: SAFETY_RULES.map((r) => r.key), label: (k) => SAFETY_RULES.find((r) => r.key === k)!.label, fmt: (k, v) => opt(SAFETY_RULES.find((r) => r.key === k)!.opts, String(v)), can: canOrg, effect: 'From the next dose signed, at every house', ev: 'medications.safety_policy.updated' },
    cdw: { view: 'rules', sec: () => 'controlled', keys: ['org', 'kowhai', 'rimu', 'longest', 'suggest'], label: (k) => (k === 'kowhai' || k === 'rimu' ? `Witness at ${CDW_LABEL[k]}` : CDW_LABEL[k]), fmt: (k, v) => opt(CDW_OPTS[k === 'kowhai' || k === 'rimu' ? 'house' : k], String(v)).replace(' (recommended)', '').replace(' (default)', ''), can: canOrg, effect: 'From the next controlled dose recorded', ev: 'medications.controlled_witness_policy.updated (new)' },
    photos: { view: 'rules', sec: () => 'photos', keys: ['who', 'prompt'], label: (k) => PHOTO_LABEL[k], fmt: (k, v) => opt(PHOTO_OPTS[k], String(v)), can: canOrg, effect: 'From the next stock received, at every house', ev: 'medications.photo_policy.updated (new)' },
    timing: { view: 'rounds', sec: () => 'timing', keys: ['early', 'late', 'soon', 'critical', 'reoffer', 'lateIncident', 'escalN', 'escalDays'], label: (k) => (k === 'critical' ? 'Time-critical medicines' : TIMING_L[k][0]), fmt: (k, v) => (k === 'critical' ? ((v as { med: string; min: string }[]).length ? (v as { med: string; min: string }[]).map((c) => `${c.med} — late after ${c.min} minutes`).join('; ') : 'None marked') : k === 'reoffer' && !v ? 'Off — no reminder' : v ? `${v} ${TIMING_L[k][1]}` : 'Not configured'), can: canOrg, effect: 'From the next dose shown on Meds today, at every house — recording is never blocked', ev: 'medications.mar_timing.updated (new — not recorded today)' },
    elig: { view: 'staff', sec: (k) => (k === 'longestEx' ? 'exemptions' : 'competency'), keys: ['validity', 'passMark', 'coreMust', 'obsNeeded', 'reminder', 'longestEx'], label: (k) => ELIG_L[k][0], fmt: (k, v) => (k === 'coreMust' ? (v === 'yes' ? 'On' : 'Off — only the pass mark counts') : k === 'obsNeeded' && !v ? 'Off — no minimum (not configured)' : v ? `${v} ${ELIG_L[k][1]}` : 'Not configured'), can: canOrg, effect: 'From the next assessment or exemption recorded, at every house — existing ones keep their end dates', ev: 'medications.competency_policy.updated (new)' },
    pin: { view: 'staff', sec: () => 'pins', keys: PIN_RULES.map((r) => r.key), label: (k) => PIN_RULES.find((r) => r.key === k)!.label, fmt: (k, v) => pinFmt(k, String(v)), can: canOrg, effect: 'From the next dose signed or witnessed, at every house', ev: 'medications.witness_pin_policy.updated (new with PIN-1)' },
    alerts: { view: 'alerts', sec: () => 'alerts', keys: ALERTS.map((a) => a.k), label: (k) => `Who gets “${alertById(k).l}”`, fmt: (_k, v) => { const x = v as AlertSetting; return `In-app ${x.inapp ? 'on' : 'off'} · email ${x.email ? 'on' : 'off'} · push ${x.push ? 'on' : 'off'} · follow up ${x.followUp ? 'on' : 'off'} · ${[...x.groups.map((g) => RECIPIENT_GROUPS[g].l), ...x.people].join(', ') || 'nobody'}`; }, can: canOrg, effect: 'From the next alert sent, at every house', ev: 'medications.alert_recipients.updated (new)' },
    alertExtra: { view: 'alerts', sec: () => 'alerts', keys: HOUSE_KEYS.flatMap((h) => ALERTS.map((a) => `${h}.${a.k}`)), label: (k) => `${alertById(k.split('.')[1]).l} — extra people at ${HOUSES[k.split('.')[0] as HouseKey]}`, fmt: (_k, v) => (v as string[]).join(', ') || 'Nobody extra', can: (p) => HOUSE_KEYS.some((h) => canHouse(h, p)), effect: 'From the next alert at that house', ev: 'medications.alert_recipients.updated (new)' },
    delivery: { view: 'alerts', sec: () => 'delivery', keys: ['realertEvery', 'realertMax', 'attended', 'escalateAfter', 'escalateTo', 'quietFrom', 'quietUntil', 'digest', 'private', 'copies', 'pinned'], label: (k) => DELIVERY_L[k], fmt: (k, v) => (k === 'escalateTo' ? (v as string[]).map((g) => RECIPIENT_GROUPS[g].l).join(', ') || 'Nobody chosen' : k === 'attended' ? opt(ATTENDED_OPTS, String(v)) : k === 'realertEvery' ? (v ? `Every ${v} minutes` : 'Off — each alert is sent once') : k === 'realertMax' ? (v ? `Up to ${v} times` : 'Off') : k === 'escalateAfter' ? (v ? `After ${v} minutes` : 'Off — nobody else is told') : k === 'quietFrom' ? (v ? `From ${fmtT(String(v))}` : 'Off — sent straight away') : k === 'quietUntil' ? (v ? `Until ${fmtT(String(v))}` : 'Off') : v === 'yes' ? 'On' : 'Off'), can: canOrg, effect: 'From the next alert sent, at every house', ev: 'medications.alert_delivery.updated (new)' },
    ea: { view: 'alerts', sec: () => 'emergency', keys: ['def', 'max', 'ext', 'reason', 'repeatN', 'repeatDays'], label: (k) => EA_L[k], fmt: (k, v) => (k === 'reason' ? (v === 'yes' ? 'On' : 'Off') : ['def', 'max', 'ext'].includes(k) ? fmtMin(String(v)) : String(v)), can: canEaPolicy, effect: 'From the next emergency access grant — grants already running keep their end time', ev: 'break_glass_policy.updated (new — not recorded today)' },
};
export const GROUP_KEYS = Object.keys(GROUPS) as GroupKey[];
export const VIEW_LABEL: Record<ViewKey, string> = { rules: 'Medication rules', rounds: 'Rounds & timing', staff: 'Staff & PINs', alerts: 'Alerts & access', history: 'Change history' };

/* ── The model ── */
export type Exemption = { id: string; who: string; house: HouseKey; reason: string; from: string; until: string; by: string; at: string; status: 'active' | 'ended' | 'revoked'; endNote?: string };
/** On-call contact for a house: follow the roster (on-call shift, then optionally the team lead on shift), with an
 * employed staff member as the fallback — or always that staff member. */
export type OnCall = (OnCallRule & { by: string; when: string }) | null;
export type Demo = { settings: 'loaded' | 'loading' | 'error' | 'stale' | 'offline' | 'first'; elig: 'loaded' | 'loading' | 'error' | 'stale' | 'empty'; rules: 'loaded' | 'empty'; tpl: 'loaded' | 'empty'; save: 'ok' | 'fail' | 'conflict' };
export type MyScenario = 'current' | 'due' | 'expired' | 'restricted' | 'exempt' | 'ack' | 'none';
export type Model = {
    persona: PersonaId;
    my: MyScenario;
    demo: Demo;
    saved: Settings;
    draft: Settings;
    /** Switches turned on whose number hasn’t been typed yet (never an invented value). */
    pendingOn: Record<string, boolean>;
    setBy: Record<GroupKey, Record<string, string>>;
    eaSaved: string | null;
    rules: Rule[];
    templates: Tpl[];
    oncall: Record<HouseKey, OnCall>;
    exemptions: Exemption[];
    eligRows: Record<string, Partial<Staff>>;
    pins: typeof STAFF_PINS;
    myAck: boolean;
    history: Hist[];
};
const clone = <T,>(o: T): T => JSON.parse(JSON.stringify(o));
export const initialModel = (): Model => ({
    persona: 'clinical', my: 'current',
    demo: { settings: 'loaded', elig: 'loaded', rules: 'loaded', tpl: 'loaded', save: 'ok' },
    saved: clone(INITIAL_SETTINGS), draft: clone(INITIAL_SETTINGS), pendingOn: {}, setBy: clone(INITIAL_SETBY), eaSaved: null,
    rules: clone(RULES_SEED), templates: clone(TEMPLATES_SEED), oncall: { kowhai: null, rimu: null }, exemptions: [], eligRows: {}, pins: clone(STAFF_PINS), myAck: false, history: [],
});
export type Store = { m: Model; set: (fn: (d: Model) => void) => void; flash: (msg: string) => void; flashMsg: string | null };
export const StoreCtx = createContext<Store | null>(null);
export const useStore = () => useContext(StoreCtx)!;
export function StoreProvider({ value, children }: { value: Store; children: ReactNode }) {
    return <StoreCtx.Provider value={value}>{children}</StoreCtx.Provider>;
}
export const mutate = (m: Model, fn: (d: Model) => void) => { const d = clone(m); fn(d); return d; };

/* ── Draft bookkeeping ── */
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
export type Change = { g: GroupKey; k: string; label: string; from: string; to: string };
export function groupChanges(m: Model, g: GroupKey): Change[] {
    const G = GROUPS[g], d = m.draft[g] as Record<string, unknown>, s = m.saved[g] as Record<string, unknown>;
    return G.keys.filter((k) => !same(d[k], s[k]) || (m.pendingOn[`${g}.${k}`] && d[k] === '')).map((k) => ({ g, k, label: G.label(k), from: G.fmt(k, s[k]), to: d[k] === '' && m.pendingOn[`${g}.${k}`] ? 'On — not entered yet' : G.fmt(k, d[k]) }));
}
export const viewGroups = (v: ViewKey) => GROUP_KEYS.filter((g) => GROUPS[g].view === v);
export const viewChanges = (m: Model, v: ViewKey) => viewGroups(v).flatMap((g) => groupChanges(m, g));
export const allChanges = (m: Model) => GROUP_KEYS.flatMap((g) => groupChanges(m, g));
export const isDirty = (m: Model, g: GroupKey, k: string) => groupChanges(m, g).some((c) => c.k === k);
export const secDirty = (m: Model, v: ViewKey, sec: string) => viewGroups(v).some((g) => groupChanges(m, g).some((c) => GROUPS[g].sec(c.k) === sec));
export function discardDrafts(d: Model, v?: ViewKey) {
    GROUP_KEYS.forEach((g) => {
        if (v && GROUPS[g].view !== v) return;
        (d.draft as Record<string, unknown>)[g] = clone(d.saved[g]);
        Object.keys(d.pendingOn).forEach((k) => { if (k.startsWith(`${g}.`)) delete d.pendingOn[k]; });
    });
}
export const reviewedBy = (m: Model, g: GroupKey, k: string) => (g === 'ea' ? m.eaSaved : m.setBy[g][k]) || null;
export const whole = (v: string, min: number, max?: number) => v !== '' && Number.isInteger(Number(v)) && Number(v) >= min && (max == null || Number(v) <= max);
export function validateView(m: Model, v: ViewKey): Record<string, string> {
    const e: Record<string, string> = {};
    const need = 'Enter a number, or turn this off.';
    if (v === 'rounds') {
        const d = m.draft.timing;
        (['early', 'late', 'soon', 'lateIncident', 'escalN', 'escalDays'] as const).forEach((k) => { if (!whole(d[k], 1)) e[k] = 'Enter a whole number, 1 or more.'; });
        if (d.reoffer !== '' && !whole(d.reoffer, 1)) e.reoffer = 'Enter a whole number of minutes, 1 or more.';
        if (m.pendingOn['timing.reoffer'] && d.reoffer === '') e.reoffer = need;
    }
    if (v === 'staff') {
        const d = m.draft.elig, pd = m.draft.pin;
        if (!whole(d.validity, 1)) e.validity = 'Enter a whole number of months, 1 or more.';
        if (!whole(d.passMark, 1, 12)) e.passMark = 'Enter a number of areas from 1 to 12.';
        if (!whole(d.reminder, 1)) e.reminder = 'Enter a whole number of days, 1 or more.';
        if (d.obsNeeded !== '' && !whole(d.obsNeeded, 1)) e.obsNeeded = 'Enter a whole number, 1 or more.';
        if (m.pendingOn['elig.obsNeeded'] && d.obsNeeded === '') e.obsNeeded = need;
        if (!whole(d.longestEx, 1)) e.longestEx = 'Enter a whole number of days, 1 or more.';
        (['attempts', 'lockout', 'confirmLimit'] as const).forEach((k) => { if (!whole(pd[k], 1)) e[k] = 'Enter a whole number, 1 or more.'; });
        if (pd.renewal !== '' && !whole(pd.renewal, 1)) e.renewal = 'Enter a whole number of months, 1 or more.';
        if (m.pendingOn['pin.renewal'] && pd.renewal === '') e.renewal = need;
    }
    if (v === 'alerts') {
        const d = m.draft.ea;
        (['def', 'max', 'ext'] as const).forEach((k) => { if (!whole(d[k], 5, 1440)) e[k] = 'Enter a whole number of minutes from 5 to 1,440 (24 hours).'; });
        if (!e.def && !e.max && Number(d.def) > Number(d.max)) e.def = `A grant can’t last longer than the longest grant (${fmtMin(d.max)}).`;
        if (!e.ext && !e.max && Number(d.ext) > Number(d.max)) e.ext = `An extension can’t be longer than the longest grant (${fmtMin(d.max)}).`;
        if (!whole(d.repeatN, 1, 100)) e.repeatN = 'Enter a number of grants from 1 to 100.';
        if (!whole(d.repeatDays, 1, 90)) e.repeatDays = 'Enter a number of days from 1 to 90.';
        ALERTS.forEach((a) => { const x = m.draft.alerts[a.k]; if (!x.inapp && !x.email && !x.push) e[`al-${a.k}`] = `“${a.l}”: turn on in-app, email or push — otherwise nobody is told.`; });
        const dl = m.draft.delivery;
        if (dl.realertEvery !== '' || m.pendingOn['delivery.realertEvery']) {
            if (!whole(dl.realertEvery, 15, 1440)) e['dl-realertEvery'] = 'Enter minutes from 15 to 1,440. Alerts are checked every 15 minutes.';
            if (!whole(dl.realertMax, 1, 10)) e['dl-realertMax'] = 'Enter how many re-alerts, from 1 to 10.';
        }
        if (dl.escalateAfter !== '' || m.pendingOn['delivery.escalateAfter']) {
            if (!whole(dl.escalateAfter, 15, 1440)) e['dl-escalateAfter'] = 'Enter minutes from 15 to 1,440. Alerts are checked every 15 minutes.';
            if (!dl.escalateTo.length) e['dl-escalateTo'] = 'Choose who it escalates to.';
        }
        if (dl.quietFrom !== '' || dl.quietUntil !== '' || m.pendingOn['delivery.quietFrom']) {
            if (!/^\d{2}:\d{2}$/.test(dl.quietFrom) || !/^\d{2}:\d{2}$/.test(dl.quietUntil)) e['dl-quiet'] = 'Choose when quiet hours start and end.';
            else if (dl.quietFrom === dl.quietUntil) e['dl-quiet'] = 'Quiet hours can’t start and end at the same time.';
        }
    }
    return e;
}
/** Mark a default as deliberately kept (reviewed), with its paired settings, and record it. */
/* ── Does a change turn a check off or make it less strict? (Main, 29 Sep 2026: loosening uses the destructive
 * variant.) Options are listed loosest → strictest; numbers say whether a higher value is looser (1) or stricter (-1).
 * Switching a number-backed setting off ('') is the loosest value where “off” means no check. ── */
const RANK: Record<string, string[]> = {
    'safety.profileAllergy': ['warn', 'confirm', 'block'], 'safety.restricted': ['off', 'cosigner', 'block'], 'safety.area': ['off', 'failed', 'failed_or_not_seen'],
    'safety.phoneRx': ['sw', 'leads', 'none'], 'safety.phoneRxBy': ['nextday', 'shift'], 'safety.amount': ['no', 'avail', 'always'],
    'cdw.org': ['off', 'on'], 'cdw.kowhai': ['off', 'org', 'on'], 'cdw.rimu': ['off', 'org', 'on'], 'cdw.suggest': ['off', 'on'], 'cdw.longest': ['7d', '24h', 'shift'],
    'pin.fallback': ['yes', 'nc', 'no'], 'pin.fallbackCd': ['yes', 'nc', 'no'],
    'elig.coreMust': ['no', 'yes'], 'ea.reason': ['no', 'yes'], 'delivery.private': ['no', 'yes'],
};
const NUM: Record<string, 1 | -1> = {
    'pin.attempts': 1, 'pin.lockout': -1, 'pin.confirmLimit': 1, 'pin.renewal': 1,
    'elig.validity': 1, 'elig.passMark': -1, 'elig.reminder': -1, 'elig.obsNeeded': -1, 'elig.longestEx': 1,
    'timing.early': 1, 'timing.late': 1, 'timing.lateIncident': 1, 'timing.escalN': 1, 'timing.escalDays': -1, 'timing.reoffer': 1,
    'ea.def': 1, 'ea.max': 1, 'ea.ext': 1, 'ea.repeatN': 1, 'ea.repeatDays': -1,
    'delivery.realertEvery': 1, 'delivery.realertMax': -1, 'delivery.escalateAfter': 1,
};
const OFF_IS_LOOSEST = ['pin.renewal', 'elig.obsNeeded', 'timing.reoffer', 'delivery.realertEvery', 'delivery.realertMax', 'delivery.escalateAfter'];
const dropped = (a: string[], b: string[]) => a.some((x) => !b.includes(x));
export function loosens(g: GroupKey, k: string, from: unknown, to: unknown): boolean {
    const key = `${g}.${k}`;
    if (g === 'alerts') { const a = from as AlertSetting, b = to as AlertSetting; return (a.inapp && !b.inapp) || (a.email && !b.email) || (a.push && !b.push) || (a.followUp && !b.followUp) || dropped(a.groups, b.groups) || dropped(a.people, b.people); }
    if (g === 'alertExtra' || key === 'delivery.escalateTo') return dropped(from as string[], to as string[]);
    if (key === 'pin.resetRoles') { const n = (v: string) => (v === 'both' ? 2 : v === 'nc' ? 0 : 1); return n(String(to)) > n(String(from)); } // more people can reset = looser
    if (RANK[key]) { const r = RANK[key], i = r.indexOf(String(from)), j = r.indexOf(String(to)); return i >= 0 && j >= 0 && j < i; }
    if (NUM[key]) {
        const f = String(from ?? ''), t = String(to ?? '');
        if (f !== '' && t === '') return OFF_IS_LOOSEST.includes(key);
        if (f === '' || t === '') return false; // switching a check on is never looser
        const d = Number(t) - Number(f);
        return NUM[key] === 1 ? d > 0 : d < 0;
    }
    return false;
}
export function keepDefault(d: Model, g: GroupKey, k: string) {
    const G = GROUPS[g];
    const label = g === 'ea' ? 'The emergency access policy' : G.label(k);
    const value = g === 'ea' ? `${G.fmt('def', d.saved.ea.def)} per grant, longest ${G.fmt('max', d.saved.ea.max)}` : G.fmt(k, (d.saved[g] as Record<string, unknown>)[k]);
    if (g === 'ea') d.eaSaved = stamp(d);
    else {
        d.setBy[g][k] = stamp(d);
        const pair: Record<string, string> = { 'timing.escalN': 'escalDays', 'delivery.realertEvery': 'realertMax', 'delivery.escalateAfter': 'escalateTo', 'delivery.quietFrom': 'quietUntil' };
        if (pair[`${g}.${k}`]) d.setBy[g][pair[`${g}.${k}`]] = stamp(d);
    }
    logChange(d, { area: G.view as 'rules', sec: G.sec(k), scope: 'All houses', what: `Reviewed — ${label}`, from: 'Default — not yet reviewed', to: `Kept: ${value}`, ev: G.ev });
    return { label, value };
}
export function logChange(d: Model, h: Omit<Hist, 'id' | 'when' | 'sort' | 'who' | 'fresh'>) {
    const n = d.history.length + 1;
    d.history.unshift({ ...h, id: `hs${n}`, when: '29 Sep 2026 9:12 am', sort: 202609290912 + n, who: PERSONAS[d.persona].name, fresh: true });
}
export const stamp = (m: Model) => `${PERSONAS[m.persona].name}, 29 Sep 2026 9:12 am`;
export const allHistory = (m: Model) => [...m.history, ...(m.demo.settings === 'first' ? [] : HIST_SEED)].sort((a, b) => Number(!!b.fresh) - Number(!!a.fresh) || b.sort - a.sort);

/* ── “Still to decide”: settings nobody has deliberately chosen ── */
export type Pending = { view: ViewKey; sec: string; label: string; state: 'nc' | 'default'; until: string; dec: string; scope: string; g?: GroupKey; k?: string };
export function decisionRegistry(m: Model): Pending[] {
    const out: Pending[] = [];
    const add = (view: ViewKey, sec: string, label: string, state: 'nc' | 'default', until: string, dec: string, scope = 'All houses', g?: GroupKey, k?: string) => out.push({ view, sec, label, state, until, dec, scope, g, k });
    SAFETY_RULES.forEach((r) => { if (!m.setBy.safety[r.key]) add('rules', 'safety', r.label, 'default', `Behaves as: ${opt(r.opts, m.saved.safety[r.key])}`, 'D2', 'All houses', 'safety', r.key); });
    (['who', 'prompt'] as const).forEach((k) => { if (!m.setBy.photos[k]) add('rules', 'photos', PHOTO_LABEL[k], 'default', `Agreed default: ${opt(PHOTO_OPTS[k], m.saved.photos[k])}`, 'P06', 'All houses', 'photos', k); });
    (['early', 'late', 'soon', 'lateIncident'] as const).forEach((k) => { if (!m.setBy.timing[k]) add('rounds', 'timing', TIMING_L[k][0], 'default', `Today’s rule: ${m.saved.timing[k]} ${TIMING_L[k][1]}`, 'D4', 'All houses', 'timing', k); });
    if (!m.setBy.timing.escalN) add('rounds', 'timing', 'Repeated refusals escalate', 'default', `Today’s rule: ${m.saved.timing.escalN} within ${m.saved.timing.escalDays} days`, 'D4', 'All houses', 'timing', 'escalN');
    if (!m.saved.timing.critical.length) add('rounds', 'timing', 'Time-critical medicines', 'nc', 'None marked — every medicine uses the late time', 'D4');
    if (!m.saved.timing.reoffer) add('rounds', 'timing', TIMING_L.reoffer[0], 'nc', 'Re-offers can still be recorded; no reminder is made', 'D4');
    (['validity', 'passMark', 'coreMust', 'reminder', 'longestEx'] as const).forEach((k) => { if (!m.setBy.elig[k]) add('staff', k === 'longestEx' ? 'exemptions' : 'competency', ELIG_L[k][0], 'default', `Behaves as: ${GROUPS.elig.fmt(k, m.saved.elig[k])}`, 'D3', 'All houses', 'elig', k); });
    if (!m.saved.elig.obsNeeded) add('staff', 'competency', ELIG_L.obsNeeded[0], 'nc', 'No minimum is asked for', 'D3');
    if (!m.eaSaved) (['def', 'max', 'ext', 'reason', 'repeatN'] as const).forEach((k) => add('alerts', 'emergency', k === 'repeatN' ? 'Flag repeat use' : EA_L[k], 'default', `Today: ${k === 'repeatN' ? `${m.saved.ea.repeatN} grants within ${m.saved.ea.repeatDays} days` : GROUPS.ea.fmt(k, m.saved.ea[k])}`, 'P10', 'All houses', 'ea', k));
    (['realertEvery', 'attended', 'escalateAfter', 'quietFrom', 'digest', 'private', 'pinned'] as const).forEach((k) => { if (!m.setBy.delivery[k]) add('alerts', 'delivery', DELIVERY_L[k], 'default', `${k === 'realertEvery' || k === 'escalateAfter' || k === 'quietFrom' ? 'Today' : 'Behaves as'}: ${GROUPS.delivery.fmt(k, m.saved.delivery[k as keyof DeliverySettings])}`, 'D12', 'All houses', 'delivery', k); });
    ALERTS.forEach((a) => { if (!m.setBy.alerts[a.k]) add('alerts', 'alerts', `Alert: ${a.l}`, 'default', GROUPS.alerts.fmt(a.k, m.saved.alerts[a.k]), 'D12', 'All houses', 'alerts', a.k); });
    HOUSE_KEYS.forEach((h) => { if (!m.oncall[h]) add('alerts', 'oncall', `On-call contact — ${HOUSES[h]}`, 'nc', 'Screens say “On-call contact: Not configured” and give no number', 'D12', HOUSES[h]); });
    return out;
}

/* ── P00 v5 rule helpers (plain-text versions of the approved wording) ── */
export const ruleItems = (r: Pick<Rule, 'match' | 'value' | 'scope'>) => MEDLIST.filter((x) => (r.scope === 'all' || x.house === r.scope) && (r.match === 'name' ? x.med.toLowerCase() === String(r.value).trim().toLowerCase() : r.match === 'route' ? x.route === r.value : r.match === 'nzulm' ? x.nzulm === r.value : r.match === 'cls' ? x.cls === r.value : r.match === 'controlled' ? !!x.cd : false));
export function ruleWhat(r: Pick<Rule, 'match' | 'value'>) {
    const v = r.value || '…';
    if (r.match === 'name') return v;
    if (r.match === 'route') return `any medicine given by ${v.toLowerCase()}`;
    if (r.match === 'nzulm') { const n = NZULM.find((x) => x[0] === r.value); return `${n ? n[1] : 'NZULM code ' + v} (NZULM ${v}, test code)`; }
    if (r.match === 'cls') return `any medicine in the class ${v.toLowerCase()}`;
    return 'any controlled medicine';
}
export function ruleNeeds(r: Pick<Rule, 'countersign' | 'obs'>) {
    const parts = [r.countersign ? 'a second person confirms with their witness PIN' : '', ...r.obs.map((o) => `record ${OBS[o]}`)].filter(Boolean);
    return parts.length ? parts.join(' and ') : 'choose what it requires';
}
export const ruleSentence = (r: Pick<Rule, 'match' | 'value' | 'scope' | 'countersign' | 'obs'>) => `Before saving a dose of ${ruleWhat(r)} at ${HOUSES[r.scope]}: ${ruleNeeds(r)}.`;
export function ruleOverlaps(m: Model, r: Pick<Rule, 'id' | 'match' | 'value' | 'scope'>) {
    const see = (x: (typeof MEDLIST)[number]) => has(m.persona, 'cd.view') || !x.cd;
    const mine = ruleItems(r).filter(see);
    return m.rules.filter((o) => o.id !== r.id && o.active && ruleItems(o).some((x) => see(x) && mine.includes(x)));
}

/* ── Round templates ── */
export const toMin = (t: string) => { const [h, mm] = t.split(':').map(Number); return h * 60 + mm; };
export const fmtT = (t: string) => { const [h, mm] = t.split(':').map(Number); return `${((h + 11) % 12) + 1}:${String(mm).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`; };
export const daysText = (d: string[]) => (!d.length || d.length === 7 ? 'Every day' : d.length === 5 && ['1', '2', '3', '4', '5'].every((x) => d.includes(x)) ? 'Monday to Friday' : d.length === 2 && d.includes('6') && d.includes('7') ? 'Saturday and Sunday' : [['1', 'Mon'], ['2', 'Tue'], ['3', 'Wed'], ['4', 'Thu'], ['5', 'Fri'], ['6', 'Sat'], ['7', 'Sun']].filter(([k]) => d.includes(k)).map(([, l]) => l).join(', '));
export function tplOverlaps(m: Model, t: Pick<Tpl, 'id' | 'house' | 'time' | 'win' | 'days'>) {
    const w = Number(t.win) || 0, a0 = toMin(t.time) - w, a1 = toMin(t.time) + w, all = ['1', '2', '3', '4', '5', '6', '7'], ds = t.days.length ? t.days : all;
    return m.templates.filter((o) => o.id !== t.id && o.house === t.house && o.status === 'active' && (o.days.length ? o.days : all).some((d) => ds.includes(d)) && toMin(o.time) - o.win < a1 && toMin(o.time) + o.win > a0);
}

/* ── Staff eligibility ── */
export type Ability = { v: 'yes' | 'no' | 'part' | 'na'; t: string; why?: string[] };
export const staffById = (id: string) => STAFF.find((x) => x.id === id);
export type StaffNow = Staff & { exempt?: Exemption };
export function staffNow(m: Model, x: Staff): StaffNow {
    const o: StaffNow = { ...x, ...(m.eligRows[x.id] || {}) };
    const ex = m.exemptions.find((e) => e.who === x.id && e.status === 'active');
    if (ex) o.exempt = ex;
    return o;
}
export const staffList = (m: Model) => STAFF.map((x) => staffNow(m, x)).filter((x) => myHouses(m.persona).includes(x.house));
export const areaRes = (x: Staff, k: string) => (x.res && x.res[k]) || (x.st === 'none' ? 'unseen' : 'yes');
export const passCount = (x: Staff) => AREAS.filter((a) => areaRes(x, a.k) === 'yes').length;
export const firstName = (x: { name: string }) => x.name.split(' ')[0];
export const reminderDays = (m: Model) => parseInt(m.saved.elig.reminder, 10) || 30;
export const validState = (x: Staff) => x.st === 'current' || x.st === 'restricted' || (x.st === 'ack' && !!x.prevValid);
export type EffStatus = 'current' | 'due' | 'expired' | 'restricted' | 'failed' | 'none' | 'ack' | 'exempt';
export function effStatus(m: Model, x: StaffNow): EffStatus {
    if (x.exempt && ['expired', 'none', 'failed', 'ack'].includes(x.st) && !x.prevValid) return 'exempt';
    if (x.st === 'current' && (x.days ?? 999) <= reminderDays(m)) return 'due';
    return x.st;
}
export const restrictMode = (m: Model) => m.saved.safety.restricted;
export function givenAbility(m: Model, x: StaffNow): Ability {
    if (x.st === 'restricted') { const r = restrictMode(m); return r === 'block' ? { v: 'no', t: 'Can’t sign given doses alone — restricted (organisation rule: Block). A colleague on shift gives the dose.' } : r === 'cosigner' ? { v: 'part', t: 'A co-signer confirms each given dose with their witness PIN (restricted).' } : { v: 'yes', t: 'Records given doses — the restriction isn’t enforced (organisation rule: Off).' }; }
    if (x.st === 'current') return { v: 'yes', t: 'Records given doses' };
    if (x.st === 'ack' && x.prevValid) return { v: 'yes', t: `Records given doses on the previous assessment (until ${x.prevValid})` };
    if (x.exempt) return { v: 'part', t: `Records given doses under an exemption until ${x.exempt.until} (${HOUSES[x.exempt.house]} only)` };
    const why = { expired: `Assessment ended ${x.until}`, none: 'Not assessed yet', failed: 'Assessment not passed', ack: 'New assessment not acknowledged yet' }[x.st as 'expired' | 'none' | 'failed' | 'ack'];
    return { v: 'no', t: `Refused, withheld and away only — ${why}` };
}
export function areaAbility(m: Model, x: StaffNow, k: string): Ability {
    const res = areaRes(x, k), mode = m.saved.safety.area, a = AREAS.find((y) => y.k === k)!, lbl = a.l.toLowerCase();
    const what = k === 'cd' ? 'controlled doses' : 'doses with a covert plan';
    if (k === 'insulin') return { v: 'na', t: res === 'yes' ? 'Insulin area passed — not checked when recording yet' : `Insulin ${res === 'no' ? 'not passed' : 'not assessed'} — the system doesn’t check this yet (orders don’t say which medicines are insulin)` };
    if (givenAbility(m, x).v === 'no') return { v: 'na', t: 'Not relevant while given doses can’t be recorded' };
    const during = x.exempt && !validState(x) ? ' — this still applies during the exemption' : '';
    if (mode === 'off') return { v: 'yes', t: `${a.l}: ${res === 'yes' ? 'passed' : res === 'no' ? 'not passed' : 'not assessed'} — not checked when recording (organisation rule: Off)` };
    if (res === 'no') return { v: 'no', t: `Can’t sign ${what} as given — ${lbl} not passed (organisation rule: block when failed)${during}` };
    if (res === 'unseen') return mode === 'failed_or_not_seen' ? { v: 'no', t: `Can’t sign ${what} — ${lbl} not assessed (organisation rule)${during}` } : { v: 'yes', t: `${a.l} not assessed — allowed, because the current rule only blocks when the area was failed` };
    return { v: 'yes', t: `${k === 'cd' ? 'Controlled doses' : 'Doses with a covert plan'} — area passed` };
}
export const pinOf = (m: Model, name: string): PinState => (m.pins.find((y) => y.name === name) || { pin: 'notset' as PinState }).pin;
export function witnessAbility(m: Model, x: StaffNow, pinOverride?: PinState): Ability {
    const pin = pinOverride ?? pinOf(m, x.name), why: string[] = [];
    if (x.st === 'restricted') why.push('restricted — can’t witness while restricted');
    else if (!(x.st === 'current' || (x.st === 'ack' && x.prevValid))) why.push(x.exempt ? 'an exemption isn’t enough' : x.st === 'none' ? 'not assessed' : x.st === 'ack' ? 'assessment not acknowledged' : x.st === 'failed' ? 'not passed' : 'assessment ended');
    if (x.st !== 'none' && areaRes(x, 'cd') !== 'yes') why.push('controlled drugs area not passed');
    else if (x.st !== 'none' && !x.witness) why.push('“can witness controlled drugs” not on');
    if (pin !== 'set') why.push(pin === 'locked' ? 'PIN locked' : pin === 'adminreset' ? 'PIN reset — must set a new one' : 'no witness PIN set');
    return why.length ? { v: 'no', t: `Not a witness — ${why.join(' · ')}`, why } : { v: 'yes', t: 'Can witness controlled doses when on shift at the house', why };
}
export const STATUS_META: Record<EffStatus, [variant: 'success' | 'warning' | 'critical' | 'info' | 'neutral', label: string]> = {
    current: ['success', 'Current'], due: ['warning', 'Due for renewal'], expired: ['critical', 'Expired'], restricted: ['warning', 'Restricted'], failed: ['critical', 'Not passed'], none: ['neutral', 'Not assessed'], ack: ['info', 'Waiting for acknowledgement'], exempt: ['info', 'Exemption'],
};
export function eligLine(m: Model, x: StaffNow) {
    const s = effStatus(m, x);
    return {
        current: `Until ${x.until}`, due: `Ends ${x.until} · in ${x.days} days`, expired: `Ended ${x.until}`, restricted: `Until ${x.until} · ${x.restricted || 'restricted'}`,
        failed: `Assessed ${x.assessed} · ${AREAS.filter((a) => a.core && areaRes(x, a.k) === 'no').map((a) => a.l.toLowerCase()).join(', ') || 'below the pass mark'} not passed`,
        none: `Started ${x.started} · no assessment`, ack: `Assessed ${x.assessed} · waiting for ${firstName(x)}${x.prevValid ? ` · previous counts until ${x.prevValid}` : ''}`,
        exempt: `Until ${x.exempt?.until} · ${x.exempt ? HOUSES[x.exempt.house] : ''}`,
    }[s];
}
export const onShiftWitnesses = (m: Model, h: HouseKey) => STAFF.filter((x) => x.house === h && ONSHIFT_NOW.includes(x.id)).map((x) => staffNow(m, x));

/* ── The support worker’s own record for “My eligibility” (scenario switch in the preview bar) ── */
export function mySelf(m: Model): StaffNow {
    const base = staffNow(m, staffById('priya')!);
    switch (m.my) {
        case 'expired': return { ...base, st: 'expired', until: '14 Sep 2026', days: -15 };
        case 'restricted': return { ...base, st: 'restricted', restricted: 'Supervised practice until reassessed' };
        case 'due': return { ...base, until: '10 Oct 2026', days: 11 };
        case 'exempt': return { ...base, st: 'expired', until: '14 Sep 2026', days: -15, exempt: { id: 'xme', who: 'priya', house: 'kowhai', from: '29 Sep 2026', until: '3 Oct 2026', by: 'Hana Kereama', at: '29 Sep 2026 8:40 am', reason: 'Renewal booked for 3 October — the assessor is on leave until then', status: 'active' } };
        case 'ack': return m.myAck ? { ...base, assessed: '28 Sep 2026', until: '28 Sep 2027', days: 364, ack: '29 Sep 2026' } : { ...base, st: 'ack', assessed: '28 Sep 2026', until: '28 Sep 2027', days: 364, ack: null, prev: 'Previous assessment ended 14 Sep 2026' };
        case 'none': return { ...base, st: 'none', started: '21 Sep 2026', res: {}, assessed: undefined, witness: false };
        default: return base;
    }
}
