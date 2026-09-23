import { EventHorizonWordmark } from '@/components/event-horizon-wordmark';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
    Activity,
    ArrowLeft,
    ArrowUpRight,
    Bell,
    Building2,
    CalendarDays,
    Camera,
    Car,
    ChevronDown,
    ClipboardCheck,
    FileText,
    Gauge,
    History,
    Home,
    LockKeyhole,
    MapPin,
    MessageSquare,
    PanelLeftClose,
    PanelLeftOpen,
    Route,
    Search,
    Settings2,
    ShieldCheck,
    Users,
    Wrench,
} from 'lucide-react';
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ChecklistProvider } from './checklist-library';
import './enhancements.css';
import {
    CheckFlow,
    ReportFlow,
    RunDetail,
    UploadFlow,
    originalRun,
    type Run,
} from './flows';
import { MapStudio, seedFences, type SharedFence } from './map-studio';
import {
    OperationDialog,
    dateLabel,
    serviceStatus,
    useVehicleModel,
} from './operations';
import './operations.css';
import { BookingsStudio, RecordStudio } from './record-studio';
import { StudioChecks, StudioSurface } from './studio';
import './studio.css';
import './styles.css';
import { TelemetryStudio } from './telemetry-studio';
import './telemetry.css';
import { TripStudio } from './trip-studio';
import { Badge, Modal, Notice, Row } from './ui';
import { VehicleCalendar } from './vehicle-calendar';

const views = [
    { key: 'overview', label: 'Overview', icon: Car },
    { key: 'compliance', label: 'Service & compliance', icon: ShieldCheck },
    { key: 'checks', label: 'Checks & inspections', icon: ClipboardCheck },
    { key: 'maintenance', label: 'Maintenance', icon: Wrench },
    { key: 'map', label: 'Map', icon: MapPin },
    { key: 'trips', label: 'Trip history', icon: Route },
    { key: 'calendar', label: 'Calendar', icon: CalendarDays },
];
const scenarios = [
    ['hold', 'Active restriction'],
    ['ready', 'Ready · current example evidence'],
    ['unknown', 'Unknown applicability & missing evidence'],
    ['overdue', 'Overdue service & check'],
    ['awaiting', 'Work completed · awaiting release'],
    ['stale', 'Stale evidence · no tracker'],
    ['empty', 'First use · empty history'],
    ['readonly', 'View-only role'],
    ['reportonly', 'Report-only staff'],
    ['denied', 'Role/site access denied'],
];
const subviews: Record<
    string,
    { key: string; label: string; icon: typeof Car }[]
