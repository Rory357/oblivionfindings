/* Medication › Settings — the Fleet Settings workspace pattern: one PageHeader page,
 * five rail views, Sections (TierTwoTabs) inside each view, Card rows with Switch,
 * drafts that survive switching tabs and views, a sticky save bar, “Review … changes”. */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { useEntityContextMenu } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader, PageHeaderFilterButton, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock,
    PageHeaderMeterCaption, PageHeaderMeterDonut, PageHeaderRail, PageHeaderSearch, PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyError, EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { ChipMulti, Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import {
    Activity, BellRing, Siren, Timer, Moon, Smartphone, ScrollText, UserX, AlarmClock, AlertTriangle, ArrowUpRight, Bell, Building2, CalendarDays, Camera, ClipboardCheck, Clock, Eye, FileText, HelpCircle, History, Home, Info, KeyRound, Layers,
    ListChecks, LockKeyhole, Mail, Pause, Pencil, Phone, Pill, Play, Plus, RefreshCw, Repeat, RotateCcw, Scale, Settings as SettingsIcon, Shield, ShieldCheck, Trash2, User, UserCheck, Users, WifiOff,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { ALERTS, ALERT_LOG, ALERT_PEOPLE, CDW_OPTS, EMPLOYED, HOUSES, HOUSE_KEYS, ONCALL_ROSTER, employee, phoneText, resolveOnCall, PIN_RULES, RECIPIENT_GROUPS, SAFETY_RULES, SAFETY_SWITCH, STAFF, type HouseKey, type Rule, type Tpl } from './data';
import {
    ATTENDED_OPTS, DELIVERY_L, has, EA_L, ELIG_L, GROUPS, alertById, PHOTO_LABEL, PHOTO_OPTS, TIMING_L, VIEW_LABEL, allChanges, allHistory, canEaPolicy, canHouse, canOrg, canRuleScope,
    canTemplates, daysText, decisionRegistry, fmtMin, fmtT, isDirty, leadCap, myHouses, readOnlyAudit, reviewedBy, ruleNeeds, ruleOverlaps,
    ruleWhat, useStore, validateView, viewChanges, type AlertSetting, type GroupKey, type Model, type ViewKey,
} from './model';
import { SET_VIEWS, settingsHref, eligHref, useNav } from './nav';
import { canRestore, reachRows, reviewItems } from './dialogs-v4';
import { Changed, Choice, DefaultNotReviewed, Flash, GroupGrid, GroupRow, KV, NotConfigured, Note, NumberInput, OnOff, Overview, RowMenu, SaveBar, Section, SettingGroup, type PickItem } from './ui';

const VIEW_ICON: Record<string, typeof Pill> = { rules: Pill, rounds: Repeat, staff: UserCheck, alerts: Bell, history: History };
const SEC_ICON: Record<string, typeof Pill> = { overview: Activity, alerts: Bell, delivery: BellRing, log: ScrollText, medicines: Pill, safety: Shield, controlled: LockKeyhole, photos: FileText, templates: Repeat, timing: Clock, competency: ClipboardCheck, exemptions: ShieldCheck, pins: KeyRound, status: Users, oncall: Bell, recipients: Users, emergency: LockKeyhole, decide: HelpCircle, changes: History };
const match = (q: string, ...s: (string | undefined)[]) => !q || s.some((x) => (x || '').toLowerCase().includes(q.toLowerCase()));
const DirtyDot = () => <span role="img" aria-label="Unsaved changes" className="size-2 rounded-full bg-status-warning" />;

type Filters = { logShow: string; logHouse: string; rulesWhere: string; rulesState: string; tplHouse: string; tplStatus: string; pinHouse: string; pinState: string; show: string; histArea: string; histWho: string; histWhere: string };
const F0: Filters = { logShow: 'all', logHouse: 'all', rulesWhere: 'all', rulesState: 'all', tplHouse: 'all', tplStatus: 'current', pinHouse: 'all', pinState: 'all', show: 'all', histArea: 'all', histWho: 'all', histWhere: 'all' };

export function SettingsPage() {
    const { m, set } = useStore();
    const { route, go, open } = useNav();
    const view = route.view as ViewKey, sec = route.sec, p = m.persona, demo = m.demo.settings;
    const [query, setQuery] = useState(route.q.get('q') || '');
    const [f, setF] = useState<Filters>({ ...F0, show: route.q.get('show') || 'all' });
    const [errs, setErrs] = useState<Record<string, string>>({});
    const [page, setPage] = useState(1);
    useEffect(() => { setQuery(route.q.get('q') || ''); setPage(1); }, [view, sec]); // Fleet: search is scoped to the view
    useEffect(() => { if (route.q.get('err') === '1') { const e = validateView(m, view); setErrs(e); } }, []); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => { const el = document.querySelector<HTMLElement>('main [aria-invalid="true"]'); if (el) el.focus(); }, [errs]);
    const reg = decisionRegistry(m);
    const nUnsaved = allChanges(m).length;

    const review = () => {
        const e = validateView(m, view);
        setErrs(e);
        if (Object.keys(e).length) {
            const k = Object.keys(e)[0];
            const target = view === 'staff' ? (['attempts', 'lockout', 'confirmLimit', 'renewal'].includes(k) ? 'pins' : k === 'longestEx' ? 'exemptions' : 'competency') : view === 'alerts' ? (k.startsWith('al-') ? 'alerts' : k.startsWith('dl-') ? 'delivery' : 'emergency') : view === 'rules' && sec === 'overview' ? 'safety' : view === 'rounds' && sec === 'overview' ? 'timing' : sec;
            if (target !== sec) go(settingsHref(view, target));
            return;
        }
        open({ kind: 'review', arg: view });
    };
    const clearErr = (k: string) => setErrs((e) => { if (!e[k]) return e; const n = { ...e }; delete n[k]; return n; });

    /* ── header ── */
    const meters = demo === 'loading' || demo === 'error'
        ? ['Still to decide', 'Medicine rules', 'Round templates', 'Witness PINs', 'On-call contacts'].map((l) => (
            <PageHeaderMeterBlock key={l} label={l} ariaLabel={`${l}: ${demo === 'loading' ? 'loading' : 'unavailable'}`} onClick={() => undefined}>
                <PageHeaderMeterBig>—</PageHeaderMeterBig><PageHeaderMeterCaption>{demo === 'loading' ? 'Loading…' : 'Unavailable'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>))
        : (() => {
            const act = m.rules.filter((r) => r.active).length, overl = m.rules.filter((r) => r.active && ruleOverlaps(m, r).length).length;
            const tpl = m.templates.filter((t) => myHouses(p).includes(t.house)), tAct = tpl.filter((t) => t.status === 'active').length;
            const n = (st: string) => m.pins.filter((x) => x.pin === st).length, total = m.pins.length;
            const oc = HOUSE_KEYS.filter((h) => m.oncall[h]).length;
            return (
                <>
                    <PageHeaderMeterBlock label="Still to decide" tone={reg.length ? 'warning' : 'success'} ariaLabel={`View ${reg.length} settings still to decide`} onClick={() => go(settingsHref('history', 'decide'))}>
                        <PageHeaderMeterBig>{reg.length}</PageHeaderMeterBig><PageHeaderMeterCaption>{reg.filter((r) => r.state === 'nc').length} not configured · the rest are defaults</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicine rules" ariaLabel={`View medicine rules, ${act} active`} onClick={() => go(settingsHref('rules', 'medicines'))}>
                        <PageHeaderMeterBig>{m.demo.rules === 'empty' ? 0 : act} active</PageHeaderMeterBig><PageHeaderMeterCaption>{m.demo.rules === 'empty' ? 'No rules yet' : `${m.rules.length - act} paused · ${overl} overlap`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Round templates" ariaLabel={`View round templates, ${tAct} active`} onClick={() => go(settingsHref('rounds', 'templates'))}>
                        <PageHeaderMeterBig>{m.demo.tpl === 'empty' ? 0 : tAct} active</PageHeaderMeterBig><PageHeaderMeterCaption>{m.demo.tpl === 'empty' ? 'None yet' : `${tpl.filter((t) => t.status === 'paused').length} paused · ${myHouses(p).length} houses`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Witness PINs" tone={n('locked') ? 'warning' : 'brand'} ariaLabel={`View witness PIN status, ${n('set')} of ${total} set`} onClick={() => go(settingsHref('staff', 'status'))}>
                        <PageHeaderMeterDonut percent={(n('set') / total) * 100} caption={<>{n('set')} of {total} set<br />{n('locked')} locked · {n('notset') + n('adminreset')} to set</>} />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="On-call contacts" tone={oc < HOUSE_KEYS.length ? 'warning' : 'success'} ariaLabel={`View on-call contacts, ${oc} of ${HOUSE_KEYS.length} set`} onClick={() => go(settingsHref('alerts', 'oncall'))}>
                        <PageHeaderMeterBig>{oc} of {HOUSE_KEYS.length}</PageHeaderMeterBig><PageHeaderMeterCaption>{oc === HOUSE_KEYS.length ? 'Every house has one' : `${HOUSE_KEYS.length - oc} not configured`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            );
        })();
    const accessText = readOnlyAudit(p) ? 'Read-only for audit' : canOrg(p) ? (canEaPolicy(p) ? 'All-sites authority · every setting' : 'All-sites authority · emergency access policy read-only') : `House settings for ${myHouses(p).map((h) => HOUSES[h].replace(' House', '')).join(' and ')} · organisation rules read-only`;
    const sel = (label: string, key: keyof Filters, options: [string, string][], allValue = 'all', icon?: typeof Home) => (
        <PageHeaderFilterSelect key={key} icon={icon} label={label} value={f[key]} allValue={allValue} options={options.map(([value, l]) => ({ value, label: l }))} onChange={(v) => { setF({ ...f, [key]: v }); setPage(1); }} />
    );
    const HOUSE_OPTS: [string, string][] = [['all', 'All houses'], ['kowhai', 'Kōwhai House'], ['rimu', 'Rimu House']];
    const filters = (
        <>
            <span className="mr-2 inline-flex items-center gap-1.5 text-[11px] text-primary-foreground/80">{readOnlyAudit(p) ? <Eye className="size-3" /> : <Shield className="size-3" />}{accessText}</span>
            {view === 'rules' && sec === 'medicines' ? <>{sel('Where', 'rulesWhere', [['all', 'All rules'], ['all-houses', 'All-houses rules'], ['kowhai', 'Applies at Kōwhai House'], ['rimu', 'Applies at Rimu House']], 'all', Home)}{sel('Status', 'rulesState', [['all', 'Any status'], ['active', 'Active'], ['paused', 'Paused']])}</>
                : view === 'rounds' && sec === 'templates' ? <>{sel('House', 'tplHouse', HOUSE_OPTS, 'all', Home)}{sel('Status', 'tplStatus', [['current', 'Active and paused'], ['active', 'Active'], ['paused', 'Paused'], ['retired', 'Retired'], ['all', 'All, including retired']], 'current')}</>
                    : view === 'staff' && sec === 'status' ? <>{sel('House', 'pinHouse', HOUSE_OPTS, 'all', Home)}{sel('PIN status', 'pinState', [['all', 'Any PIN status'], ['set', 'PIN set'], ['notset', 'No PIN set'], ['locked', 'Locked'], ['adminreset', 'Reset — must set a new one']])}</>
                        : view === 'history' && sec === 'changes' ? <>{sel('Area', 'histArea', [['all', 'All areas'], ['rules', 'Medication rules'], ['rounds', 'Rounds & timing'], ['staff', 'Staff & PINs'], ['alerts', 'Alerts & access']], 'all', Layers)}{sel('Changed by', 'histWho', [['all', 'Anyone'], ['Hana Kereama', 'Hana Kereama'], ['Jordan Tipene', 'Jordan Tipene'], ['Sione Taufa', 'Sione Taufa'], ['Rangi Parata', 'Rangi Parata'], ['Demo Admin', 'Demo Admin']])}{sel('Where', 'histWhere', [['all', 'Anywhere'], ['All houses', 'All houses'], ['Kōwhai House', 'Kōwhai House'], ['Rimu House', 'Rimu House']], 'all', Home)}</>
                            : view === 'alerts' && sec === 'log' ? <>{sel('House', 'logHouse', HOUSE_OPTS, 'all', Home)}{sel('All alerts sent', 'logShow', [['all', 'All alerts sent'], ['open', 'Not attended'], ['done', 'Attended or dealt with'], ['afterhours', 'After hours']])}</>
                            : view === 'alerts' && sec === 'alerts' ? sel('All alerts', 'show', [['all', 'All alerts'], ['open', 'Not yet reviewed'], ['changed', 'Unsaved changes'], ['email', 'Email on'], ['push', 'Push on']])
                                : ['safety', 'controlled', 'photos', 'timing', 'competency', 'exemptions', 'pins', 'emergency', 'delivery'].includes(sec) ? sel('All settings', 'show', [['all', 'All settings'], ['open', 'Not yet reviewed'], ['changed', 'Unsaved changes']]) : null}
            <PageHeaderFilterButton icon={demo === 'stale' ? AlarmClock : RefreshCw} onClick={() => { set((d) => { if (d.demo.settings === 'stale') d.demo.settings = 'loaded'; }); }} aria-label={demo === 'stale' ? 'Not updated since 8:40 am NZDT — refresh' : 'Updated 9:12 am NZDT — refresh'}>
                {demo === 'stale' ? 'Not updated since 8:40 am' : 'Updated 9:12 am'}
            </PageHeaderFilterButton>
            {nUnsaved ? <PageHeaderFilterButton active icon={Pencil} onClick={() => open({ kind: 'unsaved' })}>{nUnsaved} unsaved {nUnsaved === 1 ? 'change' : 'changes'}</PageHeaderFilterButton> : null}
        </>
    );
    const railItems = Object.entries(SET_VIEWS).map(([key, v]) => ({ key, label: v.label, icon: VIEW_ICON[key], ...(key === 'history' && reg.length ? { count: reg.length } : {}) }));
    const decorations = Object.fromEntries(Object.keys(SET_VIEWS).filter((v) => viewChanges(m, v as ViewKey).length).map((v) => [v, <DirtyDot key={v} />]));
    const header = (
        <PageHeader
            className="overflow-clip!"
            icon={SettingsIcon}
            title="Settings"
            titleChip={<PageHeaderStatusChip variant="neutral">Organisation</PageHeaderStatusChip>}
            subline="Medication rules and house settings · times in NZDT (Pacific/Auckland)"
            actions={<><PageHeaderSearch value={query} onChange={setQuery} placeholder={`Search ${(sec === 'overview' ? SET_VIEWS[view].label : SET_VIEWS[view].secs.find(([k]) => k === sec)![1]).replace(/^[A-Z](?![A-Z])/, (c) => c.toLowerCase())}`} /><PageHeaderGlassButton icon={History} onClick={() => go(settingsHref('history', 'changes'))}>Changes</PageHeaderGlassButton></>}
            meters={meters}
            filters={filters}
            rail={<PageHeaderRail items={railItems} value={view} onSelect={(k) => go(settingsHref(k))} ariaLabel="Settings views" decorations={decorations} />}
        />
    );

    /* ── body ── */
    const secChanges = (k: string) => viewChanges(m, view).filter((c) => GROUPS[c.g].sec(c.k) === k).length;
    const tabs = SET_VIEWS[view].secs.map(([key, label]) => ({ key, label, icon: SEC_ICON[key], ...(key === 'decide' && reg.length ? { count: reg.length } : {}), ...(secChanges(key) ? { warningCount: secChanges(key) } : {}) }));
    const sectionNav = <Sections tabs={tabs} value={sec} onChange={(k) => go(settingsHref(view, k))} />;
    let body: ReactNode;
    if (demo === 'loading') body = <div aria-busy="true" aria-label="Loading settings"><SkeletonTable rows={5} columns={3} /></div>;
    else if (demo === 'error') body = <EmptyError title="Couldn’t load the settings" description="The rules still apply when doses are saved — this page just couldn’t show them. Nothing you haven’t saved is lost." onRetry={() => set((d) => { d.demo.settings = 'loaded'; })} />;
    else {
        const ctx: Ctx = { f, setF, q: query, clearQ: () => setQuery(''), errs, clearErr, page, setPage };
        body = SECTION[`${view}/${sec}`]?.(ctx) ?? null;
    }
    const groups = (Object.keys(GROUPS) as GroupKey[]).filter((g) => GROUPS[g].view === view);
    const hasGroupHere = groups.some((g) => GROUPS[g].keys.some((k) => GROUPS[g].sec(k) === sec));
    const editable = groups.some((g) => GROUPS[g].can(p));
    const bar = demo !== 'loading' && demo !== 'error' && hasGroupHere ? (
        <SaveBar count={viewChanges(m, view).length} onDiscard={() => open({ kind: 'discard', arg: view })} onReview={review}
            readOnly={readOnlyAudit(p) ? 'Read-only — auditors can view settings and their history, not change them.' : !editable ? (view === 'alerts' ? 'Only admins and provider managers can change the emergency access policy (today’s rule). Ask Rangi Parata.' : 'Only someone who manages medication settings for all houses can change these — for example Hana Kereama, clinical lead.') : undefined} />
    ) : null;
    return (
        <div className="space-y-5">
            {header}
            {sectionNav}
            <Flash />
            {demo === 'stale' ? <Note><b>Not updated since 8:40 am NZDT (32 min ago).</b> Someone may have changed a setting since. Refresh before you change anything — your unsaved changes are kept. <Button variant="outline" size="sm" className="ml-2" onClick={() => set((d) => { d.demo.settings = 'loaded'; })}><RefreshCw />Refresh now</Button></Note> : null}
            {demo === 'offline' ? <Note><WifiOff className="mr-1 inline size-4" /><b>You’re offline.</b> You can read the settings, but changes can’t be saved until you reconnect. Settings are never saved on the device. Your unsaved changes stay on this page.</Note> : null}
            {body}
            {bar}
        </div>
    );
}

/* ── Section context and helpers ── */
type Ctx = { f: Filters; setF: (f: Filters) => void; q: string; clearQ: () => void; errs: Record<string, string>; clearErr: (k: string) => void; page: number; setPage: (n: number) => void };
function useEdit() {
    const { m, set } = useStore();
    const ro = (g: GroupKey) => !GROUPS[g].can(m.persona) || m.demo.settings === 'offline';
    const edit = (g: GroupKey, k: string, v: unknown) => set((d) => { (d.draft[g] as Record<string, unknown>)[k] = v; });
    /** Switch with a number under it: off clears the number; on restores the saved number or waits for one (never invents a value). */
    const toggleNumber = (g: GroupKey, k: string, on: boolean) => set((d) => {
        const saved = (d.saved[g] as Record<string, string>)[k];
        (d.draft[g] as Record<string, string>)[k] = on ? saved : '';
        if (on && !saved) d.pendingOn[`${g}.${k}`] = true; else delete d.pendingOn[`${g}.${k}`];
    });
    return { m, set, ro, edit, toggleNumber };
}
/** Show-filter + search visibility for a Card row. */
const visible = (ctx: Ctx, open: boolean, dirty: boolean, ...text: string[]) => (ctx.f.show === 'open' ? open : ctx.f.show === 'changed' ? dirty : true) && match(ctx.q, ...text);
function NoMatches({ ctx }: { ctx: Ctx }) {
    return <EmptyState icon={ListChecks} title={ctx.q ? `No settings in this tab match “${ctx.q}”` : 'No settings match this filter'} description="Try another tab, or clear the search and filter." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, show: 'all' }); }}>Clear search and filter</Button>} />;
}

