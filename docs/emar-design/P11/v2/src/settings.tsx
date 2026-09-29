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
import { InfoCard } from '@/components/wizard/primitives';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import {
    AlarmClock, ArrowUpRight, Bell, Building2, CalendarDays, ClipboardCheck, Clock, Eye, FileText, HelpCircle, History, Home, KeyRound, Layers,
    ListChecks, LockKeyhole, Pause, Pencil, Pill, Play, Plus, RefreshCw, Repeat, Settings as SettingsIcon, Shield, ShieldCheck, Trash2, UserCheck, Users, WifiOff,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { ALERTS, ALERT_PEOPLE, CDW_OPTS, HOUSES, HOUSE_KEYS, PIN_RULES, RECIPIENT_GROUPS, SAFETY_RULES, SAFETY_SWITCH, STAFF, type HouseKey, type Rule, type Tpl } from './data';
import {
    EA_L, ELIG_L, GROUPS, PHOTO_LABEL, PHOTO_OPTS, TIMING_L, VIEW_LABEL, allChanges, allHistory, canEaPolicy, canHouse, canOrg, canRuleScope,
    canTemplates, daysText, decisionRegistry, fmtMin, fmtT, isDirty, leadCap, myHouses, readOnlyAudit, reviewedBy, ruleNeeds, ruleOverlaps,
    ruleWhat, useStore, validateView, viewChanges, type GroupKey, type Model, type ViewKey,
} from './model';
import { SET_VIEWS, settingsHref, eligHref, useNav } from './nav';
import { Choice, Flash, KV, NotConfigured, Note, NumberInput, OnOff, RecordPicker, Reviewed, RowMenu, SaveBar, Section, SettingRow, type PickItem } from './ui';

const VIEW_ICON: Record<string, typeof Pill> = { rules: Pill, rounds: Repeat, staff: UserCheck, alerts: Bell, history: History };
const SEC_ICON: Record<string, typeof Pill> = { medicines: Pill, safety: Shield, controlled: LockKeyhole, photos: FileText, templates: Repeat, timing: Clock, competency: ClipboardCheck, exemptions: ShieldCheck, pins: KeyRound, status: Users, oncall: Bell, recipients: Users, emergency: LockKeyhole, decide: HelpCircle, changes: History };
const match = (q: string, ...s: (string | undefined)[]) => !q || s.some((x) => (x || '').toLowerCase().includes(q.toLowerCase()));
const DirtyDot = () => <span role="img" aria-label="Unsaved changes" className="size-2 rounded-full bg-status-warning" />;

type Filters = { rulesWhere: string; rulesState: string; tplHouse: string; tplStatus: string; pinHouse: string; pinState: string; show: string; histArea: string; histWho: string; histWhere: string };
const F0: Filters = { rulesWhere: 'all', rulesState: 'all', tplHouse: 'all', tplStatus: 'current', pinHouse: 'all', pinState: 'all', show: 'all', histArea: 'all', histWho: 'all', histWhere: 'all' };

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
            const target = view === 'staff' ? (['attempts', 'lockout', 'confirmLimit', 'renewal'].includes(k) ? 'pins' : k === 'longestEx' ? 'exemptions' : 'competency') : sec;
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
                            : ['safety', 'controlled', 'photos', 'timing', 'competency', 'exemptions', 'pins', 'recipients', 'emergency'].includes(sec) ? sel('All settings', 'show', [['all', 'All settings'], ['open', 'Still to decide'], ['changed', 'Unsaved changes']]) : null}
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
            actions={<><PageHeaderSearch value={query} onChange={setQuery} placeholder={`Search ${SET_VIEWS[view].secs.find(([k]) => k === sec)![1].replace(/^[A-Z](?![A-Z])/, (c) => c.toLowerCase())}`} /><PageHeaderGlassButton icon={History} onClick={() => go(settingsHref('history', 'changes'))}>Changes</PageHeaderGlassButton></>}
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

/* ── Medication rules › Safety checks (P00 v5 wording, shown as switches) ── */
function SafetyChecks({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, set } = useEdit();
    const d = m.draft.safety, dis = ro('safety'), R = (k: string) => SAFETY_RULES.find((r) => r.key === k)!;
    const opt = (k: string, v: string) => (R(k).opts.find((o) => o[0] === v) || ['', v])[1];
    const row = (k: string, extra?: ReactNode, badge?: ReactNode) => {
        const sw = SAFETY_SWITCH[k]!, on = d[k] !== sw.off, dirty = isDirty(m, 'safety', k), by = reviewedBy(m, 'safety', k);
        if (!visible(ctx, !by, dirty, R(k).label, R(k).help)) return null;
        return (
            <SettingRow key={k} id={`sf-${k}`} label={R(k).label} help={R(k).help} dirty={dirty}
                control={<OnOff id={`sf-${k}`} checked={on} disabled={dis} onChange={(v) => set((dd) => { dd.draft.safety[k] = v ? (dd.saved.safety[k] !== sw.off ? dd.saved.safety[k] : sw.on[0][0]) : sw.off; })} />}
                summary={opt(k, d[k])} meta={<><Reviewed by={by} />{badge}</>}>
                {on ? <div className="space-y-2"><Choice value={d[k]} options={sw.on as [string, string][]} disabled={dis} onChange={(v) => edit('safety', k, v)} />{extra}</div> : null}
            </SettingRow>
        );
    };
    const allergyDirty = isDirty(m, 'safety', 'profileAllergy');
    const rows = [
        visible(ctx, false, allergyDirty, R('profileAllergy').label, R('profileAllergy').help) ? (
            <SettingRow key="allergy" id="sf-allergy" label={R('profileAllergy').label} help={R('profileAllergy').help} dirty={allergyDirty} summary={opt('profileAllergy', d.profileAllergy)} meta={<Reviewed by={reviewedBy(m, 'safety', 'profileAllergy')} />}>
                <Choice value={d.profileAllergy} disabled={dis} onChange={(v) => edit('safety', 'profileAllergy', v)} options={[['warn', 'Warn'], ['block', 'Block'], ['confirm', 'Block unless the prescriber confirmed']]} />
            </SettingRow>) : null,
        row('restricted', null, <StatusBadge variant="info" size="sm">Agreed next step: co-signer with witness PIN, once PIN-1 is built</StatusBadge>),
        row('area'),
        row('phoneRx', <div className="space-y-1.5 pt-1"><p className="text-caption">{R('phoneRxBy').label}</p><Choice value={d.phoneRxBy} disabled={dis} onChange={(v) => edit('safety', 'phoneRxBy', v)} options={R('phoneRxBy').opts as [string, string][]} /></div>),
        row('amount'),
    ].filter(Boolean);
    return (
        <Section id="sc-safety" title="Safety checks when a dose is signed" caption={`${rows.length} of 5 settings shown`} right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            <p className="text-subtle">These apply at every house. Changes are recorded in the change history and the audit log.</p>
            {rows.length ? rows : <NoMatches ctx={ctx} />}
        </Section>
    );
}

