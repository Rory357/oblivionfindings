/* P11 v4 — Medication settings and staff eligibility. Synthetic design preview built on the
 * app’s real components (Fleet preview method). No application API is called. */
import { EventHorizonWordmark } from '@/components/event-horizon-wordmark';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
    Activity, Bell, Building2, ChevronDown, ClipboardList, FileText, Home, LockKeyhole, MessageSquare, Package, PanelLeftClose, PanelLeftOpen, Pill, Search, Settings2, Shield, BarChart3, Users,
} from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { PERSONAS, TODAY_LABEL, type HouseKey, type PersonaId } from './data';
import { AssessmentView, AssessmentWizard, Acknowledge, EndExemption, ExemptionWizard, MyEligibility } from './dialogs-elig';
import {
    AlertPerson, AlertWho, CreateRounds, KeepDefault, DiscardView, HistDetail, LeaveGuard, NotFound, OnCallDialog, OnCallRemove, OnCallView, PinReset, ReviewChanges, RuleHistory, RuleToggle, RuleView, RuleWizard,
    TemplateToggle, TemplateView, TemplateWizard, TimeCritical, UnsavedList,
} from './dialogs-settings';
import { AlertLogView, ConsentView, ReachDetail, RestoreValue, ReviewDefaults } from './dialogs-v4';
import { SafetyPage } from './eligibility';
import { allChanges, canSeeRegister, canSeeSettings, discardDrafts, has, initialModel, leadCap, mutate, StoreProvider, type Model, type MyScenario, type ViewKey } from './model';
import { NavCtx, parseRoute, type Dlg, type Route } from './nav';
import { SettingsPage } from './settings';
import './styles.css';
import { GuidePage, MedsTodayPage } from './today';

/* Query set-ups for screenshots and review links (see the Preview guide). */
function applyQuery(m0: Model, q: URLSearchParams): Model {
    if (![...q.keys()].length) return m0;
    return mutate(m0, (d) => {
        const as = q.get('as') as PersonaId | null; if (as && PERSONAS[as]) { if (as !== d.persona) discardDrafts(d); d.persona = as; }
        const my = q.get('my'); if (my) d.my = my as MyScenario;
        const sd = q.get('sd'); if (sd) d.demo.settings = sd as Model['demo']['settings'];
        const ed = q.get('ed'); if (ed) d.demo.elig = ed as Model['demo']['elig'];
        if (q.get('rules')) d.demo.rules = q.get('rules') as 'empty';
        if (q.get('tpl')) d.demo.tpl = q.get('tpl') as 'empty';
        if (q.get('save')) d.demo.save = q.get('save') as 'fail';
        if (q.get('draft') === '1') { d.draft.safety.phoneRx = 'leads'; d.draft.timing.late = '45'; d.draft.pin.confirmLimit = '20'; }
        if (q.get('draft') === 'bad') { d.draft.timing.late = '0'; d.draft.timing.escalDays = ''; }
        if (q.get('oncall') === '1' && !d.oncall.kowhai) d.oncall.kowhai = { mode: 'roster', teamLead: true, person: 'hana', by: 'Jordan Tipene', when: '29 Sep 2026' };
        // A manager part-way through setting follow-up (a draft, not saved) — for the preview and screenshots.
        if (q.get('dlv') === '1') Object.assign(d.draft.delivery, { realertEvery: '30', realertMax: '3', escalateAfter: '60', escalateTo: ['houseLead', 'onCall'], quietFrom: '21:00', quietUntil: '07:00' });
        if (q.get('ack')) d.myAck = q.get('ack') === '1';
        if (q.get('exdemo') === '1' && !d.exemptions.length) d.exemptions.push({ id: 'x1', who: 'aisha', house: 'kowhai', reason: 'Renewal booked for 3 October — the assessor is on leave until then', from: '29 Sep 2026', until: '3 Oct 2026', by: 'Hana Kereama', at: '29 Sep 2026 8:40 am', status: 'active' });
    });
}
const parseOpen = (q: URLSearchParams): Dlg => { const o = q.get('open'); if (!o) return null; const [kind, arg, step] = o.split(':'); return { kind, arg, step, extra: Object.fromEntries(q) }; };