/* ── Medication rules › Medicine rules (P00 v5 rules; house managers keep house rules — answer 1) ── */
function MedicineRules({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { open } = useNav();
    const p = m.persona, menu = useEntityContextMenu<Rule>();
    const canAdd = (canOrg(p) || myHouses(p).some((h) => canHouse(h, p))) && !readOnlyAudit(p);
    const rows = m.rules.filter((r) => (ctx.f.rulesWhere === 'all' || (ctx.f.rulesWhere === 'all-houses' ? r.scope === 'all' : r.scope === 'all' || r.scope === ctx.f.rulesWhere)) && (ctx.f.rulesState === 'all' || (ctx.f.rulesState === 'active') === r.active) && match(ctx.q, ruleWhat(r), ruleNeeds(r)));
    const can = (r: Rule) => canRuleScope(r.scope, p) && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    const actions = (r: Rule): MenuItem[] => compactMenu([
        can(r) ? { label: 'Edit rule', icon: Pencil, onClick: () => open({ kind: 'rule', arg: r.id }) } : { label: 'View rule', icon: Eye, onClick: () => open({ kind: 'ruleview', arg: r.id }) },
        can(r) && { label: r.active ? 'Pause rule' : 'Turn rule back on', icon: r.active ? Pause : Play, onClick: () => open({ kind: 'ruletoggle', arg: r.id }) },
        { separator: true },
        { label: 'View change history', icon: History, onClick: () => open({ kind: 'rulehistory', arg: r.id }) },
    ]);
    const right = canAdd && m.demo.rules === 'loaded' ? <Button size="sm" onClick={() => open({ kind: 'rule', arg: 'new' })} disabled={m.demo.settings === 'offline'}><Plus />Add a rule</Button> : null;
    return (
        <Section id="sc-med" title="Rules for specific medicines" caption={m.demo.rules === 'empty' ? 'None yet' : `${rows.length} of ${m.rules.length} shown`} right={right}>
            <p className="text-subtle">Extra checks before a dose is saved: a second person confirms with their witness PIN, and/or an observation is recorded.</p>
            {m.demo.rules === 'empty' ? (
                <EmptyState icon={Pill} title="No medicine rules yet" description="Add a rule when a medicine needs a second person or an observation before each dose." action={canAdd ? <Button size="sm" onClick={() => open({ kind: 'rule', arg: 'new' })}><Plus />Add a rule</Button> : undefined} />
            ) : rows.length ? (
                <EntityTable<Rule>
                    rows={rows} rowKey={(r) => r.id} identityLabel="Rule" identityWidth="2.2fr" minWidth={860}
                    identity={(r) => ({ icon: Pill, name: ruleWhat(r), subline: `${ruleNeeds(r)}${ruleOverlaps(m, r).length && r.active ? ` · overlaps ${ruleOverlaps(m, r).length} other ${ruleOverlaps(m, r).length === 1 ? 'rule' : 'rules'} — both apply` : ''}` })}
                    columns={[
                        { key: 'where', label: 'Where', width: '1fr', cell: (r) => <EntityChip icon={r.scope === 'all' ? Building2 : Home}>{HOUSES[r.scope]}</EntityChip> },
                        { key: 'active', label: 'Active', width: '1.1fr', cell: (r) => <div onClick={(e) => e.stopPropagation()}><OnOff id={`rs-${r.id}`} checked={r.active} disabled={!can(r)} label={`Rule active: ${ruleWhat(r)}`} onChange={() => open({ kind: 'ruletoggle', arg: r.id })} />{!r.active && r.paused ? <p className="text-caption mt-1">{r.paused}</p> : null}</div> },
                        { key: 'by', label: 'Last changed', width: '1fr', cell: (r) => <div><div className="text-[13px]">{r.by}</div><div className="text-caption">{r.when}</div></div> },
                    ]}
                    actionsFor={actions} onOpen={(r) => open({ kind: can(r) ? 'rule' : 'ruleview', arg: r.id })} onRowContextMenu={menu.open} mutedFor={(r) => !r.active}
                />
            ) : <EmptyState icon={Pill} title="No rules match these filters" description="Clear the filters or the search to see every rule." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, rulesWhere: 'all', rulesState: 'all' }); }}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={Pill} title={(r) => ruleWhat(r)} items={actions} />
            <Note>{readOnlyAudit(p) ? 'Read-only for audit.' : canOrg(p) ? 'Rules for all houses need all-sites authority; a house manager can add and change rules for their own houses.' : `You can add and change rules for ${myHouses(p).map((h) => HOUSES[h]).join(' and ')}. Rules for all houses need all-sites authority — ask Hana Kereama, clinical lead.`}</Note>
        </Section>
    );
}

/* ── Medication rules › Safety checks (P00 v5 options, grouped; switches for on/off) ── */
const rowState = (m: Model, g: GroupKey, k: string, nc = false): 'changed' | 'nc' | 'default' | null => (isDirty(m, g, k) ? 'changed' : nc ? 'nc' : reviewedBy(m, g, k) ? null : 'default');
function SafetyChecks({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, set } = useEdit();
    const d = m.draft.safety, dis = ro('safety'), R = (k: string) => SAFETY_RULES.find((r) => r.key === k)!;
    const onOff = (k: string, hint: string, extra?: ReactNode) => {
        const sw = SAFETY_SWITCH[k]!, on = d[k] !== sw.off;
        return (
            <GroupRow key={k} id={`sf-${k}`} label={R(k).label} hint={hint} state={rowState(m, 'safety', k)} hidden={!visible(ctx, !reviewedBy(m, 'safety', k), isDirty(m, 'safety', k), R(k).label)}
                control={<OnOff id={`sf-${k}`} checked={on} disabled={dis} onChange={(v) => set((dd) => { dd.draft.safety[k] = v ? (dd.saved.safety[k] !== sw.off ? dd.saved.safety[k] : sw.on[0][0]) : sw.off; })} />}>
                {on ? <><Choice value={d[k]} options={sw.on as [string, string][]} disabled={dis} onChange={(v) => edit('safety', k, v)} />{extra}</> : null}
            </GroupRow>
        );
    };
    const groups = [
        <SettingGroup key="allergy" id="allergy" icon={AlertTriangle} title="Allergies" caption="When a medicine matches an allergy on the person’s record" wide>
            <GroupRow id="sf-allergy" label="When there’s a match" hint="The match is always shown before signing." state={rowState(m, 'safety', 'profileAllergy')} hidden={!visible(ctx, false, isDirty(m, 'safety', 'profileAllergy'), R('profileAllergy').label)}>
                <Choice value={d.profileAllergy} disabled={dis} onChange={(v) => edit('safety', 'profileAllergy', v)} options={[['warn', 'Warn'], ['block', 'Block'], ['confirm', 'Block unless the prescriber confirmed']]} />
            </GroupRow>
        </SettingGroup>,
        <SettingGroup key="comp" id="competency" icon={UserCheck} title="Competency" caption="Checks on the person signing a dose as given">
            {onOff('restricted', 'Refused and withheld can always be recorded.')}
            {onOff('area', 'Controlled drugs and covert plans. Insulin isn’t checked yet.')}
        </SettingGroup>,
        <SettingGroup key="phone" id="phone" icon={Phone} title="Phone instructions" caption="A prescriber gives a different dose by phone">
            {onOff('phoneRx', 'For one dose only — it never changes the order.', <div className="space-y-1.5 pt-1"><p className="text-caption">A lead countersigns</p><Choice value={d.phoneRxBy} disabled={dis} onChange={(v) => edit('safety', 'phoneRxBy', v)} options={R('phoneRxBy').opts as [string, string][]} /></div>)}
        </SettingGroup>,
        <SettingGroup key="amount" id="amount" icon={Scale} title="Amount given" caption="Less than or more than the order">
            {onOff('amount', 'A colleague confirms with their witness PIN. Never blocks recording.')}
            <p className="text-caption px-4 py-3">More than ordered is always recorded as a medication error. It isn’t a setting.</p>
        </SettingGroup>,
    ];
    return (
        <Section id="sc-safety" title="Safety checks when a dose is signed" caption="Every house">
            <GroupGrid empty={<NoMatches ctx={ctx} />}>{groups}</GroupGrid>
        </Section>
    );
}