/* ── Medication rules › Controlled drugs (P00 v5 witness settings as switches) ── */
function ControlledDrugs({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const { go } = useNav();
    const d = m.draft.cdw, dis = ro('cdw');
    const eff = (h: string) => (d[h] === 'org' ? d.org : d[h]) === 'on';
    const houseOpts: [string, string][] = [['org', 'Follow the organisation'], ['on', 'Always required'], ['off', 'Not required']];
    const rows = [
        visible(ctx, false, isDirty(m, 'cdw', 'org'), 'Witness required for controlled drugs') && (
            <SettingRow key="org" id="cw-org" label="Witness required for controlled drugs" dirty={isDirty(m, 'cdw', 'org')}
                help="The organisation default for every house. A witness is a different person, clocked in on a shift covering the house, with witness competency and a witness PIN set — checked against the roster. Any order can still require a witness for its medicine."
                control={<OnOff id="cw-org" checked={d.org === 'on'} disabled={dis} onChange={(v) => edit('cdw', 'org', v ? 'on' : 'off')} />}
                summary={d.org === 'on' ? 'Every controlled dose needs a witness unless a house says otherwise or an override covers it.' : 'Controlled doses are recorded without a witness unless a house or the order requires one.'}
                meta={<Reviewed by={reviewedBy(m, 'cdw', 'org')} />} />),
        visible(ctx, false, isDirty(m, 'cdw', 'kowhai') || isDirty(m, 'cdw', 'rimu'), 'Each house', 'Kōwhai', 'Rimu') && (
            <SettingRow key="houses" label="Each house" help="A house follows the organisation setting unless it sets its own." dirty={isDirty(m, 'cdw', 'kowhai') || isDirty(m, 'cdw', 'rimu')}>
                <div className="divide-y divide-border rounded-lg border">
                    {(['kowhai', 'rimu'] as HouseKey[]).map((h) => (
                        <div key={h} className="flex flex-wrap items-center justify-between gap-3 p-3">
                            <div><div className="text-sm font-semibold">{HOUSES[h]}</div><div className="text-caption">{h === 'kowhai' ? '2 people with controlled medicines' : '1 person with controlled medicines'}</div></div>
                            <Choice value={d[h]} options={houseOpts} disabled={dis} onChange={(v) => edit('cdw', h, v)} />
                            <StatusBadge variant={eff(h) ? 'success' : 'warning'} size="sm">{eff(h) ? 'Witness required' : 'Not required'}</StatusBadge>
                        </div>
                    ))}
                </div>
            </SettingRow>),
        visible(ctx, false, isDirty(m, 'cdw', 'longest'), 'Longest witness override') && (
            <SettingRow key="longest" label="Longest witness override" dirty={isDirty(m, 'cdw', 'longest')}
                help="Time-limited overrides are for one house (optionally one person or medicine), with a start, an end and a reason. They end by themselves; each dose is marked and followed up."
                summary="Who can grant: the new permission “Grant controlled-drug witness overrides”." meta={<Reviewed by={reviewedBy(m, 'cdw', 'longest')} />}>
                <Choice value={d.longest} options={CDW_OPTS.longest.map(([v, l]) => [v, l.replace(' (default)', '')]) as [string, string][]} disabled={dis} onChange={(v) => edit('cdw', 'longest', v)} />
                <div><Button variant="outline" size="sm" onClick={() => go('#/safety/overrides')}>Open witness overrides<ArrowUpRight /></Button></div>
            </SettingRow>),
        visible(ctx, false, isDirty(m, 'cdw', 'suggest'), 'Heads-up before single staffing') && (
            <SettingRow key="suggest" id="cw-suggest" label="Heads-up before single staffing" dirty={isDirty(m, 'cdw', 'suggest')}
                help="When an upcoming shift has only one staff member at a house that holds controlled drugs, managers see a heads-up offering to set up a witness override in advance. It never switches anything on by itself."
                control={<OnOff id="cw-suggest" checked={d.suggest === 'on'} disabled={dis} onChange={(v) => edit('cdw', 'suggest', v ? 'on' : 'off')} />} meta={<Reviewed by={reviewedBy(m, 'cdw', 'suggest')} />} />),
    ].filter(Boolean);
    return (
        <Section id="sc-cdw" title="Controlled drugs — witness" caption="Decided by Stephan, 29 September 2026" right={<StatusBadge variant="neutral" size="sm">D8</StatusBadge>}>
            {rows.length ? rows : <NoMatches ctx={ctx} />}
        </Section>
    );
}

/* ── Medication rules › Medicine photos (answer 18) ── */
function MedicinePhotos({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const d = m.draft.photos, dis = ro('photos');
    const rows = [
        visible(ctx, !reviewedBy(m, 'photos', 'who'), isDirty(m, 'photos', 'who'), PHOTO_LABEL.who) && (
            <SettingRow key="who" label={PHOTO_LABEL.who} dirty={isDirty(m, 'photos', 'who')} help="Photos are taken or replaced when stock is received (P06) and shown on Meds today and the person’s record (P01, P02)." meta={<Reviewed by={reviewedBy(m, 'photos', 'who')} />}>
                <Choice value={d.who} options={PHOTO_OPTS.who} disabled={dis} onChange={(v) => edit('photos', 'who', v)} />
            </SettingRow>),
        visible(ctx, !reviewedBy(m, 'photos', 'prompt'), isDirty(m, 'photos', 'prompt'), PHOTO_LABEL.prompt) && (
            <SettingRow key="prompt" id="ph-prompt" label={PHOTO_LABEL.prompt} dirty={isDirty(m, 'photos', 'prompt')} help="Asks when there’s no photo yet, or the brand or pack has changed. It never stops stock being received."
                control={<OnOff id="ph-prompt" checked={d.prompt === 'prompt'} disabled={dis} onChange={(v) => edit('photos', 'prompt', v ? 'prompt' : 'off')} />} meta={<Reviewed by={reviewedBy(m, 'photos', 'prompt')} />} />),
    ].filter(Boolean);
    return (
        <Section id="sc-photos" title="Medicine photos" caption="Photos of the pack actually supplied" right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            {rows.length ? rows : <NoMatches ctx={ctx} />}
            <Note>Always: stored privately, with controlled-medicine concealment · each photo shows when it was taken and which pack it came from · every screen says “Check the label — the picture is a guide only”.</Note>
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

/* ── Rounds & timing › Dose timing (Stephan: keep today’s rules; answer 14) ── */
function DoseTiming({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber, set } = useEdit();
    const { open } = useNav();
    const d = m.draft.timing, dis = ro('timing');
    const num = (k: 'early' | 'late' | 'soon' | 'lateIncident', help: string) => visible(ctx, !reviewedBy(m, 'timing', k), isDirty(m, 'timing', k), TIMING_L[k][0], help) && (
        <SettingRow key={k} id={`tm-${k}`} label={TIMING_L[k][0]} help={help} dirty={isDirty(m, 'timing', k)} error={ctx.errs[k]} errorId={`tm-${k}-error`}
            control={<NumberInput id={`tm-${k}`} value={d[k]} unit={TIMING_L[k][1]} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('timing', k, v); ctx.clearErr(k); }} />}
            meta={<Reviewed by={reviewedBy(m, 'timing', k)} />} />);
    const reOn = !!d.reoffer || !!m.pendingOn['timing.reoffer'];
    const rows = [
        num('early', 'Before this, the dose shows as not yet due.'),
        num('late', 'After this, the dose shows as late and overdue alerts go to everyone rostered and the house lead.'),
        num('soon', 'Only changes what Meds today highlights.'),
        visible(ctx, !m.saved.timing.critical.length, isDirty(m, 'timing', 'critical'), 'Time-critical medicines') && (
            <SettingRow key="critical" label="Time-critical medicines" dirty={isDirty(m, 'timing', 'critical')}
                help="Medicines the clinical lead marks as time-critical use their own, shorter late time. Until then every medicine uses the late time above."
                meta={d.critical.length || m.saved.timing.critical.length ? <Reviewed by={reviewedBy(m, 'timing', 'critical')} /> : <NotConfigured />}>
                {d.critical.length ? (
                    <ul className="divide-y divide-border rounded-lg border">
                        {d.critical.map((c, i) => (
                            <li key={c.med} className="flex items-center justify-between gap-3 p-3 text-[13px]"><span><b>{c.med}</b> · late after {c.min} minutes</span>
                                {dis ? null : <Button variant="outline" size="sm" aria-label={`Remove ${c.med}`} onClick={() => set((dd) => { dd.draft.timing.critical.splice(i, 1); })}>Remove</Button>}</li>
                        ))}
                    </ul>) : null}
                {dis ? null : <div><Button variant="outline" size="sm" onClick={() => open({ kind: 'crit' })}><Plus />Mark a medicine as time-critical</Button></div>}
            </SettingRow>),
        visible(ctx, !m.saved.timing.reoffer, isDirty(m, 'timing', 'reoffer'), TIMING_L.reoffer[0]) && (
            <SettingRow key="reoffer" id="tm-reon" label={TIMING_L.reoffer[0]} dirty={isDirty(m, 'timing', 'reoffer')} error={ctx.errs.reoffer} errorId="tm-reoffer-error"
                help="Re-offers can always be recorded. When on, the refusal follow-up reminds staff to offer again after this many minutes."
                control={<OnOff id="tm-reon" checked={reOn} disabled={dis} onChange={(v) => { toggleNumber('timing', 'reoffer', v); ctx.clearErr('reoffer'); }} />}
                meta={reOn || m.saved.timing.reoffer ? <Reviewed by={reviewedBy(m, 'timing', 'reoffer')} /> : <NotConfigured />}>
                {reOn ? <NumberInput id="tm-reoffer" label="Minutes after the refusal" value={d.reoffer} unit={TIMING_L.reoffer[1]} disabled={dis} error={ctx.errs.reoffer} onChange={(v) => { edit('timing', 'reoffer', v); ctx.clearErr('reoffer'); }} /> : null}
            </SettingRow>),
        num('lateIncident', 'A late dose raises a Control Room incident after this long (serious after 4 hours). Now a setting (answer 14).'),
        visible(ctx, !reviewedBy(m, 'timing', 'escalN'), isDirty(m, 'timing', 'escalN') || isDirty(m, 'timing', 'escalDays'), 'Repeated refusals escalate') && (
            <SettingRow key="escal" id="tm-escalN" label="Repeated refusals escalate" dirty={isDirty(m, 'timing', 'escalN') || isDirty(m, 'timing', 'escalDays')} error={ctx.errs.escalN || ctx.errs.escalDays}
                help="Refusals or withholds of the same medicine that escalate to a manager and the GP. Now a setting (answer 14)."
                control={<span className="inline-flex flex-wrap items-center gap-2"><NumberInput id="tm-escalN" label="Number of refusals" value={d.escalN} unit="within" disabled={dis} error={ctx.errs.escalN} onChange={(v) => { edit('timing', 'escalN', v); ctx.clearErr('escalN'); }} /><NumberInput id="tm-escalDays" label="Number of days" value={d.escalDays} unit="days" disabled={dis} error={ctx.errs.escalDays} onChange={(v) => { edit('timing', 'escalDays', v); ctx.clearErr('escalDays'); }} /></span>}
                meta={<Reviewed by={reviewedBy(m, 'timing', 'escalN')} />} />),
    ].filter(Boolean);
    return (
        <Section id="sc-timing" title="Dose timing" caption={`${rows.length} settings shown`} right={<><StatusBadge variant="neutral" size="sm">D4</StatusBadge><EntityChip icon={Building2}>Every house</EntityChip></>}>
            <p className="text-subtle">When a scheduled dose shows as due soon, due and late on Meds today, and when overdue alerts and escalations go out.</p>
            <Note>Stephan, 29 Sep 2026: keep today’s rules until the clinical lead reviews them. <b>Recording is never blocked by these times.</b> Round templates have their own window (5–120 minutes) for grouping doses into a round; these times decide when a dose is due and late.</Note>
            {rows.length ? rows : <NoMatches ctx={ctx} />}
        </Section>
    );
}

/* ── Staff & PINs › Competency (answer 7) ── */
function Competency({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber } = useEdit();
    const { go } = useNav();
    const d = m.draft.elig, dis = ro('elig');
    const num = (k: string, help: string, max?: number) => visible(ctx, !reviewedBy(m, 'elig', k), isDirty(m, 'elig', k), ELIG_L[k][0], help) && (
        <SettingRow key={k} id={`el-${k}`} label={ELIG_L[k][0]} help={help} dirty={isDirty(m, 'elig', k)} error={ctx.errs[k]} errorId={`el-${k}-error`}
            control={<NumberInput id={`el-${k}`} value={d[k]} unit={ELIG_L[k][1]} max={max} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('elig', k, v); ctx.clearErr(k); }} />}
            meta={<Reviewed by={reviewedBy(m, 'elig', k)} />} />);
    const obsOn = !!d.obsNeeded || !!m.pendingOn['elig.obsNeeded'];
    const rows = [
        num('validity', 'The assessment form fills in the end date from this; the assessor can choose an earlier date. Today: 1 year, fixed in code.'),
        num('passMark', 'An area that wasn’t assessed counts as not passed. Today the server passes an assessment at 10 of 12.', 12),
        visible(ctx, !reviewedBy(m, 'elig', 'coreMust'), isDirty(m, 'elig', 'coreMust'), ELIG_L.coreMust[0]) && (
            <SettingRow key="core" id="el-core" label={ELIG_L.coreMust[0]} dirty={isDirty(m, 'elig', 'coreMust')}
                help="Core areas: medication knowledge, the five rights, safety checks, documentation, error reporting and allergy awareness. Today only the form checks this — the server will too when built."
                control={<OnOff id="el-core" checked={d.coreMust === 'yes'} disabled={dis} onChange={(v) => edit('elig', 'coreMust', v ? 'yes' : 'no')} />} meta={<Reviewed by={reviewedBy(m, 'elig', 'coreMust')} />} />),
        visible(ctx, !m.saved.elig.obsNeeded, isDirty(m, 'elig', 'obsNeeded'), ELIG_L.obsNeeded[0]) && (
            <SettingRow key="obs" id="el-obs" label={ELIG_L.obsNeeded[0]} dirty={isDirty(m, 'elig', 'obsNeeded')} error={ctx.errs.obsNeeded} errorId="el-obsNeeded-error"
                help="Today the form asks for 12, citing UK guidance. No New Zealand source is recorded, so it stays Not configured until the organisation sets it."
                control={<OnOff id="el-obs" checked={obsOn} disabled={dis} onChange={(v) => { toggleNumber('elig', 'obsNeeded', v); ctx.clearErr('obsNeeded'); }} />}
                meta={obsOn || m.saved.elig.obsNeeded ? <Reviewed by={reviewedBy(m, 'elig', 'obsNeeded')} /> : <NotConfigured />}>
                {obsOn ? <NumberInput id="el-obsNeeded" label="Minimum observed administrations" value={d.obsNeeded} unit={ELIG_L.obsNeeded[1]} disabled={dis} error={ctx.errs.obsNeeded} onChange={(v) => { edit('elig', 'obsNeeded', v); ctx.clearErr('obsNeeded'); }} /> : null}
            </SettingRow>),
        num('reminder', 'Used for “Due for renewal”, the rostering warning and the reminder alert.'),
    ].filter(Boolean);
    return (
        <div className="space-y-5">
            <Section id="sc-comp" title="How assessments work" caption="The values the assessment form and the register use" right={<><StatusBadge variant="neutral" size="sm">D3</StatusBadge><EntityChip icon={Building2}>Every house</EntityChip></>}>
                {rows.length ? rows : <NoMatches ctx={ctx} />}
            </Section>
            <Section id="sc-facts" title="What competency controls" caption="Read-only — set elsewhere, decided, or not built yet" right={<Button variant="outline" size="sm" onClick={() => go(eligHref())}>Open Staff eligibility<ArrowUpRight /></Button>}>
                <Card className="p-4"><KV rows={[
                    ['Restricted competency', `${SAFETY_RULES[1].opts.find((o) => o[0] === m.saved.safety.restricted)![1]} (Safety checks)`],
                    ['Controlled-drug and covert areas', `${SAFETY_RULES[2].opts.find((o) => o[0] === m.saved.safety.area)![1]} (Safety checks)`],
                    ['Insulin area', 'Not checked yet — orders don’t say which medicines are insulin (deferred, D3)'],
                    ['“Can give unsupervised”', 'Recorded on each assessment; not used yet (deferred, D3)'],
                    ['Witnessing controlled drugs', 'A current assessment (not an exemption) with “can witness controlled drugs”, which needs the controlled drugs area passed; not while restricted; a witness PIN; on shift at the house'],
                    ['Rostering', 'No current assessment blocks rostering on a medication shift; ending within the renewal reminder is a warning the rosterer can override'],
                    ['Who records assessments', 'People who manage orders at the house (today’s rule, kept for now)'],
                    ['Acknowledgement', 'Only from the worker’s own login (Meds today › My eligibility)'],
                ]} /></Card>
            </Section>
        </div>
    );
}
/* ── Staff & PINs › Exemption limit (answer 8 — NF-03: today there’s no screen and no maximum) ── */
function Exemptions({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const { go } = useNav();
    const d = m.draft.elig, dis = ro('elig');
    const row = visible(ctx, !reviewedBy(m, 'elig', 'longestEx'), isDirty(m, 'elig', 'longestEx'), ELIG_L.longestEx[0]) && (
        <SettingRow id="el-longestEx" label={ELIG_L.longestEx[0]} dirty={isDirty(m, 'elig', 'longestEx')} error={ctx.errs.longestEx} errorId="el-longestEx-error"
            help="Every exemption needs an end date no later than this. Stephan, 29 Sep 2026: 30 days, for the clinical lead to confirm."
            control={<NumberInput id="el-longestEx" value={d.longestEx} unit="days" disabled={dis} error={ctx.errs.longestEx} onChange={(v) => { edit('elig', 'longestEx', v); ctx.clearErr('longestEx'); }} />}
            meta={<Reviewed by={reviewedBy(m, 'elig', 'longestEx')} />} />);
    return (
        <div className="space-y-5">
            <Section id="sc-ex" title="Exemption limit" caption="Applies to every exemption" right={<StatusBadge variant="neutral" size="sm">NF-03</StatusBadge>}>
                {row || <NoMatches ctx={ctx} />}
            </Section>
            <Section id="sc-exfacts" title="How exemptions work" caption="Read-only" right={<Button variant="outline" size="sm" onClick={() => go(eligHref('exemptions'))}>Open exemptions<ArrowUpRight /></Button>}>
                <Card className="p-4"><KV rows={[
                    ['What it does', 'Lets someone record doses as given at one house without a current assessment, until a fixed end date'],
                    ['Who can grant', 'Permission “Grant competency exemptions” — clinical leads and provider managers'],
                    ['Approver', 'Someone else, with access to that house'],
                    ['Reason', 'Required, at least 10 characters'],
                    ['Other rules', 'The restricted and area rules still apply during an exemption (decided 29 Sep 2026)'],
                    ['Witnessing', 'Never — a witness needs a current assessment'],
                ]} /></Card>
            </Section>
        </div>
    );
}

/* ── Staff & PINs › Witness PINs (P00 v5 PIN rules, Stephan’s values; answer 20 wording) ── */
function WitnessPins({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit, toggleNumber, set } = useEdit();
    const d = m.draft.pin, dis = ro('pin'), R = (k: string) => PIN_RULES.find((r) => r.key === k)!;
    const num = (k: string, extra = '') => visible(ctx, false, isDirty(m, 'pin', k), R(k).label, R(k).help) && (
        <SettingRow key={k} id={`pr-${k}`} label={R(k).label} help={R(k).help + extra} dirty={isDirty(m, 'pin', k)} error={ctx.errs[k]} errorId={`pr-${k}-error`}
            control={<NumberInput id={`pr-${k}`} value={d[k]} unit={R(k).unit!} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('pin', k, v); ctx.clearErr(k); }} />}
            meta={<Reviewed by={reviewedBy(m, 'pin', k)} />} />);
    const sw = (k: string) => visible(ctx, false, isDirty(m, 'pin', k), R(k).label, R(k).help) && (
        <SettingRow key={k} id={`pr-${k}`} label={R(k).label} help={R(k).help} dirty={isDirty(m, 'pin', k)}
            control={<OnOff id={`pr-${k}`} checked={d[k] === 'yes'} disabled={dis} onChange={(v) => edit('pin', k, v ? 'yes' : 'no')} />} meta={<Reviewed by={reviewedBy(m, 'pin', k)} />} />);
    const renOn = !!d.renewal || !!m.pendingOn['pin.renewal'];
    const reset = { lead: ['lead', 'both'].includes(d.resetRoles), clinical: ['clinical', 'both'].includes(d.resetRoles) };
    const setReset = (k: 'lead' | 'clinical', v: boolean) => set((dd) => { const r = { ...reset, [k]: v }; dd.draft.pin.resetRoles = r.lead && r.clinical ? 'both' : r.lead ? 'lead' : r.clinical ? 'clinical' : 'nc'; });
    const rows = [
        match(ctx.q, 'Method', 'witness PIN') && ctx.f.show === 'all' && <SettingRow key="method" label="Method" help="Chosen by Stephan on 29 September 2026. The colleague’s login password is retired for this. Each person sets their own 6-digit PIN in their account settings (Settings › Witness PIN)." control={<StatusBadge variant="neutral"><LockKeyhole className="size-3" />Personal 6-digit witness PIN</StatusBadge>} />,
        num('attempts'),
        num('lockout', ' It also unlocks when the owner resets it.'),
        visible(ctx, false, isDirty(m, 'pin', 'renewal'), R('renewal').label) && (
            <SettingRow key="renewal" id="pr-renon" label={R('renewal').label} help="When on, people are asked to choose a new PIN after this many months. Stephan, 29 Sep 2026: no forced renewal." dirty={isDirty(m, 'pin', 'renewal')} error={ctx.errs.renewal} errorId="pr-renewal-error"
                control={<OnOff id="pr-renon" checked={renOn} disabled={dis} onChange={(v) => { toggleNumber('pin', 'renewal', v); ctx.clearErr('renewal'); }} />}
                summary={renOn ? `People choose a new PIN every ${d.renewal || '…'} months.` : 'No renewal — a PIN stays until its owner changes it.'} meta={<Reviewed by={reviewedBy(m, 'pin', 'renewal')} />}>
                {renOn ? <NumberInput id="pr-renewal" label="Months between renewals" value={d.renewal} unit="months" disabled={dis} error={ctx.errs.renewal} onChange={(v) => { edit('pin', 'renewal', v); ctx.clearErr('renewal'); }} /> : null}
            </SettingRow>),
        sw('fallback'),
        sw('fallbackCd'),
        visible(ctx, false, isDirty(m, 'pin', 'resetRoles'), R('resetRoles').label, R('resetRoles').help) && (
            <SettingRow key="reset" label={R('resetRoles').label} help={R('resetRoles').help} dirty={isDirty(m, 'pin', 'resetRoles')} meta={<Reviewed by={reviewedBy(m, 'pin', 'resetRoles')} />}>
                <div className="divide-y divide-border rounded-lg border">
                    {([['lead', 'House leads — for their own houses'], ['clinical', 'Clinical leads']] as const).map(([k, l]) => (
                        <div key={k} className="flex items-center justify-between gap-4 p-3"><label htmlFor={`pr-reset-${k}`} className="text-[13px]">{l}</label><OnOff id={`pr-reset-${k}`} checked={reset[k]} disabled={dis} onChange={(v) => setReset(k, v)} /></div>
                    ))}
                </div>
            </SettingRow>),
        num('confirmLimit'),
        visible(ctx, false, isDirty(m, 'pin', 'routeTo'), R('routeTo').label, R('routeTo').help) && (
            <SettingRow key="route" label={R('routeTo').label} help={R('routeTo').help} dirty={isDirty(m, 'pin', 'routeTo')} meta={<Reviewed by={reviewedBy(m, 'pin', 'routeTo')} />}>
                <Choice value={d.routeTo} disabled={dis} onChange={(v) => edit('pin', 'routeTo', v)} options={[['lead-on-shift', 'House lead on shift'], ['lead-house', 'House lead for the house']]} />
            </SettingRow>),
    ].filter(Boolean);
    return (
        <Section id="sc-pins" title="Second-person confirmation" caption={`${rows.length} settings shown`} right={<><StatusBadge variant="neutral" size="sm">D8</StatusBadge><EntityChip icon={Building2}>Every house</EntityChip></>}>
            <p className="text-subtle">For restricted-competency co-signing, controlled-drug witnessing and confirming a different amount.</p>
            {rows.length ? rows : <NoMatches ctx={ctx} />}
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
            <Note>House leads (for their own houses) and clinical leads can reset a PIN (Stephan, 29 Sep 2026). The owner then chooses a new one in their account settings before they can co-sign or witness again.</Note>
        </Section>
    );
}

