import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Pagination } from '@/components/ui/pagination';
import { TooltipProvider } from '@/components/ui/tooltip';
import { App as InertiaApp, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    ChartNoAxesCombined,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock,
    Columns3,
    FileText,
    Globe,
    HeartHandshake,
    Home,
    Inbox,
    LayoutGrid,
    Link,
    List,
    LockKeyhole,
    MessageSquare,
    Moon,
    Phone,
    Plus,
    Search,
    Settings2,
    ShieldCheck,
    Sun,
    Users,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './brand.css';
import { CaseOverview } from './case-design';
import { ReferralCollection, WorkCollection } from './collections';
import { PrototypeContext, usePrototype } from './context';
import {
    TODAY,
    activationBlocks,
    conversionBlocks,
    coverage,
    date,
    makeFunding,
    makeReferral,
    money,
    pathways,
    permissions,
    scopeDefaults,
    seed,
    staff,
} from './data';
import { EvidenceAction, EvidenceWorkspace } from './evidence-workflows';
import {
    FundingValidity,
    ProposalAction,
    ProposalDetails,
    ReassessmentAction,
    fundingTermFields,
} from './funding-workflows';
import {
    HandoverAction,
    HandoverReceipt,
    LifecycleAction,
    MatchReviewAction,
} from './handover-workflows';
import {
    actorFor,
    allowedSites,
    businessDate,
    canReadRecord,
    clone,
    decisionChanged,
    displayStamp,
    eligibleOwners,
    followupsFor,
    fundingIssues,
    knownNumber,
    normalise,
    openInWorkspace,
    serviceStartUpdate,
    stamp,
} from './model';
import './prototype.css';
import { PublicView } from './public';
import { ContactsPanel, IntakeReview, RecordAction } from './record-workflows';
import { ReferralCalendar } from './referral-calendar';
import { csvCell, reportCsv } from './report-export';
import { reportingScenario } from './report-scenario';
import { OperationalReports } from './reporting';
import { SettingsView } from './settings';
import {
    Badge,
    Button,
    Checkbox,
    Empty,
    Field,
    Input,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
    Section,
    Select,
    Wizard,
    download,
} from './ui';
import { AppointmentAction, WorkAction, WorkPanel } from './workflows';

const STORAGE = 'oblivion-referrals-prototype-v1';
function load() {
    try {
        const parsed = JSON.parse(localStorage.getItem(STORAGE));
        const defaults = seed();
        return normalise(parsed?.records ? parsed : defaults, defaults);
    } catch {
        const defaults = seed();
        return normalise(defaults, defaults);
    }
}
const railItems = [
    { key: 'Referrals', label: 'Referrals', icon: Inbox },
    { key: 'Funding assistance', label: 'Funding assistance', icon: Wallet },
    { key: 'Follow-ups', label: 'Follow-ups', icon: CalendarDays },
    { key: 'Calendar', label: 'Calendar', icon: CalendarDays },
];
const readWorkspace = () => {
    try {
        return JSON.parse(
            sessionStorage.getItem('referrals-workspace') || '{}',
        );
    } catch {
        return {};
    }
};
function App() {
    const initialWorkspace = useMemo(readWorkspace, []);
    const [phoneLayout, setPhoneLayout] = useState(
        () => matchMedia('(max-width:640px)').matches,
    );
    useEffect(() => {
        const media = matchMedia('(max-width:640px)'),
            change = () => setPhoneLayout(media.matches);
        media.addEventListener('change', change);
        return () => media.removeEventListener('change', change);
    }, []);
    const [data, setData] = useState(load),
        [role, setRole] = useState('Referral manager'),
        [route, setRoute] = useState(
            () =>
                new URLSearchParams(location.hash.slice(1)).get('route') ||
                initialWorkspace.route ||
                'Referrals',
        ),
        [selected, setSelected] = useState(() =>
            new URLSearchParams(location.hash.slice(1)).get('referral'),
        ),
        [tab, setTab] = useState(
            () =>
                new URLSearchParams(location.hash.slice(1)).get('section') ||
                'Overview',
        ),
        [query, setQuery] = useState(initialWorkspace.query || ''),
        [view, setView] = useState(initialWorkspace.view || 'List'),
        [site, setSite] = useState(initialWorkspace.site || 'all'),
        [filter, setFilter] = useState(initialWorkspace.filter || 'All open'),
        [advanced, setAdvanced] = useState(
            initialWorkspace.advanced || {
                owner: 'All owners',
                source: 'All sources',
                path: 'All pathways',
                fundingStatus: 'All funding states',
                priority: 'All priorities',
                sort: 'Due soonest',
                from: '',
                to: '',
            },
        ),
        [page, setPage] = useState(initialWorkspace.page || 1),
        [returnQueue, setReturnQueue] = useState(
            initialWorkspace.returnQueue || { route: 'Referrals' },
        ),
        [modal, setModal] = useState(null),
        [toast, setToast] = useState(''),
        [dark, setDark] = useState(false),
        [collapsed, setCollapsed] = useState(false),
        [failure, setFailure] = useState('Normal'),
        [settingsDirty, setSettingsDirty] = useState(false),
        [wizardDirty, setWizardDirty] = useState(false),
        [pendingNav, setPendingNav] = useState(null),
        [persistError, setPersistError] = useState(false);
    const historyIndex = useRef(Number(history.state?.referralIndex || 0));
    const restoringHistory = useRef(false);
    const allowHistory = useRef(false);
    const dirtyNavigation = useRef(false);
    dirtyNavigation.current = settingsDirty || wizardDirty;
    const access = data.roles[role],
        can = (p) =>
            !!access[p] &&
            ([
                'settings',
                'settingsDraft',
                'governance',
                'create',
                'view',
            ].includes(p) ||
                !!access.view) &&
            (!['funding', 'verify'].includes(p) || !!access.fundingRead);
    const scope = data.config.scopeRules?.[role] || scopeDefaults[role];
    const inScope = (r) => canReadRecord(data, role, r);
    const scoped = data.records.filter(inScope),
        visiblePeople = data.people.filter(
            (person) =>
                can('personRead') &&
                (!person.restricted || can('restricted')) &&
                allowedSites(data, role).includes(person.site) &&
                scoped.some(
                    (referral) =>
                        referral.personId === person.id ||
                        referral.existingPerson === person.id,
                ),
        ),
        current = data.records.find((r) => r.id === selected),
        record = current && inScope(current) ? current : null;
    useEffect(() => {
        try {
            localStorage.setItem(STORAGE, JSON.stringify(data));
            setPersistError(false);
        } catch {
            setPersistError(true);
        }
    }, [data]);
    useEffect(() => {
        document.documentElement.classList.toggle('dark', dark);
    }, [dark]);
    useEffect(() => {
        if (!toast) return;
        const id = setTimeout(() => setToast(''), 5000);
        return () => clearTimeout(id);
    }, [toast]);
    useEffect(() => {
        const fn = (e) => {
            if (settingsDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', fn);
        return () => window.removeEventListener('beforeunload', fn);
    }, [settingsDirty]);
    const notify = (text) => setToast(text);
    const audit = (state, text) => ({
        ...state,
        audit: [
            {
                at: stamp(),
                by: actorFor(state, role),
                kind: /^(Published settings|Migrated|Settings |Changed permission)/.test(
                    text,
                )
                    ? 'configuration'
                    : 'record',
                text,
            },
            ...state.audit,
        ],
    });
    const update = (id, patch, text) => {
        setData((s) =>
            audit(
                {
                    ...s,
                    records: s.records.map((r) =>
                        r.id === id
                            ? {
                                  ...r,
                                  ...(typeof patch === 'function'
                                      ? patch(r)
                                      : patch),
                                  ...(typeof patch === 'object' &&
                                  patch.next !== undefined &&
                                  patch.nextDone === undefined
                                      ? { nextDone: false }
                                      : {}),
                                  ...(typeof patch === 'object' &&
                                  patch.stage &&
                                  patch.stage !== r.stage
                                      ? { stageAt: stamp() }
                                      : {}),
                                  activity: [
                                      {
                                          at: stamp(),
                                          by: actorFor(s, role),
                                          text: text.startsWith(
                                              'Assessment reviewed:',
                                          )
                                              ? 'Assessment and readiness updated'
                                              : text,
                                      },
                                      ...r.activity,
                                  ],
                              }
                            : r,
                    ),
                },
                text + ' · ' + id,
            ),
        );
        notify('Saved in prototype');
    };
    const navigate = (next, id = null, section = 'Overview') => {
        const move = () => {
            if (id && !selected)
                setReturnQueue({
                    route,
                    query,
                    filter,
                    view,
                    site,
                    advanced,
                    page,
                    scroll: window.scrollY,
                });
            setRoute(next);
            setSelected(id);
            setTab(section);
            setModal(null);
            history.pushState(
                { referralIndex: ++historyIndex.current },
                '',
                location.pathname +
                    location.search +
                    '#' +
                    new URLSearchParams({
                        route: next,
                        ...(id ? { referral: id, section } : {}),
                    }),
            );
            if (['Funding assistance', 'Follow-ups', 'Reports'].includes(next))
                setFilter('All records');
            window.scrollTo(0, 0);
        };
        if (settingsDirty || wizardDirty) {
            setPendingNav(() => move);
        } else move();
    };
    useEffect(() => {
        sessionStorage.setItem(
            'referrals-workspace',
            JSON.stringify({
                route,
                query,
                view,
                site,
                filter,
                advanced,
                returnQueue,
                page,
            }),
        );
    }, [route, query, view, site, filter, advanced, returnQueue, page]);
    useEffect(() => {
        setPage(1);
    }, [query, site, filter, advanced]);
    useEffect(() => {
        history.replaceState(
            { ...history.state, referralIndex: historyIndex.current },
            '',
            location.href,
        );
        const onPop = (event) => {
            // The standalone prototype owns hash history. Do not let Inertia
            // remount an unfinished form while the discard guard is open.
            event.stopImmediatePropagation();
            if (restoringHistory.current) {
                restoringHistory.current = false;
                return;
            }
            const destination = Number(
                event.state?.referralIndex ?? historyIndex.current - 1,
            );
            const delta = destination - historyIndex.current;
            if (dirtyNavigation.current && !allowHistory.current && delta) {
                restoringHistory.current = true;
                history.go(-delta);
                setPendingNav(() => () => {
                    allowHistory.current = true;
                    history.go(delta);
                });
                return;
            }
            allowHistory.current = false;
            historyIndex.current = destination;
            const p = new URLSearchParams(location.hash.slice(1));
            setRoute(p.get('route') || 'Referrals');
            setSelected(p.get('referral'));
            setTab(p.get('section') || 'Overview');
            setModal(null);
        };
        window.addEventListener('popstate', onPop, true);
        return () => window.removeEventListener('popstate', onPop, true);
    }, []);
    useEffect(() => {
        if (selected)
            history.replaceState(
                { ...history.state, referralIndex: historyIndex.current },
                '',
                location.pathname +
                    location.search +
                    '#' +
                    new URLSearchParams({
                        route,
                        referral: selected,
                        section: tab,
                    }),
            );
    }, [tab, selected]);
    const open = (r) => navigate('Referrals', r.id);
    useEffect(
        () =>
            router.on('before', (event) => {
                event.preventDefault();
                // Inertia attempts to replace unknown hash-history entries.
                // Our history guard owns those entries; a same-URL replacement
                // must not turn into a new navigation or close the open form.
                if (
                    restoringHistory.current ||
                    (event.detail.visit.replace &&
                        event.detail.visit.url.href === location.href)
                )
                    return;
                const hash = event.detail.visit.url.hash;
                const destination = new URLSearchParams(hash.slice(1));
                if (destination.has('referral'))
                    navigate(
                        'Referrals',
                        destination.get('referral'),
                        destination.get('section') || 'Overview',
                    );
                else if (hash === '#referral-follow-ups') {
                    navigate('Follow-ups');
                    setFilter('Mine');
                }
            }),
        [
            settingsDirty,
            wizardDirty,
            selected,
            route,
            query,
            filter,
            view,
            site,
            advanced,
            page,
        ],
    );
    const request = (type, r = record, extra = {}) =>
        setModal({ type, r, ...extra });
    const newIntake = (draft) => request('intake', null, { draft });
    useEffect(() => {
        const handle = (e) => {
            if (
                (e.ctrlKey || e.metaKey) &&
                e.key.toLowerCase() === 'k' &&
                !modal &&
                !settingsDirty &&
                route !== 'Public website'
            ) {
                e.preventDefault();
                setModal({ type: 'find', r: null });
            }
        };
        window.addEventListener('keydown', handle);
        return () => window.removeEventListener('keydown', handle);
    }, [modal, settingsDirty, route]);

    const filtered = scoped.filter(
        (r) =>
            (site === 'all' || r.site === site) &&
            (advanced.owner === 'All owners' ||
                r.owner === advanced.owner ||
                (['Follow-ups', 'Funding assistance'].includes(route) &&
                    followupsFor(r).some((w) => w.owner === advanced.owner))) &&
            (!advanced.team ||
                advanced.team === 'All teams' ||
                r.team === advanced.team) &&
            (!advanced.service ||
                advanced.service === 'All services' ||
                r.service === advanced.service) &&
            (!advanced.channel ||
                advanced.channel === 'All channels' ||
                r.channel === advanced.channel ||
                r.preferredContact === advanced.channel) &&
            (!advanced.holdReason ||
                advanced.holdReason === 'All hold reasons' ||
                r.holdReason === advanced.holdReason) &&
            (advanced.source === 'All sources' ||
                r.source === advanced.source) &&
            (advanced.priority === 'All priorities' ||
                r.priority === advanced.priority) &&
            (advanced.path === 'All pathways' ||
                (can('fundingRead') &&
                    r.funding.some((f) => f.path === advanced.path))) &&
            (advanced.fundingStatus === 'All funding states' ||
                (can('fundingRead') &&
                    r.funding.some(
                        (f) => f.status === advanced.fundingStatus,
                    ))) &&
            (!advanced.from || r.createdAt.slice(0, 10) >= advanced.from) &&
            (!advanced.to || r.createdAt.slice(0, 10) <= advanced.to) &&
            `${r.name} ${r.id} ${r.owner} ${r.phone} ${r.email || ''} ${r.referrerRecord?.name || ''} ${r.tasks.map((t) => t.label).join(' ')} ${r.next} ${can('fundingRead') ? r.funding.map((f) => [f.reference, f.path, f.next].filter(Boolean).join(' ')).join(' ') : ''}`
                .toLowerCase()
                .includes(query.toLowerCase()) &&
            (filter === 'All records' || filter === 'All open'
                ? openInWorkspace(r, route) || filter === 'All records'
                : filter === 'Unassigned'
                  ? r.owner === 'Intake team'
                  : filter === 'Overdue'
                    ? openInWorkspace(r, route) &&
                      (['Follow-ups', 'Funding assistance'].includes(route)
                          ? followupsFor(r).some(
                                (w) =>
                                    (!w.financial || can('fundingRead')) &&
                                    w.due < TODAY,
                            )
                          : !r.nextDone && r.due < TODAY)
                    : filter === 'Mine'
                      ? ['Follow-ups', 'Funding assistance'].includes(route)
                          ? followupsFor(r).some(
                                (w) =>
                                    (!w.financial || can('fundingRead')) &&
                                    w.owner === scope.person,
                            )
                          : r.owner === scope.person
                      : r.stage === filter),
    );
    filtered.sort((a, b) =>
        advanced.sort === 'Name A–Z'
            ? a.name.localeCompare(b.name)
            : advanced.sort === 'Newest first'
              ? b.createdAt.localeCompare(a.createdAt)
              : a.due.localeCompare(b.due),
    );
    const returnToQueue = () => {
        navigate(returnQueue.route || 'Referrals');
        setQuery(returnQueue.query || '');
        setFilter(returnQueue.filter || 'All open');
        setView(returnQueue.view || 'List');
        setSite(returnQueue.site || 'all');
        if (returnQueue.advanced) setAdvanced(returnQueue.advanced);
        setPage(returnQueue.page || 1);
        requestAnimationFrame(() =>
            window.scrollTo(0, returnQueue.scroll || 0),
        );
    };
    const api = {
        data,
        setData,
        role,
        can,
        scoped,
        visiblePeople,
        record,
        update,
        notify,
        navigate,
        request,
        newIntake,
        failure,
        setFailure,
        audit,
        setSettingsDirty,
        setWizardDirty,
        actor: actorFor(data, role),
        scope,
        filter,
        advanced,
        phoneLayout,
        eligibleSites: allowedSites(data, role),
        returnToQueue,
        returnQueue,
        query,
    };
    const actionsFor = (r) => [
        { label: 'Open referral', icon: ArrowUpRight, onClick: () => open(r) },
        {
            label: 'Assign owner',
            icon: Users,
            onClick: () => request('assign', r),
            disabled: can('assign') ? undefined : 'Requires Assign referrals',
        },
        {
            label: 'Log contact',
            icon: Phone,
            onClick: () => request('contact', r),
            disabled: can('contact') ? undefined : 'Requires Log contact',
        },
        {
            label: 'Review handover',
            icon: Link,
            onClick: () => request('convert', r),
            disabled: can('convert')
                ? undefined
                : 'Requires Create / link supported person',
        },
    ];
    const ctx = useEntityContextMenu();
    const reset = (scenario = 'standard') => {
        const defaults = seed();
        setData(
            scenario === 'reporting'
                ? reportingScenario()
                : normalise(defaults, defaults),
        );
        setRole('Referral manager');
        setFailure('Normal');
        setSettingsDirty(false);
        setRoute(scenario === 'reporting' ? 'Reports' : 'Referrals');
        if (scenario === 'reporting') {
            sessionStorage.setItem(
                'referrals-report-filters',
                JSON.stringify({
                    query: '',
                    site: 'all',
                    period: 'All time',
                    from: '',
                    to: TODAY,
                }),
            );
            sessionStorage.setItem('referrals-report-view', 'overview');
        }
        setSelected(null);
        setModal(null);
        setToast('');
        sessionStorage.removeItem('referrals-public-receipt');
        sessionStorage.removeItem('referrals-public-draft-v2');
        setQuery('');
        setFilter('All open');
        setSite('all');
        setAdvanced({
            owner: 'All owners',
            source: 'All sources',
            path: 'All pathways',
            fundingStatus: 'All funding states',
            priority: 'All priorities',
            sort: 'Due soonest',
            from: '',
            to: '',
        });
        setPage(1);
        setReturnQueue({ route: 'Referrals' });
    };
    return (
        <PrototypeContext.Provider value={api}>
            <TooltipProvider>
                <div className="prototype-bar">
                    <span>
                        <span className="demo-dot" />
                        Interactive prototype · fictional data
                    </span>
                    <details className="prototype-controls" open={!phoneLayout}>
                        <summary className="frontline-hit">
                            Prototype controls
                        </summary>
                        <div className="actions">
                            <Select
                                label="Preview role"
                                options={Object.keys(data.roles)}
                                value={role}
                                onChange={(v) => {
                                    if (settingsDirty) {
                                        notify(
                                            'Save or discard your settings draft before switching role.',
                                        );
                                        return;
                                    }
                                    setRole(v);
                                    setModal(null);
                                    setToast('');
                                }}
                            />
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    navigate(
                                        route === 'Public website'
                                            ? 'Referrals'
                                            : 'Public website',
                                    )
                                }
                            >
                                <Globe />{' '}
                                {route === 'Public website'
                                    ? 'Staff workspace'
                                    : 'Website entry'}
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => request('scenarios', null)}
                            >
                                <Workflow />
                                Walkthroughs
                            </Button>
                            <Button
                                variant="ghost"
                                aria-label={
                                    dark ? 'Use light theme' : 'Use dark theme'
                                }
                                onClick={() => setDark(!dark)}
                            >
                                {dark ? <Sun /> : <Moon />}
                            </Button>
                        </div>
                    </details>
                </div>
                {route === 'Public website' ? (
                    <PublicView />
                ) : (
                    <>
                        <div
                            className={
                                'app-shell ' + (collapsed ? 'is-collapsed' : '')
                            }
                        >
                            <header className="global-header">
                                <button
                                    className="wordmark"
                                    onClick={() => navigate('Referrals')}
                                    aria-label="Oblivion Care home"
                                >
                                    <span className="brand-ring" />{' '}
                                    {!collapsed && (
                                        <>
                                            blivion <strong>Care</strong>
                                        </>
                                    )}
                                </button>
                                <button
                                    className="global-search frontline-hit"
                                    aria-label="Search referrals globally"
                                    onClick={() => request('find', null)}
                                >
                                    <Search />
                                    <span className="global-search-label">
                                        Search referrals, people and reference
                                        numbers
                                    </span>
                                    <kbd className="text-caption">Ctrl K</kbd>
                                </button>
                                <span className="global-date">
                                    {date(TODAY)} · NZ time
                                </span>
                                <button
                                    className="avatar"
                                    aria-label="Review current permissions"
                                    onClick={() => request('access', null)}
                                >
                                    ET
                                </button>
                            </header>
                            <aside className="sidebar">
                                <div className="sidebar-label">
                                    CARE OPERATIONS
                                </div>
                                {[
                                    { key: 'Referrals', icon: Inbox },
                                    { key: 'Supported people', icon: Users },
                                    { key: 'Funding assistance', icon: Wallet },
                                    { key: 'Follow-ups', icon: CalendarDays },
                                    { key: 'Calendar', icon: CalendarDays },
                                    {
                                        key: 'Reports',
                                        icon: ChartNoAxesCombined,
                                    },
                                    { key: 'Settings', icon: Settings2 },
                                ].map(({ key, icon: Icon }) => (
                                    <button
                                        key={key}
                                        aria-label={key}
                                        className={
                                            route === key ? 'active' : ''
                                        }
                                        onClick={() => navigate(key)}
                                        title={collapsed ? key : undefined}
                                    >
                                        <Icon />
                                        {!collapsed && (
                                            <>
                                                <span>{key}</span>
                                                {key === 'Referrals' &&
                                                    can('view') && (
                                                        <small>
                                                            {
                                                                scoped.filter(
                                                                    (r) =>
                                                                        ![
                                                                            'Closed',
                                                                            'Converted',
                                                                        ].includes(
                                                                            r.stage,
                                                                        ),
                                                                ).length
                                                            }
                                                        </small>
                                                    )}
                                            </>
                                        )}
                                    </button>
                                ))}
                                <div className="sidebar-bottom">
                                    {!collapsed && (
                                        <>
                                            <Badge tone="neutral">
                                                One organisation
                                            </Badge>
                                            <p className="text-caption">
                                                Approved sites · role
                                                permissions
                                                <br />
                                                Previewing: {role}
                                            </p>
                                        </>
                                    )}
                                    <button
                                        className="collapse-button"
                                        onClick={() => setCollapsed(!collapsed)}
                                        aria-label={
                                            collapsed
                                                ? 'Expand navigation'
                                                : 'Collapse navigation'
                                        }
                                    >
                                        {collapsed ? (
                                            <ChevronRight />
                                        ) : (
                                            <ChevronLeft />
                                        )}
                                    </button>
                                </div>
                            </aside>
                            <main className="workspace">
                                <nav
                                    aria-label="Breadcrumb"
                                    className="breadcrumb"
                                >
                                    <button
                                        onClick={() => navigate('Referrals')}
                                    >
                                        <Home /> Home
                                    </button>
                                    <ChevronRight />
                                    <button onClick={() => navigate(route)}>
                                        {route}
                                    </button>
                                    {selected && (
                                        <>
                                            <ChevronRight />
                                            <span>
                                                {record?.name ||
                                                    'Restricted record'}
                                            </span>
                                        </>
                                    )}
                                </nav>
                                {persistError && (
                                    <Notice tone="critical">
                                        Browser storage is full or unavailable.
                                        Changes remain in this tab only.
                                    </Notice>
                                )}
                                {route === 'Settings' ? (
                                    <SettingsView />
                                ) : route === 'Calendar' ? (
                                    <ReferralCalendar />
                                ) : selected && !record ? (
                                    <Empty title="You don’t have access to this referral">
                                        Access depends on your role, approved
                                        site, ownership and privacy
                                        restrictions. No record details are
                                        shown.
                                    </Empty>
                                ) : record ? (
                                    <ReferralDetail
                                        record={record}
                                        tab={tab}
                                        setTab={setTab}
                                    />
                                ) : route === 'Supported people' ? (
                                    <PeopleView />
                                ) : route === 'Reports' ? (
                                    <OperationalReports
                                        key={data.scenario || 'standard'}
                                        records={scoped}
                                    />
                                ) : !can('view') ? (
                                    <>
                                        <PageHeader
                                            icon={LockKeyhole}
                                            title={route}
                                            frontline={phoneLayout}
                                            mobileSummary={`${filtered.length} matching referrals · filters and summary`}
                                            subline="Permission required"
                                        />
                                        <Empty title="This workspace is restricted">
                                            Your current role does not include
                                            View referrals. Try another preview
                                            role to explore access.
                                        </Empty>
                                    </>
                                ) : (
                                    <>
                                        <PageHeader
                                            icon={
                                                route === 'Funding assistance'
                                                    ? Wallet
                                                    : route === 'Follow-ups'
                                                      ? CalendarDays
                                                      : Inbox
                                            }
                                            title={route}
                                            subline={
                                                route === 'Funding assistance'
                                                    ? 'Applications, decisions and funding follow-ups'
                                                    : route === 'Follow-ups'
                                                      ? 'Next actions, due dates and accountable owners'
                                                      : 'Enquiries, assessment and supported-living handover'
                                            }
                                            frontline={phoneLayout}
                                            mobileSummary={`${filtered.length} matching referrals · filters and summary`}
                                            titleChip={
                                                <PageHeaderStatusChip variant="neutral">
                                                    {scoped.length} in your
                                                    scope
                                                </PageHeaderStatusChip>
                                            }
                                            actions={
                                                <>
                                                    <PageHeaderSearch
                                                        value={query}
                                                        onChange={setQuery}
                                                        placeholder="Find a person or reference"
                                                    />
                                                    <PageHeaderGlassButton
                                                        onClick={() =>
                                                            request(
                                                                'savedViews',
                                                                null,
                                                                {
                                                                    query,
                                                                    route,
                                                                    advanced,
                                                                    filter,
                                                                    site,
                                                                    view,
                                                                    apply: (
                                                                        v,
                                                                    ) => {
                                                                        if (
                                                                            v.route
                                                                        )
                                                                            setRoute(
                                                                                v.route,
                                                                            );
                                                                        if (
                                                                            v.advanced
                                                                        )
                                                                            setAdvanced(
                                                                                v.advanced,
                                                                            );
                                                                        setQuery(
                                                                            v.query,
                                                                        );
                                                                        setFilter(
                                                                            v.filter,
                                                                        );
                                                                        setSite(
                                                                            v.site,
                                                                        );
                                                                        setView(
                                                                            v.view,
                                                                        );
                                                                    },
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Saved views
                                                    </PageHeaderGlassButton>
                                                    {can('create') && (
                                                        <PageHeaderPrimaryButton
                                                            onClick={() =>
                                                                newIntake()
                                                            }
                                                        >
                                                            <Plus />
                                                            New referral
                                                        </PageHeaderPrimaryButton>
                                                    )}
                                                </>
                                            }
                                            meters={
                                                <>
                                                    {[
                                                        [
                                                            'Open referrals',
                                                            scoped.filter(
                                                                (r) =>
                                                                    ![
                                                                        'Closed',
                                                                        'Converted',
                                                                    ].includes(
                                                                        r.stage,
                                                                    ),
                                                            ).length,
                                                            'All open',
                                                            'brand',
                                                        ],
                                                        [
                                                            'Unassigned',
                                                            scoped.filter(
                                                                (r) =>
                                                                    r.owner ===
                                                                        'Intake team' &&
                                                                    ![
                                                                        'Closed',
                                                                        'Converted',
                                                                    ].includes(
                                                                        r.stage,
                                                                    ),
                                                            ).length,
                                                            'Unassigned',
                                                            'warning',
                                                        ],
                                                        [
                                                            'Follow-up overdue',
                                                            scoped.filter(
                                                                (r) =>
                                                                    !r.nextDone &&
                                                                    r.due <
                                                                        TODAY &&
                                                                    ![
                                                                        'Closed',
                                                                        'Converted',
                                                                    ].includes(
                                                                        r.stage,
                                                                    ),
                                                            ).length,
                                                            'Overdue',
                                                            'critical',
                                                        ],
                                                        [
                                                            'Ready for handover',
                                                            scoped.filter(
                                                                (r) =>
                                                                    r.stage ===
                                                                    'Ready for handover',
                                                            ).length,
                                                            'Ready for handover',
                                                            'success',
                                                        ],
                                                    ].map(
                                                        ([
                                                            label,
                                                            n,
                                                            f,
                                                            tone,
                                                        ]) => (
                                                            <PageHeaderMeterBlock
                                                                key={label}
                                                                label={label}
                                                                tone={tone}
                                                                onClick={() => {
                                                                    setFilter(
                                                                        f,
                                                                    );
                                                                    setRoute(
                                                                        'Referrals',
                                                                    );
                                                                }}
                                                                pressed={
                                                                    filter === f
                                                                }
                                                            >
                                                                <PageHeaderMeterBig>
                                                                    {n}
                                                                </PageHeaderMeterBig>
                                                                <PageHeaderMeterCaption>
                                                                    {label ===
                                                                    'Follow-up overdue'
                                                                        ? 'Human follow-up needed'
                                                                        : 'View this queue'}{' '}
                                                                </PageHeaderMeterCaption>
                                                            </PageHeaderMeterBlock>
                                                        ),
                                                    )}
                                                </>
                                            }
                                            filters={
                                                <>
                                                    <PageHeaderFilterSelect
                                                        label="Site"
                                                        value={site}
                                                        onChange={setSite}
                                                        options={[
                                                            {
                                                                value: 'all',
                                                                label: 'All approved sites',
                                                            },
                                                            ...allowedSites(
                                                                data,
                                                                role,
                                                            ).map((s) => ({
                                                                value: s,
                                                                label: s,
                                                            })),
                                                        ]}
                                                    />
                                                    <PageHeaderFilterSelect
                                                        label="View"
                                                        allValue="All open"
                                                        value={filter}
                                                        onChange={setFilter}
                                                        options={[
                                                            'All open',
                                                            'All records',
                                                            'Mine',
                                                            'Unassigned',
                                                            'Overdue',
                                                            ...data.config
                                                                .stages,
                                                        ].map((v) => ({
                                                            value: v,
                                                            label: v,
                                                        }))}
                                                    />
                                                    <PageHeaderGlassButton
                                                        onClick={() =>
                                                            request(
                                                                'advancedFilters',
                                                                null,
                                                                {
                                                                    advanced,
                                                                    apply: setAdvanced,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        More filters
                                                    </PageHeaderGlassButton>
                                                    {[
                                                        'Referrals',
                                                        'Funding assistance',
                                                        'Follow-ups',
                                                    ].includes(route) && (
                                                        <PageHeaderViewToggle
                                                            ariaLabel="Layout"
                                                            value={view}
                                                            onChange={setView}
                                                            options={[
                                                                {
                                                                    value: 'Cards',
                                                                    label: 'Cards',
                                                                    icon: LayoutGrid,
                                                                },
                                                                {
                                                                    value: 'List',
                                                                    label: 'Table',
                                                                    icon: List,
                                                                },
                                                                ...(route ===
                                                                'Referrals'
                                                                    ? [
                                                                          {
                                                                              value: 'Pipeline',
                                                                              label: 'Pipeline',
                                                                              icon: Columns3,
                                                                          },
                                                                      ]
                                                                    : []),
                                                            ]}
                                                        />
                                                    )}
                                                </>
                                            }
                                            rail={
                                                <PageHeaderRail
                                                    items={railItems}
                                                    value={route}
                                                    onSelect={(v) =>
                                                        navigate(v)
                                                    }
                                                    onFind={() =>
                                                        request('find', null)
                                                    }
                                                />
                                            }
                                        />
                                        <div className="body-stack">
                                            {route === 'Follow-ups' ? (
                                                <WorkCollection
                                                    records={filtered}
                                                    total={scoped.length}
                                                    view={view}
                                                    mode="Follow-ups"
                                                />
                                            ) : route ===
                                              'Funding assistance' ? (
                                                <WorkCollection
                                                    records={filtered}
                                                    total={scoped.length}
                                                    view={view}
                                                    mode="Funding assistance"
                                                />
                                            ) : (
                                                <>
                                                    {data.drafts.filter(
                                                        (d) =>
                                                            (d.role ||
                                                                'Referral manager') ===
                                                            role,
                                                    ).length > 0 &&
                                                        can('create') && (
                                                            <Notice>
                                                                {
                                                                    data.drafts.filter(
                                                                        (d) =>
                                                                            (d.role ||
                                                                                'Referral manager') ===
                                                                            role,
                                                                    ).length
                                                                }{' '}
                                                                unfinished
                                                                intake draft(s).{' '}
                                                                <Button
                                                                    variant="link"
                                                                    onClick={() =>
                                                                        request(
                                                                            'drafts',
                                                                            null,
                                                                        )
                                                                    }
                                                                >
                                                                    Choose a
                                                                    draft
                                                                </Button>
                                                            </Notice>
                                                        )}
                                                    {filter ===
                                                        'Unassigned' && (
                                                        <Notice>
                                                            Central intake
                                                            remains accountable
                                                            until an owner
                                                            accepts the
                                                            referral. Assignment
                                                            escalates to the
                                                            manager after the
                                                            configured target.
                                                        </Notice>
                                                    )}
                                                    <ReferralCollection
                                                        records={
                                                            view === 'Pipeline'
                                                                ? filtered
                                                                : filtered.slice(
                                                                      (page -
                                                                          1) *
                                                                          10,
                                                                      page * 10,
                                                                  )
                                                        }
                                                        total={filtered.length}
                                                        view={view}
                                                    />
                                                    <Pagination
                                                        currentPage={Math.min(
                                                            page,
                                                            Math.ceil(
                                                                filtered.length /
                                                                    10,
                                                            ) || 1,
                                                        )}
                                                        lastPage={Math.ceil(
                                                            filtered.length /
                                                                10,
                                                        )}
                                                        onPageChange={setPage}
                                                    />
                                                    <p className="text-caption text-muted-foreground">
                                                        {filtered.length}{' '}
                                                        visible referrals ·
                                                        Dates shown in New
                                                        Zealand time · Private
                                                        records outside your
                                                        scope are excluded.
                                                    </p>
                                                </>
                                            )}
                                        </div>
                                    </>
                                )}
                            </main>
                        </div>
                    </>
                )}
                {ctx.ctx && (
                    <EntityContextMenu
                        x={ctx.ctx.x}
                        y={ctx.ctx.y}
                        title={ctx.ctx.record.name}
                        items={actionsFor(ctx.ctx.record)}
                        onClose={ctx.close}
                    />
                )}
                <div
                    role="status"
                    aria-live="polite"
                    className={toast && !modal ? 'toast' : 'sr-only'}
                >
                    {toast}
                </div>
                {modal && (
                    <Actions
                        key={modal.type + (modal.r?.id || '')}
                        modal={modal}
                        close={() => setModal(null)}
                        reset={reset}
                    />
                )}
                {pendingNav && (
                    <Modal
                        title={
                            settingsDirty
                                ? 'Unpublished settings changes'
                                : 'Unsaved form changes'
                        }
                        description="Keep editing, or discard your changes before leaving this page."
                        onClose={() => setPendingNav(null)}
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setPendingNav(null)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        setSettingsDirty(false);
                                        setWizardDirty(false);
                                        pendingNav();
                                        setPendingNav(null);
                                    }}
                                >
                                    Discard and leave
                                </Button>
                            </>
                        }
                    />
                )}
            </TooltipProvider>
        </PrototypeContext.Provider>
    );
}

function ReferralDetail({ record: r, tab, setTab }) {
    const {
        can,
        request,
        navigate,
        data,
        returnToQueue,
        returnQueue,
        phoneLayout,
    } = usePrototype();
    const tabs = [
        'Overview',
        'Funding',
        'Proposal',
        'Tasks',
        'Contacts',
        'Evidence',
        'Activity',
        'Handover',
    ];
    return (
        <>
            <PageHeader
                variant="profile"
                frontline={phoneLayout}
                mobileSummary={`${r.owner} · ${date(r.due)}`}
                mark={
                    <span
                        className="eh-mark-ring text-[17px] font-bold tracking-tight"
                        aria-hidden="true"
                    >
                        {r.name
                            .split(' ')
                            .filter(Boolean)
                            .map((n) => n[0])
                            .slice(0, 2)
                            .join('')}
                    </span>
                }
                title={r.name}
                subline={`${r.id} · ${r.source} · ${r.site} · Workflow v${r.version}`}
                titleChip={
                    <PageHeaderStatusChip
                        variant={r.stage === 'Converted' ? 'success' : 'info'}
                    >
                        {r.stage}
                    </PageHeaderStatusChip>
                }
                actions={
                    <>
                        <PageHeaderGlassButton onClick={returnToQueue}>
                            <ArrowLeft />
                            {returnQueue.route === 'Referrals'
                                ? 'All referrals'
                                : 'Back to ' + returnQueue.route.toLowerCase()}
                        </PageHeaderGlassButton>
                        {can('contact') && (
                            <PageHeaderGlassButton
                                onClick={() => request('contact')}
                            >
                                <Phone />
                                Log contact
                            </PageHeaderGlassButton>
                        )}
                        {can('convert') && (
                            <PageHeaderPrimaryButton
                                onClick={() =>
                                    r.stage === 'Closed'
                                        ? request('lifecycle')
                                        : r.owner === 'Intake team' &&
                                            can('assign')
                                          ? request('assign')
                                          : ['New', 'Triage'].includes(
                                                  r.stage,
                                              ) &&
                                              !r.notes.some(
                                                  (n) => n.successful,
                                              ) &&
                                              can('contact')
                                            ? request('contact')
                                            : r.personId
                                              ? setTab('Handover')
                                              : conversionBlocks(r).length
                                                ? request('assessment')
                                                : request('convert')
                                }
                            >
                                <Link />
                                {r.stage === 'Closed'
                                    ? 'Reopen referral'
                                    : r.owner === 'Intake team' && can('assign')
                                      ? 'Assign coordinator'
                                      : ['New', 'Triage'].includes(r.stage) &&
                                          !r.notes.some((n) => n.successful) &&
                                          can('contact')
                                        ? 'Make first contact'
                                        : r.personId
                                          ? 'View handover'
                                          : conversionBlocks(r).length
                                            ? 'Review readiness'
                                            : 'Review handover'}
                            </PageHeaderPrimaryButton>
                        )}
                    </>
                }
                meters={
                    <>
                        {[
                            ['Accountable owner', r.owner, 'Contacts'],
                            ['Next follow-up', date(r.due), 'Tasks'],
                            [
                                'Funding position',
                                can('fundingRead')
                                    ? money(coverage(r)) + ' / week'
                                    : 'Restricted',
                                'Funding',
                            ],
                            [
                                'Handover checks',
                                conversionBlocks(r).length + ' remaining',
                                'Handover',
                            ],
                        ].map(([label, value, action]) => (
                            <PageHeaderMeterBlock
                                label={label}
                                key={label}
                                onClick={() => setTab(action)}
                            >
                                <strong className="meter-text">{value}</strong>
                                <PageHeaderMeterCaption>
                                    {label === 'Accountable owner'
                                        ? r.team
                                        : label === 'Funding position'
                                          ? 'Verified service contribution'
                                          : 'Review details'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </>
                }
                rail={
                    <PageHeaderRail
                        items={tabs.map((t) => ({
                            key: t,
                            label: t,
                            icon:
                                t === 'Funding'
                                    ? Wallet
                                    : t === 'Handover'
                                      ? Link
                                      : ClipboardList,
                        }))}
                        value={tab}
                        onSelect={setTab}
                        onFind={() => request('find')}
                    />
                }
            />
            <div className="body-stack">
                {r.restricted && (
                    <Notice>
                        Privacy-restricted case. Access and changes are
                        recorded.
                    </Notice>
                )}
                {r.stage === 'On hold' && (
                    <Notice>
                        On hold until {date(r.due)}. The owner remains
                        responsible for the next review.{' '}
                        <Button
                            variant="link"
                            onClick={() => request('lifecycle')}
                        >
                            Review hold
                        </Button>
                    </Notice>
                )}
                {tab === 'Overview' ? (
                    <>
                        <CaseOverview r={r} onTab={setTab} />
                        <IntakeReview r={r} />
                    </>
                ) : tab === 'Funding' ? (
                    <FundingPanel r={r} />
                ) : tab === 'Proposal' ? (
                    <>
                        <Proposal r={r} />
                        <ProposalDetails r={r} />
                    </>
                ) : tab === 'Contacts' ? (
                    <ContactsPanel r={r} />
                ) : tab === 'Tasks' ? (
                    <WorkPanel r={r} />
                ) : tab === 'Evidence' ? (
                    <EvidenceWorkspace r={r} />
                ) : tab === 'Activity' ? (
                    <Activity r={r} />
                ) : (
                    <>
                        <Handover r={r} />
                        <HandoverReceipt r={r} />
                    </>
                )}
            </div>
        </>
    );
}

function FundingPanel({ r }) {
    const { can, request } = usePrototype();
    if (!can('fundingRead'))
        return (
            <Empty title="Funding details are restricted">
                Your role can see referral progress, but not financial or
                assessment information.
            </Empty>
        );
    return (
        <>
            <Section
                title="Funding assistance"
                icon={Wallet}
                caption="Helping the person prepare, apply and follow up. Eligibility and awards are decided externally."
                actions={
                    can('funding') && (
                        <Button onClick={() => request('funding', r)}>
                            <Plus />
                            Add pathway
                        </Button>
                    )
                }
            >
                <div className="two-col">
                    <ReviewCard icon={Wallet} title="Service funding">
                        <ReviewRow
                            label="Weekly support proposal"
                            value={money(r.proposal.weekly)}
                        />
                        <ReviewRow
                            label="Verified contributions"
                            value={money(coverage(r))}
                        />
                        <ReviewRow
                            label="Unfunded gap"
                            value={
                                <Badge
                                    tone={
                                        knownNumber(r.proposal.weekly) &&
                                        coverage(r) >= Number(r.proposal.weekly)
                                            ? 'success'
                                            : 'warning'
                                    }
                                >
                                    {knownNumber(r.proposal.weekly)
                                        ? money(
                                              Math.max(
                                                  0,
                                                  r.proposal.weekly -
                                                      coverage(r),
                                              ),
                                          )
                                        : 'Not yet costed'}
                                </Badge>
                            }
                        />
                    </ReviewCard>
                    <ReviewCard icon={HeartHandshake} title="Person’s choice">
                        <ReviewRow
                            label="Continue helping"
                            value="While funding is pending"
                        />
                        <ReviewRow
                            label="Options"
                            value="Review, alternative pathway, revised support"
                        />
                        <ReviewRow
                            label="Income assistance"
                            value="Separate from provider service funding"
                        />
                    </ReviewCard>
                </div>
            </Section>
            {r.funding.map((f) => (
                <Section
                    key={f.id}
                    title={f.path}
                    icon={Wallet}
                    caption={`${f.navigator} · Checklist v${f.version}${f.income ? ' · Personal income track' : ''}`}
                    actions={
                        <>
                            <Badge>{f.status}</Badge>
                            {can('funding') && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        request('fundingCase', r, {
                                            funding: f,
                                        })
                                    }
                                >
                                    Work on case
                                </Button>
                            )}
                            {can('verify') && (
                                <Button
                                    variant="outline"
                                    disabled={
                                        ![
                                            'Decision recorded',
                                            'Verified',
                                        ].includes(f.status)
                                    }
                                    onClick={() =>
                                        request('verify', r, { funding: f })
                                    }
                                >
                                    Verify decision
                                </Button>
                            )}
                        </>
                    }
                >
                    <div className="two-col">
                        <div className="stack">
                            {f.checks.map((c) => (
                                <div className="check-line" key={c.label}>
                                    {c.done ? (
                                        <CheckCircle2 className="text-status-success" />
                                    ) : (
                                        <Clock className="text-muted-foreground" />
                                    )}
                                    <span>{c.label}</span>
                                </div>
                            ))}
                        </div>
                        <div>
                            <ReviewRow
                                label="Requested"
                                value={
                                    f.income
                                        ? 'Income assistance only'
                                        : money(f.requested) + ' / week'
                                }
                            />
                            <ReviewRow
                                label="Assistance requested"
                                value={
                                    f.assistanceReason ||
                                    'Review with the person'
                                }
                            />
                            <ReviewRow
                                label="External decision"
                                value={f.decision || 'Not received'}
                            />
                            <ReviewRow
                                label="Contribution"
                                value={
                                    f.income
                                        ? 'Excluded from service coverage'
                                        : money(f.amount) + ' / week'
                                }
                            />
                            <ReviewRow
                                label="Reference"
                                value={f.reference || 'Not supplied'}
                            />
                            <ReviewRow
                                label="Assessment appointment"
                                value={
                                    (r.appointments || [])
                                        .filter((a) => a.fundingId === f.id)
                                        .map(
                                            (a) =>
                                                `${date(a.date)} · ${a.time} · ${a.status}`,
                                        )
                                        .join(' · ') || 'Not scheduled'
                                }
                            />
                            <ReviewRow
                                label="Evidence"
                                value={f.evidence || 'Not supplied'}
                            />
                        </div>
                    </div>
                    {[
                        'Partial approval',
                        'Partially accepted',
                        'Conditional approval',
                        'Declined',
                    ].includes(f.decision) && (
                        <Notice>
                            Next: discuss the decision and remaining gap with
                            the person. Record a review deadline or add an
                            alternative pathway.{' '}
                            <Button
                                variant="link"
                                onClick={() =>
                                    request('fundingCase', r, {
                                        funding: f,
                                        review: true,
                                    })
                                }
                            >
                                Plan review / reassessment
                            </Button>
                        </Notice>
                    )}
                    <FundingValidity r={r} f={f} />
                    {f.history.length > 0 && (
                        <details>
                            <summary>
                                Decision & submission history (
                                {f.history.length})
                            </summary>
                            {f.history.map((h, i) => (
                                <p key={i} className="timeline-item">
                                    {h}
                                </p>
                            ))}
                        </details>
                    )}
                </Section>
            ))}
        </>
    );
}
function FundingQueue({ records }) {
    const { can, navigate } = usePrototype();
    if (!can('fundingRead'))
        return (
            <Empty title="Funding queue is restricted">
                View assessments & evidence is required.
            </Empty>
        );
    return (
        <Section
            title="Applications & decisions"
            caption="Service budgets and personal income assistance are tracked separately."
        >
            {records.flatMap((r) =>
                r.funding.map((f) => (
                    <div className="work-row" key={f.id}>
                        <div>
                            <Button
                                variant="link"
                                onClick={() =>
                                    navigate('Referrals', r.id, 'Funding')
                                }
                            >
                                {r.name}
                            </Button>
                            <p className="text-subtle">
                                {f.path} · {f.navigator}
                            </p>
                        </div>
                        <Badge>{f.status}</Badge>
                        <span>
                            {f.income
                                ? 'Personal income'
                                : money(f.amount) + ' / week'}
                        </span>
                        <Button
                            variant="outline"
                            onClick={() =>
                                navigate('Referrals', r.id, 'Funding')
                            }
                        >
                            Open case
                        </Button>
                    </div>
                )),
            )}
        </Section>
    );
}
function TaskPanel({ r }) {
    const { can, request, update } = usePrototype();
    return (
        <Section
            title="Follow-up tasks"
            caption="Tasks stay with the referral after handover until completed."
            icon={CalendarDays}
            actions={
                can('edit') && (
                    <Button onClick={() => request('task', r)}>
                        <Plus />
                        Add task
                    </Button>
                )
            }
        >
            {r.tasks.length ? (
                r.tasks.map((t) => (
                    <div className="work-row" key={t.id}>
                        <Checkbox
                            aria-label={'Complete ' + t.label}
                            disabled={!can('edit')}
                            checked={t.done}
                            onCheckedChange={(done) =>
                                update(
                                    r.id,
                                    {
                                        tasks: r.tasks.map((x) =>
                                            x.id === t.id ? { ...x, done } : x,
                                        ),
                                    },
                                    (done ? 'Completed: ' : 'Reopened: ') +
                                        t.label,
                                )
                            }
                        />
                        <div className="grow">
                            <strong className={t.done ? 'line-through' : ''}>
                                {t.label}
                            </strong>
                            <p className="text-subtle">
                                {t.owner} · Due {date(t.due)}
                            </p>
                        </div>
                        <Badge>
                            {t.done
                                ? 'Complete'
                                : t.due < TODAY
                                  ? 'Overdue'
                                  : 'Open'}
                        </Badge>
                    </div>
                ))
            ) : (
                <Empty title="No tasks yet">
                    Add a follow-up with an owner and due date.
                </Empty>
            )}
        </Section>
    );
}
function Followups({ records }) {
    const { navigate } = usePrototype();
    const pending = records
        .filter((r) => r.stage !== 'Closed')
        .flatMap((r) => [
            ...(!r.nextDone
                ? [
                      {
                          r,
                          t: {
                              id: 'next-' + r.id,
                              label: r.next,
                              due: r.due,
                              owner: r.owner,
                              primary: true,
                          },
                      },
                  ]
                : []),
            ...r.tasks
                .filter((t) => !t.done && (r.nextDone || t.label !== r.next))
                .map((t) => ({ r, t })),
        ]);
    return (
        <Section
            title="Upcoming & overdue"
            icon={CalendarDays}
            caption="Referral next steps and individual tasks, including ongoing work after handover."
        >
            {pending
                .sort((a, b) => a.t.due.localeCompare(b.t.due))
                .map(({ r, t }) => (
                    <div className="work-row" key={t.id}>
                        <CalendarDays />
                        <div className="grow">
                            <strong>{t.label}</strong>
                            <p className="text-subtle">
                                {r.name} · {t.owner}
                            </p>
                        </div>
                        <Badge tone={t.due < TODAY ? 'critical' : 'neutral'}>
                            {date(t.due)}
                        </Badge>
                        <Button
                            variant="outline"
                            onClick={() =>
                                navigate(
                                    'Referrals',
                                    r.id,
                                    t.primary ? 'Overview' : 'Tasks',
                                )
                            }
                        >
                            {t.primary ? 'Open next step' : 'Open task'}
                        </Button>
                    </div>
                ))}
        </Section>
    );
}
function Proposal({ r }) {
    const { can, request } = usePrototype();
    if (!can('fundingRead'))
        return <Empty title="Proposal details are restricted" />;
    return (
        <>
            <Section
                title="Support proposal & payer split"
                icon={HeartHandshake}
                actions={
                    can('edit') && (
                        <Button onClick={() => request('proposal', r)}>
                            Edit proposal
                        </Button>
                    )
                }
            >
                <div className="two-col">
                    <ReviewCard icon={ClipboardList} title="Proposed support">
                        <ReviewRow label="Service" value={r.service} />
                        <ReviewRow
                            label="Cost"
                            value={money(r.proposal.weekly) + ' / week'}
                        />
                        <ReviewRow
                            label="Primary payer"
                            value={r.proposal.payer}
                        />
                        <ReviewRow
                            label="Planned private share"
                            value={money(r.proposal.privateAmount) + ' / week'}
                        />
                        <ReviewRow
                            label="Capacity"
                            value={
                                r.capacity
                                    ? 'Available'
                                    : 'Waitlist / review needed'
                            }
                        />
                    </ReviewCard>
                    <ReviewCard icon={ShieldCheck} title="Approvals">
                        <ReviewRow
                            label="Internal approval"
                            value={
                                r.proposal.approved
                                    ? 'Approved'
                                    : 'Pending authorized review'
                            }
                        />
                        <ReviewRow
                            label="Person / payer acceptance"
                            value={
                                r.proposal.accepted
                                    ? 'Accepted'
                                    : 'Not yet recorded'
                            }
                        />
                        <ReviewRow
                            label="Verified funding"
                            value={money(coverage(r))}
                        />
                        <ReviewRow
                            label="Remaining gap"
                            value={
                                knownNumber(r.proposal.weekly)
                                    ? money(
                                          Math.max(
                                              0,
                                              r.proposal.weekly - coverage(r),
                                          ),
                                      )
                                    : 'Not yet costed'
                            }
                        />
                    </ReviewCard>
                </div>
                <Notice>
                    Each payer contribution needs its own verified funding
                    arrangement. Editing a proposed private share does not make
                    that funding verified.
                </Notice>
                {can('verify') && (
                    <Button
                        variant="outline"
                        onClick={() => request('approveProposal', r)}
                    >
                        Review internal approval
                    </Button>
                )}
            </Section>
        </>
    );
}
function Activity({ r }) {
    const { can, request } = usePrototype();
    return (
        <>
            <Section
                title="Contact & communication"
                icon={MessageSquare}
                actions={
                    can('contact') && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() => request('contact', r)}
                            >
                                Log contact
                            </Button>
                            <Button
                                disabled={!can('disclose')}
                                onClick={() => request('message', r)}
                            >
                                Preview request
                            </Button>
                        </>
                    )
                }
            >
                <ReviewRow
                    label="Last successful human contact"
                    value={(() => {
                        const n = r.notes.find((n) => n.successful);
                        return n
                            ? `${date(n.occurredDate)} · ${n.occurredTime} · ${n.by}`
                            : 'Not recorded';
                    })()}
                />
                {r.notes.length ? (
                    r.notes
                        .filter(
                            (n) =>
                                n.audience === 'General intake' ||
                                can('sensitive'),
                        )
                        .map((n, i) => (
                            <details
                                key={i}
                                className="note"
                                open={n.delivery === 'Failed'}
                            >
                                <summary>
                                    {n.channel} · {n.outcome}{' '}
                                    <Badge>{n.delivery || 'Logged'}</Badge>
                                </summary>
                                <p className="text-caption">
                                    Occurred{' '}
                                    {n.occurredDate
                                        ? date(n.occurredDate) +
                                          ' · ' +
                                          n.occurredTime
                                        : displayStamp(n.at)}{' '}
                                    · recorded {displayStamp(n.at)} ·{' '}
                                    {n.by || 'Sample intake'}
                                </p>
                                <p>{n.body}</p>
                                {n.delivery === 'Failed' && can('edit') && (
                                    <Button
                                        onClick={() =>
                                            request('retryMessage', r, {
                                                noteIndex: i,
                                            })
                                        }
                                    >
                                        Retry delivery preview
                                    </Button>
                                )}
                            </details>
                        ))
                ) : (
                    <p className="text-subtle">No conversations logged yet.</p>
                )}
            </Section>
            <Section title="Activity history" icon={Clock}>
                {can('view') ? (
                    r.activity.map((a, i) => (
                        <div className="timeline-item" key={i}>
                            <span className="timeline-dot" />
                            <div>
                                <strong>{a.text}</strong>
                                <p className="text-caption text-muted-foreground">
                                    {displayStamp(a.at)} · {a.by}
                                </p>
                            </div>
                        </div>
                    ))
                ) : (
                    <Notice>
                        Detailed audit history requires View audit history.
                    </Notice>
                )}
            </Section>
        </>
    );
}
function Evidence({ r }) {
    const { can, request } = usePrototype();
    if (!can('sensitive')) return <Empty title="Evidence is restricted" />;
    return (
        <Section
            title="Evidence & documents"
            icon={FileText}
            caption="Fictional documents only. Browser files are staged locally and never uploaded."
            actions={
                can('edit') && (
                    <Button onClick={() => request('document', r)}>
                        <Plus />
                        Add evidence
                    </Button>
                )
            }
        >
            {r.documents.length ? (
                r.documents.map((d) => (
                    <div className="work-row" key={d.id}>
                        <FileText />
                        <div className="grow">
                            <strong>{d.name}</strong>
                            <p className="text-subtle">
                                {d.classification || 'Funding evidence'} ·
                                Referral access rules apply
                            </p>
                        </div>
                        <Badge>{d.state}</Badge>
                        <Button
                            variant="outline"
                            disabled={d.state === 'Quarantined'}
                            onClick={() =>
                                request('viewDocument', r, { document: d })
                            }
                        >
                            View
                        </Button>
                        {d.state === 'Available' && can('edit') && (
                            <Button
                                onClick={() =>
                                    request('reviewDocument', r, {
                                        document: d,
                                    })
                                }
                            >
                                Mark reviewed
                            </Button>
                        )}
                    </div>
                ))
            ) : (
                <Empty title="No evidence attached">
                    Use the synthetic example or stage a sample document.
                </Empty>
            )}
        </Section>
    );
}
function Handover({ r }) {
    const { can, request, navigate } = usePrototype();
    if (!can('convert'))
        return (
            <Empty title="Handover requires permission">
                Create / link supported person is required.
            </Empty>
        );
    const blocks = conversionBlocks(r),
        start = activationBlocks(r);
    return (
        <>
            <Section
                title="Referral → supported person"
                icon={Link}
                caption="Reuse the existing person record. Outstanding funding and tasks continue."
                actions={
                    <Button
                        onClick={() =>
                            r.personId
                                ? navigate('Supported people')
                                : request('convert', r)
                        }
                    >
                        {r.personId
                            ? 'Review linked record'
                            : 'Review conversion'}
                    </Button>
                }
            >
                <div className="two-col">
                    <ReviewCard icon={Users} title="Create or link">
                        <ReviewRow label="Person" value={r.name} />
                        <ReviewRow
                            label="Destination"
                            value={
                                r.personId
                                    ? 'Linked person ' + r.personId
                                    : r.existingPerson
                                      ? 'Existing person ' + r.existingPerson
                                      : 'New onboarding record'
                            }
                        />
                        <ReviewRow label="Receiving site" value={r.site} />
                        <ReviewRow
                            label="Information to transfer"
                            value="Identity, contact preferences, consent and support request"
                        />
                        <ReviewRow
                            label="Restricted evidence"
                            value="Access-controlled links only"
                        />
                    </ReviewCard>
                    <ReviewCard icon={ShieldCheck} title="Conversion readiness">
                        {r.personId ? (
                            <Notice tone="success">
                                Handover completed. Identity, goals and contact
                                preferences are linked to {r.personId};
                                remaining work continues with its owners.
                            </Notice>
                        ) : blocks.length ? (
                            blocks.map((b) => (
                                <ReviewRow
                                    key={b}
                                    label={b}
                                    value={
                                        <Badge tone="warning">Required</Badge>
                                    }
                                />
                            ))
                        ) : (
                            <Notice tone="success">
                                Ready to create or link the person. Funding may
                                remain pending when policy allows.
                            </Notice>
                        )}
                    </ReviewCard>
                </div>
            </Section>
            <Section
                title="Service-start gate"
                icon={ShieldCheck}
                actions={
                    can('activate') && (
                        <Button
                            disabled={
                                !r.personId ||
                                !!start.length ||
                                r.serviceStarted
                            }
                            onClick={() => request('activate', r)}
                        >
                            {r.serviceStarted
                                ? 'Service start approved'
                                : r.serviceStart?.status === 'Scheduled'
                                  ? 'Review scheduled start'
                                  : 'Approve service start'}
                        </Button>
                    )
                }
            >
                {r.serviceStart && (
                    <ReviewCard
                        icon={ShieldCheck}
                        title={
                            r.serviceStart.status === 'Scheduled'
                                ? 'Support scheduled — commencement to confirm'
                                : 'Service commencement recorded'
                        }
                    >
                        <ReviewRow
                            label="Agreed start"
                            value={date(r.serviceStart.date)}
                        />
                        <ReviewRow
                            label="Approved by"
                            value={`${r.serviceStart.approvedBy} · ${displayStamp(r.serviceStart.approvedAt)}`}
                        />
                        <ReviewRow
                            label="Proposal"
                            value={`Version ${r.serviceStart.proposalVersion}`}
                        />
                        {can('sensitive') && (
                            <ReviewRow
                                label="Safe-start handover"
                                value={r.serviceStart.note}
                            />
                        )}
                    </ReviewCard>
                )}
                {start.length ? (
                    <div className="check-grid">
                        {start.map((b) => (
                            <div key={b}>
                                <Clock />
                                <span>{b}</span>
                            </div>
                        ))}
                    </div>
                ) : (
                    <Notice tone="success">
                        {r.serviceStarted
                            ? 'Commencement is recorded. Continue with the linked person’s support and follow-up work.'
                            : r.serviceStart?.status === 'Scheduled'
                              ? 'Support is scheduled. Confirm the actual start when support begins; it is not counted as commenced yet.'
                              : 'All service-start requirements are met. An authorized manager can approve commencement.'}
                    </Notice>
                )}
                {r.personId && (
                    <Button
                        variant="link"
                        onClick={() => navigate('Supported people')}
                    >
                        Open {r.personId} in supported people <ArrowUpRight />
                    </Button>
                )}
            </Section>
        </>
    );
}
function PeopleView() {
    const { visiblePeople, scoped, can, navigate } = usePrototype();
    return (
        <>
            <PageHeader
                icon={Users}
                title="Supported people"
                subline="Handover preview · canonical person records"
            />
            <div className="body-stack">
                {!can('personRead') ? (
                    <Empty title="Person handover preview is restricted">
                        Use a coordinator or referral manager role.
                    </Empty>
                ) : (
                    visiblePeople.map((p) => (
                        <Section
                            key={p.id}
                            title={p.name}
                            icon={Users}
                            caption={p.id + ' · ' + p.site}
                            actions={<Badge>{p.status}</Badge>}
                        >
                            <ReviewRow
                                label="Active services"
                                value={p.services.join(', ') || 'None started'}
                            />
                            <ReviewRow
                                label="Proposed services"
                                value={
                                    (p.proposedServices || [])
                                        .map((x) => x.service)
                                        .join(', ') || 'None'
                                }
                            />
                            <ReviewRow
                                label="Goals"
                                value={p.goals || 'Not transferred'}
                            />
                            {p.preferred && (
                                <ReviewRow
                                    label="Preferred name"
                                    value={p.preferred}
                                />
                            )}
                            {p.dob && (
                                <ReviewRow
                                    label="Date of birth"
                                    value={date(p.dob)}
                                />
                            )}
                            <ReviewRow
                                label="Communication preferences"
                                value={
                                    [
                                        p.preferredContact,
                                        p.leaveMessage,
                                        p.interpreter,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || 'Review with the person'
                                }
                            />
                            {(p.serviceStarts || []).map((s) => (
                                <ReviewRow
                                    key={s.referralId}
                                    label={`Service start · ${s.referralId}`}
                                    value={`${s.status} · ${date(s.date)}`}
                                />
                            ))}
                            <ReviewRow
                                label="Person contacts"
                                value={
                                    [p.phone, p.email]
                                        .filter(Boolean)
                                        .join(' · ') || 'Not supplied'
                                }
                            />
                            {p.safe && (
                                <ReviewRow
                                    label="Safe contact"
                                    value={p.safe}
                                />
                            )}
                            <ReviewRow
                                label="Outstanding work"
                                value="Referral tasks and funding remain with their owners"
                            />
                            {scoped
                                .filter(
                                    (r) =>
                                        r.personId === p.id ||
                                        r.existingPerson === p.id,
                                )
                                .map((r) => (
                                    <Button
                                        key={r.id}
                                        variant="link"
                                        onClick={() =>
                                            navigate(
                                                'Referrals',
                                                r.id,
                                                'Handover',
                                            )
                                        }
                                    >
                                        Open referral {r.id}
                                    </Button>
                                ))}
                        </Section>
                    ))
                )}
            </div>
        </>
    );
}
function Reports({ records }) {
    const { can, request } = usePrototype();
    return (
        <>
            <Section
                title="Operational overview"
                caption="Counts use the current site and queue filters. Fictional snapshot: 15 October 2026."
                icon={LayoutGrid}
                actions={
                    <Button
                        variant="outline"
                        disabled={!can('export')}
                        onClick={() => request('export', null, { records })}
                    >
                        Preview export
                    </Button>
                }
            >
                <div className="report-grid">
                    {['Website', 'Phone', 'Professional', 'Email'].map((s) => (
                        <div key={s}>
                            <p>{s}</p>
                            <strong className="text-page-title">
                                {records.filter((r) => r.source === s).length}
                            </strong>
                            <p className="text-caption">
                                referrals in this view
                            </p>
                        </div>
                    ))}
                </div>
            </Section>
            <Section title="Work requiring a decision" icon={Clock}>
                {[
                    [
                        'Unassigned intake',
                        records.filter((r) => r.owner === 'Intake team').length,
                    ],
                    [
                        'Follow-up overdue',
                        records.filter((r) => !r.nextDone && r.due < TODAY)
                            .length,
                    ],
                    [
                        'On capacity hold',
                        records.filter((r) => r.stage === 'On hold').length,
                    ],
                    [
                        'Converted to supported person',
                        records.filter((r) => r.personId).length,
                    ],
                ].map(([label, value]) => (
                    <ReviewRow key={label} label={label} value={value} />
                ))}
            </Section>
            {can('fundingRead') ? (
                <Section title="Funding assistance outcomes" icon={Wallet}>
                    {pathways.map((path) => {
                        const cases = records
                            .flatMap((r) => r.funding)
                            .filter((f) => f.path === path);
                        return (
                            <ReviewRow
                                key={path}
                                label={path}
                                value={`${cases.length} cases · ${cases.filter((f) => f.verified).length} verified`}
                            />
                        );
                    })}
                </Section>
            ) : (
                <Notice>
                    Financial and sensitive report fields are excluded for this
                    role.
                </Notice>
            )}
            {!can('export') && (
                <Notice>
                    Export is unavailable for your current role. Counts remain
                    scoped to permitted referrals.
                </Notice>
            )}
        </>
    );
}

// Every command is permission-checked again when the dialog is opened and saved.
function Actions({ modal: m, close, reset }) {
    const api = usePrototype();
    const {
        data,
        setData,
        role,
        can,
        scoped,
        visiblePeople,
        update,
        notify,
        navigate,
        request,
        failure,
        setFailure,
        audit,
    } = api;
    const r = m.r;
    const [form, setForm] = useState({}),
        [file, setFile] = useState(null),
        [error, setError] = useState(''),
        [q, setQ] = useState('');
    if (m.r && !scoped.some((x) => x.id === m.r.id))
        return <Modal title="Record unavailable" onClose={close} />;
    if (['personEdit', 'authorityEdit', 'contactEdit'].includes(m.type))
        return <RecordAction m={m} close={close} />;
    if (['workEdit', 'task'].includes(m.type))
        return <WorkAction m={m} close={close} />;
    if (m.type === 'lifecycle') return <LifecycleAction m={m} close={close} />;
    if (m.type === 'convert') return <HandoverAction m={m} close={close} />;
    if (m.type === 'matchReview')
        return <MatchReviewAction m={m} close={close} />;
    if (
        [
            'infoRequest',
            'recipientResponse',
            'evidenceView',
            'evidenceEdit',
        ].includes(m.type)
    )
        return <EvidenceAction m={m} close={close} />;
    if (m.type === 'message')
        return (
            <EvidenceAction m={{ ...m, type: 'infoRequest' }} close={close} />
        );
    if (m.type === 'appointment')
        return <AppointmentAction m={m} close={close} />;
    if (m.type === 'proposal') return <ProposalAction m={m} close={close} />;
    if (m.type === 'fundingCase' && m.review)
        return <ReassessmentAction m={m} close={close} />;
    const gate = {
        intake: 'create',
        drafts: 'create',
        assign: 'assign',
        assessment: 'edit',
        contact: 'contact',
        message: 'disclose',
        retryMessage: 'disclose',
        task: 'tasks',
        addContact: 'contact',
        lifecycle: 'transition',
        duplicate: 'duplicates',
        funding: 'funding',
        fundingCase: 'funding',
        verify: 'verify',
        proposal: 'edit',
        approveProposal: 'verify',
        convert: 'convert',
        activate: 'activate',
        document: 'documents',
        reviewDocument: 'documents',
        viewDocument: 'sensitive',
        export: 'export',
    };
    if (
        (r && !scoped.some((record) => record.id === r.id)) ||
        (m.draft && (m.draft.role || 'Referral manager') !== role)
    )
        return (
            <Modal
                title="Record unavailable"
                description="Your current role does not have access to this record or draft."
                onClose={close}
            />
        );
    if (gate[m.type] && !can(gate[m.type]))
        return (
            <Modal
                title="Permission required"
                description={
                    'This action requires: ' + permissions[gate[m.type]] + '.'
                }
                onClose={close}
            >
                <Notice>
                    Your role, approved sites, ownership and privacy rules
                    control access.
                </Notice>
            </Modal>
        );
    if (
        ['assessment', 'document', 'reviewDocument', 'proposal'].includes(
            m.type,
        ) &&
        !can('sensitive')
    )
        return (
            <Modal
                title="Assessment permission required"
                description="This action also requires View assessments & evidence. You can continue other permitted referral work."
                onClose={close}
            />
        );
    if (
        ['viewDocument', 'reviewDocument'].includes(m.type) &&
        (!can('sensitive') ||
            !r.documents.some(
                (doc) =>
                    doc.id === m.document?.id && doc.state !== 'Quarantined',
            ))
    )
        return (
            <Modal
                title="Evidence unavailable"
                description="This evidence is quarantined or outside your permitted access."
                onClose={close}
            />
        );
    const save = (patch, text) => {
        update(r.id, patch, text);
        return { message: text };
    };
    const wizard = (title, steps, initial, onSave, extra = {}) => (
        <Wizard
            title={title}
            steps={steps}
            initial={initial}
            onClose={close}
            failure={failure}
            clearFailure={() => setFailure('Normal')}
            onSave={(v) =>
                can(gate[m.type]) &&
                (!r || scoped.some((record) => record.id === r.id))
                    ? onSave(v)
                    : {
                          error: 'Permission changed. This action is no longer available.',
                      }
            }
            {...extra}
        />
    );
    if (m.type === 'advancedFilters')
        return (
            <Wizard
                title="Refine workspace"
                onClose={close}
                initial={{
                    team: 'All teams',
                    service: 'All services',
                    channel: 'All channels',
                    holdReason: 'All hold reasons',
                    ...m.advanced,
                }}
                steps={[
                    {
                        label: 'Filter and sort',
                        render: (_v, set) => (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    Object.entries({
                                        owner: 'All owners',
                                        source: 'All sources',
                                        path: 'All pathways',
                                        fundingStatus: 'All funding states',
                                        priority: 'All priorities',
                                        sort: 'Due soonest',
                                        from: '',
                                        to: '',
                                        team: 'All teams',
                                        service: 'All services',
                                        channel: 'All channels',
                                        holdReason: 'All hold reasons',
                                    }).forEach(([k, v]) => set(k, v))
                                }
                            >
                                Reset advanced filters
                            </Button>
                        ),
                        fields: [
                            {
                                key: 'team',
                                label: 'Receiving team',
                                type: 'select',
                                options: [
                                    'All teams',
                                    'Central intake',
                                    'Supported living intake',
                                    'Funding navigators',
                                ],
                            },
                            {
                                key: 'service',
                                label: 'Requested service',
                                type: 'select',
                                options: [
                                    'All services',
                                    ...data.config.services,
                                ],
                            },
                            {
                                key: 'channel',
                                label: 'Preferred channel',
                                type: 'select',
                                options: [
                                    'All channels',
                                    'Phone',
                                    'Email',
                                    'Text callback request',
                                ],
                            },
                            {
                                key: 'holdReason',
                                label: 'Hold reason',
                                type: 'select',
                                options: [
                                    'All hold reasons',
                                    ...data.config.holdReasons,
                                ],
                            },
                            {
                                key: 'owner',
                                label: 'Owner / navigator',
                                type: 'select',
                                options: ['All owners', ...staff],
                            },
                            {
                                key: 'source',
                                label: 'Source',
                                type: 'select',
                                options: [
                                    'All sources',
                                    'Website',
                                    'Phone',
                                    'Email',
                                    'Professional',
                                    'Other',
                                ],
                            },
                            {
                                key: 'priority',
                                label: 'Priority',
                                type: 'select',
                                options: [
                                    'All priorities',
                                    'Standard',
                                    'High',
                                    'Urgent',
                                ],
                            },
                            {
                                key: 'path',
                                label: 'Pathway',
                                type: 'select',
                                options: [
                                    'All pathways',
                                    ...(can('fundingRead') ? pathways : []),
                                ],
                            },
                            {
                                key: 'fundingStatus',
                                label: 'Funding state',
                                type: 'select',
                                options: [
                                    'All funding states',
                                    'Preparing',
                                    'Submitted externally',
                                    'Awaiting assessment',
                                    'Review requested',
                                    'Acknowledged',
                                    'Awaiting decision',
                                    'Decision recorded',
                                    'Verified',
                                    'Withdrawn',
                                ],
                            },
                            {
                                key: 'sort',
                                label: 'Sort records',
                                type: 'select',
                                options: [
                                    'Due soonest',
                                    'Newest first',
                                    'Name A–Z',
                                ],
                            },
                            {
                                key: 'from',
                                label: 'Received from',
                                type: 'date',
                            },
                            {
                                key: 'to',
                                label: 'Received through',
                                type: 'date',
                            },
                        ],
                        validate: (v) =>
                            v.from && v.to && v.from > v.to
                                ? 'Choose a valid date range.'
                                : '',
                    },
                ]}
                onSave={(v) => {
                    m.apply(v);
                    return {
                        message:
                            'Workspace filters applied. You can save this view from the header.',
                    };
                }}
            />
        );
    if (m.type === 'intake')
        return wizard(
            'New referral',
            [
                {
                    label: 'Person & source',
                    fields: [
                        {
                            key: 'name',
                            label: 'Person’s full name',
                            required: true,
                        },
                        { key: 'preferred', label: 'Preferred name' },
                        {
                            key: 'source',
                            label: 'Source',
                            type: 'select',
                            options: [
                                'Phone',
                                'Professional',
                                'Email',
                                'In person',
                                'Other',
                            ],
                        },
                        {
                            key: 'relationship',
                            label: 'Referrer relationship',
                            type: 'select',
                            options: [
                                'Self',
                                'Whānau / family',
                                'Professional',
                                'Other',
                            ],
                        },
                        {
                            key: 'referrerName',
                            label: 'Caller / referrer name',
                            when: (v) => v.relationship !== 'Self',
                        },
                        { key: 'phone', label: 'Phone' },
                        { key: 'email', label: 'Email', type: 'email' },
                    ],
                    validate: (v) =>
                        !v.phone && !v.email
                            ? 'Enter a phone number or email for safe follow-up.'
                            : '',
                },
                {
                    label: 'Support & contact',
                    fields: [
                        {
                            key: 'needs',
                            label: 'What support is being sought?',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'safe',
                            label: 'Safe contact instructions',
                            required: true,
                        },
                        {
                            key: 'authority',
                            label: 'Authority to discuss',
                            type: 'select',
                            options: [
                                'To clarify',
                                'Confirmed',
                                'Professional referral authority',
                            ],
                        },
                        {
                            key: 'fundingHelp',
                            label: 'Funding help',
                            type: 'select',
                            options: [
                                'Help to get funding',
                                'Already funded',
                                'Not sure',
                                'Private payment',
                            ],
                        },
                    ],
                },
                {
                    label: 'Ownership & follow-up',
                    fields: [
                        {
                            key: 'site',
                            label: 'Receiving site',
                            type: 'search',
                            options: allowedSites(
                                data,
                                role,
                                m.type === 'intake',
                            ),
                            disabled: m.type === 'assessment',
                        },
                        {
                            key: 'owner',
                            label: 'Owner',
                            type: 'search',
                            options: (v) =>
                                can('assign')
                                    ? eligibleOwners(data, v.site)
                                    : ['Intake team'],
                        },
                        {
                            key: 'due',
                            label: 'First contact due',
                            type: 'date',
                            required: true,
                        },
                        { key: 'next', label: 'Next action', required: true },
                    ],
                },
            ],
            m.draft?.values || {
                name: '',
                source: 'Phone',
                relationship: 'Whānau / family',
                safe: 'Ask for preferred contact time',
                authority: 'To clarify',
                needs: '',
                site: 'Central intake',
                owner: 'Intake team',
                fundingHelp: 'Help to get funding',
                due: businessDate(
                    data.config.firstContact,
                    undefined,
                    data.config,
                ),
                next: 'Confirm safe contact and authority',
            },
            (v) => {
                if (
                    !allowedSites(data, role, true).includes(v.site) ||
                    !eligibleOwners(data, v.site).includes(v.owner)
                )
                    return {
                        error: 'Select an eligible owner for this receiving site.',
                    };
                const created = makeReferral({
                    ...v,
                    safeContactPerson: v.referrerName || v.name,
                    safeContactPhone: v.phone,
                    safeContactEmail: v.email,
                    referrerRecord:
                        v.relationship !== 'Self'
                            ? {
                                  name: v.referrerName || 'Name to clarify',
                                  phone: v.phone,
                                  email: v.email,
                                  relationship: v.relationship,
                              }
                            : null,
                    phone: v.relationship === 'Self' ? v.phone : '',
                    email: v.relationship === 'Self' ? v.email : '',
                    generalSummary:
                        'Staff-recorded enquiry; safe contact details recorded',
                    team: data.config.routing,
                    version: data.config.version,
                    funding:
                        v.fundingHelp === 'Help to get funding'
                            ? [
                                  {
                                      ...makeFunding(
                                          'DSS / NASC',
                                          data.config.version,
                                          data.config.requirements[
                                              'DSS / NASC'
                                          ],
                                      ),
                                      navigator: eligibleOwners(
                                          data,
                                          v.site,
                                      ).includes('Sam Patel')
                                          ? 'Sam Patel'
                                          : eligibleOwners(data, v.site)[0],
                                      service: v.service || 'Supported living',
                                      due: businessDate(
                                          data.config.reviewDays,
                                          undefined,
                                          data.config,
                                      ),
                                      assistanceReason:
                                          'Help accessing funding requested during staff intake.',
                                  },
                              ]
                            : [],
                });
                setData((s) =>
                    audit(
                        {
                            ...s,
                            records: [created, ...s.records],
                            drafts: s.drafts.filter(
                                (d) => d.id !== m.draft?.id,
                            ),
                        },
                        'Created ' + created.id,
                    ),
                );
                return {
                    message:
                        created.id +
                        (canReadRecord(data, role, created)
                            ? ' is in your permitted referral queue.'
                            : ' has been handed to central intake and is outside your current viewing scope.') +
                        ' ' +
                        created.owner +
                        ' is accountable for the next contact by ' +
                        date(created.due) +
                        '.',
                };
            },
            {
                submitLabel: 'Create referral',
                onDraft: (v) => {
                    setData((s) => ({
                        ...s,
                        drafts: [
                            {
                                id: m.draft?.id || crypto.randomUUID(),
                                role,
                                savedAt: new Date().toLocaleString('en-NZ', {
                                    timeZone: 'Pacific/Auckland',
                                }),
                                values: v,
                            },
                            ...s.drafts.filter((d) => d.id !== m.draft?.id),
                        ],
                    }));
                    notify('Intake draft saved');
                },
            },
        );
    if (m.type === 'assign')
        return wizard(
            'Assign referral',
            [
                {
                    label: 'Accountable team & owner',
                    fields: [
                        {
                            key: 'site',
                            label: 'Approved site',
                            type: 'search',
                            options: allowedSites(data, role),
                        },
                        {
                            key: 'team',
                            label: 'Team',
                            type: 'select',
                            options: [
                                'Central intake',
                                'Supported living intake',
                                'Funding navigators',
                            ],
                        },
                        {
                            key: 'owner',
                            label: 'Named owner',
                            type: 'search',
                            options: (v) => eligibleOwners(data, v.site),
                        },
                    ],
                    validate: (v) =>
                        !allowedSites(data, role).includes(v.site) ||
                        !eligibleOwners(data, v.site).includes(v.owner)
                            ? 'Choose an eligible owner for the approved receiving site.'
                            : '',
                },
                {
                    label: 'Handover instruction',
                    fields: [
                        { key: 'next', label: 'Next action', required: true },
                        {
                            key: 'due',
                            label: 'Follow-up due',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'reason',
                            label: 'Assignment note',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                },
            ],
            {
                site: r.site,
                team: r.team,
                owner: r.owner,
                next: r.next,
                due: r.due,
            },
            (v) =>
                save(
                    { ...v, stage: r.stage === 'New' ? 'Triage' : r.stage },
                    'Assigned to ' + v.owner + ' · ' + v.reason,
                ),
        );
    if (m.type === 'assessment')
        return wizard(
            'Review person & readiness',
            [
                {
                    label: 'Person & preferences',
                    fields: [
                        { key: 'preferred', label: 'Preferred name' },
                        { key: 'dob', label: 'Date of birth', type: 'date' },
                        {
                            key: 'safe',
                            label: 'Safe contact instructions',
                            required: true,
                        },
                        {
                            key: 'needs',
                            label: 'What matters to the person',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'site',
                            label: 'Receiving site',
                            type: 'search',
                            options: allowedSites(
                                data,
                                role,
                                m.type === 'intake',
                            ),
                        },
                    ],
                },
                {
                    label: 'Authority & suitability',
                    notice: 'Record the basis and the person’s wishes. Never infer authority from a family relationship.',
                    fields: [
                        {
                            key: 'authority',
                            label: 'Authority basis',
                            required: true,
                        },
                        {
                            key: 'consent',
                            label: 'Sharing permission / lawful basis recorded',
                            type: 'check',
                        },
                        {
                            key: 'choice',
                            label: 'Person chooses this provider',
                            type: 'check',
                        },
                        {
                            key: 'identity',
                            label: 'Identity checked',
                            type: 'check',
                        },
                        {
                            key: 'suitability',
                            label: 'Requested support is suitable',
                            type: 'check',
                        },
                        {
                            key: 'capacity',
                            label: 'Capacity is available',
                            type: 'check',
                        },
                        {
                            key: 'agreement',
                            label: 'Service agreement accepted',
                            type: 'check',
                        },
                        {
                            key: 'safety',
                            label: 'Safe start plan agreed',
                            type: 'check',
                        },
                        {
                            key: 'basis',
                            label: 'Review note / evidence',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                },
            ],
            { ...r, basis: '' },
            (v) =>
                save(
                    {
                        ...Object.fromEntries(
                            [
                                'preferred',
                                'dob',
                                'safe',
                                'needs',
                                'site',
                                'authority',
                                'consent',
                                'choice',
                                'identity',
                                'suitability',
                                'capacity',
                                'agreement',
                                'safety',
                            ].map((k) => [k, v[k]]),
                        ),
                        authorityState: v.consent ? 'Current' : 'To clarify',
                        authorityScopes: {
                            ...r.authorityScopes,
                            disclose: !!v.consent,
                            service: !!v.agreement,
                        },
                        needsAudience: 'Restricted assessment',
                        assessmentReviews: [
                            ...(r.assessmentReviews || []),
                            {
                                at: stamp(),
                                by: actorFor(data, role),
                                note: v.basis,
                                checks: Object.fromEntries(
                                    [
                                        'identity',
                                        'consent',
                                        'choice',
                                        'suitability',
                                        'capacity',
                                        'agreement',
                                        'safety',
                                    ].map((k) => [k, !!v[k]]),
                                ),
                            },
                        ],
                    },
                    'Assessment and readiness updated',
                ),
        );
    if (m.type === 'contact' || m.type === 'message')
        return wizard(
            m.type === 'message'
                ? 'Preview information request'
                : 'Log contact',
            [
                {
                    label: 'Contact details',
                    fields: [
                        {
                            key: 'occurredDate',
                            label: 'Contact occurred on',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'occurredTime',
                            label: 'Contact time',
                            type: 'time',
                            required: true,
                        },
                        {
                            key: 'channel',
                            label: 'Channel',
                            type: 'select',
                            options:
                                m.type === 'message'
                                    ? ['Email', 'SMS']
                                    : [
                                          'Phone',
                                          'Email',
                                          'In person',
                                          'Video call',
                                      ],
                        },
                        {
                            key: 'recipient',
                            label: 'Contact / recipient',
                            required: true,
                        },
                        {
                            key: 'outcome',
                            label: 'Outcome',
                            type: 'select',
                            options: [
                                'Information requested',
                                'Spoke with person',
                                'Left safe callback request',
                                'No answer',
                                'Person declined contact',
                            ],
                        },
                    ],
                },
                {
                    label: 'Message & follow-up',
                    notice: 'Safe contact: ' + r.safe,
                    fields: [
                        {
                            key: 'audience',
                            label: 'Note audience',
                            type: 'select',
                            options: can('sensitive')
                                ? ['General intake', 'Restricted assessment']
                                : ['General intake'],
                        },
                        {
                            key: 'body',
                            label:
                                m.type === 'message'
                                    ? 'Message preview'
                                    : 'Contact note',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'due',
                            label: 'Next follow-up',
                            type: 'date',
                            required: true,
                        },
                        { key: 'next', label: 'Next action', required: true },
                    ],
                },
            ],
            {
                channel: m.type === 'message' ? 'Email' : 'Phone',
                recipient:
                    m.type === 'message'
                        ? r.safeContactEmail || r.email || ''
                        : r.safeContactPhone ||
                          r.phone ||
                          r.safeContactPerson ||
                          r.name,
                audience: 'General intake',
                occurredDate: TODAY,
                occurredTime: new Intl.DateTimeFormat('en-NZ', {
                    timeZone: 'Pacific/Auckland',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                }).format(new Date()),
                outcome: 'Information requested',
                body:
                    m.type === 'message'
                        ? data.config.messageTemplate.replaceAll(
                              '{name}',
                              r.preferred || r.name,
                          )
                        : '',
                due: r.due,
                next: r.next,
            },
            (v) =>
                save(
                    {
                        notes: [
                            {
                                ...v,
                                at: stamp(),
                                by: actorFor(data, role),
                                occurredAt:
                                    v.occurredDate + 'T' + v.occurredTime,
                                successful: v.outcome === 'Spoke with person',
                                delivery:
                                    m.type === 'message'
                                        ? failure === 'Delivery failure'
                                            ? 'Failed'
                                            : 'Simulated delivery'
                                        : 'Logged',
                            },
                            ...r.notes,
                        ],
                        due: v.due,
                        next: v.next,
                    },
                    m.type === 'message'
                        ? 'Information request preview recorded'
                        : 'Contact logged',
                ),
            {
                submitLabel:
                    m.type === 'message' ? 'Simulate delivery' : 'Save contact',
            },
        );
    if (m.type === 'retryMessage')
        return (
            <Modal
                title="Retry delivery preview"
                description="The earlier attempt stays in the history. No real message is sent."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                update(
                                    r.id,
                                    {
                                        notes: r.notes.map((n, i) =>
                                            i === m.noteIndex
                                                ? {
                                                      ...n,
                                                      delivery:
                                                          'Simulated delivery',
                                                      body:
                                                          n.body +
                                                          '\nRetry: simulated successfully.',
                                                  }
                                                : n,
                                        ),
                                    },
                                    'Delivery retried in prototype',
                                );
                                setFailure('Normal');
                                close();
                            }}
                        >
                            Simulate retry
                        </Button>
                    </>
                }
            >
                <p>{r.notes[m.noteIndex].body}</p>
            </Modal>
        );
    if (m.type === 'task')
        return wizard(
            'Plan follow-up',
            [
                {
                    label: 'Task & owner',
                    fields: [
                        {
                            key: 'label',
                            label: 'What needs to happen?',
                            required: true,
                        },
                        {
                            key: 'owner',
                            label: 'Owner',
                            type: 'search',
                            options: staff,
                        },
                        {
                            key: 'due',
                            label: 'Due date',
                            type: 'date',
                            required: true,
                        },
                    ],
                },
            ],
            { label: '', owner: r.owner, due: r.due },
            (v) =>
                save(
                    {
                        tasks: [
                            ...r.tasks,
                            { ...v, id: crypto.randomUUID(), done: false },
                        ],
                        next: v.label,
                        due: v.due,
                    },
                    'Follow-up scheduled: ' + v.label,
                ),
        );
    if (m.type === 'lifecycle')
        return wizard(
            'Review stage or closure',
            [
                {
                    label: 'Next position',
                    fields: [
                        {
                            key: 'stage',
                            label: 'Stage',
                            type: 'select',
                            options: data.config.stages.filter(
                                (s) => s !== 'Converted',
                            ),
                        },
                        {
                            key: 'reason',
                            label: 'Reason and person’s preference',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'due',
                            label: 'Review date',
                            type: 'date',
                            required: true,
                            when: (v) => v.stage !== 'Closed',
                        },
                        {
                            key: 'next',
                            label: 'Next action',
                            required: true,
                            when: (v) => v.stage !== 'Closed',
                        },
                    ],
                    validate: (v) =>
                        v.stage === 'Ready for handover' &&
                        conversionBlocks(r).length
                            ? 'Complete the conversion readiness checks before marking ready.'
                            : '',
                },
            ],
            {
                stage: r.stage === 'Converted' ? 'Assessment' : r.stage,
                due: r.due,
                next: r.next,
            },
            (v) =>
                save(
                    {
                        stage: v.stage,
                        due: v.due,
                        next:
                            v.stage === 'Closed'
                                ? 'Closed · ' + v.reason
                                : v.next,
                    },
                    'Stage changed to ' + v.stage + ': ' + v.reason,
                ),
        );
    if (m.type === 'funding')
        return wizard(
            'Add funding pathway',
            [
                {
                    label: 'Pathway & navigator',
                    fields: [
                        {
                            key: 'path',
                            label: 'Funding pathway',
                            type: 'search',
                            options: pathways,
                        },
                        {
                            key: 'navigator',
                            label: 'Navigator',
                            type: 'search',
                            options: eligibleOwners(data, r.site),
                        },
                        {
                            key: 'requested',
                            label: 'Requested weekly service contribution (NZD)',
                            type: 'number',
                            when: (v) =>
                                v.path !== 'Personal income assistance',
                        },
                        {
                            key: 'reason',
                            label: 'Person’s preference / assistance requested',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        !eligibleOwners(data, r.site).includes(v.navigator)
                            ? 'Choose an available navigator with access to this site.'
                            : '',
                },
            ],
            {
                path: 'DSS / NASC',
                navigator: eligibleOwners(data, r.site).includes('Sam Patel')
                    ? 'Sam Patel'
                    : eligibleOwners(data, r.site)[0],
                requested: null,
            },
            (v) =>
                save(
                    {
                        funding: [
                            ...r.funding,
                            {
                                ...makeFunding(
                                    v.path,
                                    data.config.version,
                                    data.config.requirements[v.path],
                                ),
                                navigator: v.navigator,
                                service: r.service,
                                assistanceReason: v.reason,
                                due: businessDate(
                                    data.config.reviewDays,
                                    undefined,
                                    data.config,
                                ),
                                requested: knownNumber(v.requested)
                                    ? Number(v.requested)
                                    : null,
                            },
                        ],
                    },
                    'Funding assistance opened: ' + v.path,
                ),
        );
    if (m.type === 'fundingCase') {
        const f = m.funding;
        return wizard(
            'Work on ' + f.path,
            [
                {
                    label: 'Preparation & assessment',
                    validate: (v) =>
                        !eligibleOwners(data, r.site).includes(v.navigator)
                            ? 'Choose an available navigator with access to this site.'
                            : '',
                    notice: f.income
                        ? 'Income support is separate from service purchasing authority.'
                        : f.path === 'Private payment'
                          ? 'Record payer agreement, not a government funding award.'
                          : 'Record external contact and evidence. This workspace does not determine eligibility.',
                    fields: [
                        {
                            key: 'navigator',
                            label: 'Navigator',
                            type: 'search',
                            options: eligibleOwners(data, r.site),
                        },

                        ...f.checks.map((c, i) => ({
                            key: 'check' + i,
                            label: c.label,
                            type: 'check',
                        })),
                    ],
                },
                {
                    label: 'Submission / external decision',
                    fields: [
                        {
                            key: 'status',
                            label: 'Case status',
                            type: 'select',
                            options: [
                                'Preparing',
                                'Awaiting assessment',
                                'Submitted externally',
                                'Acknowledged',
                                'Awaiting decision',
                                'Decision recorded',
                                'Review requested',
                                'Withdrawn',
                            ],
                        },
                        {
                            key: 'reference',
                            label: 'External reference / payer agreement',
                        },
                        {
                            key: 'evidence',
                            label: 'Evidence or submission record',
                            type: 'textarea',
                        },
                        {
                            key: 'decision',
                            label:
                                f.path === 'Private payment'
                                    ? 'Payer response'
                                    : 'External decision',
                            type: 'select',
                            options:
                                f.path === 'Private payment'
                                    ? [
                                          'Not received',
                                          'Accepted',
                                          'Partially accepted',
                                          'Declined',
                                      ]
                                    : [
                                          'Not received',
                                          'Full approval',
                                          'Partial approval',
                                          'Conditional approval',
                                          'Declined',
                                      ],
                        },
                        {
                            key: 'amount',
                            label: 'Confirmed weekly service contribution (NZD)',
                            type: 'number',
                            when: () => !f.income,
                        },
                        ...(!f.income ? fundingTermFields : []),
                        {
                            key: 'conditions',
                            label: 'Conditions, scope and validity period',
                            type: 'textarea',
                        },
                    ],
                    validate: (v) =>
                        [
                            'Submitted externally',
                            'Decision recorded',
                            'Review requested',
                        ].includes(v.status) &&
                        (!v.reference || !v.evidence)
                            ? 'A reference and evidence are required for submission or decision records.'
                            : v.status === 'Submitted externally' &&
                                !f.checks.every((c, i) => v['check' + i])
                              ? 'Complete the pathway preparation checklist first.'
                              : v.status === 'Decision recorded' &&
                                  v.decision === 'Not received'
                                ? 'Select the external decision.'
                                : '',
                },
                {
                    label: 'Next step & review',
                    fields: [
                        { key: 'next', label: 'Next action', required: true },
                        {
                            key: 'due',
                            label: 'Follow-up / review deadline',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'deadlineSource',
                            label: 'Deadline source',
                            hint: 'Record an agency letter or agreed follow-up; no automatic legal deadline is assumed.',
                        },
                    ],
                },
            ],
            {
                ...f,
                status:
                    f.status === 'Verified'
                        ? 'Decision recorded'
                        : m.review
                          ? 'Review requested'
                          : f.status,
                decision: f.decision || 'Not received',
                next: f.next || r.next,
                due: f.due || r.due,
                ...Object.fromEntries(
                    f.checks.map((c, i) => ['check' + i, c.done]),
                ),
            },
            (v) => {
                const material = decisionChanged(f, v);
                const updated = {
                    ...f,
                    ...v,
                    amount: knownNumber(v.amount) ? Number(v.amount) : null,
                    status:
                        f.verified &&
                        !material &&
                        v.status === 'Decision recorded'
                            ? 'Verified'
                            : v.status,
                    verified:
                        f.verified && !material && v.status !== 'Withdrawn',
                    decisions: material
                        ? [
                              ...(f.decisions || []),
                              {
                                  ...clone(f),
                                  decisions: undefined,
                                  at: stamp(),
                                  by: actorFor(data, role),
                              },
                          ]
                        : f.decisions || [],
                    checks: f.checks.map((c, i) => ({
                        ...c,
                        done: !!v['check' + i],
                    })),
                    history: [
                        ...f.history,
                        `${v.status}: ${v.decision} · ${v.reference || 'No reference'} · ${v.evidence || 'Preparation updated'}`,
                    ],
                };
                return save(
                    {
                        funding: r.funding.map((x) =>
                            x.id === f.id ? updated : x,
                        ),
                    },
                    material
                        ? 'Decision revision saved; renewed verification required'
                        : 'Funding work updated; decision verification preserved',
                );
            },
        );
    }
    if (m.type === 'verify') {
        const f = m.funding;
        return wizard(
            'Verify funding decision',
            [
                {
                    label: 'Evidence & scope',
                    notice: `${f.path} · ${f.decision || 'No decision'} · ${money(f.amount)} per week. ${f.evidence || 'No evidence supplied.'}`,
                    fields: [
                        {
                            key: 'checked',
                            label: 'I checked the external evidence and delegated authority',
                            type: 'check',
                        },
                        {
                            key: 'conditionsMet',
                            label: 'Scope, conditions and validity are confirmed',
                            type: 'check',
                        },
                        {
                            key: 'note',
                            label: 'Verification note',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        !f.reference || !f.evidence
                            ? 'A reference and evidence are required.'
                            : !v.checked || !v.conditionsMet
                              ? 'Both verification confirmations are required.'
                              : f.decision === 'Declined' &&
                                  Number(f.amount) > 0
                                ? 'A declined decision cannot contribute service funding. Correct the amount first.'
                                : !f.income &&
                                    fundingIssues({ ...f, verified: true }, r)
                                        .length
                                  ? fundingIssues(
                                        { ...f, verified: true },
                                        r,
                                    ).join('; ')
                                  : '',
                },
            ],
            {},
            (v) =>
                save(
                    {
                        funding: r.funding.map((x) =>
                            x.id === f.id
                                ? {
                                      ...x,
                                      verified: true,
                                      verifiedAt: stamp(),
                                      verifiedBy: actorFor(data, role),
                                      status: 'Verified',
                                      history: [
                                          ...x.history,
                                          'Verified by ' + role + ': ' + v.note,
                                      ],
                                  }
                                : x,
                        ),
                    },
                    'External funding decision verified',
                ),
        );
    }
    if (m.type === 'proposal')
        return wizard(
            'Support proposal',
            [
                {
                    label: 'Scope & payer split',
                    fields: [
                        {
                            key: 'service',
                            label: 'Service',
                            type: 'select',
                            options: [
                                'Supported living',
                                'Community participation',
                                'Additional supported living',
                            ],
                        },
                        {
                            key: 'weekly',
                            label: 'Total weekly cost (NZD)',
                            type: 'number',
                            required: true,
                        },
                        {
                            key: 'payer',
                            label: 'Primary payer / host',
                            required: true,
                        },
                        {
                            key: 'privateAmount',
                            label: 'Planned private share (NZD)',
                            type: 'number',
                        },
                        {
                            key: 'capacity',
                            label: 'Capacity confirmed',
                            type: 'check',
                        },
                        {
                            key: 'accepted',
                            label: 'Person / payer accepts this proposal',
                            type: 'check',
                        },
                    ],
                    validate: (v) =>
                        Number(v.privateAmount) > Number(v.weekly)
                            ? 'The private share cannot exceed the total proposal.'
                            : '',
                },
                {
                    label: 'Rationale',
                    fields: [
                        {
                            key: 'note',
                            label: 'Support, frequency and agreement evidence',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                },
            ],
            { ...r.proposal, service: r.service, capacity: r.capacity },
            (v) =>
                save(
                    {
                        service: v.service,
                        capacity: v.capacity,
                        proposal: {
                            weekly: Number(v.weekly),
                            payer: v.payer,
                            privateAmount: Number(v.privateAmount),
                            accepted: v.accepted,
                            approved: false,
                        },
                    },
                    'Proposal revised; internal approval required again',
                ),
        );
    if (m.type === 'approveProposal')
        return wizard(
            'Approve support proposal',
            [
                {
                    label: 'Internal review',
                    notice: `${money(r.proposal.weekly)} per week · ${r.proposal.payer}. Funding verification and service-start checks remain separate.`,
                    fields: [
                        {
                            key: 'approved',
                            label: 'Scope, rate and capacity reviewed',
                            type: 'check',
                        },
                        {
                            key: 'note',
                            label: 'Approval rationale',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        data.config.separateReviewer &&
                        r.proposal.authoredBy === actorFor(data, role)
                            ? 'A different delegated reviewer must approve this proposal.'
                            : Number(r.proposal.weekly) >
                                    Number(data.config.approvalLimit) &&
                                !can('governance')
                              ? 'This amount exceeds your approval delegation. A referral manager must review it.'
                              : !v.approved
                                ? 'Confirm the internal review before approving.'
                                : '',
                },
            ],
            {},
            (v) =>
                save(
                    {
                        proposal: {
                            ...r.proposal,
                            approved: true,
                            approvedBy: actorFor(data, role),
                            approvedAt: stamp(),
                            approvalNote: v.note,
                        },
                    },
                    'Proposal approved: ' + v.note,
                ),
        );
    if (m.type === 'convert') {
        const blocks = conversionBlocks(r);
        const pending = coverage(r) < Number(r.proposal.weekly);
        return wizard(
            'Convert to supported person',
            [
                {
                    label: 'Match & destination',
                    notice: r.personId
                        ? 'Already linked to ' +
                          r.personId +
                          '. Conversion is idempotent and will not create another person.'
                        : 'Check existing identity before creating a record. Restricted matches must be reviewed by an authorized manager.',
                    fields: [
                        {
                            key: 'target',
                            label: 'Create or link',
                            type: 'select',
                            options: [
                                {
                                    value: 'new',
                                    label: 'Create new onboarding record',
                                },
                                ...visiblePeople.map((p) => ({
                                    value: p.id,
                                    label: `Link ${p.name} (${p.id})`,
                                })),
                            ],
                        },
                        {
                            key: 'matchChecked',
                            label: 'I checked identity and possible duplicates',
                            type: 'check',
                        },
                    ],
                    validate: (v) =>
                        !v.matchChecked
                            ? 'Confirm the identity match review.'
                            : blocks.length
                              ? 'Required: ' + blocks.join('; ')
                              : (r.existingPerson ||
                                      data.people.some(
                                          (p) =>
                                              p.name.toLowerCase() ===
                                              r.name.toLowerCase(),
                                      )) &&
                                  v.target === 'new'
                                ? 'This referral already matches an existing person. Choose the canonical record.'
                                : v.target !== 'new' &&
                                    (!visiblePeople.some(
                                        (p) => p.id === v.target,
                                    ) ||
                                        (v.target !== r.existingPerson &&
                                            visiblePeople
                                                .find((p) => p.id === v.target)
                                                ?.name.toLowerCase() !==
                                                r.name.toLowerCase()))
                                  ? 'The selected person has a different identity. Review the match before linking.'
                                  : '',
                },
                {
                    label: 'Transfer & funding position',
                    notice: 'Copies identity, safe contact, sharing basis and requested support. Evidence stays access controlled. Open funding and follow-ups remain active.',
                    fields: [
                        {
                            key: 'ack',
                            label: 'I reviewed the information to transfer',
                            type: 'check',
                        },
                        {
                            key: 'pending',
                            label: 'Acknowledge pending funding — onboarding only',
                            type: 'check',
                            when: () => pending,
                        },
                    ],
                    validate: (v) =>
                        !v.ack
                            ? 'Review and acknowledge the transfer.'
                            : pending && !data.config.allowPending
                              ? 'Current settings require verified funding before conversion.'
                              : pending && !v.pending
                                ? 'Acknowledge that this creates onboarding only; services cannot start yet.'
                                : '',
                },
            ],
            { target: r.personId || r.existingPerson || 'new' },
            (v) => {
                if (r.personId)
                    return {
                        message:
                            'Already linked to ' +
                            r.personId +
                            '. No duplicate was created.',
                    };
                const id =
                    v.target === 'new'
                        ? 'SP-' + Math.floor(3000 + Math.random() * 6000)
                        : v.target;
                setData((s) =>
                    audit(
                        {
                            ...s,
                            people:
                                v.target === 'new'
                                    ? [
                                          ...s.people,
                                          {
                                              id,
                                              name: r.name,
                                              status: 'Onboarding',
                                              site: r.site,
                                              services: [r.service],
                                              safe: r.safe,
                                              phone: r.phone,
                                          },
                                      ]
                                    : s.people.map((p) =>
                                          p.id === id
                                              ? {
                                                    ...p,
                                                    services: [
                                                        ...new Set([
                                                            ...p.services,
                                                            r.service,
                                                        ]),
                                                    ],
                                                }
                                              : p,
                                      ),
                            records: s.records.map((x) =>
                                x.id === r.id
                                    ? {
                                          ...x,
                                          stage: 'Converted',
                                          personId: id,
                                          activity: [
                                              {
                                                  at: stamp(),
                                                  by: actorFor(data, role),
                                                  text:
                                                      'Linked to ' +
                                                      id +
                                                      '; funding and tasks retained',
                                              },
                                              ...x.activity,
                                          ],
                                      }
                                    : x,
                            ),
                        },
                        'Converted ' + r.id + ' into ' + id,
                    ),
                );
                return {
                    message: `Linked to ${id}. ${v.target === 'new' ? 'The new person is in Onboarding.' : 'Existing active status and verified identity were preserved.'} Funding assistance and outstanding tasks continue.`,
                };
            },
            { submitLabel: 'Confirm handover' },
        );
    }
    if (m.type === 'activate')
        return wizard(
            'Approve service start',
            [
                {
                    label: 'Service commencement',
                    notice: 'A future date schedules support; it does not mark it as started. Return on or after that date to confirm commencement. Existing active services keep their status.',
                    fields: [
                        {
                            key: 'start',
                            label: 'Agreed start date',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'note',
                            label: 'Approval / safe-start handover',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        activationBlocks({
                            ...r,
                            proposal: { ...r.proposal, start: v.start },
                        }).length
                            ? 'Unmet requirements: ' +
                              activationBlocks({
                                  ...r,
                                  proposal: { ...r.proposal, start: v.start },
                              }).join('; ')
                            : !r.personId
                              ? 'Complete referral conversion first.'
                              : '',
                },
            ],
            { start: r.serviceStart?.date || r.proposal.start || TODAY },
            (v) => {
                const person = data.people.find((p) => p.id === r.personId);
                if (!person)
                    return {
                        error: 'The linked person must be available before approving service start.',
                    };
                const changes = serviceStartUpdate(
                    r,
                    person,
                    v,
                    actorFor(data, role),
                );
                setData((s) =>
                    audit(
                        {
                            ...s,
                            people: s.people.map((p) =>
                                p.id === r.personId ? changes.person : p,
                            ),
                            records: s.records.map((x) =>
                                x.id === r.id
                                    ? {
                                          ...x,
                                          ...changes.record,
                                          activity: [
                                              {
                                                  at: stamp(),
                                                  by: actorFor(data, role),
                                                  text:
                                                      (changes.record
                                                          .serviceStarted
                                                          ? 'Service commenced: '
                                                          : 'Service scheduled: ') +
                                                      v.start,
                                              },
                                              ...x.activity,
                                          ],
                                      }
                                    : x,
                            ),
                        },
                        'Service ' +
                            changes.record.serviceStart.status.toLowerCase() +
                            ' ' +
                            r.personId,
                    ),
                );
                return {
                    message: changes.record.serviceStarted
                        ? 'Service commencement recorded for ' + date(v.start)
                        : 'Support scheduled for ' +
                          date(v.start) +
                          '. Confirm commencement after the first support starts; onboarding remains open.',
                };
            },
        );
    if (m.type === 'duplicate')
        return wizard(
            'Review possible duplicate',
            [
                {
                    label: 'Canonical referral',
                    notice: 'Only permitted records are searchable. A privacy-restricted match must go to an authorized reviewer without exposing identity.',
                    fields: [
                        {
                            key: 'target',
                            label: 'Canonical referral',
                            type: 'search',
                            options: scoped
                                .filter((x) => x.id !== r.id)
                                .map((x) => x.id + ' · ' + x.name),
                        },
                        {
                            key: 'confirmed',
                            label: 'Identity verified — these are the same person and enquiry',
                            type: 'check',
                        },
                        {
                            key: 'reason',
                            label: 'Evidence and resolution note',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                    validate: (v) =>
                        !v.target || !v.confirmed
                            ? 'Choose a canonical referral and confirm the identity match.'
                            : '',
                },
            ],
            {},
            (v) =>
                save(
                    {
                        stage: 'Closed',
                        duplicateOf: v.target.split(' · ')[0],
                        next: 'Duplicate linked to ' + v.target,
                    },
                    'Duplicate resolved without merging evidence: ' + v.reason,
                ),
        );
    if (m.type === 'document')
        return (
            <Modal
                title="Add evidence"
                description="Use fictional files only. Nothing is uploaded."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            disabled={!file}
                            onClick={() => {
                                const doc = {
                                    id: crypto.randomUUID(),
                                    name: file.name,
                                    state:
                                        failure === 'Quarantined document'
                                            ? 'Quarantined'
                                            : 'Available',
                                    classification:
                                        form.classification ||
                                        'Funding evidence',
                                    text:
                                        form.text ||
                                        'Local file staged for this prototype. File bytes are not persisted; choose the synthetic example to preview actual content.',
                                };
                                update(
                                    r.id,
                                    { documents: [...r.documents, doc] },
                                    'Evidence staged: ' + doc.name,
                                );
                                if (failure === 'Quarantined document')
                                    setFailure('Normal');
                                close();
                            }}
                        >
                            Add to prototype
                        </Button>
                    </>
                }
            >
                <FileDropzone
                    onFiles={(files) => {
                        setFile(files[0]);
                        setForm({ ...form, text: '' });
                    }}
                    multiple={false}
                    accept=".txt,.pdf,.png,.jpg"
                    hint="Fictional TXT, PDF or image · metadata only for local files"
                />
                {file && (
                    <StagedFileCard
                        file={file}
                        onRemove={() => setFile(null)}
                    />
                )}
                <Button
                    variant="outline"
                    onClick={() => {
                        setFile(
                            new File(
                                ['Synthetic evidence'],
                                'Sample-funding-evidence.txt',
                                { type: 'text/plain' },
                            ),
                        );
                        setForm({
                            ...form,
                            text: `FICTIONAL EVIDENCE — NOT AN AGENCY DOCUMENT\nPerson: ${r.name}\nPurpose: supported living funding discussion\nAllocation: sample only, not a verified award.\nScope and start dates must be checked externally.`,
                        });
                    }}
                >
                    Use synthetic example
                </Button>
                <Field
                    label="Classification"
                    value={form.classification || 'Funding evidence'}
                    type="select"
                    options={[
                        'Funding evidence',
                        'Consent / authority',
                        'Assessment',
                        'Service agreement',
                    ]}
                    onChange={(v) => setForm({ ...form, classification: v })}
                />
            </Modal>
        );
    if (m.type === 'viewDocument')
        return (
            <Modal
                title={m.document.name}
                description={
                    m.document.name.endsWith('.txt')
                        ? 'Synthetic text evidence viewer'
                        : 'Document staging preview'
                }
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Close
                        </Button>
                        {m.document.name.endsWith('.txt') && (
                            <Button
                                onClick={() =>
                                    download(m.document.name, m.document.text)
                                }
                            >
                                Download text
                            </Button>
                        )}
                    </>
                }
            >
                {m.document.name.endsWith('.txt') ? (
                    <pre className="document-content">{m.document.text}</pre>
                ) : (
                    <Notice>
                        The local file’s metadata was staged. Its contents are
                        not uploaded or retained by this prototype. Use the
                        synthetic text example to try the content viewer.
                    </Notice>
                )}
            </Modal>
        );
    if (m.type === 'reviewDocument')
        return wizard(
            'Review evidence',
            [
                {
                    label: 'Document review',
                    notice: m.document.name,
                    fields: [
                        {
                            key: 'note',
                            label: 'Review outcome',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'checked',
                            label: 'Content, person and scope checked',
                            type: 'check',
                        },
                    ],
                    validate: (v) =>
                        !v.checked
                            ? 'Confirm that the document was reviewed.'
                            : '',
                },
            ],
            {},
            (v) =>
                save(
                    {
                        documents: r.documents.map((d) =>
                            d.id === m.document.id
                                ? { ...d, state: 'Reviewed' }
                                : d,
                        ),
                    },
                    'Evidence reviewed: ' + v.note,
                ),
        );
    if (m.type === 'export')
        return (
            <Modal
                title="Export preview"
                description="Only records within your current role, site and filters are included."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        {m.report && (
                            <Button
                                onClick={() => {
                                    download(
                                        'fictional-referral-report-summary.csv',
                                        reportCsv(m.report),
                                        'text/csv',
                                    );
                                    setData((s) =>
                                        audit(
                                            s,
                                            'Exported fictional report summary: ' +
                                                m.report.title,
                                        ),
                                    );
                                    close();
                                }}
                            >
                                Download report CSV
                            </Button>
                        )}
                        <Button
                            onClick={() => {
                                download(
                                    'fictional-referral-report.csv',
                                    'Reference,Person,Stage,Source,Owner,Due\n' +
                                        m.records
                                            .map((x) =>
                                                [
                                                    x.id,
                                                    x.name,
                                                    x.stage,
                                                    x.source,
                                                    x.owner,
                                                    x.due,
                                                ]
                                                    .map(csvCell)
                                                    .join(','),
                                            )
                                            .join('\n'),
                                    'text/csv',
                                );
                                setData((s) =>
                                    audit(
                                        s,
                                        'Exported ' +
                                            m.records.length +
                                            ' fictional referrals',
                                    ),
                                );
                                close();
                            }}
                        >
                            {m.report
                                ? 'Download referral rows CSV'
                                : 'Download CSV'}
                        </Button>
                    </>
                }
            >
                {m.report && (
                    <>
                        <ReviewRow label="Report" value={m.report.title} />
                        <ReviewRow
                            label="Period / scope"
                            value={m.report.period}
                        />
                        <ReviewRow label="Site" value={m.report.site} />
                        <ReviewRow label="Search" value={m.report.search} />
                        <details>
                            <summary>
                                Report measures ({m.report.measures.length})
                            </summary>
                            {m.report.measures.map((measure) => (
                                <ReviewRow
                                    key={measure.label}
                                    label={measure.label}
                                    value={String(measure.value)}
                                />
                            ))}
                        </details>
                    </>
                )}
                <ReviewRow label="Rows" value={m.records.length} />
                <ReviewRow
                    label="Fields"
                    value="Reference, name, stage, source, owner, due"
                />
                <ReviewRow
                    label="Excluded"
                    value={
                        m.report
                            ? 'Evidence, private notes and out-of-scope records. The report summary includes only permitted funding aggregates; referral rows contain no funding details.'
                            : 'Evidence, private notes, funding and out-of-scope records'
                    }
                />
                <Notice>
                    Prototype export contains fictional personal data.
                </Notice>
            </Modal>
        );
    if (m.type === 'access')
        return (
            <Modal
                title={role + ' permissions'}
                description="Preview only. Production authorization will be enforced on the server."
                onClose={close}
            >
                {Object.entries(permissions).map(([key, label]) => (
                    <ReviewRow
                        key={key}
                        label={label}
                        value={
                            <Badge tone={can(key) ? 'success' : 'neutral'}>
                                {can(key) ? 'Allowed' : 'Not granted'}
                            </Badge>
                        }
                    />
                ))}
            </Modal>
        );
    if (m.type === 'addContact')
        return wizard(
            'Add referral contact',
            [
                {
                    label: 'Contact & relationship',
                    fields: [
                        { key: 'name', label: 'Contact name', required: true },
                        {
                            key: 'relationship',
                            label: 'Relationship / organisation',
                            required: true,
                        },
                        {
                            key: 'contact',
                            label: 'Phone or email',
                            required: true,
                        },
                    ],
                },
                {
                    label: 'Authority & participation',
                    fields: [
                        {
                            key: 'authority',
                            label: 'Authority basis and scope',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'safe',
                            label: 'Person’s wishes and safe-contact instructions',
                            type: 'textarea',
                            required: true,
                        },
                    ],
                },
            ],
            {},
            (v) =>
                save(
                    {
                        contacts: [
                            ...(r.contacts || []),
                            { ...v, id: crypto.randomUUID() },
                        ],
                    },
                    'Contact and authority recorded: ' + v.name,
                ),
        );
    if (m.type === 'savedViews')
        return (
            <Modal
                title="Your saved views"
                description="Save the current search, site, queue and layout. Permissions still apply whenever a view is opened."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Close
                        </Button>
                        <Button
                            disabled={!form.name?.trim()}
                            onClick={() => {
                                setData((s) => ({
                                    ...s,
                                    savedViews: [
                                        ...(s.savedViews || []),
                                        {
                                            id: crypto.randomUUID(),
                                            name: form.name.trim(),
                                            role,
                                            route: m.route,
                                            advanced: m.advanced,
                                            query: m.query,
                                            filter: m.filter,
                                            site: m.site,
                                            view: m.view,
                                        },
                                    ],
                                }));
                                notify('View saved');
                                setForm({ name: '' });
                            }}
                        >
                            Save current view
                        </Button>
                    </>
                }
            >
                <Field
                    label="Name this view"
                    value={form.name || ''}
                    onChange={(v) => setForm({ name: v })}
                />
                {(data.savedViews || [])
                    .filter((v) => v.role === role)
                    .map((v) => (
                        <div className="work-row" key={v.id}>
                            <Button
                                variant="link"
                                onClick={() => {
                                    m.apply(v);
                                    close();
                                }}
                            >
                                {v.name}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!form.name?.trim()}
                                onClick={() =>
                                    setData((s) => ({
                                        ...s,
                                        savedViews: s.savedViews.map((x) =>
                                            x.id === v.id
                                                ? {
                                                      ...x,
                                                      name: form.name.trim(),
                                                  }
                                                : x,
                                        ),
                                    }))
                                }
                            >
                                Rename
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setData((s) => ({
                                        ...s,
                                        savedViews: s.savedViews.map((x) =>
                                            x.id === v.id
                                                ? {
                                                      ...x,
                                                      query: m.query,
                                                      filter: m.filter,
                                                      site: m.site,
                                                      view: m.view,
                                                      route: m.route,
                                                      advanced: m.advanced,
                                                  }
                                                : x,
                                        ),
                                    }))
                                }
                            >
                                Replace with current view
                            </Button>
                            <span className="text-caption grow">
                                {v.site} · {v.filter} · {v.view}
                            </span>
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    setData((s) => ({
                                        ...s,
                                        savedViews: s.savedViews.filter(
                                            (x) => x.id !== v.id,
                                        ),
                                    }))
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
            </Modal>
        );
    if (m.type === 'drafts')
        return (
            <Modal
                title="Your intake drafts"
                description="Choose unfinished work saved by your current preview role. Drafts are separate from submitted referrals."
                onClose={close}
            >
                <div className="stack">
                    {data.drafts
                        .filter((d) => (d.role || 'Referral manager') === role)
                        .map((draft) => (
                            <div className="setting-row" key={draft.id}>
                                <div>
                                    <strong>
                                        {draft.values.name || 'Unnamed enquiry'}
                                    </strong>
                                    <p className="text-subtle">
                                        {draft.values.source || 'Intake'} ·{' '}
                                        {draft.savedAt || 'Previously saved'}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        request('intake', null, { draft })
                                    }
                                >
                                    Resume{' '}
                                    <span className="sr-only">
                                        {draft.values.name || 'unnamed enquiry'}
                                    </span>
                                </Button>
                            </div>
                        ))}
                </div>
            </Modal>
        );
    if (m.type === 'find')
        return (
            <Modal
                title="Find in Referrals"
                description="Search only the records visible to your current role."
                onClose={close}
            >
                <Input
                    aria-label="Find a referral"
                    placeholder="Name or reference"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                {scoped
                    .filter((x) =>
                        (x.name + x.id).toLowerCase().includes(q.toLowerCase()),
                    )
                    .map((x) => (
                        <Button
                            key={x.id}
                            variant="ghost"
                            onClick={() => navigate('Referrals', x.id)}
                        >
                            {x.name} · {x.id}
                            <ArrowRight />
                        </Button>
                    ))}
            </Modal>
        );
    if (m.type === 'scenarios')
        return (
            <Modal
                title="Walk through the prototype"
                description="Select an example, role or recovery state. All actions use fictional records."
                onClose={close}
            >
                <div className="stack">
                    {[
                        [
                            'Maia · website enquiry, no funding',
                            'REF-1042',
                            'Overview',
                        ],
                        [
                            'Noah · funded professional referral',
                            'REF-1043',
                            'Handover',
                        ],
                        [
                            'Hana · IF partial approval and gap',
                            'REF-1044',
                            'Funding',
                        ],
                        [
                            'Leo · private payer proposal',
                            'REF-1045',
                            'Proposal',
                        ],
                        ['Aria · EGL capacity waitlist', 'REF-1046', 'Funding'],
                        [
                            'Isla · personal income assistance',
                            'REF-1048',
                            'Funding',
                        ],
                        [
                            'Eli · existing person, extra support',
                            'REF-1049',
                            'Handover',
                        ],
                    ].map(([label, id, tab]) => (
                        <Button
                            variant="outline"
                            key={id}
                            onClick={() => navigate('Referrals', id, tab)}
                        >
                            {label}
                            <ArrowUpRight />
                        </Button>
                    ))}
                    <Field
                        label="Recovery state to preview"
                        value={failure}
                        type="select"
                        options={[
                            'Normal',
                            'Loading',
                            'Empty',
                            'Save failure',
                            'Conflict',
                            'Delivery failure',
                            'Quarantined document',
                            'Service unavailable',
                        ]}
                        onChange={setFailure}
                    />
                    <p className="text-caption text-muted-foreground">
                        Save failure and conflict interrupt the next wizard save
                        and keep its entries. Delivery failure affects message
                        previews. Quarantine affects the next staged document.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => navigate('Settings')}
                    >
                        Explore configurable settings & permissions
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={() => request('reset', null)}
                    >
                        Reset fictional examples
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => request('reportScenario', null)}
                    >
                        Load fictional reporting scenario
                    </Button>
                </div>
            </Modal>
        );
    if (m.type === 'reportScenario')
        return (
            <Modal
                title="Load fictional reporting scenario?"
                description="Replace this browser’s sample changes with 32 fictional referrals spanning six weeks, including contact, funding and handover outcomes. Reset fictional examples restores the original eight examples."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button onClick={() => reset('reporting')}>
                            Load reporting scenario
                        </Button>
                    </>
                }
            />
        );
    if (m.type === 'reset')
        return (
            <Modal
                title="Reset prototype data?"
                description="This removes only this prototype’s saved fictional changes and restores all examples."
                onClose={close}
                actions={
                    <>
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={reset}>
                            Reset examples
                        </Button>
                    </>
                }
            />
        );
    return null;
}
createRoot(document.getElementById('root')).render(
    <InertiaApp
        initialPage={{
            component: 'ReferralsPrototype',
            props: { auth: { user: { id: 2, name: 'Emma Taylor' } } },
            url: '/?calendar_date=' + TODAY,
            version: null,
            clearHistory: false,
            encryptHistory: false,
        }}
        initialComponent={App}
        resolveComponent={() => App}
    />,
);

import './refinement.css';