/* ── Medication rules › Controlled drugs (P00 v5 witness settings, grouped) ── */
function ControlledDrugs({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const { go } = useNav();
    const d = m.draft.cdw, dis = ro('cdw');
    const eff = (h: string) => (d[h] === 'org' ? d.org : d[h]) === 'on';
    const houseOpts: [string, string][] = [['org', 'Follow the organisation'], ['on', 'Always'], ['off', 'Not required']];
    return (
        <Section id="sc-cdw" title="Controlled drugs" caption="Witnessing and overrides">
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="witness" icon={Users} title="Witness" caption="A second person, on shift at the house, with a witness PIN" wide>
                    <GroupRow id="cw-org" label="Witness required for controlled drugs" hint="The default for every house. An order can still require a witness." state={rowState(m, 'cdw', 'org')} hidden={!visible(ctx, false, isDirty(m, 'cdw', 'org'), 'Witness required')}
                        control={<OnOff id="cw-org" checked={d.org === 'on'} disabled={dis} onChange={(v) => edit('cdw', 'org', v ? 'on' : 'off')} />} />
                    {(['kowhai', 'rimu'] as HouseKey[]).map((h) => (
                        <GroupRow key={h} id={`cw-${h}`} label={HOUSES[h]} hint={h === 'kowhai' ? '2 people with controlled medicines' : '1 person with controlled medicines'} state={rowState(m, 'cdw', h)} hidden={!visible(ctx, false, isDirty(m, 'cdw', h), HOUSES[h])}
                            control={<StatusBadge variant={eff(h) ? 'success' : 'warning'} size="sm">{eff(h) ? 'Witness required' : 'Not required'}</StatusBadge>}>
                            <Choice value={d[h]} options={houseOpts} disabled={dis} onChange={(v) => edit('cdw', h, v)} />
                        </GroupRow>
                    ))}
                </SettingGroup>
                <SettingGroup id="overrides" icon={Clock} title="Witness overrides" caption="Time-limited, for one house; each dose is followed up">
                    <GroupRow id="cw-longest" label="Longest override" hint="Overrides end by themselves." state={rowState(m, 'cdw', 'longest')} hidden={!visible(ctx, false, isDirty(m, 'cdw', 'longest'), 'Longest override')}>
                        <Choice value={d.longest} options={CDW_OPTS.longest.map(([v, l]) => [v, l.replace(' (default)', '')]) as [string, string][]} disabled={dis} onChange={(v) => edit('cdw', 'longest', v)} />
                    </GroupRow>
                    <GroupRow id="cw-open" label="Current overrides" hint="Grant, end or review overrides in Safety & oversight." control={<Button variant="link" onClick={() => go('#/safety/overrides')}>Open<ArrowUpRight className="size-4" /></Button>} />
                </SettingGroup>
                <SettingGroup id="headsup" icon={Bell} title="Single staffing" caption="Before a shift with one person at a house that holds controlled drugs">
                    <GroupRow id="cw-suggest" label="Heads-up for managers" hint="Offers to set up an override in advance. Never switches anything on." state={rowState(m, 'cdw', 'suggest')} hidden={!visible(ctx, false, isDirty(m, 'cdw', 'suggest'), 'Heads-up')}
                        control={<OnOff id="cw-suggest" checked={d.suggest === 'on'} disabled={dis} onChange={(v) => edit('cdw', 'suggest', v ? 'on' : 'off')} />} />
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}

/* ── Medication rules › Medicine photos ── */
function MedicinePhotos({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const d = m.draft.photos, dis = ro('photos');
    return (
        <Section id="sc-photos" title="Medicine photos" caption="Photos of the pack actually supplied">
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="photos" icon={Camera} title="Taking photos" caption="When stock is received" wide>
                    <GroupRow id="ph-who" label={PHOTO_LABEL.who} state={rowState(m, 'photos', 'who')} hidden={!visible(ctx, !reviewedBy(m, 'photos', 'who'), isDirty(m, 'photos', 'who'), PHOTO_LABEL.who)}>
                        <Choice value={d.who} options={PHOTO_OPTS.who} disabled={dis} onChange={(v) => edit('photos', 'who', v)} />
                    </GroupRow>
                    <GroupRow id="ph-prompt" label="Prompt for a photo at receipt" hint="When there’s no photo yet, or the brand or pack has changed. Never required." state={rowState(m, 'photos', 'prompt')} hidden={!visible(ctx, !reviewedBy(m, 'photos', 'prompt'), isDirty(m, 'photos', 'prompt'), PHOTO_LABEL.prompt)}
                        control={<OnOff id="ph-prompt" checked={d.prompt === 'prompt'} disabled={dis} onChange={(v) => edit('photos', 'prompt', v ? 'prompt' : 'off')} />} />
                </SettingGroup>
            </GroupGrid>
            <Note>Photos are stored privately, with controlled-medicine concealment. Every screen that shows one says “Check the label — the picture is a guide only”.</Note>
        </Section>
    );
}

/* ── Rounds & timing › Round templates (moved from Meds today › Rounds) ── */
function RoundTemplates({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { open } = useNav();
    const p = m.persona, menu = useEntityContextMenu<Tpl>();
    const canAny = myHouses(p).some((h) => canTemplates(h, p)) && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    const all = m.templates.filter((t) => myHouses(p).includes(t.house));
    const rows = m.demo.tpl === 'empty' ? [] : all.filter((t) => (ctx.f.tplHouse === 'all' || t.house === ctx.f.tplHouse) && (ctx.f.tplStatus === 'current' ? t.status !== 'retired' : ctx.f.tplStatus === 'all' || t.status === ctx.f.tplStatus) && match(ctx.q, t.name, HOUSES[t.house]));
    const can = (t: Tpl) => canTemplates(t.house, p) && t.status !== 'retired' && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    const actions = (t: Tpl): MenuItem[] => compactMenu([
        can(t) ? { label: 'Edit template', icon: Pencil, onClick: () => open({ kind: 'tpl', arg: t.id }) } : { label: 'View template', icon: Eye, onClick: () => open({ kind: 'tplview', arg: t.id }) },
        can(t) && { label: t.status === 'active' ? 'Pause — stop creating rounds' : 'Turn back on — create rounds again', icon: t.status === 'active' ? Pause : Play, onClick: () => open({ kind: 'tpltoggle', arg: t.id }) },
        can(t) && { separator: true },
        can(t) && { label: 'Retire template', icon: Trash2, danger: true, onClick: () => open({ kind: 'tplretire', arg: t.id }) },
    ]);
    const right = canAny ? <><Button variant="outline" size="sm" onClick={() => open({ kind: 'gen' })}><CalendarDays />Create rounds for a day</Button><Button size="sm" onClick={() => open({ kind: 'tpl', arg: 'new' })}><Plus />Add a template</Button></> : null;
    return (
        <Section id="sc-tpl" title="Round templates" caption={m.demo.tpl === 'empty' ? 'None yet' : `${rows.length} of ${all.length} shown`} right={right}>
            <p className="text-subtle">When each medication round happens at a house. Rounds are created from active templates at 12:05 am every day; today’s rounds are in Meds today › Rounds.</p>
            {m.demo.tpl === 'empty' ? <EmptyState icon={Repeat} title="No round templates yet" description="Add one for each time doses are usually given at this house. Until then, doses still show on Meds today — rounds just aren’t created." action={canAny ? <Button size="sm" onClick={() => open({ kind: 'tpl', arg: 'new' })}><Plus />Add a template</Button> : undefined} />
                : rows.length ? (
                    <EntityTable<Tpl>
                        rows={rows} rowKey={(t) => t.id} identityLabel="Round" identityWidth="1.8fr" minWidth={900} mutedFor={(t) => t.status === 'retired'}
                        identity={(t) => ({ icon: Repeat, name: t.name, subline: `${fmtT(t.time)}, ${t.win} minutes either side · ${daysText(t.days)}` })}
                        columns={[
                            { key: 'house', label: 'House', width: '1fr', cell: (t) => <EntityChip icon={Home}>{HOUSES[t.house]}</EntityChip> },
                            { key: 'who', label: 'Staff', width: '1.3fr', cell: (t) => (t.who ? <div><div className="text-[13px]">{t.who}</div><div className="text-caption">Default — can change on the day</div></div> : <span className="text-[13px]">Everyone rostered on a covering shift</span>) },
                            { key: 'today', label: 'Today', width: '0.9fr', cell: (t) => (t.status === 'active' ? <span className="text-[13px]">{t.doses} doses · {t.people} {t.people === 1 ? 'person' : 'people'}</span> : <span className="text-caption">No round</span>) },
                            { key: 'active', label: 'Active', width: '1fr', cell: (t) => (t.status === 'retired' ? <StatusBadge variant="neutral" size="sm">Retired {t.when}</StatusBadge> : <div onClick={(e) => e.stopPropagation()}><OnOff id={`ts-${t.id}`} checked={t.status === 'active'} disabled={!can(t)} label={`Create rounds from ${t.name}, ${HOUSES[t.house]}`} onChange={() => open({ kind: 'tpltoggle', arg: t.id })} /></div>) },
                        ]}
                        actionsFor={actions} onOpen={(t) => open({ kind: can(t) ? 'tpl' : 'tplview', arg: t.id })} onRowContextMenu={menu.open}
                    />
                ) : <EmptyState icon={Repeat} title="No templates match these filters" description="Clear the filters or the search to see every template." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, tplHouse: 'all', tplStatus: 'current' }); }}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={Repeat} title={(t) => `${t.name} — ${HOUSES[t.house]}`} items={actions} />
            {canAny ? null : <Note>Only people who manage orders at a house can change its round templates{readOnlyAudit(p) ? '' : ' — for example the house lead'}.</Note>}
        </Section>
    );
}

/* ── Rounds & timing › Dose timing (Stephan: keep today’s values until the clinical lead reviews them) ── */
function DoseTiming({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber, set } = useEdit();
    const { open } = useNav();
    const d = m.draft.timing, dis = ro('timing');
    const num = (k: 'early' | 'late' | 'soon' | 'lateIncident', label: string, hint: string) => (
        <GroupRow key={k} id={`tm-${k}`} label={label} hint={hint} state={rowState(m, 'timing', k)} error={ctx.errs[k]} errorId={`tm-${k}-error`} hidden={!visible(ctx, !reviewedBy(m, 'timing', k), isDirty(m, 'timing', k), label, hint)}
            control={<NumberInput id={`tm-${k}`} value={d[k]} unit={TIMING_L[k][1].replace(' the dose time', '')} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('timing', k, v); ctx.clearErr(k); }} />} />
    );
    const reOn = !!d.reoffer || !!m.pendingOn['timing.reoffer'];
    return (
        <Section id="sc-timing" title="Dose timing" caption="Every house · recording is never blocked by these times">
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="due" icon={Clock} title="When a dose is due" caption="What Meds today shows for a scheduled dose">
                    {num('early', 'Can be given from', 'Before this, the dose shows as not yet due.')}
                    {num('soon', 'Shows as due soon', 'Only changes what Meds today highlights.')}
                    {num('late', 'Counts as late', 'Overdue alerts go out after this.')}
                </SettingGroup>
                <SettingGroup id="late" icon={AlarmClock} title="Late doses" caption="When a late dose becomes an incident">
                    {num('lateIncident', 'Raises an incident after', 'Serious after 4 hours.')}
                </SettingGroup>
                <SettingGroup id="refusals" icon={RotateCcw} title="After a refusal" caption="Re-offers and repeated refusals">
                    <GroupRow id="tm-reon" label="Remind staff to offer again" hint="Re-offers can always be recorded." state={isDirty(m, 'timing', 'reoffer') ? 'changed' : m.saved.timing.reoffer ? null : 'nc'} error={ctx.errs.reoffer} errorId="tm-reoffer-error" hidden={!visible(ctx, !m.saved.timing.reoffer, isDirty(m, 'timing', 'reoffer'), TIMING_L.reoffer[0])}
                        control={<OnOff id="tm-reon" checked={reOn} disabled={dis} onChange={(v) => { toggleNumber('timing', 'reoffer', v); ctx.clearErr('reoffer'); }} />}>
                        {reOn ? <NumberInput id="tm-reoffer" label="Minutes after the refusal" value={d.reoffer} unit="minutes after the refusal" disabled={dis} error={ctx.errs.reoffer} onChange={(v) => { edit('timing', 'reoffer', v); ctx.clearErr('reoffer'); }} /> : null}
                    </GroupRow>
                    <GroupRow id="tm-escalN" label="Escalate repeated refusals" hint="Refusals or withholds of the same medicine go to a manager and the GP." state={isDirty(m, 'timing', 'escalN') || isDirty(m, 'timing', 'escalDays') ? 'changed' : reviewedBy(m, 'timing', 'escalN') ? null : 'default'} error={ctx.errs.escalN || ctx.errs.escalDays} hidden={!visible(ctx, !reviewedBy(m, 'timing', 'escalN'), isDirty(m, 'timing', 'escalN'), 'Escalate repeated refusals')}>
                        <span className="inline-flex flex-wrap items-center gap-2"><NumberInput id="tm-escalN" label="Number of refusals" value={d.escalN} unit="within" disabled={dis} error={ctx.errs.escalN} onChange={(v) => { edit('timing', 'escalN', v); ctx.clearErr('escalN'); }} /><NumberInput id="tm-escalDays" label="Number of days" value={d.escalDays} unit="days" disabled={dis} error={ctx.errs.escalDays} onChange={(v) => { edit('timing', 'escalDays', v); ctx.clearErr('escalDays'); }} /></span>
                    </GroupRow>
                </SettingGroup>
                <SettingGroup id="critical" icon={Pill} title="Time-critical medicines" caption="Their own, shorter late time">
                    <GroupRow id="tm-critical" label={d.critical.length ? `${d.critical.length} marked` : 'None marked'} hint="Until one is marked, every medicine uses the late time." state={isDirty(m, 'timing', 'critical') ? 'changed' : m.saved.timing.critical.length ? null : 'nc'} hidden={!visible(ctx, !m.saved.timing.critical.length, isDirty(m, 'timing', 'critical'), 'Time-critical medicines')}
                        control={dis ? undefined : <Button variant="outline" size="sm" onClick={() => open({ kind: 'crit' })}><Plus />Mark a medicine</Button>}>
                        {d.critical.length ? (
                            <ul className="divide-y divide-border rounded-lg border">
                                {d.critical.map((c, i) => (
                                    <li key={c.med} className="flex items-center justify-between gap-3 p-2.5 text-[13px]"><span><b>{c.med}</b> · late after {c.min} minutes</span>
                                        {dis ? null : <Button variant="outline" size="sm" aria-label={`Remove ${c.med}`} onClick={() => set((dd) => { dd.draft.timing.critical.splice(i, 1); })}>Remove</Button>}</li>
                                ))}
                            </ul>) : null}
                    </GroupRow>
                </SettingGroup>
            </GroupGrid>
            <p className="text-caption">A round template has its own window (5–120 minutes) for grouping doses into a round. These times decide when a dose is due and late.</p>
        </Section>
    );
}