/* ── Alerts & access › On-call contacts: one card per house (Stephan, 29 Sep 2026: settings read better as cards) ── */
function OnCallContacts({ ctx }: { ctx: Ctx }) {
    const { m } = useStore();
    const { open } = useNav();
    const p = m.persona;
    const houses = HOUSE_KEYS.filter((h) => myHouses(p).includes(h) && match(ctx.q, HOUSES[h], m.oncall[h]?.name));
    const can = (h: HouseKey) => canHouse(h, p) && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    return (
        <Section id="sc-oncall" title="On-call contact after hours" caption="One per house" right={<StatusBadge variant="neutral" size="sm">D12</StatusBadge>}>
            <p className="text-subtle">Shown on “Can’t clock in?”, escalations and follow-ups. Until a house sets one, those screens say “On-call contact: Not configured” and give no number.</p>
            {houses.length ? (
                <div className="grid gap-5 lg:grid-cols-2">
                    {houses.map((h) => {
                        const c = m.oncall[h];
                        return (
                            <Card key={h} className="gap-3 p-4" data-setting={`oncall-${h}`}>
                                <div className="flex items-start justify-between gap-3">
                                    <div><p className="text-sm font-semibold">{HOUSES[h]}</p><p className="text-caption">House lead: {h === 'kowhai' ? 'Jordan Tipene' : 'Sione Taufa'}</p></div>
                                    {c ? <StatusBadge variant="success" size="sm">Set</StatusBadge> : <NotConfigured />}
                                </div>
                                {c ? (
                                    <div className="rounded-lg border p-3 text-[13px]"><p className="font-semibold">{c.name}</p><p>{c.phone}{c.note ? ` · ${c.note}` : ''}</p><p className="text-caption mt-1">Last changed by {c.by}, {c.when}</p></div>
                                ) : <p className="text-subtle">Screens at this house give no number after hours.</p>}
                                {can(h) ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button variant={c ? 'outline' : 'default'} size="sm" onClick={() => open({ kind: 'oncall', arg: h })}>{c ? <><Pencil />Change contact</> : <><Plus />Add contact</>}</Button>
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

/* ── Alerts & access › Who gets alerts (Stephan, 29 Sep 2026: managers set it; cards like Safety checks).
 * One set for every house (all-sites authority) + extra people per house (that house’s managers).
 * The routing he decided earlier stays locked on. ── */
export const peopleItems = (taken: string[]): PickItem[] => ALERT_PEOPLE.map((x) => ({ id: x.name, name: x.name, sub: `${x.role} · ${x.houses}`, ok: !taken.includes(x.name), why: 'already added' }));
/** Named people on an alert: a compact list; “Add a person” opens a Fleet Modal with the searchable picker. */
function PeopleList({ label, people, disabled, onRemove, onAdd }: { label: string; people: string[]; disabled: boolean; onRemove: (n: string) => void; onAdd: () => void }) {
    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-3">
                <p className="text-caption font-semibold">{label}</p>
                {disabled ? null : <Button variant="outline" size="sm" onClick={onAdd}><Plus />Add a person</Button>}
            </div>
            {people.length ? (
                <ul className="divide-y divide-border rounded-lg border">
                    {people.map((n) => <li key={n} className="flex items-center justify-between gap-3 p-2.5 text-[13px]"><span>{n} <span className="text-caption">· {ALERT_PEOPLE.find((x) => x.name === n)?.role}</span></span>{disabled ? null : <Button variant="outline" size="sm" aria-label={`Remove ${n} from ${label.toLowerCase()}`} onClick={() => onRemove(n)}>Remove</Button>}</li>)}
                </ul>
            ) : <p className="text-subtle">Nobody named.</p>}
        </div>
    );
}
function Recipients({ ctx }: { ctx: Ctx }) {
    const { m, set } = useEdit();
    const { open } = useNav();
    const p = m.persona;
    const mine = HOUSE_KEYS.filter((h) => myHouses(p).includes(h));
    const [house, setHouse] = useState<HouseKey>(mine.find((h) => canHouse(h, p)) ?? mine[0]);
    const orgRo = !canOrg(p) || readOnlyAudit(p) || m.demo.settings === 'offline';
    const houseRo = !canHouse(house, p) || readOnlyAudit(p) || m.demo.settings === 'offline';
    const cards = ALERTS.filter((a) => {
        const dirty = isDirty(m, 'alerts', a.k) || isDirty(m, 'alertExtra', `${house}.${a.k}`);
        return visible(ctx, !reviewedBy(m, 'alerts', a.k), dirty, a.l, a.sub ?? '', a.today);
    });
    const toggle = (k: string, g: string, on: boolean) => set((d) => { const x = d.draft.alerts[k]; x.groups = on ? [...x.groups, g] : x.groups.filter((y) => y !== g); });
    return (
        <Section id="sc-recip" title="Who gets each alert" caption={`${cards.length} of ${ALERTS.length} alerts shown`} right={<><StatusBadge variant="neutral" size="sm">D12</StatusBadge><EntityChip icon={Building2}>Every house + house extras</EntityChip></>}>
            <Note>In-app alerts. <b>Locked groups are the routing you decided</b> and can’t be switched off. Alerts about controlled medicines only reach people with controlled-medicine access, whoever is switched on. {orgRo && !readOnlyAudit(p) ? 'Only someone with all-sites authority changes the groups — you can add extra people for your own houses.' : ''}</Note>
            <Note><b>Control Room also routes medication signals today</b> (missed and late doses, discrepancies, stock) in Control Room › Settings. When this is built, each alert should have one owner — see question Q3 in the audit.</Note>
            <Card className="flex flex-row flex-wrap items-end justify-between gap-4 p-4">
                <div className="min-w-[280px] flex-1"><RecordPicker id="ar-house" label="Show extra people for" value={house} items={mine.map((h) => ({ id: h, name: HOUSES[h], sub: canHouse(h, p) ? 'You can add people here' : 'Read-only for you', ok: true }))} onChange={(v) => setHouse(v as HouseKey)} placeholder="Choose a house" search="Search houses…" /></div>
                <p className="text-caption max-w-[52ch]">Extra people get the alert only when it is about {HOUSES[house]}. They add to the groups below; they never replace them.</p>
            </Card>
            {cards.length ? cards.map((a) => {
                const d = m.draft.alerts[a.k], extraKey = `${house}.${a.k}`, extra = m.draft.alertExtra[extraKey] ?? [];
                const dirty = isDirty(m, 'alerts', a.k) || isDirty(m, 'alertExtra', extraKey);
                return (
                    <SettingRow key={a.k} id={`al-${a.k}`} label={a.l} help={a.sub} dirty={dirty}
                        summary={<>{a.until}. <b>Today, in code:</b> {a.today}</>}
                        meta={<><Reviewed by={reviewedBy(m, 'alerts', a.k)} />{a.check ? <StatusBadge variant="warning" size="sm">Fix task running</StatusBadge> : null}</>}>
                        <div className="overflow-hidden rounded-lg border"><div className="-mb-px grid sm:grid-cols-2 [&>*]:border-b [&>*]:border-border sm:[&>*:nth-child(odd)]:border-r" role="group" aria-label={`Groups that get “${a.l}”`}>
                            {a.groups.map((g) => {
                                const locked = a.locked?.includes(g), on = locked || d.groups.includes(g);
                                return (
                                    <div key={g} className="flex items-center justify-between gap-4 p-3">
                                        <div className="min-w-0"><label htmlFor={`al-${a.k}-${g}`} className="flex items-center gap-1.5 text-[13px] font-medium">{locked ? <LockKeyhole className="size-3.5" aria-hidden="true" /> : null}{RECIPIENT_GROUPS[g].l}</label><p className="text-caption">{locked ? 'Always on — your decision, 29 Sep 2026' : RECIPIENT_GROUPS[g].d}</p></div>
                                        <OnOff id={`al-${a.k}-${g}`} checked={on} disabled={locked || orgRo} label={`${RECIPIENT_GROUPS[g].l} get “${a.l}”`} onChange={(v) => toggle(a.k, g, v)} />
                                    </div>
                                );
                            })}
                        </div></div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <PeopleList label="Named people · every house" people={d.people} disabled={orgRo} onRemove={(n) => set((dd) => { dd.draft.alerts[a.k].people = dd.draft.alerts[a.k].people.filter((y) => y !== n); })} onAdd={() => open({ kind: 'alertperson', arg: a.k, step: 'org' })} />
                            <PeopleList label={`Extra people · ${HOUSES[house]}`} people={extra} disabled={houseRo} onRemove={(n) => set((dd) => { dd.draft.alertExtra[extraKey] = (dd.draft.alertExtra[extraKey] ?? []).filter((y) => y !== n); })} onAdd={() => open({ kind: 'alertperson', arg: a.k, step: house })} />
                        </div>
                    </SettingRow>
                );
            }) : <NoMatches ctx={ctx} />}
        </Section>
    );
}

/* ── Alerts & access › Emergency access policy (answer 2 editors; answer 19 P10 proposals) ── */
function EmergencyAccess({ ctx }: { ctx: Ctx }) {
    const { m, ro, edit } = useEdit();
    const d = m.draft.ea, dis = ro('ea'), open = !m.eaSaved;
    const num = (k: string, help: string) => visible(ctx, open, isDirty(m, 'ea', k), EA_L[k], help) && (
        <SettingRow key={k} id={`ea-${k}`} label={EA_L[k]} help={`${help}${d[k] && Number(d[k]) >= 60 ? ` (${fmtMin(d[k])})` : ''}`} dirty={isDirty(m, 'ea', k)} error={ctx.errs[k]} errorId={`ea-${k}-error`}
            control={<NumberInput id={`ea-${k}`} value={d[k]} unit="minutes" min={5} max={1440} disabled={dis} error={ctx.errs[k]} onChange={(v) => { edit('ea', k, v); ctx.clearErr(k); }} />}
            meta={<Reviewed by={reviewedBy(m, 'ea', k)} />} />);
    const rows = [
        num('def', 'How long a new grant lasts unless the person chooses a shorter time.'),
        num('max', 'No grant or extension can go past this.'),
        num('ext', 'Added when the person extends — never past the longest grant.'),
        visible(ctx, open, isDirty(m, 'ea', 'reason'), EA_L.reason) && <SettingRow key="reason" id="ea-reason" label={EA_L.reason} help="The reason is shown to reviewers and in the audit trail." dirty={isDirty(m, 'ea', 'reason')} control={<OnOff id="ea-reason" checked={d.reason === 'yes'} disabled={dis} onChange={(v) => edit('ea', 'reason', v ? 'yes' : 'no')} />} meta={<Reviewed by={reviewedBy(m, 'ea', 'reason')} />} />,
        visible(ctx, open, isDirty(m, 'ea', 'repeatN') || isDirty(m, 'ea', 'repeatDays'), 'Flag repeat use') && (
            <SettingRow key="repeat" id="ea-repeatN" label="Flag repeat use" help="Reviewers see a flag when one person uses emergency access this often." dirty={isDirty(m, 'ea', 'repeatN') || isDirty(m, 'ea', 'repeatDays')} error={ctx.errs.repeatN || ctx.errs.repeatDays}
                control={<span className="inline-flex flex-wrap items-center gap-2"><NumberInput id="ea-repeatN" label="Number of grants" value={d.repeatN} unit="grants within" max={100} disabled={dis} error={ctx.errs.repeatN} onChange={(v) => { edit('ea', 'repeatN', v); ctx.clearErr('repeatN'); }} /><NumberInput id="ea-repeatDays" label="Number of days" value={d.repeatDays} unit="days" max={90} disabled={dis} error={ctx.errs.repeatDays} onChange={(v) => { edit('ea', 'repeatDays', v); ctx.clearErr('repeatDays'); }} /></span>}
                meta={<Reviewed by={reviewedBy(m, 'ea', 'repeatN')} />} />),
    ].filter(Boolean);
    return (
        <div className="space-y-5">
            <Section id="sc-ea" title="Emergency access policy" caption="Changed by admins and provider managers" right={<EntityChip icon={Building2}>Every house</EntityChip>}>
                <Note>Emergency access lets someone with the emergency access permission record for one person they aren’t rostered for, for a short time. <b>A grant covers one person — never a whole house or round</b> — and ends by itself.</Note>
                {rows.length ? rows : <NoMatches ctx={ctx} />}
            </Section>
            <Section id="sc-p10" title="To build with P10" caption="Approved for P10 on 29 September 2026" right={<StatusBadge variant="info" size="sm">Approved for P10</StatusBadge>}>
                <Card className="p-4"><KV rows={[
                    ['Durations offered', 'Only up to the longest grant — today the request always offers 30 minutes, 1, 2 and 4 hours'],
                    ['A second person confirms', 'A setting: optional or required'],
                    ['Review due within', 'A setting, with overdue reviews followed up'],
                    ['Reviewer', 'Someone other than the person who used it; reviews kept as history'],
                    ['Policy changes', 'Recorded in Change history and the audit log (not recorded today)'],
                ]} /></Card>
            </Section>
        </div>
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
        <Section id="sc-decide" title="Still to decide" caption={`${reg.length} settings nobody has deliberately chosen`}>
            <p className="text-subtle">“Not configured” means screens give no value. “Default — not yet reviewed” means today’s behaviour carries on until someone saves a choice — or confirms it with “Keep today’s value” in the ⋯ menu.</p>
            {reg.length ? (
                <EntityTable<PendingRow>
                    rows={reg} rowKey={(r) => `${r.view}-${r.label}`} identityLabel="Setting" identityWidth="3fr" minWidth={1000} rowHeight="content"
                    identity={(r) => ({ icon: HelpCircle, name: r.label, subline: `${r.scope} · decision ${r.dec}` })}
                    columns={[
                        { key: 'where', label: 'Where', width: '1.2fr', cell: (r) => <span className="py-2 text-[12.5px]">{VIEW_LABEL[r.view]} › {SET_VIEWS[r.view].secs.find(([k]) => k === r.sec)![1]}</span> },
                        { key: 'state', label: 'State', width: '1fr', cell: (r) => (r.state === 'nc' ? <NotConfigured /> : <Reviewed by={null} />) },
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
    const actions = (h: HistRow): MenuItem[] => [{ label: 'View before and after', icon: Eye, onClick: () => open({ kind: 'hist', arg: h.id }) }, { label: 'Go to the setting', icon: ArrowUpRight, onClick: () => go(settingsHref(h.area, h.sec)) }];
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

const SECTION: Record<string, (ctx: Ctx) => ReactNode> = {
    'rules/medicines': (c) => <MedicineRules ctx={c} />, 'rules/safety': (c) => <SafetyChecks ctx={c} />, 'rules/controlled': (c) => <ControlledDrugs ctx={c} />, 'rules/photos': (c) => <MedicinePhotos ctx={c} />,
    'rounds/templates': (c) => <RoundTemplates ctx={c} />, 'rounds/timing': (c) => <DoseTiming ctx={c} />,
    'staff/competency': (c) => <Competency ctx={c} />, 'staff/exemptions': (c) => <Exemptions ctx={c} />, 'staff/pins': (c) => <WitnessPins ctx={c} />, 'staff/status': (c) => <PinStatus ctx={c} />,
    'alerts/oncall': (c) => <OnCallContacts ctx={c} />, 'alerts/recipients': (c) => <Recipients ctx={c} />, 'alerts/emergency': (c) => <EmergencyAccess ctx={c} />,
    'history/decide': (c) => <StillToDecide ctx={c} />, 'history/changes': (c) => <AllChanges ctx={c} />,
};
export { ReviewCard, ReviewRow, InfoCard };
void useMemo;