> = {
    overview: [
        { key: 'summary', label: 'Readiness', icon: ShieldCheck },
        { key: 'details', label: 'Vehicle details', icon: Car },
    ],
    compliance: [
        { key: 'summary', label: 'Evidence & due dates', icon: FileText },
        { key: 'schedules', label: 'Service schedules', icon: Wrench },
        { key: 'reminders', label: 'Reminders', icon: Bell },
        { key: 'history', label: 'Service history', icon: History },
        { key: 'mileage', label: 'Mileage', icon: Gauge },
    ],
    checks: [
        { key: 'summary', label: 'Recent checks', icon: ClipboardCheck },
        { key: 'templates', label: 'Templates', icon: FileText },
    ],
    maintenance: [
        { key: 'summary', label: 'Open work', icon: Wrench },
        { key: 'history', label: 'Historical work', icon: History },
    ],
    map: [
        { key: 'summary', label: 'Location & geofences', icon: MapPin },
        { key: 'telemetry', label: 'Vehicle telemetry', icon: Activity },
        { key: 'driving', label: 'Driving insights', icon: Gauge },
        { key: 'alerts', label: 'Alerts & Control Room', icon: Bell },
    ],
    trips: [{ key: 'summary', label: 'Vehicle trips', icon: Route }],
    calendar: [{ key: 'summary', label: 'Calendar', icon: CalendarDays }],
};
const defaultEntries = [
    {
        kind: 'restriction',
        day: 'Now',
        title: 'Vehicle use restricted',
        sub: 'Active from 21 Sep · 8:20 am · No end recorded',
        ref: 'RST-DEMO-12',
        tone: 'critical',
    },
    {
        kind: 'service',
        day: '24 Sep',
        title: 'Routine service appointment',
        sub: '9:00–11:00 am · Internal appointment',
        ref: 'APT-DEMO-28',
        tone: 'info',
    },
    {
        kind: 'estimate',
        day: '24–25 Sep',
        title: 'Estimated maintenance window',
        sub: 'Advisory dates · No provider confirmation',
        ref: 'WO-0264',
        tone: 'warning',
    },
    {
        kind: 'booking',
        day: '25 Sep',
        title: 'Busy',
        sub: '10:00 am–12:00 pm · Booking details restricted',
        ref: 'Busy-only',
        tone: 'neutral',
    },
    {
        kind: 'compliance',
        day: '08 Oct',
        title: 'Registration reminder',
        sub: 'Due date reminder · Does not reserve a day',
        ref: 'REG-DEMO-14',
        tone: 'warning',
    },
];
function App() {
    const [initialSection] = useState(() => {
        const match = location.hash.match(
            /^#\/fleet-assets\/vehicles\/14\/([^/?]+)(?:\/([^/?]+))?/,
        );
        const v = match && subviews[match[1]] ? match[1] : 'overview';
        const s =
            match?.[2] && subviews[v].some((x) => x.key === match[2])
                ? match[2]
                : 'summary';
        return { view: v, sub: s };
    });
    const [tripFocus, setTripFocus] = useState('');
    const [alertFocus, setAlertFocus] = useState('');
    const [fences, setFences] = useState<SharedFence[]>(seedFences),
        [selectedFences, setSelectedFences] = useState<(string | number)[]>([
            'GEO-DEMO-01',
        ]);
    const [scenario, setScenario] = useState('hold'),
        [view, setView] = useState(initialSection.view),
        [sub, setSub] = useState(initialSection.sub),
        [attention, setAttention] = useState(false),
        [collapsed, setCollapsed] = useState(false),
        [dialog, setDialog] = useState(''),
        [detail, setDetail] = useState(''),
        [fault, setFault] = useState('none'),
        [dark, setDark] = useState(false),
        [run, setRun] = useState<Run>(originalRun),
        [runs, setRuns] = useState<Run[]>([originalRun]),
        [linked, setLinked] = useState<Record<string, string>>({
            'CHK-0182': 'WO-0264',
        }),
        [checkTemplate, setCheckTemplate] = useState('condition'),
        [observation, setObservation] = useState('latest'),
        [createdReport, setCreatedReport] = useState(false),
        [reportFromCheck, setReportFromCheck] = useState(false),
        [work, setWork] = useState(''),
        [workReturn, setWorkReturn] = useState('maintenance'),
        [note, setNote] = useState(''),
        [noteSaved, setNoteSaved] = useState(false),
        [noteError, setNoteError] = useState(false),
        [notesOpen, setNotesOpen] = useState(false);
    const model = useVehicleModel(scenario, fault, runs[0]?.outcome);
    const denied = scenario === 'denied',
        ready = scenario === 'ready',
        empty = scenario === 'empty',
        unknown = scenario === 'unknown' || empty,
        stale = scenario === 'stale',
        overdue = scenario === 'overdue',
        awaiting = scenario === 'awaiting',
        readonly = scenario === 'readonly',
        reportOnly = scenario === 'reportonly';
    const hold = model.hold;
    const uncertain =
        model.data.checkDue < '2026-09-22' ||
        model.readingStale ||
        !model.odo ||
        model.data.compliance.some(
            (c) =>
                c.applies === 'Unknown' ||
                (c.applies === 'Applicable' &&
                    (!c.evidence ||
                        c.outcome === 'Failed' ||
                        (c.due && c.due < '2026-09-22') ||
                        (c.high > 0 && model.odo > c.high))),
        ) ||
        model.data.schedules.some((s) => serviceStatus(s, model.odo).overdue) ||
        model.data.works.some((w) => w.outcome === 'Needs retest') ||
        (!hold && runs[0]?.outcome !== 'Passed');
    const readiness =
        hold || model.data.profile.life !== 'In service'
            ? 'Not ready'
            : uncertain
              ? 'Needs assessment'
              : 'Ready';
    const nav = (v: string, s = 'summary') => {
        setView(v);
        setSub(s);
        setAttention(false);
        setWork('');
        location.hash = `/fleet-assets/vehicles/14/${v}${s !== 'summary' ? `/${s}` : ''}`;
    };
    const show = (name: string) => {
        if (name === 'Next service') {
            nav('compliance', 'schedules');
            return;
        }
        if (name === 'Notifications') {
            nav('compliance', 'reminders');
            return;
        }
        if (name === 'Progress') {
            model.assess(work || 'WO-0264');
            return;
        }
        if (name === 'Complete work') {
            model.complete(work || 'WO-0264');
            return;
        }
        if (name === 'Cancelled work') {
            openWork('WO-0210');
            return;
        }
        if (name === 'Mileage source') {
            nav('compliance', 'mileage');
            return;
        }
        if (['WoF', 'Registration', 'RUC', 'CoF'].includes(name)) {
            nav('compliance');
            return;
        }

        setDetail(name);
        setDialog('detail');
    };
    const openWork = (id = 'WO-0264') => {
        setWorkReturn(`${view}:${sub}`);
        setWork(id);
        setNote('');
        setNoteSaved(false);
        setNoteError(false);
        setNotesOpen(false);
        location.hash = `/fleet-assets/maintenance/work-orders/${encodeURIComponent(id)}?return=vehicle-14`;
    };
    const closeWork = () => {
        setWork('');
        nav(workReturn.split(':')[0], workReturn.split(':')[1] || 'summary');
        setTimeout(
            () =>
                document
                    .querySelector<HTMLButtonElement>('[data-work-link]')
                    ?.focus(),
            0,
        );
    };
    const evidence = [
        {
            title: 'Odometer',
            sub: empty
                ? 'Observation date not recorded'
                : stale
                  ? 'Last observed 2 Sep 2026 · 4:15 pm'
                  : 'Observed 21 Sep 2026 · 8:10 am',
            value: model.odo
                ? `${model.planningOdo.toLocaleString()} km`
                : 'Not recorded',
            status: empty ? 'Unknown' : stale ? 'Stale evidence' : 'Recorded',
            tone: empty || stale ? 'warning' : 'neutral',
            source: empty
                ? 'No observation available'
                : 'Inspection CHK-0182 · Manual reading',
            attention: empty || stale,
        },
        {
            title: 'Next service',
            sub: 'Date and distance are separate schedule fields',
            value: model.nextSchedule
                ? serviceStatus(model.nextSchedule, model.planningOdo).label
                : 'Not scheduled',
            status: empty ? 'Unknown' : overdue ? 'Overdue' : 'Due soon',
            tone: overdue ? 'critical' : 'warning',
            source: empty
                ? 'No schedule available'
                : 'SCH-DEMO-07 · Routine service',
            attention: true,
        },
        {
            title: 'WoF',
            sub: unknown
                ? 'Evidence date not recorded'
                : 'Evidence recorded 9 Apr 2026',
            value: unknown ? 'Not recorded' : '09 Apr 2027',
            status: unknown ? 'Unknown' : 'Current evidence',
            tone: unknown ? 'warning' : 'success',
            source: unknown
                ? 'Applicability and evidence need review'
                : 'WOF-DEMO-14 · Certificate record',
            attention: unknown,
        },
        {
            title: 'Registration',
            sub: unknown
                ? 'No source document recorded'
                : 'Evidence recorded 8 Jul 2026',
            value: unknown ? 'Not recorded' : '08 Oct 2026',
            status: unknown ? 'Unknown' : 'Due soon',
            tone: 'warning',
            source: unknown
                ? 'Expiry has not been verified'
                : 'REG-DEMO-14 · Licence record',
            attention: true,
        },
        {
            title: 'RUC',
            sub: ready
                ? 'Evidence recorded 15 Sep 2026'
                : 'Vehicle-specific applicability',
            value: ready
                ? '80,000–90,000 km'
                : unknown
                  ? 'Applicability unknown'
                  : 'Evidence not recorded',
            status: ready ? 'Current evidence' : 'Needs assessment',
            tone: ready ? 'success' : 'warning',
            source: ready
                ? 'RUC-DEMO-14 · Illustrative applicable licence'
                : 'No claim of applicability or exemption',
            attention: !ready,
        },
        {
            title: 'CoF',
            sub: ready
                ? 'Example applicability record · 15 Sep 2026'
                : 'Vehicle-specific applicability',
            value: ready ? 'Not applicable' : 'Applicability not verified',
            status: ready ? 'Applicability recorded' : 'Unknown',
            tone: ready ? 'neutral' : 'warning',
            source: ready
                ? 'APP-DEMO-14 · Synthetic decision, not legal guidance'
                : 'Confirm the applicable compliance evidence',
            attention: !ready,
        },
    ];
    const openItems = empty
        ? []
        : [
              {
                  id: 'WO-0264',
                  title: 'Condition concern',
                  status: awaiting
                      ? 'Completed · awaiting release'
                      : 'Awaiting assessment',
                  note: 'Original check CHK-0182 · Kōwhai House Coordinator',
                  tone: awaiting ? 'warning' : 'critical',
              },
              {
                  id: 'WO-0268',
                  title: 'Routine service',
                  status: 'Awaiting scheduling',
                  note: 'Schedule SCH-DEMO-07 · 24 Sep / 85,000 km',
                  tone: 'info',
              },
          ].filter((item) => !ready || item.id !== 'WO-0264');
    if (createdReport)
        openItems.unshift({
            id: 'WO-DEMO-0269',
            title: 'Reported vehicle concern',
            status: 'Awaiting assessment',
            note: 'New report · Kōwhai House Coordinator',
            tone: 'warning',
        });
    const entries = empty
        ? []
        : defaultEntries.filter(
              (e) =>
                  (e.kind !== 'restriction' || hold) &&
                  (!ready || e.kind !== 'estimate'),
          );
    const actionDisabled = readonly || denied;
    const serviceWork = work === 'WO-0268';
    const historicalWork = work === 'WO-0188';
    const workCheckRuns = runs.filter(
        (item) =>
            (work === 'WO-0264' && item.id === originalRun.id) ||
            linked[item.id] === work,
    );
    const upcoming = (
        <div className="agenda">
            {entries.length ? (
                entries
                    .filter(
                        (e) =>
                            !attention ||
                            ['restriction', 'compliance'].includes(e.kind),
                    )
                    .map((e) => (
                        <button
                            className={`agenda-row ${e.kind}`}
                            key={e.ref}
                            onClick={() => {
                                setDetail(e.kind);
                                setDialog('event');
                            }}
                        >
                            <span className="agenda-date">{e.day}</span>
                            <span className="agenda-info">
                                <strong>{e.title}</strong>
                                <small>{e.sub}</small>
                                <span className="ref">{e.ref}</span>
                            </span>
                            <ArrowUpRight size={16} />
                        </button>
                    ))
            ) : (
                <div className="empty">
                    <CalendarDays />
                    <strong>No upcoming records</strong>
                    <p>
                        Source-owned bookings, appointments and reminders will
                        appear here.
                    </p>
                </div>
            )}
        </div>
    );
    const table = (
        <div className="evidence-list">
            {evidence
                .filter((e) => !attention || e.attention)
                .map((e) => (
                    <Row
                        key={e.title}
                        title={e.title}
                        sub={`${e.sub} · ${e.source}`}
                        value={<strong>{e.value}</strong>}
                        badge={<Badge tone={e.tone as any}>{e.status}</Badge>}
                        action={() => show(e.title)}
                    />
                ))}
        </div>
    );
    const checkRows = runs
        .filter((r) => !attention || r.outcome !== 'Passed')
        .map((r, i) => (
            <button
                className="record-row"
                key={r.id}
                onClick={() => {
                    setRun(r);
                    setDialog('run');
                }}
            >
                <span className="record-icon">
                    <ClipboardCheck />
                </span>
                <span className="record-main">
                    <strong>{r.template}</strong>
                    <small>
                        {r.id} · {r.version} · {r.submitted} · Alex Morgan
                    </small>
                </span>
                <Badge
                    tone={
                        r.outcome === 'Failed'
                            ? 'critical'
                            : r.outcome === 'Passed'
                              ? 'success'
                              : 'warning'
                    }
                >
                    {r.outcome}
                </Badge>
                <ArrowUpRight size={16} />
            </button>
        ));
    return (
        <TooltipProvider>
            <div className="preview-bar">
                <strong>PKG-02B · v5</strong>
                <span className="preview-label">
                    Synthetic design · nothing is sent
                </span>
                <label>
                    Scenario{' '}
                    <select
                        aria-label="Preview scenario"
                        value={scenario}
                        onChange={(e) => {
                            setScenario(e.target.value);
                            setRuns(
                                e.target.value === 'empty'
                                    ? []
                                    : e.target.value === 'ready'
                                      ? [
                                            {
                                                ...originalRun,
                                                id: 'CHK-DEMO-0183',
                                                answers: [
                                                    'No issue recorded',
                                                    'No issue recorded',
                                                ],
                                                outcome: 'Passed',
                                            },
                                        ]
                                      : [originalRun],
                            );
                            setRun(originalRun);
                            setLinked({ 'CHK-0182': 'WO-0264' });
                            setCreatedReport(false);
                            setCheckTemplate('condition');
                            setWork('');
                            setDialog('');

                            setCreatedReport(false);
                            setNote('');
                            setNoteSaved(false);
                            setNoteError(false);
                            nav('overview');
                        }}
                    >
                        {scenarios.map(([id, label]) => (
                            <option key={id} value={id}>
                                {label}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    Recovery{' '}
                    <select
                        aria-label="Preview recovery"
                        value={fault}
                        onChange={(e) => setFault(e.target.value)}
                    >
                        <option value="none">Normal</option>
                        <option value="save">First save interrupted</option>
                        <option value="search">Search failure</option>
                        <option value="upload">Partial upload failure</option>
                        <option value="map">Map imagery unavailable</option>
                    </select>
                </label>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setDark(!dark);
                        document.documentElement.classList.toggle('dark');
                    }}
                >
                    {dark ? 'Light' : 'Dark'} theme
                </Button>
            </div>
            <header className="chrome">
                <div className="wordmark">
                    <EventHorizonWordmark />
                </div>
                <span className="chrome-search">
                    <Search size={16} />
                    Search Oblivion Care <kbd>Ctrl K</kbd>
                </span>
                <div className="chrome-right">
                    <span>Tue, 22 September</span>
                    <Button
                        variant="ghost"
                        className="chrome-action"
                        onClick={() => show('Global actions')}
                    >
                        Report incident
                    </Button>
                    <button
                        aria-label="Messages"
                        onClick={() => show('Messages')}
                    >
                        <MessageSquare size={18} />
                    </button>
                    <button
                        aria-label="Notifications"
                        onClick={() => show('Notifications')}
                    >
                        <Bell size={18} />
                    </button>
                    <span className="avatar">AM</span>
                </div>
            </header>
            <div className={`workspace ${collapsed ? 'collapsed' : ''}`}>
                <aside className="sidebar">
                    <nav aria-label="Main navigation">
                        {[
                            [Home, 'Home'],
                            [Activity, 'My day'],
                            [Users, 'Clients'],
                            [Building2, 'Sites'],
                        ].map(([Icon, label]) => (
                            <button
                                key={String(label)}
                                onClick={() => show(String(label))}
                            >
                                {React.createElement(Icon as typeof Home, {
                                    size: 18,
                                })}
                                <span>{String(label)}</span>
                            </button>
                        ))}
                        <div className="module-label">
                            <Car size={18} />
                            <span>Fleet & assets</span>
                            <ChevronDown size={14} />
                        </div>
                        {[
                            'Overview',
                            'Vehicles',
                            'Bookings',
                            'Maintenance',
                            'Assets',
                            'Reports',
                        ].map((t) => (
                            <button
                                key={t}
                                className={t === 'Vehicles' ? 'selected' : ''}
                                onClick={() =>
                                    t === 'Vehicles' ? nav('overview') : show(t)
                                }
                            >
                                <span className="nav-dot" />
                                <span>{t}</span>
                            </button>
                        ))}
                        <button
                            className="settings-link"
                            onClick={() => show('Settings')}
                        >
                            <Settings2 size={18} />
                            <span>Settings</span>
                        </button>
                    </nav>
                    <button
                        className="sidebar-toggle"
                        aria-label={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        onClick={() => setCollapsed(!collapsed)}
                    >
                        {collapsed ? (
                            <PanelLeftOpen size={17} />
                        ) : (
                            <PanelLeftClose size={17} />
                        )}
                    </button>
                </aside>
                <main className="main">
                    <nav className="breadcrumbs" aria-label="Breadcrumb">
                        <button onClick={() => show('Home')}>Home</button>
                        <span>›</span>
                        <button onClick={() => show('Fleet & assets')}>
                            Fleet & assets
                        </button>
                        <span>›</span>
                        <button onClick={() => show('Vehicle register')}>
                            Vehicles
                        </button>
                        <span>›</span>
                        <span>
                            {denied ? 'Restricted record' : 'Kōwhai van'}
                            {work ? ' › Maintenance' : ''}
                        </span>
                    </nav>
                    {denied ? (
                        <div className="denied panel">
                            <LockKeyhole size={32} />
                            <h1 className="text-page-title">
                                This vehicle is not available to you
                            </h1>
                            <p>
                                Your role or approved site access does not allow
                                this record. Vehicle details and actions are
                                hidden.
                            </p>
                            <Button onClick={() => show('Vehicle register')}>
                                Back to permitted vehicles
                            </Button>
                        </div>
                    ) : view === 'calendar' && !work ? (
                        <>
                            <VehicleCalendar
                                focusDate={
                                    /^\d{4}-/.test(sub) ? sub : undefined
                                }
                                hold={hold}
                                empty={empty}
                                ready={ready}
                                items={model.calendarItems}
                                actionsFor={(id) => {
                                    const reminder = model.data.followups.find(
                                        (r) => r.id === id,
                                    );
                                    if (reminder)
                                        return [
                                            {
                                                label: 'Edit / reschedule reminder',
                                                run: () => model.followup(id),
                                                disabled: !model.canManage,
                                            },
                                            {
                                                label: 'View reminder activity',
                                                run: () =>
                                                    model.followupAction(
                                                        id,
                                                        'detail',
                                                    ),
                                            },
                                        ];
                                    const b = model.data.bookings.find(
                                        (b) => b.id === id,
                                    );
                                    const w = model.data.works.find(
                                        (w) => w.id === id,
                                    );
                                    return b &&
                                        model.canManage &&
                                        !['Returned', 'Cancelled'].includes(
                                            b.status,
                                        )
                                        ? [
                                              {
                                                  label: 'Change booking',
                                                  run: () =>
                                                      model.booking(
                                                          b.start,
                                                          b.id,
                                                          b.block,
                                                      ),
                                                  disabled:
                                                      b.status ===
                                                      'Checked out',
                                              },
                                              {
                                                  label:
                                                      b.status ===
                                                      'Pending approval'
                                                          ? 'Review & approve'
                                                          : b.status ===
                                                              'Confirmed'
                                                            ? 'Check out'
                                                            : 'Record return',
                                                  run: () =>
                                                      model.bookingTransition(
                                                          b.id,
                                                          b.status ===
                                                              'Pending approval'
                                                              ? 'approve'
                                                              : b.status ===
                                                                  'Confirmed'
                                                                ? 'out'
                                                                : 'return',
                                                      ),
                                                  disabled: !!b.block,
                                              },
                                              {
                                                  label: 'Cancel booking',
                                                  run: () =>
                                                      model.bookingTransition(
                                                          b.id,
                                                          'cancel',
                                                      ),
                                                  disabled:
                                                      b.status ===
                                                      'Checked out',
                                              },
                                          ]
                                        : w && model.canManage
                                          ? [
                                                {
                                                    label: 'Manage appointment',
                                                    run: () => model.plan(w.id),
                                                },
                                            ]
                                          : [];
                                }}
                                onCreate={
                                    model.canRequest
                                        ? (start) => model.booking(start)
                                        : undefined
                                }
                                onBack={() =>
                                    /^\d{4}-/.test(sub)
                                        ? nav('compliance', 'reminders')
                                        : nav('overview')
                                }
                                onOpen={(id) => {
                                    if (
                                        model.data.followups.some(
                                            (r) => r.id === id,
                                        )
                                    ) {
                                        model.followupAction(id, 'detail');
                                        return;
                                    }
                                    if (id.startsWith('estimate-')) {
                                        openWork(id.slice(9));
                                        return;
                                    }
                                    if (
                                        model.data.works.some(
                                            (w) => w.id === id,
                                        )
                                    ) {
                                        openWork(id);
                                        return;
                                    }
                                    if (
                                        model.data.schedules.some(
                                            (s) => s.id === id,
                                        )
                                    ) {
                                        nav('compliance', 'schedules');
                                        return;
                                    }
                                    if (
                                        model.data.compliance.some(
                                            (c) => c.id === id,
                                        )
                                    ) {
                                        nav('compliance');
                                        return;
                                    }
                                    if (id === 'check-due') {
                                        nav('checks');
                                        return;
                                    }
                                    if (id === 'restriction') {
                                        model.detail('Restriction record', [
                                            ['Reference', 'RST-DEMO-12'],
                                            [
                                                'Started',
                                                '21 Sep 2026 · 8:20 am',
                                            ],
                                            ['End', 'No release recorded'],
                                            ['Owner', 'Operations Manager'],
                                            ['Source', 'CHK-0182 → WO-0264'],
                                        ]);
                                        return;
                                    }
                                    const booking = model.data.bookings.find(
                                        (b) => b.id === id,
                                    );
                                    model.detail(
                                        booking ? 'Booking record' : 'Busy',
                                        booking
                                            ? [
                                                  ['Reference', booking.id],
                                                  ['Purpose', booking.purpose],
                                                  ['Status', booking.status],
                                                  [
                                                      'Next action',
                                                      'Use Vehicle bookings & custody below the calendar.',
                                                  ],
                                              ]
                                            : [
                                                  [
                                                      'Busy interval',
                                                      '25 Sep 10:00 am–12:00 pm',
                                                  ],
                                                  [
                                                      'Access',
                                                      'Booking details restricted',
                                                  ],
                                              ],
                                    );
                                }}
                            />
                            <div className="content">
                                <BookingsStudio model={model} />
                            </div>
                        </>
                    ) : (
                        <>
                            <PageHeader
                                variant="profile"
                                wrapTitle
                                mark={
                                    <div className="identity-mark">
                                        <button
                                            aria-label={
                                                work
                                                    ? 'Back to vehicle profile'
                                                    : 'Back to vehicles'
                                            }
                                            className="hero-back"
                                            onClick={() =>
                                                work
                                                    ? closeWork()
                                                    : show('Vehicle register')
                                            }
                                        >
                                            <ArrowLeft size={16} />
                                        </button>
                                        <button
                                            className="eh-mark-ring photo-header-trigger"
                                            disabled={
                                                !!work || !model.canManage
                                            }
                                            aria-label="Upload vehicle profile photo"
                                            onClick={() =>
                                                model.setPhotoOpen(true)
                                            }
                                        >
                                            {work ? (
                                                <Wrench size={23} />
                                            ) : model.data.photo?.url ? (
                                                <img
                                                    src={model.data.photo.url}
                                                    alt="Kōwhai van"
                                                />
                                            ) : (
                                                <Car size={25} />
                                            )}{' '}
                                            {!work && (
                                                <Camera
                                                    size={15}
                                                    className="photo-corner"
                                                />
                                            )}
                                        </button>
                                    </div>
                                }
                                title={
                                    work
                                        ? model.data.works.find(
                                              (w) => w.id === work,
                                          )?.title || 'Maintenance work'
                                        : 'Kōwhai van'
                                }
                                titleChip={
                                    <PageHeaderStatusChip
                                        variant={
                                            work
                                                ? 'info'
                                                : hold
                                                  ? 'critical'
                                                  : uncertain
                                                    ? 'warning'
                                                    : 'success'
                                        }
                                    >
                                        {work
                                            ? (model.data.works.find(
                                                  (w) => w.id === work,
                                              )?.status ?? 'Open')
                                            : readiness}
                                    </PageHeaderStatusChip>
                                }
                                subline={
                                    work ? (
                                        <>
                                            {work} · Kōwhai van · VH-014
                                            <br />
                                            Maintenance · Original record
                                            context
                                        </>
                                    ) : (
                                        <>
                                            KWH014 · VH-014 · Kōwhai House
                                            <br />
                                            Toyota Hiace · Diesel · Community
                                            transport
                                        </>
                                    )
                                }
                                actions={
                                    <>
                                        <PageHeaderSearchTrigger
                                            placeholder="Find in this vehicle…"
                                            onOpen={() => setDialog('search')}
                                        />
                                        <PageHeaderGlassButton
                                            onClick={() => nav('calendar')}
                                        >
                                            <CalendarDays size={16} />
                                            Calendar
                                        </PageHeaderGlassButton>
                                        <button
                                            className="hero-primary"
                                            disabled={actionDisabled}
                                            onClick={() => {
                                                setCheckTemplate('condition');
                                                setDialog('check');
                                            }}
                                        >
                                            <ClipboardCheck size={16} />
                                            Start check
                                        </button>
                                        <PageHeaderGlassButton
                                            disabled={actionDisabled}
                                            onClick={() => {
                                                setReportFromCheck(false);
                                                setDialog('report');
                                            }}
                                        >
                                            Report a problem
                                        </PageHeaderGlassButton>
                                    </>
                                }
                                meters={
                                    <div className="meter-grid">
                                        {(work
                                            ? [
                                                  [
                                                      'Vehicle',
                                                      'VH-014',
                                                      'Kōwhai van',
                                                      'overview',
                                                  ],
                                                  [
                                                      'Source',
                                                      serviceWork ||
                                                      historicalWork
                                                          ? 'SCH-DEMO-07'
                                                          : model.data.works.find(
                                                                (w) =>
                                                                    w.id ===
                                                                    work,
                                                            )?.source ||
                                                            'Manual report',
                                                      serviceWork ||
                                                      historicalWork
                                                          ? 'Original service schedule'
                                                          : workCheckRuns.length
                                                            ? 'Original submitted check'
                                                            : model.data.works
                                                                    .find(
                                                                        (w) =>
                                                                            w.id ===
                                                                            work,
                                                                    )
                                                                    ?.source.startsWith(
                                                                        'CR-',
                                                                    )
                                                              ? 'Control Room signal'
                                                              : 'Manual source record',
                                                      serviceWork ||
                                                      historicalWork
                                                          ? 'compliance'
                                                          : 'checks',
                                                  ],
                                                  [
                                                      'Restriction',
                                                      hold
                                                          ? 'Active'
                                                          : 'Review',
                                                      'Separate release decision',
                                                      'overview',
                                                  ],
                                                  [
                                                      'Evidence',
                                                      `${new Set([...model.data.documents.filter((x) => x.owner === work || workCheckRuns.some((r) => r.id === x.owner)), ...workCheckRuns.flatMap((r) => r.attachments || [])].map((f) => f.id)).size} linked files`,
                                                      'Original records & notes',
                                                      'maintenance',
                                                  ],
                                              ]
                                            : [
                                                  [
                                                      'Odometer',
                                                      model.planningOdo
                                                          ? `${model.planningOdo.toLocaleString()} km`
                                                          : 'Unknown',
                                                      model.tracker.usable
                                                          ? 'Tracker estimate · dashboard check available'
                                                          : model.data
                                                                  .readings[0]
                                                            ? `${dateLabel(model.data.readings[0].at)} · ${model.data.readings[0].source}`
                                                            : 'No observation available',
                                                      'compliance',
                                                  ],
                                                  [
                                                      'Next service',
                                                      model.nextSchedule
                                                          ? dateLabel(
                                                                model.data
                                                                    .schedules[0]
                                                                    .due,
                                                            )
                                                          : 'Not scheduled',
                                                      model.nextSchedule
                                                          ? `${model.nextSchedule.dueKm.toLocaleString()} km · ${model.nextSchedule.name}`
                                                          : 'Set up a service requirement',
                                                      'compliance',
                                                  ],
                                                  [
                                                      'Last check',
                                                      !runs.length
                                                          ? 'No record'
                                                          : overdue
                                                            ? 'Overdue'
                                                            : runs[0].outcome,
                                                      !runs.length
                                                          ? 'No submitted checks'
                                                          : `${runs[0]?.id} · ${runs[0]?.version}`,
                                                      'checks',
                                                  ],
                                                  [
                                                      'Maintenance',
                                                      empty
                                                          ? 'No history'
                                                          : hold
                                                            ? 'Hold active'
                                                            : 'View work',
                                                      empty
                                                          ? 'First-use example'
                                                          : awaiting
                                                            ? 'Repair complete · release pending'
                                                            : 'Original issues and work',
                                                      'maintenance',
                                                  ],
                                              ]
                                        ).map(
                                            ([label, value, caption, dest]) => (
                                                <PageHeaderMeterBlock
                                                    key={label}
                                                    label={label}
                                                    tone={
                                                        label ===
                                                            'Maintenance' &&
                                                        hold
                                                            ? 'critical'
                                                            : 'brand'
                                                    }
                                                    onClick={() =>
                                                        nav(
                                                            dest,
                                                            label === 'Odometer'
                                                                ? 'mileage'
                                                                : 'summary',
                                                        )
                                                    }
                                                >
                                                    <PageHeaderMeterBig>
                                                        {value}
                                                    </PageHeaderMeterBig>
                                                    <PageHeaderMeterCaption>
                                                        {caption}
                                                    </PageHeaderMeterCaption>
                                                </PageHeaderMeterBlock>
                                            ),
                                        )}
                                    </div>
                                }
                                filters={
                                    <div className="header-filters">
                                        <span>
                                            As at 22 Sep 2026 · 9:30 am ·
                                            Pacific/Auckland
                                        </span>
                                        <button
                                            onClick={() =>
                                                nav('compliance', 'reminders')
                                            }
                                        >
                                            <Bell size={14} /> Reminders &
                                            follow-up
                                        </button>
                                    </div>
                                }
                                rail={
                                    <PageHeaderRail
                                        items={views}
                                        value={view}
                                        onSelect={(v) => nav(v)}
                                        onFind={() => setDialog('search')}
                                    />
                                }
                            />
                            {readonly && (
                                <Notice title="View-only access">
                                    You can review permitted evidence.
                                    Reporting, checks and updates need
                                    additional authority.
                                </Notice>
                            )}
                            {work ? (
                                <RecordStudio
                                    model={model}
                                    id={work}
                                    onBack={closeWork}
                                    onAlert={(id) => {
                                        setAlertFocus(id);
                                        nav('map', 'alerts');
                                    }}
                                    onCheck={(id) => {
                                        setRun(
                                            runs.find((r) => r.id === id) ??
                                                originalRun,
                                        );
                                        setDialog('run');
                                    }}
                                />
                            ) : (
                                <>
                                    <TierTwoTabs
                                        tabs={subviews[view]}
                                        activeTab={sub}
                                        onTab={(s) => nav(view, s)}
                                        testIdPrefix="vehicle"
                                        ariaLabel="Vehicle sections"
                                        panelId="vehicle-content"
                                        renderLink={(
                                            tab,
                                            className,
                                            inner,
                                            a,
                                        ) => (
                                            <button
                                                key={tab.key}
                                                className={className}
                                                {...a}
                                                onClick={() => nav(view, tab.key)}
                                            >
                                                {inner}
                                            </button>
                                        )}
                                    />
                                    <div
                                        id="vehicle-content"
                                        className="content"
                                        role="tabpanel"
                                    >
                                        <StudioSurface
                                            readiness={readiness}
                                            model={model}
                                            view={view}
                                            sub={sub}
                                            onNav={nav}
                                            onWork={openWork}
                                            onCheck={() => setDialog('check')}
                                        />
                                        {view === 'map' &&
                                            sub !== 'summary' && (
                                                <TelemetryStudio
                                                    onTrip={(id) => {
                                                        setTripFocus(id);
                                                        nav('trips');
                                                    }}
                                                    focusAlert={alertFocus}
                                                    onAlertClosed={() =>
                                                        setAlertFocus('')
                                                    }
                                                    model={model}
                                                    sub={sub}
                                                    onNav={nav}
                                                    onWork={openWork}
                                                />
                                            )}
                                        {view === 'map' &&
                                            sub === 'summary' && (
                                                <MapStudio
                                                    onNav={nav}
                                                    model={model}
                                                    dark={dark}
                                                    noTracker={
                                                        !model.tracker.available
                                                    }
                                                    unavailable={
                                                        fault === 'map'
                                                    }
                                                    onTrips={() => nav('trips')}
                                                    fences={fences}
                                                    setFences={setFences}
                                                    selectedFences={
                                                        selectedFences
                                                    }
                                                    setSelectedFences={
                                                        setSelectedFences
                                                    }
                                                />
                                            )}
                                        {view === 'trips' && (
                                            <TripStudio
                                                onNav={nav}
                                                focusTrip={tripFocus}
                                                model={model}
                                                dark={dark}
                                                noTracker={stale || empty}
                                            />
                                        )}
                                        {view === 'checks' && (
                                            <StudioChecks
                                                model={model}
                                                runs={runs}
                                                templates={sub === 'templates'}
                                                onStart={(template) => {
                                                    setCheckTemplate(template);
                                                    setDialog('check');
                                                }}
                                                onView={(r) => {
                                                    setRun({
                                                        ...r,
                                                        attachments: [
                                                            ...(r.attachments ||
                                                                []),
                                                            ...model.data.documents.filter(
                                                                (f) =>
                                                                    f.owner ===
                                                                    r.id,
                                                            ),
                                                        ],
                                                    });
                                                    setDialog('run');
                                                }}
                                            />
                                        )}
                                    </div>
                                </>
                            )}
                        </>
                    )}
                    <footer className="page-footer">
                        <span>
                            PKG-02B v5 · All people, records, dates and outcomes
                            are synthetic.
                        </span>
                        <span>Design review · Application unchanged</span>
                    </footer>
                </main>
            </div>
            <OperationDialog model={model} />
            {model.message && (
                <div className="op-toast" role="status">
                    <span>{model.message}</span>
                    {model.canUndo && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={model.undo}
                        >
                            Undo
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => model.setMessage('')}
                    >
                        Dismiss
                    </Button>
                </div>
            )}
            {dialog === 'check' && !actionDisabled && (
                <CheckFlow
                    initialTemplate={checkTemplate}
                    nextId={`CHK-DEMO-${String(182 + runs.length).padStart(4, '0')}`}
                    onClose={() => setDialog('')}
                    fail={fault === 'save'}
                    searchFail={fault === 'search'}
                    unconfigured={unknown}
                    onDone={(r) => {
                        if (actionDisabled) return;
                        setRun(r);
                        setRuns((a) => [r, ...a.filter((x) => x.id !== r.id)]);
                    }}
                    onReport={() => {
                        setReportFromCheck(true);
                        setDialog('report');
                    }}
                />
            )}
            {dialog === 'run' && (
                <RunDetail
                    returnLabel={
                        work ? 'Back to work record' : 'Back to vehicle'
                    }
                    run={run}
                    linked={linked[run.id] || false}
                    canReport={Boolean(linked[run.id]) || !actionDisabled}
                    onClose={() => setDialog('')}
                    onReport={() => {
                        if (actionDisabled && !linked[run.id]) {
                            show('Reporting unavailable');
                            return;
                        }
                        if (linked[run.id]) {
                            setDialog('');
                            openWork(linked[run.id]);
                            return;
                        }
                        setReportFromCheck(true);
                        setDialog('report');
                    }}
                />
            )}
            {dialog === 'report' && !actionDisabled && (
                <ReportFlow
                    workOptions={model.data.works
                        .filter(
                            (w) =>
                                ![
                                    'Completed',
                                    'Cancelled',
                                    'Completed · awaiting release',
                                ].includes(w.status),
                        )
                        .map((w) => ({
                            id: w.id,
                            name: w.title + ' · ' + w.id,
                            detail: 'VH-014 · ' + w.status,
                        }))}
                    nextWorkId={`WO-DEMO-${269 + model.data.works.filter((w) => w.id.startsWith('WO-DEMO-')).length}`}
                    run={reportFromCheck ? run : undefined}
                    onClose={() => setDialog('')}
                    onDone={(id, report) => {
                        if (reportFromCheck)
                            setLinked((links) => ({ ...links, [run.id]: id }));
                        if (id === 'WO-DEMO-0269') setCreatedReport(true);
                        {
                            model.reportCreated(
                                id,
                                reportFromCheck ? run.id : 'Vehicle profile',
                                {
                                    ...report,
                                    check: reportFromCheck ? run : undefined,
                                },
                            );
                        }
                        setDialog('');
                        openWork(id);
                    }}
                    fail={fault === 'save'}
                    searchFail={fault === 'search'}
                    reportOnly={reportOnly}
                />
            )}
            {dialog === 'upload' && !actionDisabled && !reportOnly && (
                <UploadFlow
                    work={work || 'WO-0264'}
                    onClose={() => setDialog('')}
                    fail={fault === 'upload'}
                />
            )}
            {dialog === 'search' && (
                <Modal
                    size="standard"
                    icon={Search}
                    title="Find in this vehicle"
                    description="Search sections and permitted references"
                    onClose={() => setDialog('')}
                >
                    <Command>
                        <CommandInput
                            placeholder="Search service, RUC, checks, WO-0264…"
                            aria-label="Search vehicle sections"
                        />
                        <CommandList>
                            <CommandEmpty>
                                No matching section or reference.
                            </CommandEmpty>
                            {[
                                ...model.data.schedules.map((s) => ({
                                    label: s.name + ' · ' + s.id,
                                    keywords: 'schedule service',
                                    action: () =>
                                        nav('compliance', 'schedules'),
                                })),
                                ...model.data.works.map((w) => ({
                                    label: w.title + ' · ' + w.id,
                                    keywords: w.source,
                                    action: () => openWork(w.id),
                                })),
                                ...model.data.compliance.map((c) => ({
                                    label: c.name + ' · ' + c.id,
                                    keywords: c.evidence,
                                    action: () => nav('compliance'),
                                })),
                                {
                                    label: 'Reminders and delivery history',
                                    keywords: 'notifications task',
                                    action: () =>
                                        nav('compliance', 'reminders'),
                                },
                                ...views.map((v) => ({
                                    label: v.label,
                                    keywords:
                                        v.key === 'compliance'
                                            ? 'WoF registration RUC odometer'
                                            : v.label,
                                    action: () => nav(v.key),
                                })),
                                ...runs.map((r) => ({
                                    label: r.template + ' · ' + r.id,
                                    keywords: r.version,
                                    action: () => {
                                        setRun(r);
                                        setDialog('run');
                                    },
                                })),
                                ...model.data.documents.map((d) => ({
                                    label: d.name + ' · ' + d.id,
                                    keywords: d.owner,
                                    action: () =>
                                        model.detail('Evidence record', [
                                            ['Reference', d.id],
                                            ['Name', d.name],
                                            [
                                                'Owner',
                                                d.owner || 'Vehicle profile',
                                            ],
                                        ]),
                                })),
                                ...model.data.bookings.map((b) => ({
                                    label: b.purpose + ' · ' + b.id,
                                    keywords: b.status,
                                    action: () => nav('calendar'),
                                })),
                            ].map((x) => (
                                <CommandItem
                                    key={x.label}
                                    value={`${x.label} ${x.keywords}`}
                                    onSelect={() => {
                                        setDialog('');
                                        x.action();
                                    }}
                                >
                                    {x.label}
                                    <ArrowUpRight
                                        className="ml-auto"
                                        size={16}
                                    />
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                </Modal>
            )}
            {dialog === 'event' && (
                <Modal
                    icon={CalendarDays}
                    title={
                        detail === 'booking'
                            ? 'Busy'
                            : (defaultEntries.find((e) => e.kind === detail)
                                  ?.title ?? 'Upcoming event')
                    }
                    description="Kōwhai van · Pacific/Auckland · Source-owned context"
                    onClose={() => setDialog('')}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDialog('')}
                            >
                                Close
                            </Button>
                            {detail !== 'booking' && (
                                <Button
                                    onClick={() => {
                                        setDialog('');
                                        detail === 'compliance'
                                            ? nav('compliance')
                                            : openWork(
                                                  detail === 'service'
                                                      ? 'WO-0268'
                                                      : 'WO-0264',
                                              );
                                    }}
                                >
                                    Open source record
                                </Button>
                            )}
                        </>
                    }
                >
                    {detail === 'booking' ? (
                        <>
                            <Notice title="Busy · 25 Sep, 10:00 am–12:00 pm">
                                Your access allows busy times only. Booking
                                details are not included.
                            </Notice>
                            <p>
                                No requester, passenger, destination or purpose
                                is available in this preview state.
                            </p>
                        </>
                    ) : (
                        <>
                            <Row
                                title="Source"
                                value={
                                    defaultEntries.find(
                                        (e) => e.kind === detail,
                                    )?.ref
                                }
                            />
                            <Row
                                title="Recorded dates"
                                value={
                                    defaultEntries.find(
                                        (e) => e.kind === detail,
                                    )?.sub
                                }
                            />
                            <Notice
                                title={
                                    detail === 'restriction'
                                        ? 'Active restriction'
                                        : detail === 'service'
                                          ? 'Internal appointment'
                                          : detail === 'estimate'
                                            ? 'Estimated dates only'
                                            : 'Due reminder'
                                }
                                tone={
                                    detail === 'restriction'
                                        ? 'critical'
                                        : 'info'
                                }
                            >
                                {detail === 'restriction'
                                    ? 'No end or authorised release has been recorded.'
                                    : detail === 'service'
                                      ? 'Provider confirmation has not been recorded. This is not proof of an external booking.'
                                      : detail === 'estimate'
                                        ? '24–25 September comes from the report. It does not create a booking or release the vehicle at the end.'
                                        : 'This reminder does not reserve the vehicle for the day.'}
                            </Notice>
                        </>
                    )}
                </Modal>
            )}
            {dialog === 'detail' && (
                <Modal
                    title={detail}
                    description="Vehicle profile · Synthetic source detail"
                    onClose={() => setDialog('')}
                >
                    {evidence.some((e) => e.title === detail) ? (
                        <>
                            {(() => {
                                const e = evidence.find(
                                    (e) => e.title === detail,
                                )!;
                                return (
                                    <>
                                        <Row
                                            title="Recorded value"
                                            value={e.value}
                                            badge={
                                                <Badge tone={e.tone as any}>
                                                    {e.status}
                                                </Badge>
                                            }
                                        />
                                        <Row
                                            title="Evidence"
                                            value={e.source}
                                        />
                                        <Row
                                            title="Observation / effective date"
                                            value={e.sub}
                                        />
                                    </>
                                );
                            })()}
                            <Notice
                                title={
                                    ['RUC', 'CoF'].includes(detail) && !ready
                                        ? 'Applicability needs assessment'
                                        : 'Review the original source'
                                }
                            >
                                {detail === 'Next service'
                                    ? 'Schedule SCH-DEMO-07 records 24 Sep 2026 and 85,000 km. Last completed 24 Mar at 74,800 km. Due-soon treatment is an illustrative supplied status; no threshold is inferred.'
                                    : ['RUC', 'CoF'].includes(detail)
                                      ? 'The demo does not define legal applicability, freshness rules or exemptions. Confirm the vehicle-specific evidence through the approved source.'
                                      : 'The document date, expiry and observation are separate facts. Missing evidence remains unknown.'}
                            </Notice>
                        </>
                    ) : detail === 'Complete work' ? (
                        <Notice title="Continue in Maintenance" tone="warning">
                            Completion uses Maintenance's guarded requirements
                            and authorisation. It cannot release a restriction
                            or approve an invoice. This destination preview does
                            not perform the transition.
                        </Notice>
                    ) : detail === 'Reporting unavailable' ? (
                        <Notice
                            title="Additional access required"
                            tone="warning"
                        >
                            Your current role can review the record but cannot
                            create or link a report.
                        </Notice>
                    ) : detail === 'Original evidence' ||
                      detail === 'Service evidence' ? (
                        <>
                            <div className="document-preview">
                                <FileText size={38} />
                                <strong>
                                    {detail === 'Original evidence'
                                        ? 'EV-DEMO-0182'
                                        : 'EV-DEMO-0188'}
                                </strong>
                                <p>Synthetic evidence reference</p>
                                <p>
                                    Original author, observation and owning
                                    record remain attached.
                                </p>
                            </div>
                            <Notice title="No real document is opened">
                                This demonstrates the permitted evidence view
                                and return path only.
                            </Notice>
                        </>
                    ) : (
                        <Notice title="Contextual destination">
                            {detail === 'Progress'
                                ? 'Work progress belongs to the existing Maintenance record. Review its owner and next action there; this preview does not change progress.'
                                : detail === 'Equipment'
                                  ? 'Passenger lift is recorded on this vehicle. Its current check, service and restriction evidence must be assessed independently.'
                                  : `${detail} retains its existing destination and access rules. This isolated preview is limited to vehicle readiness and its linked sources.`}
                        </Notice>
                    )}
                </Modal>
            )}
        </TooltipProvider>
    );
}
function Empty({ title, text }: { title: string; text: string }) {
    return (
        <div className="empty">
            <History size={28} />
            <strong>{title}</strong>
            <p>{text}</p>
        </div>
    );
}
createRoot(document.getElementById('root')!).render(
    <ChecklistProvider>
        <App />
    </ChecklistProvider>,
);