/* ── Staff & PINs › Competency ── */
function Competency({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber } = useEdit();
    const { go } = useNav();
    const d = m.draft.elig, dis = ro('elig');
    const num = (k: string, label: string, hint: string, max?: number) => (
        <GroupRow key={k} id={`el-${k}`} label={label} hint={hint} state={rowState(m, 'elig', k)} error={ctx.errs[k]} errorId={`el-${k}-error`} hidden={!visible(ctx, !reviewedBy(m, 'elig', k), isDirty(m, 'elig', k), label, hint)}
            control={<NumberInput id={`el-${k}`} value={d[k]} unit={ELIG_L[k][1]} max={max} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('elig', k, v); ctx.clearErr(k); }} />} />
    );
    const obsOn = !!d.obsNeeded || !!m.pendingOn['elig.obsNeeded'];
    return (
        <Section id="sc-comp" title="Medication competency" caption="Used by the assessment form and the register" right={<Button variant="link" onClick={() => go(eligHref())}>Open Staff eligibility<ArrowUpRight className="size-4" /></Button>}>
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="assessment" icon={ClipboardCheck} title="Assessment" caption="How long it lasts and what passes">
                    {num('validity', 'Stays current for', 'The assessor can choose an earlier end date.')}
                    {num('passMark', 'Pass mark', 'An area not assessed counts as not passed.', 12)}
                    <GroupRow id="el-core" label="Every core area must pass" hint="Knowledge, the five rights, safety checks, documentation, errors, allergies." state={rowState(m, 'elig', 'coreMust')} hidden={!visible(ctx, !reviewedBy(m, 'elig', 'coreMust'), isDirty(m, 'elig', 'coreMust'), ELIG_L.coreMust[0])}
                        control={<OnOff id="el-core" checked={d.coreMust === 'yes'} disabled={dis} onChange={(v) => edit('elig', 'coreMust', v ? 'yes' : 'no')} />} />
                </SettingGroup>
                <SettingGroup id="evidence" icon={CalendarDays} title="Evidence and renewal" caption="What the assessor logs, and when renewal is due">
                    <GroupRow id="el-obs" label="Minimum observed administrations" hint="Off until the organisation chooses a number." state={isDirty(m, 'elig', 'obsNeeded') ? 'changed' : m.saved.elig.obsNeeded ? null : 'nc'} error={ctx.errs.obsNeeded} errorId="el-obsNeeded-error" hidden={!visible(ctx, !m.saved.elig.obsNeeded, isDirty(m, 'elig', 'obsNeeded'), ELIG_L.obsNeeded[0])}
                        control={<OnOff id="el-obs" checked={obsOn} disabled={dis} onChange={(v) => { toggleNumber('elig', 'obsNeeded', v); ctx.clearErr('obsNeeded'); }} />}>
                        {obsOn ? <NumberInput id="el-obsNeeded" label="Minimum observed administrations" value={d.obsNeeded} unit="observed administrations" disabled={dis} error={ctx.errs.obsNeeded} onChange={(v) => { edit('elig', 'obsNeeded', v); ctx.clearErr('obsNeeded'); }} /> : null}
                    </GroupRow>
                    {num('reminder', 'Renewal reminder', 'Starts “Due for renewal”, the rostering warning and the reminder alert.')}
                </SettingGroup>
                <ReviewCard icon={Info} title="How competency is used" span>
                    <KV rows={[
                        ['Restricted competency', `${SAFETY_RULES[1].opts.find((o) => o[0] === m.saved.safety.restricted)![1]} (Safety checks)`],
                        ['Controlled-drug and covert areas', `${SAFETY_RULES[2].opts.find((o) => o[0] === m.saved.safety.area)![1]} (Safety checks)`],
                        ['Witnessing controlled drugs', 'A current assessment with “can witness”, which needs the controlled drugs area passed; not while restricted; a witness PIN; on shift at the house'],
                        ['Rostering', 'No current assessment blocks medication shifts; ending soon is a warning the rosterer can override'],
                        ['Acknowledgement', 'Only from the worker’s own login (Meds today › My eligibility)'],
                    ]} />
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}
/* ── Staff & PINs › Exemption limit ── */
function Exemptions({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const { go } = useNav();
    const d = m.draft.elig, dis = ro('elig');
    return (
        <Section id="sc-ex" title="Exemption limit" caption="Applies to every exemption" right={<Button variant="link" onClick={() => go(eligHref('exemptions'))}>Open exemptions<ArrowUpRight className="size-4" /></Button>}>
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="limit" icon={ShieldCheck} title="Limit" caption="Every exemption needs an end date within this">
                    <GroupRow id="el-longestEx" label="Longest exemption" hint="For the clinical lead to confirm." state={rowState(m, 'elig', 'longestEx')} error={ctx.errs.longestEx} errorId="el-longestEx-error" hidden={!visible(ctx, !reviewedBy(m, 'elig', 'longestEx'), isDirty(m, 'elig', 'longestEx'), ELIG_L.longestEx[0])}
                        control={<NumberInput id="el-longestEx" value={d.longestEx} unit="days" disabled={dis} error={ctx.errs.longestEx} onChange={(v) => { edit('elig', 'longestEx', v); ctx.clearErr('longestEx'); }} />} />
                </SettingGroup>
                <ReviewCard icon={Info} title="How exemptions work">
                    <KV rows={[
                        ['What it does', 'Lets someone record doses as given at one house without a current assessment, until a fixed end date'],
                        ['Who can grant', 'Clinical leads and provider managers, for someone else'],
                        ['Rules that still apply', 'The restricted and area checks'],
                        ['Witnessing', 'Never — a witness needs a current assessment'],
                    ]} />
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › Witness PINs (P00 v5 rules with Stephan’s values, grouped) ── */
function WitnessPins({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber, set } = useEdit();
    const d = m.draft.pin, dis = ro('pin'), R = (k: string) => PIN_RULES.find((r) => r.key === k)!;
    const num = (k: string, label: string, hint: string) => (
        <GroupRow key={k} id={`pr-${k}`} label={label} hint={hint} state={rowState(m, 'pin', k)} error={ctx.errs[k]} errorId={`pr-${k}-error`} hidden={!visible(ctx, false, isDirty(m, 'pin', k), label, R(k).label)}
            control={<NumberInput id={`pr-${k}`} value={d[k]} unit={R(k).unit!} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('pin', k, v); ctx.clearErr(k); }} />} />
    );
    const sw = (k: string, label: string, hint: string) => (
        <GroupRow key={k} id={`pr-${k}`} label={label} hint={hint} state={rowState(m, 'pin', k)} hidden={!visible(ctx, false, isDirty(m, 'pin', k), label, R(k).label)}
            control={<OnOff id={`pr-${k}`} checked={d[k] === 'yes'} disabled={dis} onChange={(v) => edit('pin', k, v ? 'yes' : 'no')} />} />
    );
    const renOn = !!d.renewal || !!m.pendingOn['pin.renewal'];
    const reset = { lead: ['lead', 'both'].includes(d.resetRoles), clinical: ['clinical', 'both'].includes(d.resetRoles) };
    const setReset = (k: 'lead' | 'clinical', v: boolean) => set((dd) => { const r = { ...reset, [k]: v }; dd.draft.pin.resetRoles = r.lead && r.clinical ? 'both' : r.lead ? 'lead' : r.clinical ? 'clinical' : 'nc'; });
    return (
        <Section id="sc-pins" title="Second-person confirmation" caption="Co-signing, witnessing controlled drugs, and confirming a different amount">
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="method" icon={KeyRound} title="Witness PIN" caption="Each person sets their own in their account">
                    <GroupRow id="pr-method" label="Method" hint="The colleague’s login password is no longer used for this." control={<StatusBadge variant="neutral"><LockKeyhole className="size-3" />Personal 6-digit PIN</StatusBadge>} />
                    <GroupRow id="pr-renon" label={R('renewal').label} hint={renOn ? `People choose a new PIN every ${d.renewal || '…'} months.` : 'No renewal — a PIN stays until its owner changes it.'} state={rowState(m, 'pin', 'renewal')} error={ctx.errs.renewal} errorId="pr-renewal-error" hidden={!visible(ctx, false, isDirty(m, 'pin', 'renewal'), R('renewal').label)}
                        control={<OnOff id="pr-renon" checked={renOn} disabled={dis} onChange={(v) => { toggleNumber('pin', 'renewal', v); ctx.clearErr('renewal'); }} />}>
                        {renOn ? <NumberInput id="pr-renewal" label="Months between renewals" value={d.renewal} unit="months" disabled={dis} error={ctx.errs.renewal} onChange={(v) => { edit('pin', 'renewal', v); ctx.clearErr('renewal'); }} /> : null}
                    </GroupRow>
                </SettingGroup>
                <SettingGroup id="locking" icon={LockKeyhole} title="Locking" caption="After wrong PINs, across all screens">
                    {num('attempts', 'Locks after', 'Counts wrong PINs typed for one person.')}
                    {num('lockout', 'Stays locked for', 'Or until the owner resets it.')}
                </SettingGroup>
                <SettingGroup id="forgotten" icon={HelpCircle} title="Forgotten PIN" caption="Naming a colleague who can’t remember theirs">
                    {sw('fallback', 'Allowed', 'The dose is marked “second person not verified”; they confirm from their own login.')}
                    {sw('fallbackCd', 'Allowed for controlled drugs', 'A controlled-drug witness must be physically present.')}
                </SettingGroup>
                <SettingGroup id="reset" icon={RefreshCw} title="Who can reset a PIN" caption="A reset never shows or sets the PIN">
                    {(['lead', 'clinical'] as const).map((k) => (
                        <GroupRow key={k} id={`pr-reset-${k}`} label={k === 'lead' ? 'House leads' : 'Clinical leads'} hint={k === 'lead' ? 'For their own houses.' : 'For every house they can access.'} state={isDirty(m, 'pin', 'resetRoles') ? 'changed' : null} hidden={!visible(ctx, false, isDirty(m, 'pin', 'resetRoles'), R('resetRoles').label)}
                            control={<OnOff id={`pr-reset-${k}`} checked={reset[k]} disabled={dis} onChange={(v) => setReset(k, v)} />} />
                    ))}
                </SettingGroup>
                <SettingGroup id="confirm" icon={UserCheck} title="Named colleague confirms" caption="When someone is named instead of typing a PIN" wide>
                    {num('confirmLimit', 'Time to confirm', 'After this, the house lead gets a follow-up.')}
                    <GroupRow id="pr-routeTo" label={R('routeTo').label} hint="When the colleague says “I wasn’t there” or doesn’t answer." state={rowState(m, 'pin', 'routeTo')} hidden={!visible(ctx, false, isDirty(m, 'pin', 'routeTo'), R('routeTo').label)}>
                        <Choice value={d.routeTo} disabled={dis} onChange={(v) => edit('pin', 'routeTo', v)} options={[['lead-on-shift', 'House lead on shift'], ['lead-house', 'House lead for the house']]} />
                    </GroupRow>
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}

/* ── Staff & PINs › PIN status (P00 staff PIN list; house leads reset for their houses — answer 20) ── */
type PinRow = Model['pins'][number];
export const pinBadge = (pin: string, changed?: string) => pin === 'set' ? <StatusBadge variant="success" size="sm">PIN set{changed ? ` · ${changed}` : ''}</StatusBadge> : pin === 'notset' ? <StatusBadge variant="warning" size="sm">No PIN set</StatusBadge> : pin === 'locked' ? <StatusBadge variant="critical" size="sm">Locked</StatusBadge> : <StatusBadge variant="warning" size="sm">Reset — must set a new one</StatusBadge>;
export const canResetPin = (m: Model, house: string) => {
    const r = m.saved.pin.resetRoles, p = m.persona;
    return ((r === 'lead' || r === 'both') && p === 'lead' && myHouses(p).some((h) => HOUSES[h] === house)) || ((r === 'clinical' || r === 'both') && (p === 'clinical' || p === 'pm'));
};
function PinStatus({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { go, open } = useNav();
    const menu = useEntityContextMenu<PinRow>();
    const rows = m.pins.filter((x) => (ctx.f.pinHouse === 'all' || x.house === HOUSES[ctx.f.pinHouse as HouseKey]) && (ctx.f.pinState === 'all' || x.pin === ctx.f.pinState) && match(ctx.q, x.name, x.role, x.house));
    const staffFor = (x: PinRow) => STAFF.find((s) => s.name === x.name);
    const actions = (x: PinRow): MenuItem[] => compactMenu([
        leadCap(m.persona) && staffFor(x) && { label: 'Open in Staff eligibility', icon: UserCheck, onClick: () => go(eligHref('witness', `open=av:${staffFor(x)!.id}`)) },
        x.pin !== 'notset' && canResetPin(m, x.house) && { label: 'Reset witness PIN', icon: RefreshCw, danger: true, onClick: () => open({ kind: 'pinreset', arg: x.name }) },
    ]);
    return (
        <Section id="sc-status" title="Staff witness PINs" caption={`${rows.length} of ${m.pins.length} shown`}>
            <p className="text-subtle">Status only — nobody can see or set another person’s PIN.</p>
            {rows.length ? (
                <EntityTable<PinRow>
                    rows={rows} rowKey={(x) => x.name} identityLabel="Person" identityWidth="1.4fr" minWidth={760}
                    identity={(x) => ({ mark: <PersonDisc name={x.name} size={30} />, name: x.name, subline: x.role })}
                    columns={[
                        { key: 'house', label: 'House', width: '1fr', cell: (x) => <EntityChip icon={Home}>{x.house}</EntityChip> },
                        { key: 'pin', label: 'Witness PIN', width: '1.8fr', cell: (x) => <div>{pinBadge(x.pin, x.changed)}{x.pin === 'locked' ? <p className="text-caption mt-1">Locked at 8:55 am after {m.saved.pin.attempts} wrong attempts · unlocks after {m.saved.pin.lockout} minutes</p> : x.pin === 'notset' ? <p className="text-caption mt-1">Can’t be chosen to co-sign or witness</p> : null}</div> },
                    ]}
                    actionsFor={actions} onOpen={(x) => { const s = staffFor(x); if (s && leadCap(m.persona)) go(eligHref('witness', `open=av:${s.id}`)); }} onRowContextMenu={menu.open}
                />
            ) : <EmptyState icon={Users} title="Nobody matches" description="Clear the filters or the search." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, pinHouse: 'all', pinState: 'all' }); }}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={KeyRound} title={(x) => x.name} items={actions} />
            <Note>House leads (for their own houses) and clinical leads can reset a PIN. The owner then chooses a new one in their account settings before they can co-sign or witness again.</Note>
        </Section>
    );
}

