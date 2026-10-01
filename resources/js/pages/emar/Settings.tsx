/* Medication › Settings (eMAR P11 v5). The Fleet Settings workspace pattern:
 * one PageHeader page with a rail of views, Sections (TierTwoTabs) inside each
 * view, titled groups of Switch rows, drafts that survive moving between tabs
 * and views, a sticky save bar with "Review … changes", an unsaved-draft
 * guard, and a change history every save and "Keep today's value" writes. */
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import AppLayout from '@/layouts/app-layout';
import { formatTime } from '@/lib/datetime';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    Clock,
    Eye,
    FileText,
    HelpCircle,
    History,
    Home,
    KeyRound,
    Layers,
    LockKeyhole,
    Pencil,
    Pill,
    RefreshCw,
    Repeat,
    Settings as SettingsIcon,
    Shield,
    UserCheck,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    SettingsCtx,
    type Dialog,
    type SettingsContext,
} from './settings/_context';
import { DialogHost } from './settings/_dialogs';
import {
    AllChanges,
    HISTORY_FILTERS,
    StillToDecide,
    type HistoryFilters,
} from './settings/_history';
import {
    changes,
    stillToDecide,
    validateView,
    type Draft,
    type SettingsPayload,
    type ViewKey,
} from './settings/_model';
import {
    parseHash,
    sectionLabel,
    SET_VIEWS,
    settingsHash,
    visibleSections,
    visibleViews,
    type Built,
} from './settings/_nav';
import {
    MedicineRules,
    RuleDialogHost,
    RulesOverview,
    type MedicineRule,
    type RuleData,
    type RuleOptions,
} from './settings/_rules';
import {
    PIN_STATUS_OPTIONS,
    PinStatus,
    SafetyChecks,
    WitnessPins,
    type WitnessPinProps,
} from './settings/_sections';
import { useStatusMessage } from './settings/_status';
import { DoseTiming, RoundsOverview } from './settings/_timing';
import { SaveBar, StatusMessage } from './settings/_ui';

type Props = {
    rules: MedicineRule[];
    ruleOptions: RuleOptions;
    /** Houses this person can see; the house choices for medicine rules. */
    sites: { id: number; name: string }[];
    can: { manage: boolean; manage_global: boolean };
    settings: SettingsPayload;
    witnessPin: WitnessPinProps;
    /** false = a house lead who can only reset PINs sees Staff & PINs, read-only. */
    settingsAccess: boolean;
    /** An auditor: every view and the change history, read-only (P11 answer 6). */
    readOnlyAudit: boolean;
};

const VIEW_ICON: Record<ViewKey, LucideIcon> = {
    rules: Pill,
    rounds: Repeat,
    staff: UserCheck,
    alerts: Shield,
    history: History,
};
const SEC_ICON: Record<string, LucideIcon> = {
    overview: Activity,
    medicines: Pill,
    safety: Shield,
    controlled: LockKeyhole,
    photos: FileText,
    timing: Clock,
    pins: KeyRound,
    status: Users,
    decide: HelpCircle,
    changes: History,
};
/** Tabs whose settings are saved through the save bar. */
const SAVED_SECTIONS = ['safety', 'timing', 'pins'];
const SHOW_OPTIONS = [
    { value: 'all', label: 'All settings' },
    { value: 'open', label: 'Not yet reviewed' },
    { value: 'changed', label: 'Unsaved changes' },
];

export type Filters = {
    show: string;
    rulesWhere: string;
    rulesState: string;
    pinState: string;
    history: HistoryFilters;
};
const F0: Filters = {
    show: 'all',
    rulesWhere: 'all',
    rulesState: 'all',
    pinState: 'all',
    history: HISTORY_FILTERS,
};

const DirtyDot = () => (
    <span
        role="img"
        aria-label="Unsaved changes"
        className="size-2 rounded-full bg-status-warning"
    />
);