const HUBS: { id: string; label: string; icon: typeof Pill; href?: string; show: (p: PersonaId) => boolean }[] = [
    { id: 'today', label: 'Meds today', icon: Pill, href: '#/today', show: (p) => has(p, 'administer') || leadCap(p) },
    { id: 'mar', label: 'MAR & medicines', icon: ClipboardList, show: (p) => leadCap(p) || has(p, 'audit.view') },
    { id: 'orders', label: 'Orders & reviews', icon: FileText, show: (p) => leadCap(p) },
    { id: 'stock', label: 'Stock & controlled drugs', icon: Package, show: (p) => has(p, 'stock.update') || (has(p, 'cd.view') && leadCap(p)) },
    { id: 'safety', label: 'Safety & oversight', icon: Shield, href: '#/safety/eligibility', show: (p) => leadCap(p) || has(p, 'audit.view') },
    { id: 'reports', label: 'Reports & audit', icon: BarChart3, show: (p) => has(p, 'reports.export') || has(p, 'audit.view') },
];

function App() {
    const first = parseRoute(location.hash || '#/settings/rules');
    const [m, setM] = useState<Model>(() => applyQuery(initialModel(), first.q));
    const [route, setRoute] = useState<Route>(first);
    const [dialog, setDialog] = useState<Dlg>(() => parseOpen(first.q));
    const [flashMsg, setFlash] = useState<string | null>(null);
    const [leaveTo, setLeaveTo] = useState<string | null>(null);
    const [collapsed, setCollapsed] = useState(false), [dark, setDark] = useState(false);
    const mRef = useRef(m); mRef.current = m;
    const routeRef = useRef(route); routeRef.current = route;
    const prevHash = useRef(location.hash || '#/settings/rules'), bypass = useRef(false);
    useEffect(() => { if (!location.hash) history.replaceState(null, '', '#/settings/rules'); }, []);
    useEffect(() => {
        const onHash = () => {
            const next = parseRoute(location.hash);
            // Fleet’s leave guard: leaving Settings with an unsaved draft asks first.
            if (!bypass.current && routeRef.current.page === 'settings' && next.page !== 'settings' && allChanges(mRef.current).length && !next.q.get('as')) {
                const target = location.hash;
                history.replaceState(null, '', prevHash.current);
                setLeaveTo(target); setDialog({ kind: 'guard' });
                return;
            }
            bypass.current = false;
            prevHash.current = location.hash;
            setM((cur) => applyQuery(cur, next.q));
            setRoute(next); setFlash(null); setDialog(parseOpen(next.q));
            window.scrollTo(0, 0);
        };
        const beforeUnload = (e: BeforeUnloadEvent) => { if (routeRef.current.page === 'settings' && allChanges(mRef.current).length) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('hashchange', onHash);
        window.addEventListener('beforeunload', beforeUnload);
        return () => { window.removeEventListener('hashchange', onHash); window.removeEventListener('beforeunload', beforeUnload); };
    }, []);
    const set = useCallback((fn: (d: Model) => void) => setM((cur) => mutate(cur, fn)), []);
    const go = useCallback((href: string) => { setDialog(null); if (location.hash === href) { setRoute(parseRoute(href)); return; } location.hash = href; }, []);
    const nav = { route, go, open: setDialog, close: () => setDialog(null), dialog };
    const store = { m, set, flash: (msg: string) => setFlash(msg), flashMsg };
    const p = m.persona;
    const hub = route.page === 'settings' ? 'Settings' : route.page === 'safety' ? 'Safety & oversight' : route.page === 'today' ? 'Meds today' : route.page === 'guide' ? 'Preview guide' : decodeURIComponent(route.view || 'Outside this preview');
    const firstHub = HUBS.find((h) => h.show(p));
    let page: React.ReactNode;
    if (route.page === 'settings') page = canSeeSettings(p) ? <SettingsPage key={`${p}`} /> : <NoAccess title="Settings" who="Medication settings are for people who manage medication settings, and auditors (read-only). Ask Hana Kereama, clinical lead, if you need access." />;
    else if (route.page === 'safety') page = (leadCap(p) || has(p, 'audit.view')) && (route.view !== 'eligibility' || canSeeRegister(p)) ? <SafetyPage key={p} /> : <NoAccess title="Staff eligibility" who={has(p, 'administer') ? 'The register is for leads (decided 29 Sep 2026). Your own status is in Meds today › My eligibility.' : 'The register is for leads. Auditors see settings changes in Settings › Change history.'} action={has(p, 'administer') ? <Button size="sm" onClick={() => { go('#/today'); setTimeout(() => setDialog({ kind: 'me' }), 0); }}>Open My eligibility</Button> : undefined} />;
    else if (route.page === 'today') page = has(p, 'administer') || leadCap(p) ? <MedsTodayPage /> : <NoAccess title="Meds today" who="Meds today is for people who record or oversee doses." />;
    else if (route.page === 'guide') page = <GuidePage />;
    else page = <EmptyState icon={Building2} title={`${hub} is outside this preview`} description="This preview covers Medication › Settings, Safety & oversight › Staff eligibility, and the My eligibility block on Meds today." action={<Button variant="outline" size="sm" onClick={() => go('#/settings/rules')}>Back to Settings</Button>} />;
    function NoAccess({ title, who, action }: { title: string; who: string; action?: React.ReactNode }) {
        return <EmptyState icon={LockKeyhole} title={`You don’t have access to ${title}`} description={who} action={action ?? (firstHub ? <Button variant="outline" size="sm" onClick={() => go(firstHub.href ?? `#/outside/${encodeURIComponent(firstHub.label)}`)}>Go to {firstHub.label}</Button> : undefined)} />;
    }
    return (
        <StoreProvider value={store}>
            <NavCtx.Provider value={nav}>
                <TooltipProvider>
                    <div className="preview-bar" role="region" aria-label="Preview controls">
                        <strong>P11 · v4</strong>
                        <span className="preview-label">Synthetic design · nothing is sent · 29 Sep 2026, 9:12 am NZDT</span>
                        <label>Signed in as <select aria-label="Signed in as" value={p} onChange={(e) => { set((d) => { discardDrafts(d); d.persona = e.target.value as PersonaId; }); setDialog(null); }}>{Object.values(PERSONAS).map((x) => <option key={x.id} value={x.id}>{x.role} — {x.name}</option>)}</select></label>
                        {route.page === 'settings' ? <label>Settings data <select aria-label="Settings data" value={m.demo.settings} onChange={(e) => set((d) => { d.demo.settings = e.target.value as Model['demo']['settings']; })}>{[['loaded', 'Loaded'], ['loading', 'Loading'], ['error', 'Couldn’t load'], ['stale', 'Out of date'], ['offline', 'Offline'], ['first', 'First use — nothing saved yet']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label> : null}
                        {route.page === 'safety' ? <label>Register data <select aria-label="Register data" value={m.demo.elig} onChange={(e) => set((d) => { d.demo.elig = e.target.value as Model['demo']['elig']; })}>{[['loaded', 'Loaded'], ['loading', 'Loading'], ['error', 'Couldn’t load'], ['stale', 'Out of date'], ['empty', 'Nobody assessed yet']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label> : null}
                        {route.page === 'settings' ? <label>Next save <select aria-label="Next save" value={m.demo.save} onChange={(e) => set((d) => { d.demo.save = e.target.value as Model['demo']['save']; })}><option value="ok">Works</option><option value="fail">Fails once</option><option value="conflict">Someone else saved first</option></select></label> : null}
                        {route.page === 'today' || p === 'sw' ? <label>My eligibility <select aria-label="My eligibility scenario" value={m.my} onChange={(e) => set((d) => { d.my = e.target.value as MyScenario; d.myAck = false; })}>{[['current', 'Current'], ['due', 'Renewal due'], ['expired', 'Expired'], ['restricted', 'Restricted'], ['exempt', 'Exemption'], ['ack', 'New assessment to acknowledge'], ['none', 'Not assessed yet']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label> : null}
                        <a href="#/guide" className="ml-auto">Preview guide</a>
                        <Button variant="outline" size="sm" onClick={() => { setDark(!dark); document.documentElement.classList.toggle('dark'); }}>{dark ? 'Light' : 'Dark'} theme</Button>
                    </div>
                    <header className="chrome">
                        <div className="wordmark"><EventHorizonWordmark /></div>
                        <span className="chrome-search" aria-label="Search (outside this preview)"><Search size={16} />Search Oblivion Care <kbd>Ctrl K</kbd></span>
                        <div className="chrome-right">
                            <span>{TODAY_LABEL}</span>
                            <button aria-label="Messages (outside this preview)" onClick={() => go('#/outside/Messages')}><MessageSquare size={18} /></button>
                            <button aria-label="Notifications (outside this preview)" onClick={() => go('#/outside/Notifications')}><Bell size={18} /></button>
                            <span className="avatar" title={PERSONAS[p].name}>{PERSONAS[p].initials}</span>
                        </div>
                    </header>
                    <div className={`workspace ${collapsed ? 'collapsed' : ''}`}>
                        <aside className="sidebar">
                            <nav aria-label="Main navigation">
                                {([[Home, 'Home'], [Activity, 'My day'], [Users, 'Clients'], [Building2, 'Sites']] as const).map(([Icon, label]) => (
                                    <button key={label} onClick={() => go(`#/outside/${encodeURIComponent(label)}`)}><Icon size={18} /><span>{label}</span></button>
                                ))}
                                <div className="module-label"><Pill size={18} /><span>Medication</span><ChevronDown size={14} /></div>
                                {HUBS.filter((h) => h.show(p)).map((h) => {
                                    const on = (h.id === 'today' && route.page === 'today') || (h.id === 'safety' && route.page === 'safety');
                                    return <button key={h.id} className={on ? 'selected' : ''} aria-current={on ? 'page' : undefined} onClick={() => go(h.href ?? `#/outside/${encodeURIComponent(h.label)}`)}><span className="nav-dot" /><span>{h.label}</span></button>;
                                })}
                                {canSeeSettings(p) ? <button className={`settings-link ${route.page === 'settings' ? 'selected' : ''}`} aria-current={route.page === 'settings' ? 'page' : undefined} onClick={() => go('#/settings/rules')}><Settings2 size={18} /><span>Settings</span></button> : null}
                            </nav>
                            <button className="sidebar-toggle" aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} onClick={() => setCollapsed(!collapsed)}>{collapsed ? <PanelLeftOpen size={17} /> : <PanelLeftClose size={17} />}</button>
                        </aside>
                        <main className="main" id="main">
                            <nav className="breadcrumbs" aria-label="Breadcrumb">
                                <button onClick={() => go('#/outside/Home')}>Home</button><span aria-hidden="true">›</span>
                                <button onClick={() => go(firstHub?.href ?? '#/settings/rules')}>Medication</button><span aria-hidden="true">›</span>
                                <span aria-current="page">{hub}</span>
                            </nav>
                            {page}
                        </main>
                    </div>
                    <DialogHost dialog={dialog} leaveTo={leaveTo} onLeave={() => { set((d) => discardDrafts(d)); bypass.current = true; setDialog(null); const t = leaveTo ?? '#/today'; setLeaveTo(null); location.hash = t; }} />
                </TooltipProvider>
            </NavCtx.Provider>
        </StoreProvider>
    );
}

function DialogHost({ dialog, onLeave }: { dialog: Dlg; leaveTo: string | null; onLeave: () => void }) {
    if (!dialog) return null;
    const { kind, arg = '', step, extra } = dialog;
    const k = JSON.stringify(dialog);
    switch (kind) {
        case 'review': return <ReviewChanges key={k} view={(arg || 'rules') as ViewKey} />;
        case 'discard': return <DiscardView key={k} view={(arg || 'rules') as ViewKey} />;
        case 'unsaved': return <UnsavedList key={k} />;
        case 'guard': return <LeaveGuard key={k} onLeave={onLeave} />;
        case 'hist': return <HistDetail key={k} id={arg} />;
        case 'rule': return <RuleWizard key={k} id={arg || 'new'} />;
        case 'ruleview': return <RuleView key={k} id={arg} />;
        case 'ruletoggle': return <RuleToggle key={k} id={arg} />;
        case 'rulehistory': return <RuleHistory key={k} id={arg} />;
        case 'tpl': return <TemplateWizard key={k} id={arg || 'new'} seed={extra} />;
        case 'tplview': return <TemplateView key={k} id={arg} />;
        case 'tpltoggle': return <TemplateToggle key={k} id={arg} />;
        case 'tplretire': return <TemplateToggle key={k} id={arg} retire />;
        case 'gen': return <CreateRounds key={k} />;
        case 'keepdefault': return <KeepDefault key={k} spec={arg} />;
        case 'reviewdefaults': return <ReviewDefaults key={k} />;
        case 'restore': return <RestoreValue key={k} id={arg} />;
        case 'alertlog': return <AlertLogView key={k} id={arg} />;
        case 'reach': return <ReachDetail key={k} id={arg} />;
        case 'consent': return <ConsentView key={k} />;
        case 'alertwho': return <AlertWho key={k} k={arg} />;
        case 'alertperson': return <AlertPerson key={k} k={arg} scope={step || 'org'} />;
        case 'oncall': return <OnCallDialog key={k} h={(arg || 'kowhai') as HouseKey} seed={extra} />;
        case 'oncallview': return <OnCallView key={k} h={(arg || 'kowhai') as HouseKey} />;
        case 'oncallremove': return <OnCallRemove key={k} h={(arg || 'kowhai') as HouseKey} />;
        case 'crit': return <TimeCritical key={k} />;
        case 'pinreset': return <PinReset key={k} name={arg} />;
        case 'av': return <AssessmentView key={k} id={arg} />;
        case 'aw': return <AssessmentWizard key={k} who={arg === 'new' ? undefined : arg} mode={step || 'new'} seed={extra} />;
        case 'xw': return <ExemptionWizard key={k} who={arg === 'new' ? undefined : arg} seed={extra} />;
        case 'xend': return <EndExemption key={k} id={arg} />;
        case 'me': return <MyEligibility key={k} />;
        case 'ack': return <Acknowledge key={k} err0={step === 'err'} />;
        default: return <NotFound key={k} />;
    }
}

createRoot(document.getElementById('root')!).render(<App />);