/* ── Alerts & access › On-call contacts: one card per house. The contact follows the roster (Stephan, 29 Sep 2026). ── */
function OnCallContacts({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { open } = useNav();
    const p = m.persona;
    const houses = HOUSE_KEYS.filter((h) => myHouses(p).includes(h) && match(ctx.q, HOUSES[h], employee(m.oncall[h]?.person ?? '')?.name));
    const can = (h: HouseKey) => canHouse(h, p) && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    return (
        <Section id="sc-oncall" title="On-call contact" caption="One per house · follows the roster">
            <p className="text-subtle">Shown on “Can’t clock in?”, escalations and follow-ups, and can get alerts. After hours it’s whoever is on an on-call shift, then the team lead on shift, then a backup person. Until a house sets one, screens say “On-call contact: Not configured”.</p>
            {houses.length ? (
                <div className="grid gap-5 lg:grid-cols-2">
                    {houses.map((h) => {
                        const c = m.oncall[h], b = c ? employee(c.person) : undefined, n = ONCALL_ROSTER[h][0], now = c ? resolveOnCall(c, n) : null;
                        return (
                            <Card key={h} className="gap-3 p-4" data-setting={`oncall-${h}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div><p className="text-sm font-semibold">{HOUSES[h]}</p><p className="text-caption">House lead: {h === 'kowhai' ? 'Jordan Tipene' : 'Sione Taufa'}</p></div>
                                    {c ? <StatusBadge variant="success" size="sm">{c.mode === 'roster' ? 'Follows the roster' : 'Set'}</StatusBadge> : <NotConfigured />}
                                </div>
                                {c && now ? (
                                    <>
                                        <div className="flex items-center gap-3 rounded-lg border p-3 text-[13px]">
                                            <Phone className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                            <div><p className="text-caption">{n.day} · {n.hours}</p><p className="font-semibold">{now.who ? `${now.who.name} · ${phoneText(now.who)}` : now.warn}</p><p className="text-caption">{now.how}</p></div>
                                        </div>
                                        <KV rows={[
                                            ['How it’s decided', c.mode === 'roster' ? `The roster${c.teamLead ? ', then the team lead on shift' : ''}` : 'Always the same person'],
                                            [c.mode === 'roster' ? 'Backup' : 'Person', b ? `${b.name} · ${phoneText(b)}` : '—'],
                                            ['Last changed', `${c.by}, ${c.when}`],
                                        ]} />
                                        {ONCALL_ROSTER[h].some((x) => resolveOnCall(c, x).warn) ? <InfoCard icon={AlertTriangle} tone="warn">{ONCALL_ROSTER[h].filter((x) => resolveOnCall(c, x).warn).map((x) => `${x.day}: ${resolveOnCall(c, x).warn}`).join('; ')}. Roster an on-call shift, or change the backup.</InfoCard> : null}
                                    </>
                                ) : <p className="text-subtle">Screens at this house give no number.{ONCALL_ROSTER[h].some((x) => x.onCall) ? ' Rostering has on-call shifts here — set up a contact to use them.' : ''}</p>}
                                {can(h) ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant={c ? 'outline' : 'default'} size="sm" onClick={() => open({ kind: 'oncall', arg: h })}>{c ? <><Pencil />Change contact</> : <><Plus />Set up contact</>}</Button>
                                        {c ? <Button variant="outline" size="sm" onClick={() => open({ kind: 'oncallremove', arg: h })}><Trash2 />Remove</Button> : null}
                                    </div>
                                ) : <p className="text-caption">{readOnlyAudit(p) ? 'Read-only for audit.' : `Only someone who manages settings for ${HOUSES[h]} can change it.`}</p>}
                            </Card>
                        );
                    })}
                </div>
            ) : <NoMatches ctx={ctx} />}
        </Section>
    );
}

export const peopleItems = (taken: string[]): PickItem[] => ALERT_PEOPLE.map((x) => ({ id: x.name, name: x.name, sub: `${x.role} · ${x.houses}`, ok: !taken.includes(x.name), why: 'already added' }));

/* ── Alerts & access › Alerts (Fleet Notifications: one table, In-app and Email switch columns).
 * Stephan, 29 Sep 2026: the organisation sets each alert’s channels and who gets it; people can add email
 * copies for themselves; email starts off as a default not yet reviewed. Clicking a row edits who gets it. ── */
type AlertRowT = (typeof ALERTS)[number];
const goesTo = (x: AlertSetting, extras: number) => {
    const names = [...x.groups.map((g) => RECIPIENT_GROUPS[g].l), ...x.people];
    return `${names.slice(0, 2).join(', ')}${names.length > 2 ? ` +${names.length - 2}` : ''}${extras ? ` · ${extras} house ${extras === 1 ? 'extra' : 'extras'}` : ''}` || 'Nobody';
};
function Alerts({ ctx }: { ctx: Ctx }) {
    const { m, set } = useEdit();
    const { open, go } = useNav();
    const p = m.persona, menu = useEntityContextMenu<AlertRowT>();
    const orgRo = !canOrg(p) || readOnlyAudit(p) || m.demo.settings === 'offline';
    const extrasFor = (k: string) => HOUSE_KEYS.reduce((n, h) => n + (m.draft.alertExtra[`${h}.${k}`]?.length ?? 0), 0);
    const rows = ALERTS.filter((a) => {
        const x = m.draft.alerts[a.k], dirty = isDirty(m, 'alerts', a.k) || HOUSE_KEYS.some((h) => isDirty(m, 'alertExtra', `${h}.${a.k}`));
        const show = ctx.f.show === 'open' ? !reviewedBy(m, 'alerts', a.k) : ctx.f.show === 'changed' ? dirty : ctx.f.show === 'email' ? x.email : ctx.f.show === 'push' ? x.push : true;
        return show && match(ctx.q, a.l, a.sub);
    });
    const setCh = (k: string, ch: 'inapp' | 'email' | 'push', v: boolean) => { set((d) => { d.draft.alerts[k][ch] = v; }); ctx.clearErr(`al-${k}`); };
    const actions = (a: AlertRowT): MenuItem[] => compactMenu([
        { label: orgRo && !HOUSE_KEYS.some((h) => canHouse(h, p)) ? 'View who gets it' : 'Edit who gets it', icon: Users, onClick: () => open({ kind: 'alertwho', arg: a.k }) },
        !orgRo && { label: 'Add a person', icon: Plus, onClick: () => open({ kind: 'alertperson', arg: a.k, step: 'org' }) },
    ]);
    const errs = Object.entries(ctx.errs).filter(([k]) => k.startsWith('al-'));
    return (
        <Section id="sc-alerts" title="Medication alerts" caption={`${rows.length} of ${ALERTS.length} alert types shown`} right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            {errs.length ? <div role="alert" className="space-y-1">{errs.map(([k, v]) => <p key={k} className="flex items-center gap-1 text-xs text-status-critical"><AlertTriangle className="size-3" />{v}</p>)}</div> : null}
            {rows.length ? (
                <EntityTable<AlertRowT>
                    rows={rows} rowKey={(a) => a.k} identityLabel="Alert" identityWidth="1.9fr" minWidth={1000} rowHeight="content"
                    identity={(a) => ({ icon: Bell, name: a.l, subline: a.sub })}
                    columns={[
                        { key: 'inapp', label: 'In-app', width: '0.6fr', cell: (a) => { const locked = !!a.locked; return <div onClick={(e) => e.stopPropagation()} data-setting={`al-${a.k}`}><OnOff id={`al-${a.k}-inapp`} checked={m.draft.alerts[a.k].inapp} disabled={orgRo || locked} label={`${a.l}: in-app`} onChange={(v) => setCh(a.k, 'inapp', v)} />{locked ? <p className="text-caption mt-1 flex items-center gap-1"><LockKeyhole className="size-3" />Always on</p> : null}</div>; } },
                        { key: 'email', label: 'Email', width: '0.6fr', cell: (a) => <div onClick={(e) => e.stopPropagation()}><OnOff id={`al-${a.k}-email`} checked={m.draft.alerts[a.k].email} disabled={orgRo} label={`${a.l}: email`} onChange={(v) => setCh(a.k, 'email', v)} /></div> },
                        { key: 'push', label: 'Push', width: '0.6fr', cell: (a) => <div onClick={(e) => e.stopPropagation()}><OnOff id={`al-${a.k}-push`} checked={m.draft.alerts[a.k].push} disabled={orgRo} label={`${a.l}: push`} onChange={(v) => setCh(a.k, 'push', v)} /></div> },
                        { key: 'fu', label: 'Follow up', width: '0.7fr', cell: (a) => <div onClick={(e) => e.stopPropagation()}><OnOff id={`al-${a.k}-fu`} checked={m.draft.alerts[a.k].followUp} disabled={orgRo} label={`${a.l}: follow up until attended`} onChange={(v) => set((d) => { d.draft.alerts[a.k].followUp = v; })} /></div> },
                        { key: 'to', label: 'Goes to', width: '1.3fr', cell: (a) => <span className="py-2 text-[12.5px]">{goesTo(m.draft.alerts[a.k], extrasFor(a.k))}</span> },
                        { key: 'state', label: 'Status', width: '1.3fr', cell: (a) => (isDirty(m, 'alerts', a.k) || HOUSE_KEYS.some((h) => isDirty(m, 'alertExtra', `${h}.${a.k}`)) ? <Changed /> : reviewedBy(m, 'alerts', a.k) ? <StatusBadge variant="neutral" size="sm">Reviewed</StatusBadge> : <DefaultNotReviewed />) },
                    ]}
                    actionsFor={actions} onOpen={(a) => open({ kind: 'alertwho', arg: a.k })} onRowContextMenu={menu.open}
                />
            ) : <NoMatches ctx={ctx} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={Bell} title={(a) => a.l} items={actions} />
            <div className="text-subtle flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <span className="inline-flex items-center gap-2"><Shield className="size-3.5" />Alerts about controlled medicines only reach people with controlled-medicine access. Control Room shows these alerts in its queue; who is told is set here.</span>
                <Button variant="ghost" size="sm" onClick={() => go(settingsHref('alerts', 'delivery'))}>Review delivery & follow-up<ArrowUpRight className="size-3.5" /></Button>
            </div>
        </Section>
    );
}
/* ── Alerts & access › Delivery (Fleet “Delivery & channels”, made interactive). Stephan, 29 Sep 2026: “why is it not
 * interactive like … re-alert, attended”. Verified on main: medication alerts are in-app only, each is sent once
 * (no re-alert), `emar:send-alerts` runs every 15 minutes, and dashboard alerts record who acknowledged them.
 * So re-alerting and escalation are new and start off (today’s behaviour), marked “not yet reviewed”. ── */
const FOLLOW_TO = ['houseLead', 'onCall', 'clinicalLead', 'providerManager'];
const glabel = (g: string) => RECIPIENT_GROUPS[g].l;
function Delivery({ ctx }: { ctx: Ctx }) {
    const { m, set, ro, edit, toggleNumber } = useEdit();
    const { go } = useNav();
    const d = m.draft.delivery, dis = ro('delivery');
    const inapp = ALERTS.filter((a) => m.draft.alerts[a.k].inapp).length, email = ALERTS.filter((a) => m.draft.alerts[a.k].email).length;
    const push = ALERTS.filter((a) => m.draft.alerts[a.k].push).length, quietOn = d.quietFrom !== '' || d.quietUntil !== '' || !!m.pendingOn['delivery.quietFrom'];
    const fu = ALERTS.filter((a) => m.draft.alerts[a.k].followUp), toOnCall = ALERTS.filter((a) => m.draft.alerts[a.k].groups.includes('onCall'));
    const reOn = d.realertEvery !== '' || !!m.pendingOn['delivery.realertEvery'], escOn = d.escalateAfter !== '' || !!m.pendingOn['delivery.escalateAfter'];
    const oc = HOUSE_KEYS.filter((h) => m.oncall[h]).length;
    const st = (k: string) => rowState(m, 'delivery', k);
    const vis = (k: string, ...t: string[]) => visible(ctx, !reviewedBy(m, 'delivery', k), isDirty(m, 'delivery', k), DELIVERY_L[k], ...t);
    const link = (label: string, ...t: string[]) => visible(ctx, false, false, label, ...t);
    const sw = (k: string, hint: string) => (
        <GroupRow key={k} id={`dl-${k}`} label={DELIVERY_L[k]} hint={hint} state={st(k)} hidden={!vis(k)}
            control={<OnOff id={`dl-${k}`} checked={(d as unknown as Record<string, string>)[k] === 'yes'} disabled={dis} onChange={(v) => edit('delivery', k, v ? 'yes' : 'no')} />} />
    );
    const toAlerts = <Button variant="link" onClick={() => go(settingsHref('alerts', 'alerts'))}>Alerts<ArrowUpRight className="size-4" /></Button>;
    return (
        <>
        <Section id="sc-delivery" title="Delivery & follow-up" caption="How alerts reach people, and what happens if nobody attends" right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            {fu.length && !reOn && !escOn ? <InfoCard icon={Info}><b>Follow up is on for {fu.length} alert types, but re-alerting and escalation are both off.</b> Each alert is still sent once, as today.</InfoCard> : null}
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="realert" icon={BellRing} title="Re-alert until attended" caption={`For the ${fu.length} alert ${fu.length === 1 ? 'type' : 'types'} with Follow up on`}>
                    <GroupRow id="dl-reon" label={DELIVERY_L.realertEvery} hint={reOn ? `Sent again to everyone told so far every ${d.realertEvery || '…'} minutes, up to ${d.realertMax || '…'} times, until someone attends.` : 'Off — each alert is sent once (today).'} state={st('realertEvery')} error={ctx.errs['dl-realertEvery'] || ctx.errs['dl-realertMax']} errorId="dl-realert-error" hidden={!vis('realertEvery', 're-alert', 'repeat')}
                        control={<OnOff id="dl-reon" checked={reOn} disabled={dis} onChange={(v) => { toggleNumber('delivery', 'realertEvery', v); toggleNumber('delivery', 'realertMax', v); ctx.clearErr('dl-realertEvery'); ctx.clearErr('dl-realertMax'); }} />}>
                        {reOn ? (
                            <span className="inline-flex flex-wrap items-center gap-2">
                                <span className="text-subtle">Every</span>
                                <NumberInput id="dl-realertEvery" label="Minutes between re-alerts" value={d.realertEvery} unit="minutes, up to" min={15} max={1440} disabled={dis} error={ctx.errs['dl-realertEvery']} onChange={(v) => { edit('delivery', 'realertEvery', v); ctx.clearErr('dl-realertEvery'); }} />
                                <NumberInput id="dl-realertMax" label="Most re-alerts" value={d.realertMax} unit="times" min={1} max={10} disabled={dis} error={ctx.errs['dl-realertMax']} onChange={(v) => { edit('delivery', 'realertMax', v); ctx.clearErr('dl-realertMax'); }} />
                            </span>
                        ) : null}
                    </GroupRow>
                    <GroupRow id="dl-attended" label={DELIVERY_L.attended} hint="Stops re-alerts and escalation. Recorded with the person’s name and the time." state={st('attended')} hidden={!vis('attended', 'acknowledge')}>
                        <Choice value={d.attended} disabled={dis} onChange={(v) => edit('delivery', 'attended', v)} options={ATTENDED_OPTS} />
                    </GroupRow>
                    <GroupRow id="dl-fu" label="Alerts followed up" hint={fu.length ? fu.map((a) => a.l).join(', ') : 'None — turn on Follow up in the Alerts table.'} hidden={!link('Alerts followed up', 'follow up')} control={toAlerts} />
                </SettingGroup>
                <SettingGroup id="escalate" icon={Siren} title="Escalate if still not attended" caption="Tells more people, as well as the first ones">
                    <GroupRow id="dl-escon" label={DELIVERY_L.escalateAfter} hint={escOn ? `After ${d.escalateAfter || '…'} minutes with nobody attending.` : 'Off — nobody else is told (today).'} state={st('escalateAfter')} error={ctx.errs['dl-escalateAfter']} errorId="dl-escalateAfter-error" hidden={!vis('escalateAfter', 'escalate')}
                        control={<OnOff id="dl-escon" checked={escOn} disabled={dis} onChange={(v) => { toggleNumber('delivery', 'escalateAfter', v); if (!v) set((dd) => { dd.draft.delivery.escalateTo = [...dd.saved.delivery.escalateTo]; }); ctx.clearErr('dl-escalateAfter'); ctx.clearErr('dl-escalateTo'); }} />}>
                        {escOn ? <NumberInput id="dl-escalateAfter" label="Minutes before escalating" value={d.escalateAfter} unit="minutes" min={15} max={1440} disabled={dis} error={ctx.errs['dl-escalateAfter']} onChange={(v) => { edit('delivery', 'escalateAfter', v); ctx.clearErr('dl-escalateAfter'); }} /> : null}
                    </GroupRow>
                    {escOn ? (
                        <GroupRow id="dl-escalateTo" label={DELIVERY_L.escalateTo} hint="After hours, the on-call person is whoever the roster says." state={st('escalateTo')} error={ctx.errs['dl-escalateTo']} errorId="dl-escalateTo-error" hidden={!vis('escalateTo', 'escalate')}>
                            {dis ? <p className="text-[13px]">{d.escalateTo.map(glabel).join(', ') || 'Nobody chosen'}</p>
                                : <ChipMulti values={d.escalateTo.map(glabel)} options={FOLLOW_TO.map(glabel)} onChange={(v) => { edit('delivery', 'escalateTo', FOLLOW_TO.filter((g) => v.includes(glabel(g)))); ctx.clearErr('dl-escalateTo'); }} />}
                        </GroupRow>
                    ) : null}
                </SettingGroup>
                <SettingGroup id="email" icon={Mail} title="Email" caption="Sent to each person’s work email">
                    <GroupRow id="dl-emailn" label="Alert types sent by email" hint="Turn email on per alert in the Alerts table." hidden={!link('Alert types sent by email', 'email')} control={<span className="inline-flex items-center gap-3"><span className="text-subtle">{email} of {ALERTS.length}</span>{toAlerts}</span>} />
                    {sw('digest', d.digest === 'yes' ? 'One email an hour lists every alert since the last one.' : 'Each alert is emailed straight away.')}
                    {sw('private', 'Emails and push notifications, including on lock screens, say what happened and link to the app. The details stay in the app.')}
                    {sw('copies', 'In their account › Notifications. They can’t switch off what the organisation requires.')}
                </SettingGroup>
                <SettingGroup id="push" icon={Smartphone} title="Push" caption="To the phone app and browsers people have allowed">
                    <GroupRow id="dl-pushn" label="Alert types sent by push" hint="Turn push on per alert in the Alerts table." hidden={!link('Alert types sent by push', 'push')} control={<span className="inline-flex items-center gap-3"><span className="text-subtle">{push} of {ALERTS.length}</span>{toAlerts}</span>} />
                    <GroupRow id="dl-pushready" label="Staff with push set up" hint="People turn push on for their own phone or browser, in their account › Notifications. Gaps are listed under Who can’t be reached." hidden={!link('Staff with push set up', 'push')} control={<span className="text-subtle">{EMPLOYED.filter((e) => e.push).length} of {EMPLOYED.length}</span>} />
                </SettingGroup>
                <SettingGroup id="quiet" icon={Moon} title="Quiet hours" caption="Alerts without Follow up can wait until morning">
                    <GroupRow id="dl-quieton" label={DELIVERY_L.quietFrom} hint={quietOn ? `Email and push for alerts without Follow up wait until ${d.quietUntil ? fmtT(d.quietUntil) : '…'}. They still show in the bell straight away. Alerts with Follow up are never held.` : 'Off — every alert is sent straight away (today).'} state={st('quietFrom')} error={ctx.errs['dl-quiet']} errorId="dl-quiet-error" hidden={!vis('quietFrom', 'quiet', 'overnight', 'night')}
                        control={<OnOff id="dl-quieton" checked={quietOn} disabled={dis} onChange={(v) => { toggleNumber('delivery', 'quietFrom', v); set((dd) => { dd.draft.delivery.quietUntil = v ? dd.saved.delivery.quietUntil : ''; }); ctx.clearErr('dl-quiet'); }} />}>
                        {quietOn ? (dis ? <p className="text-[13px]">{d.quietFrom && d.quietUntil ? `${fmtT(d.quietFrom)} to ${fmtT(d.quietUntil)}` : 'Times not chosen yet'}</p> : (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="From" hint="Pacific/Auckland" htmlFor="dl-quietFrom"><TimePicker id="dl-quietFrom" label="Quiet hours start" value={d.quietFrom} invalid={!!ctx.errs['dl-quiet'] && !d.quietFrom} onChange={(v) => { edit('delivery', 'quietFrom', v); ctx.clearErr('dl-quiet'); }} /></Field>
                                <Field label="Until" hint="Pacific/Auckland" htmlFor="dl-quietUntil"><TimePicker id="dl-quietUntil" label="Quiet hours end" value={d.quietUntil} invalid={!!ctx.errs['dl-quiet'] && !d.quietUntil} onChange={(v) => { edit('delivery', 'quietUntil', v); ctx.clearErr('dl-quiet'); }} /></Field>
                            </div>
                        )) : null}
                    </GroupRow>
                </SettingGroup>
                <SettingGroup id="inapp" icon={Bell} title="In-app" caption="The bell (notifications)">
                    <GroupRow id="dl-inappn" label="Alert types sent in-app" hint="Decided alerts are always in-app. Medication errors and controlled-drug loss reports also appear as tasks in All Tasks." hidden={!link('Alert types sent in-app', 'in-app')} control={<span className="inline-flex items-center gap-3"><span className="text-subtle">{inapp} of {ALERTS.length}</span>{toAlerts}</span>} />
                    {sw('pinned', 'Alerts with Follow up on stay at the top of the bell until someone attends.')}
                </SettingGroup>
                <SettingGroup id="afterhours" icon={Phone} title="After hours" caption="Nobody is phoned automatically">
                    <GroupRow id="dl-oncall" label="On-call contacts" hint="Follows the roster: on-call shift, then the team lead on shift, then a backup person." hidden={!link('On-call contacts', 'after hours')} control={<Button variant="link" onClick={() => go(settingsHref('alerts', 'oncall'))}>{oc} of {HOUSE_KEYS.length} houses<ArrowUpRight className="size-4" /></Button>} />
                    <GroupRow id="dl-oncallgets" label="Alerts that go to the on-call person" hint={toOnCall.length ? toOnCall.map((a) => a.l).join(', ') : d.escalateTo.includes('onCall') && escOn ? 'Only through escalation.' : 'None yet — add them in an alert’s groups, or escalate to them.'} hidden={!link('Alerts that go to the on-call person', 'on-call')} control={toAlerts} />
                </SettingGroup>
                <FollowUpPreview />
            </GroupGrid>
        </Section>
        <WhoCantBeReached />
        </>
    );
}
/** What happens if nobody attends — worked out from the draft, like Fleet’s “Check saved choices”. Nothing is sent. */
function FollowUpPreview() {
    const { m } = useStore();
    const [k, setK] = useState('overdue');
    const a = alertById(k), x = m.draft.alerts[k], d = m.draft.delivery;
    const every = Number(d.realertEvery) || 0, max = Number(d.realertMax) || 0, after = Number(d.escalateAfter) || 0;
    const via = [x.inapp && 'in-app', x.email && 'email', x.push && 'push'].filter(Boolean).join(', ').replace(/, ([^,]*)$/, ' and $1') || 'no channel';
    const quiet = /^\d{2}:\d{2}$/.test(d.quietFrom) && /^\d{2}:\d{2}$/.test(d.quietUntil);
    const first = [...x.groups.map(glabel), ...x.people].join(', ') || 'Nobody';
    // One row per moment: a re-alert and an escalation at the same time are one step. After an escalation,
    // re-alerts go to everyone told so far — the first people and the escalated groups.
    const esc = x.followUp && after && d.escalateTo.length ? d.escalateTo.map(glabel).join(', ') : '';
    const reAt = x.followUp && every && max ? Array.from({ length: max }, (_, i) => every * (i + 1)) : [];
    const times = [...new Set([...reAt, ...(esc ? [after] : [])])].sort((p, q) => p - q);
    const ev: [number, string, string][] = times.map((t) => {
        const re = reAt.includes(t), up = !!esc && t === after, since = !!esc && t > after;
        if (re && up) return [t, 'Re-alert and escalate', `The same people again, plus ${esc} · ${via}`];
        if (up) return [t, 'Escalate', `${esc} · ${via}`];
        return [t, 'Re-alert', since ? `Everyone told so far, including ${esc} · ${via}` : `The same people · ${via}`];
    });
    const shown = ev.slice(0, 5);
    const stop = ATTENDED_OPTS.find((o) => o[0] === d.attended)![1].toLowerCase();
    return (
        <ReviewCard icon={Timer} title="What happens if nobody attends" span>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-subtle">Worked out from your draft, for one alert. Nothing is sent.</p>
                <div className="w-72"><SelectInput value={k} onChange={setK} placeholder="Choose an alert" ariaLabel="Alert to preview" options={ALERTS.map((y) => ({ value: y.k, label: y.l }))} /></div>
            </div>
            <ReviewRow label="When it happens" value={<span className="text-right"><b>{first}</b><span className="text-caption block">{via === 'no channel' ? 'Nobody is told — turn on in-app or email' : `By ${via}`}</span></span>} />
            {shown.map(([t, what, who]) => <ReviewRow key={`${t}-${what}`} label={`After ${fmtMin(String(t))}`} value={<span className="text-right"><b>{what}</b><span className="text-caption block">{who}</span></span>} />)}
            {ev.length > shown.length ? <ReviewRow label="Then" value={`${ev.length - shown.length} more re-alerts, up to ${max} in all`} /> : null}
            {quiet && !x.followUp && (x.email || x.push) ? <ReviewRow label="Overnight" value={`Between ${fmtT(d.quietFrom)} and ${fmtT(d.quietUntil)}, email and push wait — the bell shows it straight away`} /> : null}
            <ReviewRow label="Stops" value={!x.followUp ? `Follow up is off for “${a.l}” — it’s sent once.` : !ev.length ? 'Sent once — re-alerting and escalation are off.' : `When ${stop}. The alert itself stays ${a.until.toLowerCase()}.`} />
        </ReviewCard>
    );
}

/* ── Alerts & access › Emergency access policy (admins and provider managers) ── */
function EmergencyAccess({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const d = m.draft.ea, dis = ro('ea'), open = !m.eaSaved;
    const num = (k: string, label: string, hint: string) => (
        <GroupRow key={k} id={`ea-${k}`} label={label} hint={`${hint}${d[k] && Number(d[k]) >= 60 ? ` (${fmtMin(d[k])})` : ''}`} state={isDirty(m, 'ea', k) ? 'changed' : open ? 'default' : null} error={ctx.errs[k]} errorId={`ea-${k}-error`} hidden={!visible(ctx, open, isDirty(m, 'ea', k), label, EA_L[k])}
            control={<NumberInput id={`ea-${k}`} value={d[k]} unit="minutes" min={5} max={1440} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('ea', k, v); ctx.clearErr(k); }} />} />
    );
    return (
        <Section id="sc-ea" title="Emergency access" caption="Changed by admins and provider managers" right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            <p className="text-subtle">Lets someone record for one person they aren’t rostered for, for a short time. A grant covers one person — never a whole house or round — and ends by itself.</p>
            <GroupGrid empty={<NoMatches ctx={ctx} />}>
                <SettingGroup id="length" icon={Clock} title="Grant length" caption="No grant or extension goes past the longest grant">
                    {num('def', 'A grant lasts', 'Unless the person chooses a shorter time.')}
                    {num('max', 'Longest grant', 'The limit for grants and extensions.')}
                    {num('ext', 'Each extension adds', 'Never past the longest grant.')}
                </SettingGroup>
                <SettingGroup id="review" icon={Eye} title="Reasons and repeat use" caption="What reviewers see">
                    <GroupRow id="ea-reason" label={EA_L.reason} hint="Shown to reviewers and in the audit trail." state={isDirty(m, 'ea', 'reason') ? 'changed' : open ? 'default' : null} hidden={!visible(ctx, open, isDirty(m, 'ea', 'reason'), EA_L.reason)}
                        control={<OnOff id="ea-reason" checked={d.reason === 'yes'} disabled={dis} onChange={(v) => edit('ea', 'reason', v ? 'yes' : 'no')} />} />
                    <GroupRow id="ea-repeatN" label="Flag repeat use" hint="When one person uses emergency access this often." state={isDirty(m, 'ea', 'repeatN') || isDirty(m, 'ea', 'repeatDays') ? 'changed' : open ? 'default' : null} error={ctx.errs.repeatN || ctx.errs.repeatDays} hidden={!visible(ctx, open, isDirty(m, 'ea', 'repeatN'), 'Flag repeat use')}>
                        <span className="inline-flex flex-wrap items-center gap-2"><NumberInput id="ea-repeatN" label="Number of grants" value={d.repeatN} unit="grants within" max={100} disabled={dis} error={ctx.errs.repeatN} onChange={(v) => { edit('ea', 'repeatN', v); ctx.clearErr('repeatN'); }} /><NumberInput id="ea-repeatDays" label="Number of days" value={d.repeatDays} unit="days" max={90} disabled={dis} error={ctx.errs.repeatDays} onChange={(v) => { edit('ea', 'repeatDays', v); ctx.clearErr('repeatDays'); }} /></span>
                    </GroupRow>
                </SettingGroup>
                <ReviewCard icon={Info} title="Planned — not built yet" span>
                    <KV rows={[
                        ['Durations offered', 'Only up to the longest grant'],
                        ['A second person confirms', 'A setting: optional or required'],
                        ['Review due within', 'A setting, with overdue reviews followed up'],
                        ['Reviewer', 'Someone other than the person who used it'],
                    ]} />
                </ReviewCard>
            </GroupGrid>
        </Section>
    );
}

/* ── Change history › Still to decide ── */
type PendingRow = ReturnType<typeof decisionRegistry>[number];
function StillToDecide({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { go } = useNav();
    const menu = useEntityContextMenu<PendingRow>();
    const reg = decisionRegistry(m).filter((r) => match(ctx.q, r.label, r.scope, r.until));
    const { open } = useNav();
    const canKeep = (r: PendingRow) => r.state === 'default' && !!r.g && GROUPS[r.g].can(m.persona) && !readOnlyAudit(m.persona) && m.demo.settings !== 'offline';
    const actions = (r: PendingRow): MenuItem[] => compactMenu([{ label: 'Go to the setting', icon: ArrowUpRight, onClick: () => go(settingsHref(r.view, r.sec)) }, canKeep(r) && { label: 'Keep today’s value', icon: ShieldCheck, onClick: () => open({ kind: 'keepdefault', arg: `${r.g}|${r.k}` }) }]);
    return (
        <Section id="sc-decide" title="Still to decide" caption={`${reg.length} settings nobody has deliberately chosen`} right={reviewItems(m).some((i) => i.keepable) ? <Button size="sm" onClick={() => open({ kind: 'reviewdefaults' })}><ShieldCheck />Review the defaults one by one</Button> : undefined}>
            <p className="text-subtle">“Not configured” means screens give no value. “Default — not yet reviewed” means today’s behaviour carries on until someone saves a choice — or confirms it with “Keep today’s value” in the ⋯ menu.</p>
            {reg.length ? (
                <EntityTable<PendingRow>
                    rows={reg} rowKey={(r) => `${r.view}-${r.label}`} identityLabel="Setting" identityWidth="3fr" minWidth={1000} rowHeight="content"
                    identity={(r) => ({ icon: HelpCircle, name: r.label, subline: r.scope })}
                    columns={[
                        { key: 'where', label: 'Where', width: '1.2fr', cell: (r) => <span className="py-2 text-[12.5px]">{VIEW_LABEL[r.view]} › {SET_VIEWS[r.view].secs.find(([k]) => k === r.sec)![1]}</span> },
                        { key: 'state', label: 'State', width: '1fr', cell: (r) => (r.state === 'nc' ? <NotConfigured /> : <DefaultNotReviewed />) },
                        { key: 'until', label: 'Until it’s decided', width: '1.5fr', cell: (r) => <span className="py-2 text-[12.5px]">{r.until}</span> },
                    ]}
                    actionsFor={actions} onOpen={(r) => go(settingsHref(r.view, r.sec))} onRowContextMenu={menu.open}
                />
            ) : <EmptyState icon={Check2} title="Nothing left to decide" description="Every setting has been deliberately chosen. Changes still show in All changes." />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={HelpCircle} title={(r) => r.label} items={actions} />
        </Section>
    );
}
const Check2 = ShieldCheck;

/* ── Change history › All changes (Fleet ChangeHistory: EntityTable + Previous/Next) ── */
type HistRow = ReturnType<typeof allHistory>[number];
function AllChanges({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { go, open } = useNav();
    const menu = useEntityContextMenu<HistRow>();
    const all = allHistory(m);
    const rows = all.filter((h) => (ctx.f.histArea === 'all' || h.area === ctx.f.histArea) && (ctx.f.histWho === 'all' || h.who === ctx.f.histWho) && (ctx.f.histWhere === 'all' || h.scope === ctx.f.histWhere) && match(ctx.q, h.what, h.to, h.who));
    const per = 8, pages = Math.max(1, Math.ceil(rows.length / per)), pg = Math.min(ctx.page, pages), slice = rows.slice((pg - 1) * per, pg * per);
    const actions = (h: HistRow): MenuItem[] => compactMenu([{ label: 'View before and after', icon: Eye, onClick: () => open({ kind: 'hist', arg: h.id }) }, { label: 'Go to the setting', icon: ArrowUpRight, onClick: () => go(settingsHref(h.area, h.sec)) }, canRestore(m, h) && { label: 'Put the earlier value back', icon: RotateCcw, onClick: () => open({ kind: 'restore', arg: h.id }) }]);
    if (!all.length) return <Section id="sc-changes" title="All changes" caption="Every saved setting, newest first · also in the audit log"><EmptyState icon={History} title="No saved changes yet" description="Saved settings appear here with who changed them and when. Operational records — doses, rounds, assessments — keep their own histories." /></Section>;
    return (
        <Section id="sc-changes" title="All changes" caption={`${rows.length} of ${all.length} shown · newest first · also in the audit log`}>
            {rows.length ? (
                <>
                    <EntityTable<HistRow>
                        rows={slice} rowKey={(h) => h.id} identityLabel="What changed" identityWidth="2fr" minWidth={960} rowHeight="content"
                        identity={(h) => ({ icon: History, name: h.what, subline: `${h.from && h.from !== '—' ? `${h.from} → ` : ''}${h.to}`, extra: h.fresh ? <StatusBadge variant="info" size="sm">Just now</StatusBadge> : undefined })}
                        columns={[
                            { key: 'when', label: 'When (NZDT)', width: '1fr', cell: (h) => <span className="text-[13px]">{h.when}</span> },
                            { key: 'who', label: 'Who', width: '1fr', cell: (h) => <div><div className="text-[13px]">{h.who}</div>{h.note ? <div className="text-caption">{h.note}</div> : null}</div> },
                            { key: 'where', label: 'Where', width: '0.9fr', cell: (h) => <EntityChip icon={h.scope === 'All houses' ? Building2 : Home}>{h.scope}</EntityChip> },
                            { key: 'area', label: 'Area', width: '0.9fr', cell: (h) => <span className="text-[13px]">{VIEW_LABEL[h.area]}</span> },
                        ]}
                        actionsFor={actions} onOpen={(h) => open({ kind: 'hist', arg: h.id })} onRowContextMenu={menu.open}
                    />
                    <div className="flex items-center justify-end gap-3">
                        <span className="text-caption">Page {pg} of {pages}</span>
                        <Button variant="outline" disabled={pg <= 1} onClick={() => ctx.setPage(pg - 1)}>Previous</Button>
                        <Button variant="outline" disabled={pg >= pages} onClick={() => ctx.setPage(pg + 1)}>Next</Button>
                    </div>
                </>
            ) : <EmptyState icon={History} title="No changes match these filters" description="Clear the filters or the search." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, histArea: 'all', histWho: 'all', histWhere: 'all' }); }}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={History} title={(h) => h.what} items={actions} />
        </Section>
    );
}


/* ── Alerts & access › Alert log: every alert, who was told, and who attended (Stephan, 29 Sep 2026) ── */
type LogRow = (typeof ALERT_LOG)[number];
const LOG_STATUS = (r: LogRow, hide: boolean) => (r.status === 'open' ? <StatusBadge variant="warning" size="sm">Not attended · {r.waited}</StatusBadge> : r.status === 'attended' ? <StatusBadge variant="info" size="sm">Attended · {hide ? '' : `${r.by}, `}{r.at}</StatusBadge> : <StatusBadge variant="success" size="sm">Dealt with · {r.at}</StatusBadge>);
function AlertLog({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { open } = useNav();
    const menu = useEntityContextMenu<LogRow>();
    // Controlled-medicine alerts keep their details from anyone without controlled-medicine access (EM-12).
    const hide = (r: LogRow) => !!r.cd && !has(m.persona, 'cd.view');
    const mine = ALERT_LOG.filter((r) => myHouses(m.persona).includes(r.house)), nHidden = mine.filter(hide).length;
    const rows = mine.filter((r) => (ctx.f.logHouse === 'all' || r.house === ctx.f.logHouse) && (ctx.f.logShow === 'all' || (ctx.f.logShow === 'open' ? r.status === 'open' : ctx.f.logShow === 'afterhours' ? !!r.afterHours : r.status !== 'open')) && (hide(r) ? match(ctx.q, 'Controlled-medicine alert') : match(ctx.q, alertById(r.k).l, r.about, ...r.to))).sort((a, b) => b.sort - a.sort);
    const actions = (r: LogRow): MenuItem[] => compactMenu([{ label: 'View what happened', icon: Eye, onClick: () => open({ kind: 'alertlog', arg: r.id }) }, !hide(r) && { label: 'Who gets this alert', icon: Users, onClick: () => open({ kind: 'alertwho', arg: r.k }) }]);
    const openN = mine.filter((r) => r.status === 'open').length;
    return (
        <Section id="sc-log" title="Alert log" caption={`${rows.length} of ${mine.length} shown · last 3 days · synthetic examples${nHidden ? ` · ${nHidden} controlled-medicine ${nHidden === 1 ? 'alert' : 'alerts'} without details` : ''}`}>
            <p className="text-subtle">Every medication alert: who was told, how, and who attended. It never changes the medication record.{openN ? ` ${openN} not attended yet.` : ''}</p>
            {rows.length ? (
                <EntityTable<LogRow>
                    rows={rows} rowKey={(r) => r.id} identityLabel="Alert" identityWidth="2.2fr" minWidth={1000} rowHeight="content"
                    identity={(r) => (hide(r) ? { icon: LockKeyhole, name: 'Controlled-medicine alert', subline: 'Details need controlled-medicine access' } : { icon: Bell, name: alertById(r.k).l, subline: r.about })}
                    columns={[
                        { key: 'sent', label: 'Sent', width: '1fr', cell: (r) => <div><div className="text-[13px]">{r.sent}</div>{r.afterHours ? <div className="text-caption">After hours</div> : null}</div> },
                        { key: 'to', label: 'Told', width: '1.3fr', cell: (r) => (hide(r) ? <span className="text-caption">Hidden</span> : <div><div className="text-[13px]">{r.to.join(', ')}</div><div className="text-caption">{r.via.join(' and ')}</div></div>) },
                        { key: 'where', label: 'Where', width: '0.9fr', cell: (r) => <EntityChip icon={Home}>{HOUSES[r.house]}</EntityChip> },
                        { key: 'status', label: 'Status', width: '1.4fr', cell: (r) => LOG_STATUS(r, hide(r)) },
                    ]}
                    actionsFor={actions} onOpen={(r) => open({ kind: 'alertlog', arg: r.id })} onRowContextMenu={menu.open}
                />
            ) : <EmptyState icon={ScrollText} title="No alerts match these filters" description="Clear the filters or the search." action={<Button variant="outline" size="sm" onClick={() => { ctx.clearQ(); ctx.setF({ ...ctx.f, logShow: 'all', logHouse: 'all' }); }}>Clear filters</Button>} />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={Bell} title={(r) => (hide(r) ? 'Controlled-medicine alert' : alertById(r.k).l)} items={actions} />
        </Section>
    );
}

/* ── Who can’t be reached (part of Delivery): contact gaps that stop an alert or an on-call call getting through ── */
type ReachRow = ReturnType<typeof reachRows>[number];
function WhoCantBeReached() {
    const { m } = useStore();
    const { open } = useNav();
    const menu = useEntityContextMenu<ReachRow>();
    const rows = reachRows(m), now = rows.filter((r) => r.issues.some((i) => i.matters));
    const actions = (r: ReachRow): MenuItem[] => [{ label: 'Why, and who fixes it', icon: Eye, onClick: () => open({ kind: 'reach', arg: r.e.id }) }];
    return (
        <Section id="sc-reach" title="Who can’t be reached" caption={`${now.length} ${now.length === 1 ? 'person matters' : 'people matter'} now · ${rows.length} with a gap`}>
            <p className="text-subtle">From staff records, push set-up and approved leave. A gap only matters once a channel they’d need is on, or they’re needed on call.</p>
            {rows.length ? (
                <EntityTable<ReachRow>
                    rows={rows} rowKey={(r) => r.e.id} identityLabel="Person" identityWidth="1.6fr" minWidth={900} rowHeight="content"
                    identity={(r) => ({ icon: UserX, name: r.e.name, subline: `${r.e.role} · ${r.e.houses}` })}
                    columns={[
                        { key: 'gap', label: 'Gap', width: '1.6fr', cell: (r) => <span className="py-2 text-[12.5px]">{r.issues.map((i) => i.text).join(' · ')}</span> },
                        { key: 'matters', label: 'Why it matters', width: '1.8fr', cell: (r) => <span className="py-2 text-[12.5px]">{r.issues.filter((i) => i.matters).map((i) => i.matters).join(' · ') || 'Not needed with today’s settings'}</span> },
                        { key: 'state', label: 'Status', width: '1fr', cell: (r) => (r.issues.some((i) => i.matters) ? <StatusBadge variant="warning" size="sm">Can’t be reached</StatusBadge> : <StatusBadge variant="neutral" size="sm">Not needed yet</StatusBadge>) },
                    ]}
                    actionsFor={actions} onOpen={(r) => open({ kind: 'reach', arg: r.e.id })} onRowContextMenu={menu.open}
                />
            ) : <EmptyState icon={UserCheck} title="Everyone can be reached" description="Every person in an alert’s groups has what their alerts need." />}
            <RowMenu ctx={menu.ctx} close={menu.close} icon={UserX} title={(r) => r.e.name} items={actions} />
        </Section>
    );
}

/* ── Overviews (Fleet Tracking › Overview): what each area controls now, with a way in ── */
const notReviewed = (n: number) => (n ? <StatusBadge variant="warning" size="sm">{n} not yet reviewed</StatusBadge> : <StatusBadge variant="success" size="sm">All reviewed</StatusBadge>);
const countOpen = (m: Model, g: GroupKey, keys: string[]) => keys.filter((k) => !reviewedBy(m, g, k)).length;
function RulesOverview({ q }: { q: string }) {
    const { m } = useStore();
    const { go } = useNav();
    const s = m.saved.safety, act = m.rules.filter((r) => r.active).length, opt = (k: string) => SAFETY_RULES.find((r) => r.key === k)!.opts.find((o) => o[0] === s[k])![1].split(' — ')[0];
    return (
        <Overview q={q} title="Medication rules" caption="What’s checked before a dose is saved" cards={[
            { icon: Pill, title: 'Medicine rules', lines: [m.demo.rules === 'empty' ? 'No rules yet.' : `${act} active · ${m.rules.length - act} paused.`, 'A second person or an observation for specific medicines.'], cta: 'Review medicine rules', onClick: () => go(settingsHref('rules', 'medicines')) },
            { icon: Shield, title: 'Safety checks', lines: [`Allergy match: ${opt('profileAllergy')}. Restricted competency: ${opt('restricted')}.`, `Area not passed: ${opt('area')}. Less than ordered: confirm ${s.amount === 'always' ? 'always' : s.amount === 'no' ? 'not needed' : 'if someone is available'}.`], badge: notReviewed(countOpen(m, 'safety', SAFETY_RULES.map((r) => r.key))), cta: 'Review safety checks', onClick: () => go(settingsHref('rules', 'safety')) },
            { icon: LockKeyhole, title: 'Controlled drugs', lines: [m.saved.cdw.org === 'on' ? 'A witness is required at every house.' : 'A witness is not required by default.', `Overrides last up to ${CDW_OPTS.longest.find((o) => o[0] === m.saved.cdw.longest)![1].replace(' (default)', '').toLowerCase()}.`], cta: 'Review controlled drugs', onClick: () => go(settingsHref('rules', 'controlled')) },
            { icon: Camera, title: 'Medicine photos', lines: [`${PHOTO_OPTS.who.find((o) => o[0] === m.saved.photos.who)![1]} can take one.`, m.saved.photos.prompt === 'prompt' ? 'Prompted at receipt, never required.' : 'Not prompted at receipt.'], badge: notReviewed(countOpen(m, 'photos', ['who', 'prompt'])), cta: 'Review medicine photos', onClick: () => go(settingsHref('rules', 'photos')) },
        ]} />
    );
}
function RoundsOverview({ q }: { q: string }) {
    const { m } = useStore();
    const { go } = useNav();
    const t = m.saved.timing, tpl = m.templates.filter((x) => myHouses(m.persona).includes(x.house));
    return (
        <Overview q={q} title="Rounds & timing" caption="When doses are given and when they’re late" cards={[
            { icon: Repeat, title: 'Round templates', lines: [`${tpl.filter((x) => x.status === 'active').length} active across ${myHouses(m.persona).length} houses · ${tpl.filter((x) => x.status === 'paused').length} paused.`, 'Rounds are created from them at 12:05 am each day.'], cta: 'Review round templates', onClick: () => go(settingsHref('rounds', 'templates')) },
            { icon: Clock, title: 'When a dose is due', lines: [`Can be given from ${t.early} minutes before. Late after ${t.late} minutes.`, 'Recording is never blocked by these times.'], badge: notReviewed(countOpen(m, 'timing', ['early', 'late', 'soon'])), cta: 'Review dose timing', onClick: () => go(settingsHref('rounds', 'timing')) },
            { icon: RotateCcw, title: 'Refusals and late doses', lines: [`Escalates after ${t.escalN} refusals within ${t.escalDays} days. A late dose becomes an incident after ${t.lateIncident} minutes.`, t.reoffer ? `Staff are reminded to offer again after ${t.reoffer} minutes.` : 'No reminder to offer again.'], badge: notReviewed(countOpen(m, 'timing', ['escalN', 'lateIncident'])), cta: 'Review dose timing', onClick: () => go(settingsHref('rounds', 'timing')) },
            { icon: Pill, title: 'Time-critical medicines', lines: [t.critical.length ? t.critical.map((c) => `${c.med} — late after ${c.min} minutes`).join('; ') : 'None marked — every medicine uses the late time.'], badge: t.critical.length ? undefined : <NotConfigured />, cta: 'Review dose timing', onClick: () => go(settingsHref('rounds', 'timing')) },
        ]} />
    );
}
function StaffOverview({ q }: { q: string }) {
    const { m } = useStore();
    const { go } = useNav();
    const e = m.saved.elig, pr = m.saved.pin, n = (st: string) => m.pins.filter((x) => x.pin === st).length;
    return (
        <Overview q={q} title="Staff & PINs" caption="Who can give, co-sign and witness doses" cards={[
            { icon: ClipboardCheck, title: 'Competency', lines: [`An assessment lasts ${e.validity} months. Pass mark ${e.passMark} of 12${e.coreMust === 'yes' ? ', every core area passed' : ''}.`, `Renewal reminder ${e.reminder} days before it ends.`], badge: notReviewed(countOpen(m, 'elig', ['validity', 'passMark', 'coreMust', 'reminder'])), cta: 'Review competency', onClick: () => go(settingsHref('staff', 'competency')) },
            { icon: ShieldCheck, title: 'Exemption limit', lines: [`An exemption lasts up to ${e.longestEx} days, at one house.`], badge: notReviewed(countOpen(m, 'elig', ['longestEx'])), cta: 'Review exemption limit', onClick: () => go(settingsHref('staff', 'exemptions')) },
            { icon: KeyRound, title: 'Witness PINs', lines: [`Locks after ${pr.attempts} wrong attempts, for ${pr.lockout} minutes. ${pr.renewal ? `Renewed every ${pr.renewal} months.` : 'No renewal.'}`, `Forgotten PIN: ${pr.fallback === 'yes' ? 'allowed' : 'not allowed'}${pr.fallbackCd === 'yes' ? '' : ', except for controlled drugs'}.`], cta: 'Review PIN rules', onClick: () => go(settingsHref('staff', 'pins')) },
            { icon: Users, title: 'PIN status', lines: [`${n('set')} of ${m.pins.length} people have set a PIN · ${n('locked')} locked · ${n('notset') + n('adminreset')} still to set.`], badge: n('locked') ? <StatusBadge variant="warning" size="sm">{n('locked')} locked</StatusBadge> : undefined, cta: 'Review PIN status', onClick: () => go(settingsHref('staff', 'status')) },
        ]} />
    );
}
function AlertsOverview({ q }: { q: string }) {
    const { m } = useStore();
    const { go } = useNav();
    const email = ALERTS.filter((a) => m.saved.alerts[a.k].email).length, oc = HOUSE_KEYS.filter((h) => m.oncall[h]).length, ea = m.saved.ea;
    const log = ALERT_LOG.filter((r) => myHouses(m.persona).includes(r.house)), logOpen = log.filter((r) => r.status === 'open').length, reach = reachRows(m), reachNow = reach.filter((r) => r.issues.some((i) => i.matters)).length;
    return (
        <Overview q={q} title="Alerts & access" caption="Who is told, how, and who to call" cards={[
            { icon: Bell, title: 'Alerts', lines: [`${ALERTS.length} alert types · in-app on for ${ALERTS.filter((a) => m.saved.alerts[a.k].inapp).length} · email on for ${email} · push on for ${ALERTS.filter((a) => m.saved.alerts[a.k].push).length}.`, 'Choose who gets each alert, at every house and per house.'], badge: notReviewed(countOpen(m, 'alerts', ALERTS.map((a) => a.k))), cta: 'Review alerts', onClick: () => go(settingsHref('alerts', 'alerts')) },
            { icon: BellRing, title: 'Delivery & follow-up', lines: [m.saved.delivery.realertEvery ? `Re-alerts every ${m.saved.delivery.realertEvery} minutes until attended${m.saved.delivery.escalateAfter ? `; escalates after ${m.saved.delivery.escalateAfter} minutes` : ''}.` : 'Each alert is sent once — no re-alerts or escalation yet.', `Email is ${m.saved.delivery.digest === 'yes' ? 'an hourly summary' : 'sent straight away'}.${m.saved.delivery.private === 'yes' ? ' Email and push leave out client names and medicines.' : ''}`], badge: notReviewed(countOpen(m, 'delivery', ['realertEvery', 'attended', 'escalateAfter', 'digest', 'private', 'pinned'])), cta: 'Review delivery & follow-up', onClick: () => go(settingsHref('alerts', 'delivery')) },
            { icon: Phone, title: 'On-call contacts', lines: [`${oc} of ${HOUSE_KEYS.length} houses have one · ${HOUSE_KEYS.filter((h) => m.oncall[h]?.mode === 'roster').length} follow the roster.`, 'Whoever is rostered on call is shown after hours, with a backup person.'], badge: oc < HOUSE_KEYS.length ? <StatusBadge variant="warning" size="sm">{HOUSE_KEYS.length - oc} not configured</StatusBadge> : undefined, cta: 'Review on-call contacts', onClick: () => go(settingsHref('alerts', 'oncall')) },
            { icon: LockKeyhole, title: 'Emergency access', lines: [`A grant lasts ${fmtMin(ea.def)}; the longest is ${fmtMin(ea.max)}.`, `Flags repeat use at ${ea.repeatN} grants within ${ea.repeatDays} days.`], badge: m.eaSaved ? undefined : <DefaultNotReviewed />, cta: 'Review emergency access', onClick: () => go(settingsHref('alerts', 'emergency')) },
            { icon: ScrollText, title: 'Alert log', lines: [`${log.length} alerts in the last 3 days · ${logOpen} not attended.`, 'Who was told, how, and who attended.'], badge: logOpen ? <StatusBadge variant="warning" size="sm">{logOpen} not attended</StatusBadge> : undefined, cta: 'Review the alert log', onClick: () => go(settingsHref('alerts', 'log')) },
            { icon: UserX, title: 'Who can’t be reached', lines: [reach.length ? `${reach.length} people have a contact gap · ${reachNow} matter now.` : 'Everyone can be reached.', 'From staff records, push set-up and approved leave.'], badge: reachNow ? <StatusBadge variant="warning" size="sm">{reachNow} can’t be reached</StatusBadge> : undefined, cta: 'Review who can’t be reached', onClick: () => go(settingsHref('alerts', 'delivery')) },
        ]} />
    );
}

const SECTION: Record<string, (ctx: Ctx) => ReactNode> = {
    'rules/overview': (c) => <RulesOverview q={c.q} />, 'rules/medicines': (c) => <MedicineRules ctx={c} />, 'rules/safety': (c) => <SafetyChecks ctx={c} />, 'rules/controlled': (c) => <ControlledDrugs ctx={c} />, 'rules/photos': (c) => <MedicinePhotos ctx={c} />,
    'rounds/overview': (c) => <RoundsOverview q={c.q} />, 'rounds/templates': (c) => <RoundTemplates ctx={c} />, 'rounds/timing': (c) => <DoseTiming ctx={c} />,
    'staff/overview': (c) => <StaffOverview q={c.q} />, 'staff/competency': (c) => <Competency ctx={c} />, 'staff/exemptions': (c) => <Exemptions ctx={c} />, 'staff/pins': (c) => <WitnessPins ctx={c} />, 'staff/status': (c) => <PinStatus ctx={c} />,
    'alerts/overview': (c) => <AlertsOverview q={c.q} />, 'alerts/alerts': (c) => <Alerts ctx={c} />, 'alerts/delivery': (c) => <Delivery ctx={c} />, 'alerts/log': (c) => <AlertLog ctx={c} />, 'alerts/oncall': (c) => <OnCallContacts ctx={c} />, 'alerts/emergency': (c) => <EmergencyAccess ctx={c} />,
    'history/decide': (c) => <StillToDecide ctx={c} />, 'history/changes': (c) => <AllChanges ctx={c} />,
};

export { ReviewCard, ReviewRow, InfoCard };
void useMemo;