export default function EmarSettings(props: Props) {
    const { settings: s, witnessPin, settingsAccess, readOnlyAudit } = props;
    const built: Built = useMemo(
        () => ({
            rules: settingsAccess ? ['overview', 'medicines', 'safety'] : [],
            rounds: settingsAccess ? ['overview', 'timing'] : [],
            staff: ['pins', 'status'],
            history: settingsAccess ? ['decide', 'changes'] : [],
        }),
        [settingsAccess],
    );
    const [route, setRoute] = useState(() =>
        parseHash(window.location.hash, built),
    );
    const { view, sec } = route;
    const [draft, setDraftState] = useState<Draft>({});
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const status = useStatusMessage();
    const setMessage = status.show;
    const [query, setQuery] = useState('');
    const [f, setF] = useState<Filters>(F0);
    const [page, setPage] = useState(1);
    const [loadedAt, setLoadedAt] = useState(() => new Date());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [freshAfter] = useState(() =>
        Math.max(0, ...s.history.map((h) => h.id)),
    );
    const allowLeave = useRef(false);
    const unsaved = changes(s, draft);
    const pending = stillToDecide(s);

    const go = useCallback(
        (nextView: ViewKey, nextSec?: string) => {
            const next = parseHash(settingsHash(nextView, nextSec), built);
            setRoute(next);
            setQuery('');
            setPage(1);
            setMessage(null);
            window.history.replaceState(
                window.history.state,
                '',
                settingsHash(next.view, next.sec),
            );
        },
        [built, setMessage],
    );
    useEffect(() => {
        const onHash = () => {
            setRoute(parseHash(window.location.hash, built));
            setMessage(null);
        };
        window.addEventListener('hashchange', onHash);
        return () => window.removeEventListener('hashchange', onHash);
    }, [built, setMessage]);

    // The server says what was saved; show it on the page (Fleet's status
    // message). A save redirects back without the #view/tab, so put it back.
    useEffect(
        () =>
            router.on('success', (event) => {
                const saved = (
                    event.detail.page.props.flash as
                        | { medication_settings_saved?: string | null }
                        | undefined
                )?.medication_settings_saved;
                if (saved) setMessage(saved);
                if (!window.location.hash)
                    window.history.replaceState(
                        window.history.state,
                        '',
                        settingsHash(view, sec),
                    );
            }),
        [view, sec, setMessage],
    );

    // Fleet's leave guard: leaving with an unsaved draft asks first.
    const dirty = unsaved.length > 0;
    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (dirty && !allowLeave.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', beforeUnload);
        const remove = router.on('before', (event) => {
            if (
                dirty &&
                !allowLeave.current &&
                event.detail.visit.method === 'get' &&
                !event.detail.visit.only.length
            ) {
                event.preventDefault();
                setDialog({
                    kind: 'guard',
                    url: event.detail.visit.url.toString(),
                });
            }
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            remove();
        };
    }, [dirty]);

    // A value "Review changes" can't save: its field takes focus (v5). A
    // discarded or saved draft takes its message with it.
    useEffect(() => {
        document
            .querySelector<HTMLElement>('main [aria-invalid="true"]')
            ?.focus();
    }, [errors]);
    useEffect(() => {
        setErrors((e) => {
            const kept = Object.entries(e).filter(([id]) => {
                const [group, key] = id.split('.');
                return draft[group]?.[key] !== undefined;
            });
            return kept.length === Object.keys(e).length
                ? e
                : Object.fromEntries(kept);
        });
    }, [draft]);
    const review = () => {
        const found = validateView(s, draft, view);
        setErrors(found);
        const first = Object.keys(found)[0];
        if (!first) {
            setDialog({ kind: 'review', view });
            return;
        }
        const [group, key] = first.split('.');
        const section = s.definitions[group]?.[key]?.section;
        if (section && section !== sec) go(view, section);
    };

    const ruleData: RuleData = {
        rules: props.rules,
        options: props.ruleOptions,
        sites: props.sites,
        can: props.can,
        readOnlyAudit,
    };
    const canEdit = (group: string) =>
        !!s.groups[group] && s.can_manage_organisation;
    const ctx: SettingsContext = {
        s,
        draft,
        setDraft: (fn) => setDraftState(fn),
        canEdit,
        go,
        open: (next) => {
            // The walkthrough confirms in its own success pane; the page's
            // message waits until it closes.
            if (next?.kind === 'reviewdefaults' || next?.kind === 'rule')
                status.hold();
            setDialog(next);
        },
        close: () => {
            setDialog(null);
            status.release();
        },
        flash: setMessage,
        freshAfter,
        errors,
        clearError: (id) =>
            setErrors((e) => {
                if (!e[id]) return e;
                const next = { ...e };
                delete next[id];
                return next;
            }),
        leave: (url) => {
            allowLeave.current = true;
            setDialog(null);
            setDraftState({});
            router.visit(url);
        },
    };

    const refresh = () =>
        router.reload({
            only: ['settings', 'witnessPin', 'rules'],
            onSuccess: () => setLoadedAt(new Date()),
        });
    const viewGroups = Object.values(s.groups).filter((g) => g.view === view);
    const editable = viewGroups.some((g) => canEdit(g.key));
    const accessText = readOnlyAudit
        ? 'Read-only for audit'
        : !settingsAccess
          ? 'Read-only · you can reset witness PINs'
          : s.can_manage_organisation
            ? 'All-sites authority · every setting'
            : `House settings for ${props.sites.map((x) => x.name).join(' and ') || 'no houses'} · organisation rules read-only`;
    const select = (
        label: string,
        value: string,
        options: { value: string; label: string }[],
        onChange: (v: string) => void,
        icon?: LucideIcon,
        allValue = 'all',
    ) => (
        <PageHeaderFilterSelect
            key={label}
            icon={icon}
            label={label}
            value={value}
            allValue={allValue}
            options={options}
            onChange={(v) => {
                onChange(v);
                setPage(1);
            }}
        />
    );
    const historyWho = [
        ...new Set(s.history.map((h) => h.who).filter(Boolean)),
    ] as string[];
    const historyWhere = [
        ...new Set(s.history.map((h) => h.site_name ?? 'All houses')),
    ];
    const sectionFilters =
        view === 'rules' && sec === 'medicines' ? (
            <>
                {select(
                    'Where',
                    f.rulesWhere,
                    [
                        { value: 'all', label: 'All rules' },
                        { value: 'all-houses', label: 'All-houses rules' },
                        ...props.sites.map((x) => ({
                            value: String(x.id),
                            label: `Applies at ${x.name}`,
                        })),
                    ],
                    (v) => setF({ ...f, rulesWhere: v }),
                    Home,
                )}
                {select(
                    'Status',
                    f.rulesState,
                    [
                        { value: 'all', label: 'Any status' },
                        { value: 'active', label: 'Active' },
                        { value: 'paused', label: 'Paused' },
                    ],
                    (v) => setF({ ...f, rulesState: v }),
                )}
            </>
        ) : view === 'staff' && sec === 'status' ? (
            select('PIN status', f.pinState, PIN_STATUS_OPTIONS, (v) =>
                setF({ ...f, pinState: v }),
            )
        ) : view === 'history' && sec === 'changes' ? (
            <>
                {select(
                    'Area',
                    f.history.area,
                    [
                        { value: 'all', label: 'All areas' },
                        ...visibleViews(built)
                            .filter((v) => v !== 'history')
                            .map((v) => ({
                                value: v,
                                label: SET_VIEWS[v].label,
                            })),
                    ],
                    (v) => setF({ ...f, history: { ...f.history, area: v } }),
                    Layers,
                )}
                {select(
                    'Changed by',
                    f.history.who,
                    [
                        { value: 'all', label: 'Anyone' },
                        ...historyWho.map((w) => ({ value: w, label: w })),
                    ],
                    (v) => setF({ ...f, history: { ...f.history, who: v } }),
                )}
                {select(
                    'Where',
                    f.history.where,
                    [
                        { value: 'all', label: 'Anywhere' },
                        ...historyWhere.map((w) => ({ value: w, label: w })),
                    ],
                    (v) => setF({ ...f, history: { ...f.history, where: v } }),
                    Home,
                )}
            </>
        ) : SAVED_SECTIONS.includes(sec) ? (
            select('All settings', f.show, SHOW_OPTIONS, (v) =>
                setF({ ...f, show: v }),
            )
        ) : null;

    const header = (
        <PageHeader
            className="overflow-clip!"
            icon={SettingsIcon}
            title="Settings"
            titleChip={
                <PageHeaderStatusChip variant="neutral">
                    Organisation
                </PageHeaderStatusChip>
            }
            subline="Medication rules and house settings · times in NZDT (Pacific/Auckland)"
            actions={
                <>
                    <PageHeaderSearch
                        value={query}
                        onChange={setQuery}
                        placeholder={`Search ${(sec === 'overview' ? SET_VIEWS[view].label : sectionLabel(view, sec)).replace(/^[A-Z](?![A-Z])/, (c) => c.toLowerCase())}`}
                    />
                    {settingsAccess ? (
                        <PageHeaderGlassButton
                            icon={History}
                            onClick={() => go('history', 'changes')}
                        >
                            Changes
                        </PageHeaderGlassButton>
                    ) : null}
                </>
            }
            filters={
                <>
                    <span className="mr-2 inline-flex items-center gap-1.5 text-[11px] text-primary-foreground/80">
                        {editable ? (
                            <Shield className="size-3" />
                        ) : (
                            <Eye className="size-3" />
                        )}
                        {accessText}
                    </span>
                    {sectionFilters}
                    <PageHeaderFilterButton
                        icon={RefreshCw}
                        onClick={refresh}
                        aria-label={`Updated ${formatTime(loadedAt)} NZDT — refresh`}
                    >
                        Updated {formatTime(loadedAt)}
                    </PageHeaderFilterButton>
                    {unsaved.length ? (
                        <PageHeaderFilterButton
                            active
                            icon={Pencil}
                            onClick={() => setDialog({ kind: 'unsaved' })}
                        >
                            {unsaved.length} unsaved{' '}
                            {unsaved.length === 1 ? 'change' : 'changes'}
                        </PageHeaderFilterButton>
                    ) : null}
                </>
            }
            rail={
                <PageHeaderRail
                    items={visibleViews(built).map((key) => ({
                        key,
                        label: SET_VIEWS[key].label,
                        icon: VIEW_ICON[key],
                        ...(key === 'history' && pending.length
                            ? { count: pending.length }
                            : {}),
                    }))}
                    value={view}
                    onSelect={(k) => go(k)}
                    ariaLabel="Settings views"
                    decorations={Object.fromEntries(
                        visibleViews(built)
                            .filter((v) => changes(s, draft, v).length)
                            .map((v) => [v, <DirtyDot key={v} />]),
                    )}
                />
            }
        />
    );

    const secChanges = (k: string) =>
        changes(s, draft, view).filter((c) => c.section === k).length;
    const tabs = visibleSections(built, view).map(([key, label]) => ({
        key,
        label,
        icon: SEC_ICON[key],
        ...(key === 'decide' && pending.length
            ? { count: pending.length }
            : {}),
        ...(secChanges(key) ? { warningCount: secChanges(key) } : {}),
    }));
    const clearQ = () => setQuery('');
    const body =
        view === 'rules' && sec === 'overview' ? (
            <RulesOverview data={ruleData} q={query} />
        ) : view === 'rules' && sec === 'medicines' ? (
            <MedicineRules
                data={ruleData}
                q={query}
                where={f.rulesWhere}
                state={f.rulesState}
                clear={() => {
                    clearQ();
                    setF({ ...f, rulesWhere: 'all', rulesState: 'all' });
                }}
            />
        ) : view === 'rules' && sec === 'safety' ? (
            <SafetyChecks
                q={query}
                show={f.show}
                clear={() => {
                    clearQ();
                    setF({ ...f, show: 'all' });
                }}
            />
        ) : view === 'rounds' && sec === 'overview' ? (
            <RoundsOverview q={query} />
        ) : view === 'rounds' && sec === 'timing' ? (
            <DoseTiming
                q={query}
                show={f.show}
                clear={() => {
                    clearQ();
                    setF({ ...f, show: 'all' });
                }}
            />
        ) : view === 'staff' && sec === 'pins' ? (
            <WitnessPins
                q={query}
                show={f.show}
                clear={() => {
                    clearQ();
                    setF({ ...f, show: 'all' });
                }}
            />
        ) : view === 'staff' && sec === 'status' ? (
            <PinStatus
                witnessPin={witnessPin}
                q={query}
                state={f.pinState}
                clear={() => {
                    clearQ();
                    setF({ ...f, pinState: 'all' });
                }}
            />
        ) : view === 'history' && sec === 'decide' ? (
            <StillToDecide q={query} />
        ) : view === 'history' && sec === 'changes' ? (
            <AllChanges
                q={query}
                clearQ={clearQ}
                filters={f.history}
                setFilters={(h) => setF({ ...f, history: h })}
                page={page}
                setPage={setPage}
            />
        ) : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                { title: 'Settings', href: '/emar/settings' },
            ]}
        >
            <Head title="Medication settings" />
            <SettingsCtx.Provider value={ctx}>
                <div className="space-y-5">
                    {header}
                    <Sections
                        tabs={tabs}
                        value={sec}
                        onChange={(k) => go(view, k)}
                    />
                    <StatusMessage message={status.message} />
                    {body}
                    {SAVED_SECTIONS.includes(sec) ? (
                        <SaveBar
                            count={changes(s, draft, view).length}
                            onDiscard={() =>
                                setDialog({ kind: 'discard', view })
                            }
                            onReview={review}
                            readOnly={
                                editable
                                    ? undefined
                                    : readOnlyAudit
                                      ? 'Read-only — auditors can view settings and their history, not change them.'
                                      : 'Only someone who manages medication settings for all houses can change these.'
                            }
                        />
                    ) : null}
                </div>
                <DialogHost dialog={dialog} />
                <RuleDialogHost dialog={dialog} data={ruleData} />
            </SettingsCtx.Provider>
        </AppLayout>
    );
}
