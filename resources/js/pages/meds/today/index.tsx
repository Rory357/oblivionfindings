/* Meds today (/meds/today) — the support worker's single entry for doses
 * (eMAR P01 C3, approved P01 v2 `meds-today.tsx`). The Event Horizon
 * PageHeader with honest meters (every one a link), the connected-tab rail,
 * and EntityTable lists; every dose opens the one recording dialog.
 *
 * Source of truth: Emar/WorkerMedsController. What each dose needs and
 * allows comes from the same requirements the dialog reads; saving checks
 * everything again on the server. */
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    Flag,
    LogIn,
    Package,
    Pill,
    Plus,
    RefreshCw,
    Repeat,
    UserRound,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type MouseEvent,
} from 'react';

import { ControlledChecks } from '@/components/emar/controlled/controlled-checks';
import {
    AsNeededPicker,
    WhyDialog,
} from '@/components/emar/record-dose/dialogs';
import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import type {
    DoseTarget,
    RecordMode,
} from '@/components/emar/record-dose/types';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { hiddenControlledCaption } from '@/components/meds/board-bits';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { ReportErrorModal } from '@/pages/emar/components/report-error-modal';
import {
    MyEligibility,
    myMeter,
} from '@/pages/emar/eligibility/_my-eligibility';

import GuidedRoundDialog from '@/pages/emar/components/guided-round-dialog';
import { ActivityView, AsNeededView, FollowUpsView } from './_lists';
import { RoundsTab, StockTab } from './_rounds-stock';
import {
    BLOCK_CAPTION,
    blockOf,
    isDueNow,
    isDueSoon,
    isOpen,
    isStaffDose,
    needsHelp,
    nzTime,
    nzZone,
    TZ,
    type RowActions,
    type RowPermissions,
} from './_rows';
import {
    personOf,
    ScheduleView,
    type Grouping,
    type StateFilter,
} from './_schedule';
import { PrnEffectDialog } from './components/prn-effect-dialog';
import { RecordedDetailDialog } from './components/recorded-detail-dialog';
import type {
    MedsTodayProps,
    PrnFollowUp,
    PrnMedication,
    RefusalFollowUp,
    ScheduleRow,
} from './types';

type View =
    | 'schedule'
    | 'rounds'
    | 'asneeded'
    | 'followups'
    | 'stockalerts'
    | 'controlled'
    | 'activity';
const VIEWS: View[] = [
    'schedule',
    'rounds',
    'asneeded',
    'followups',
    'stockalerts',
    'controlled',
    'activity',
];

type Overlay =
    | {
          kind: 'dose';
          target: DoseTarget;
          mode: RecordMode;
          rowKey: string | null;
      }
    | { kind: 'why'; target: DoseTarget; rowKey: string }
    | { kind: 'prn-pick' }
    | { kind: 'prn'; med: PrnMedication }
    | { kind: 'detail'; row: ScheduleRow }
    | { kind: 'effect'; followUp: PrnFollowUp }
    | { kind: 'eligibility' }
    | { kind: 'error'; clientId: number | null }
    | null;

const query = () =>
    typeof window === 'undefined'
        ? new URLSearchParams()
        : new URLSearchParams(window.location.search);

/** "Mon 28 Sep 2026" from the board's "2026-09-28". */
function shortDate(ymd: string): string {
    const [y, m, d] = ymd.split('-').map(Number);
    const at = new Date(Date.UTC(y, m - 1, d, 12));
    const part = (o: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat('en-NZ', { ...o, timeZone: 'UTC' }).format(at);
    return `${part({ weekday: 'short' })} ${d} ${part({ month: 'short' })} ${y}`;
}

/** The NZ calendar day of an instant, "2026-09-28". */
const nzDay = (iso: string): string =>
    new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date(iso));

export default function MedsToday(props: MedsTodayProps) {
    const {
        schedule,
        clients,
        rounds,
        prn_medications,
        prn_follow_ups,
        stock_alerts,
        board_user,
        board_can,
    } = props;
    const offShift = props.off_shift ?? [];
    const refusals = useMemo(
        () => props.refusal_follow_ups ?? [],
        [props.refusal_follow_ups],
    );
    const extraCan = props.board_extra_can ?? {
        report_error: false,
        view_handovers: false,
    };
    const canRecordMedication = useCallback(
        (isControlled: boolean) =>
            board_can.record_administration &&
            (!isControlled || board_can.record_controlled),
        [board_can.record_administration, board_can.record_controlled],
    );

    /* ── URL-backed view and filters ── */
    const [view, setViewState] = useState<View>(() => {
        const v = query().get('view') as View | null;
        return v && VIEWS.includes(v) ? v : 'schedule';
    });
    const breadcrumbs = useEmarBreadcrumbs(`/meds/today?view=${view}`);
    const [search, setSearch] = useState(() => query().get('q') ?? '');
    const [stateFilter, setStateFilter] = useState<StateFilter>(
        () => (query().get('state') as StateFilter) || 'all',
    );
    const [grouping, setGrouping] = useState<Grouping>(() =>
        query().get('group') === 'person' ? 'person' : 'time',
    );
    const [person, setPerson] = useState<number | null>(() =>
        query().get('pp') ? Number(query().get('pp')) : null,
    );
    const range = query().get('range') === 'today' ? 'today' : '24h';
    const outcome = query().get('outcome') ?? 'all';
    const [overlay, setOverlay] = useState<Overlay>(null);
    const [ctx, setCtx] = useState<{
        x: number;
        y: number;
        title: string;
        items: MenuItem[];
    } | null>(null);
    const [stale, setStale] = useState<string | null>(null);
    const lastTrigger = useRef<HTMLElement | null>(null);
    const searchTimer = useRef<number | undefined>(undefined);

    const writeUrl = (patch: Record<string, string | null>) => {
        const q = query();
        Object.entries(patch).forEach(([k, v]) =>
            v === null ? q.delete(k) : q.set(k, v),
        );
        const s = q.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${s ? `?${s}` : ''}`,
        );
    };
    const reloadActivity = (patch: Record<string, string | null>) => {
        const q = query();
        Object.entries({ view: 'activity', ...patch }).forEach(([k, v]) =>
            v === null ? q.delete(k) : q.set(k, v),
        );
        router.get(`/meds/today`, Object.fromEntries(q.entries()), {
            only: ['activity_page'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const setView = (v: View) => {
        setViewState(v);
        if (v === 'activity')
            reloadActivity({
                page: null,
                q: search.trim() ? search.trim() : null,
            });
        else writeUrl({ view: v === 'schedule' ? null : v, page: null });
    };

    const clientById = useMemo(
        () => new Map(clients.map((c) => [c.id, c])),
        [clients],
    );
    // A refused dose's row says who follows it up and by when.
    const scheduleRows = useMemo(() => {
        const byRefusal = new Map(
            refusals.map((f) => [
                f.refusal_id,
                { owner: f.owner, due_time: f.due_time },
            ]),
        );
        return schedule.map((r) =>
            r.recorded && byRefusal.has(r.recorded.id)
                ? { ...r, follow_up: byRefusal.get(r.recorded.id) }
                : r,
        );
    }, [schedule, refusals]);

    /* ── overlays and focus return ── */
    const open = (o: Overlay) => {
        lastTrigger.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setCtx(null);
        setOverlay(o);
    };
    const returnFocus = (rowKey?: string | null) => () => {
        if (lastTrigger.current?.isConnected) return lastTrigger.current;
        return rowKey
            ? document.querySelector<HTMLElement>(`[data-return="${rowKey}"]`)
            : null;
    };
    const overlayOpen = overlay !== null || ctx !== null;
    const overlayRef = useRef(false);
    useEffect(() => {
        overlayRef.current = overlayOpen;
    }, [overlayOpen]);

    // The time of the last successful load, for "Not updated since …".
    const loadedAt = useRef(props.now_label);
    const [staleAt, setStaleAt] = useState(0);
    useEffect(() => {
        loadedAt.current = props.now_label;
    }, [props.now_label]);
    /** Reload the board in place. A failed refresh (offline, server error)
     * keeps what is shown and says it may be out of date, instead of an error page. */
    const refresh = useCallback(() => {
        let ok = false;
        const offException = router.on('exception', () => false);
        const offInvalid = router.on('invalid', () => false);
        router.reload({
            onSuccess: () => {
                ok = true;
                setStale(null);
            },
            onFinish: () => {
                offException();
                offInvalid();
                if (!ok) {
                    setStaleAt(Date.now());
                    setStale((s) => s ?? loadedAt.current);
                }
            },
        });
    }, []);
    // Keep the board honest: refresh every 60 s while visible and nothing is open.
    useEffect(() => {
        if (!props.is_today) return;
        const id = window.setInterval(() => {
            if (document.visibilityState !== 'visible' || overlayRef.current)
                return;
            refresh();
        }, 60_000);
        return () => window.clearInterval(id);
    }, [props.is_today, refresh]);

    /* ── dose targets and actions ── */
    const targetOf = (row: ScheduleRow): DoseTarget => ({
        kind: 'scheduled',
        orderId: row.medication_id,
        scheduledFor: row.scheduled_for,
        label: {
            person: personOf(row, clientById).preferred,
            medicine: row.medication_name,
        },
    });
    const actions: RowActions = {
        record: (row) =>
            open({
                kind: 'dose',
                target: targetOf(row),
                mode: 'record',
                rowKey: row.key,
            }),
        notGiven: (row) =>
            open({
                kind: 'dose',
                target: targetOf(row),
                mode: 'notgiven',
                rowKey: row.key,
            }),
        reoffer: (row) =>
            open({
                kind: 'dose',
                target: targetOf(row),
                mode: 'reoffer',
                rowKey: row.key,
            }),
        why: (row) =>
            open({ kind: 'why', target: targetOf(row), rowKey: row.key }),
        detail: (row) => open({ kind: 'detail', row }),
        chart: (row) => row.mar_url && router.visit(row.mar_url),
        reportError: (row) => open({ kind: 'error', clientId: row.client_id }),
    };
    const can: RowPermissions = {
        canRecord: (row) => canRecordMedication(row.is_controlled),
        canReportError: extraCan.report_error,
    };
    const onContext = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({
            x: kb ? b.left + 12 : e.clientX,
            y: kb ? b.top + 12 : e.clientY,
            title,
            items,
        });
    };
    const marClients = useMemo(
        () => new Set(props.mar_client_ids ?? []),
        [props.mar_client_ids],
    );
    const chartFor = (clientId: number): (() => void) | null => {
        if (!marClients.has(clientId)) return null;
        const row = schedule.find((r) => r.client_id === clientId && r.mar_url);
        return () =>
            router.visit(
                row?.mar_url ??
                    `/emar/mar?client_id=${clientId}&date=${props.date}`,
            );
    };

    /** Next open dose this worker can record: same time first, then later. */
    const nextDueAfter = (rowKey: string | null): ScheduleRow | null => {
        const me = schedule.find((r) => r.key === rowKey);
        const openRows = schedule.filter(
            (r) =>
                r.key !== rowKey &&
                isOpen(r) &&
                canRecordMedication(r.is_controlled) &&
                !blockOf(r) &&
                !needsHelp(r),
        );
        if (!me) return openRows[0] ?? null;
        return (
            openRows.find((r) => r.scheduled_for === me.scheduled_for) ??
            [...openRows].sort((a, b) =>
                a.scheduled_for.localeCompare(b.scheduled_for),
            )[0] ??
            null
        );
    };

    /* ── counts for the meters and rail ── */
    const nowMs = new Date(props.server_now).getTime();
    const due = schedule.filter(isDueNow);
    const late = schedule.filter(
        (r) => r.recorded === null && r.status === 'overdue',
    );
    const help = schedule.filter(needsHelp);
    const dueSoFar = schedule.filter(
        (r) => new Date(r.scheduled_for).getTime() <= nowMs && isStaffDose(r),
    );
    const recordedSoFar = dueSoFar.filter((r) => r.recorded !== null);
    const effectOverdue = prn_follow_ups.filter(
        (f) => f.check_due_at && new Date(f.check_due_at).getTime() < nowMs,
    ).length;
    const followUpsOpen = refusals.length + prn_follow_ups.length;
    const followUpsOverdue =
        refusals.filter((f) => f.overdue).length + effectOverdue;
    const helpReasons = [
        ...new Set(
            help.map(
                (r) => BLOCK_CAPTION[blockOf(r) ?? 'competency'] ?? 'blocked',
            ),
        ),
    ];
    const helpCritical = help.some((r) =>
        ['allergyBlocked', 'covertMissing', 'safetyBlocked'].includes(
            blockOf(r) ?? '',
        ),
    );
    const concealed = props.concealed_schedule;
    const hiddenTotal = concealed?.total ?? props.hidden_controlled_doses ?? 0;
    const hiddenLate =
        concealed?.overdue ?? props.hidden_controlled_overdue ?? 0;
    const dueCount = due.length + (concealed?.due_now ?? 0);
    const lateCount = late.length + hiddenLate;
    const dueSoFarCount = dueSoFar.length + (concealed?.due_so_far ?? 0);
    const recordedCount =
        recordedSoFar.length + (concealed?.recorded_so_far ?? 0);
    const nextUpcoming = schedule
        .filter(
            (r) =>
                r.recorded === null &&
                isStaffDose(r) &&
                (r.status === 'upcoming' || isDueSoon(r)),
        )
        .sort((a, b) => a.scheduled_for.localeCompare(b.scheduled_for))[0];
    const oldestLate = [...late].sort((a, b) =>
        a.scheduled_for.localeCompare(b.scheduled_for),
    )[0];
    const eligibility = props.my_eligibility
        ? myMeter(props.my_eligibility)
        : null;
    const tz = nzZone(props.server_now);
    const oldestFollowUp =
        [
            ...refusals.map((f) => f.due_at),
            ...prn_follow_ups.map((f) => f.check_due_at ?? null),
        ]
            .filter((at): at is string => !!at)
            .sort((a, b) => new Date(a).getTime() - new Date(b).getTime())[0] ??
        null;
    /** " Sunday" when an instant falls on another NZ day than the board's. */
    const dayWord = (iso: string) =>
        nzDay(iso) === props.date
            ? ''
            : ` ${new Intl.DateTimeFormat('en-NZ', { weekday: 'long', timeZone: TZ }).format(new Date(iso))}`;

    const goSchedule = (state: StateFilter) => {
        setStateFilter(state);
        setViewState('schedule');
        writeUrl({ view: null, state: state === 'all' ? null : state });
    };

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Due now"
                onClick={() => goSchedule('open')}
                ariaLabel={`View ${dueCount} doses due now`}
            >
                <PageHeaderMeterBig>{dueCount}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {concealed?.due_now
                        ? `${concealed.due_now} controlled doses aren’t shown`
                        : due.length
                          ? `${new Set(due.map((r) => r.client_id)).size} ${new Set(due.map((r) => r.client_id)).size === 1 ? 'person' : 'people'} · by ${nzTime([...due].sort((a, b) => (b.window_ends_at ?? '').localeCompare(a.window_ends_at ?? ''))[0].window_ends_at)}`
                          : nextUpcoming
                            ? `Next: ${nzTime(nextUpcoming.scheduled_for)}`
                            : hiddenTotal
                              ? 'Controlled doses aren’t shown'
                              : 'Next: none today'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Late"
                tone={lateCount ? 'warning' : 'brand'}
                onClick={() => goSchedule('open')}
                ariaLabel={`View ${lateCount} late doses`}
            >
                <PageHeaderMeterBig>{lateCount}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {hiddenLate
                        ? `${hiddenLate} controlled doses aren’t shown`
                        : oldestLate
                          ? `Oldest due ${nzTime(oldestLate.scheduled_for)}`
                          : 'Nothing late'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Needs help"
                tone={
                    help.length
                        ? helpCritical
                            ? 'critical'
                            : 'warning'
                        : 'brand'
                }
                onClick={() => goSchedule('help')}
                ariaLabel={`View ${help.length} doses you can’t record as given`}
            >
                <PageHeaderMeterBig>{help.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {help.length
                        ? helpReasons.length > 1
                            ? `${helpReasons.length} reasons — see the list`
                            : helpReasons[0].charAt(0).toUpperCase() +
                              helpReasons[0].slice(1)
                        : hiddenTotal
                          ? 'Shown doses only'
                          : 'Nothing blocked'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Recorded"
                value={
                    dueSoFarCount
                        ? `${recordedCount} of ${dueSoFarCount}`
                        : undefined
                }
                onClick={() => setView('activity')}
                ariaLabel={
                    dueSoFarCount
                        ? `View activity, ${recordedCount} of ${dueSoFarCount} recorded`
                        : 'Recorded: not applicable, no doses were due'
                }
            >
                {dueSoFarCount ? (
                    <PageHeaderMeterDonut
                        percent={(recordedCount / dueSoFarCount) * 100}
                        caption="Staff doses due so far · includes controlled"
                    />
                ) : (
                    <>
                        <PageHeaderMeterBig>n/a</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            None due so far today
                        </PageHeaderMeterCaption>
                    </>
                )}
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Follow-ups"
                value={
                    followUpsOverdue && followUpsOpen
                        ? String(followUpsOpen)
                        : undefined
                }
                tone={followUpsOverdue ? 'critical' : 'brand'}
                onClick={() => setView('followups')}
                ariaLabel={`View follow-ups, ${followUpsOverdue} overdue`}
            >
                <PageHeaderMeterBig>
                    {followUpsOverdue
                        ? `${followUpsOverdue} overdue`
                        : String(followUpsOpen)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {oldestFollowUp
                        ? `Oldest ${nzTime(oldestFollowUp)}${dayWord(oldestFollowUp)}`
                        : followUpsOpen
                          ? 'Open follow-ups to check'
                          : 'None open'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {eligibility ? (
                <PageHeaderMeterBlock
                    label="My eligibility"
                    tone={
                        eligibility.tone === 'neutral'
                            ? 'brand'
                            : eligibility.tone
                    }
                    onClick={() => open({ kind: 'eligibility' })}
                    ariaLabel={`View my eligibility: ${eligibility.big.toLowerCase()}`}
                >
                    <PageHeaderMeterBig>{eligibility.big}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {eligibility.cap}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
        </>
    );

    // Minutes since the board last loaded (worked out when a refresh fails).
    const staleMinutes = stale
        ? Math.max(
              0,
              Math.floor(
                  (staleAt - new Date(props.server_now).getTime()) / 60_000,
              ),
          )
        : 0;
    const updated = stale ? (
        <PageHeaderFilterButton
            icon={RefreshCw}
            className="border-status-warning"
            onClick={refresh}
        >
            Not updated since {stale} {tz}
        </PageHeaderFilterButton>
    ) : (
        <PageHeaderFilterButton
            icon={RefreshCw}
            title="All times are NZ time (Pacific/Auckland). Refresh."
            onClick={refresh}
        >
            Updated {props.now_label} {tz}
        </PageHeaderFilterButton>
    );
    const peopleOptions = [
        ...new Map(
            prn_medications.map((m) => [
                m.client_id,
                clientById.get(m.client_id)?.preferred ?? m.client_name,
            ]),
        ).entries(),
    ].map(([id, name]) => ({
        value: String(id),
        label: name,
    }));
    const filters =
        view === 'schedule' ? (
            <>
                <PageHeaderFilterSelect
                    label="All states"
                    value={stateFilter}
                    options={[
                        { value: 'all', label: 'All states' },
                        { value: 'open', label: 'Due or late' },
                        { value: 'help', label: 'Needs help' },
                        { value: 'recorded', label: 'Has an outcome' },
                    ]}
                    onChange={(v) => {
                        setStateFilter(v as StateFilter);
                        writeUrl({ state: v === 'all' ? null : v });
                    }}
                />
                <PageHeaderViewToggle<Grouping>
                    ariaLabel="Group by"
                    value={grouping}
                    onChange={(v) => {
                        setGrouping(v);
                        writeUrl({ group: v === 'time' ? null : v });
                    }}
                    options={[
                        { value: 'time', label: 'By time', icon: Clock3 },
                        {
                            value: 'person',
                            label: 'By person',
                            icon: UserRound,
                        },
                    ]}
                />
                {updated}
            </>
        ) : view === 'asneeded' ? (
            <>
                <PageHeaderFilterSelect
                    label="All people"
                    value={person === null ? 'all' : String(person)}
                    options={[
                        { value: 'all', label: 'All people' },
                        ...peopleOptions,
                    ]}
                    onChange={(v) => {
                        setPerson(v === 'all' ? null : Number(v));
                        writeUrl({ pp: v === 'all' ? null : v });
                    }}
                />
                {updated}
            </>
        ) : view === 'activity' ? (
            <>
                <PageHeaderFilterSelect
                    label="Last 24 hours"
                    allValue="24h"
                    value={range}
                    options={[
                        { value: '24h', label: 'Last 24 hours' },
                        { value: 'today', label: 'Today only' },
                    ]}
                    onChange={(v) =>
                        reloadActivity({
                            range: v === '24h' ? null : v,
                            page: null,
                        })
                    }
                />
                <PageHeaderFilterSelect
                    label="All outcomes"
                    value={outcome}
                    options={[
                        { value: 'all', label: 'All outcomes' },
                        { value: 'given', label: 'Given' },
                        { value: 'notgiven', label: 'Not given' },
                    ]}
                    onChange={(v) =>
                        reloadActivity({
                            outcome: v === 'all' ? null : v,
                            page: null,
                        })
                    }
                />
                {updated}
            </>
        ) : (
            updated
        );

    const rail = (
        <PageHeaderRail<View>
            value={view}
            onSelect={setView}
            items={[
                {
                    key: 'schedule',
                    label: 'Schedule',
                    icon: Clock3,
                    count: due.length + late.length,
                    alert: late.length > 0,
                },
                { key: 'rounds', label: 'Rounds', icon: Repeat },
                { key: 'asneeded', label: 'As-needed', icon: Pill },
                {
                    key: 'followups',
                    label: 'Follow-ups',
                    icon: Flag,
                    count: followUpsOpen,
                    alert: followUpsOverdue > 0,
                },
                {
                    key: 'stockalerts',
                    label: 'Stock alerts',
                    icon: Package,
                    ...(stock_alerts.length
                        ? { count: stock_alerts.length }
                        : {}),
                },
                { key: 'activity', label: 'Activity', icon: Activity },
                ...(board_can.view_controlled
                    ? [
                          {
                              key: 'controlled' as const,
                              label: 'Controlled checks',
                              icon: ClipboardCheck,
                          },
                      ]
                    : []),
            ]}
        />
    );

    const hasShift = props.has_shift_context;
    const clockedIn = props.clocked_in ?? true;
    const header = (
        <PageHeader
            frontline
            mobileSummary={`${due.length} due now · ${late.length} late`}
            icon={Pill}
            title="Meds today"
            titleChip={
                hasShift ? (
                    clockedIn ? (
                        <PageHeaderStatusChip
                            variant="success"
                            icon={CheckCircle2}
                        >
                            On shift
                        </PageHeaderStatusChip>
                    ) : (
                        <PageHeaderStatusChip variant="warning" icon={LogIn}>
                            Not clocked in
                        </PageHeaderStatusChip>
                    )
                ) : null
            }
            subline={[
                shortDate(props.date),
                props.house_label,
                props.shift_label
                    ? `shift ${props.shift_label.replace(' – ', '–')}`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={(v) => {
                            setSearch(v);
                            // Activity searches on the server (it pages); wait for a pause in typing.
                            window.clearTimeout(searchTimer.current);
                            if (view === 'activity')
                                searchTimer.current = window.setTimeout(
                                    () =>
                                        reloadActivity({
                                            q: v.trim() ? v.trim() : null,
                                            page: null,
                                        }),
                                    350,
                                );
                        }}
                        placeholder="Search people or medicines…"
                    />
                    {extraCan.view_handovers ? (
                        <PageHeaderGlassButton
                            asChild
                            icon={Repeat}
                            aria-label="Shift handover — medication"
                            title="Shift handover — medication"
                        >
                            <Link href="/emar/handovers" />
                        </PageHeaderGlassButton>
                    ) : null}
                    {extraCan.report_error ? (
                        <PageHeaderGlassButton
                            icon={Flag}
                            aria-label="Report a medication error"
                            title="Report a medication error"
                            onClick={() =>
                                open({ kind: 'error', clientId: null })
                            }
                        />
                    ) : null}
                    {hasShift && !clockedIn ? (
                        <PageHeaderPrimaryButton asChild icon={LogIn}>
                            <Link href="/attendance">Clock in</Link>
                        </PageHeaderPrimaryButton>
                    ) : board_can.record_administration &&
                      prn_medications.length ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => open({ kind: 'prn-pick' })}
                        >
                            Record as-needed dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={meters}
            filters={filters}
            rail={rail}
        />
    );

    const notes = [
        (props.people_after_clock_in ?? 0) > 0
            ? `Medicines for ${props.people_after_clock_in} more ${props.people_after_clock_in === 1 ? 'person' : 'people'} on your shift show once you’re clocked in to it.`
            : null,
        hiddenControlledCaption(
            props.hidden_controlled_doses ?? 0,
            props.hidden_controlled_overdue ?? 0,
        ),
    ].filter((n): n is string => !!n);

    const prnTarget = (m: PrnMedication): DoseTarget => ({
        kind: 'prn',
        orderId: m.id,
        label: {
            person:
                clientById.get(m.client_id)?.preferred ??
                m.client_name.split(' ')[0],
            medicine: m.name,
        },
    });
    const refusalTarget = (f: RefusalFollowUp): DoseTarget | null =>
        f.scheduled_for
            ? {
                  kind: 'scheduled',
                  orderId: f.medication_id,
                  scheduledFor: f.scheduled_for,
                  label: {
                      person: f.preferred,
                      medicine: f.medication_name ?? 'Medicine',
                  },
              }
            : null;
    const nextRow =
        overlay?.kind === 'dose' && overlay.mode === 'record'
            ? nextDueAfter(overlay.rowKey)
            : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Meds today" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {view === 'schedule' ? (
                        <ScheduleView
                            rows={scheduleRows}
                            offShift={offShift}
                            clients={clientById}
                            search={search}
                            stateFilter={stateFilter}
                            grouping={grouping}
                            can={can}
                            actions={actions}
                            onContext={onContext}
                            clockedIn={clockedIn}
                            hasShift={hasShift}
                            onCall={props.on_call}
                            notes={notes}
                            concealedTotal={hiddenTotal}
                            concealedOpen={concealed?.open ?? hiddenTotal}
                            concealedWaiting={concealed?.waiting ?? 0}
                            stale={
                                stale
                                    ? `${stale} ${tz}${staleMinutes >= 1 ? ` (${staleMinutes} min ago)` : ''}`
                                    : null
                            }
                            onRefresh={refresh}
                            houseLabel={props.house_label ?? null}
                            tzLabel={tz}
                        />
                    ) : null}
                    {view === 'controlled' ? (
                        board_can.view_controlled ? (
                            <ControlledChecks search={search} />
                        ) : (
                            <EmptyState
                                icon={ClipboardCheck}
                                title="You can’t view controlled medicines"
                                description="Ask your manager if you need controlled-medicine access for your work."
                            />
                        )
                    ) : null}
                    {view === 'rounds' ? (
                        <>
                            {props.guidedRound && (
                                <GuidedRoundDialog
                                    guided={props.guidedRound}
                                    witnesses={props.witnesses}
                                    notGivenReasons={props.not_given_reasons}
                                    signer={board_user}
                                    canExport={board_can.export_round === true}
                                    onPrint={() =>
                                        window.open(
                                            `/emar/pdf/round-sheet?date=${encodeURIComponent(props.date)}`,
                                            '_blank',
                                            'noopener',
                                        )
                                    }
                                    onClose={() =>
                                        router.get(
                                            '/meds/today',
                                            { view: 'rounds' },
                                            { preserveScroll: true },
                                        )
                                    }
                                    workerBoard
                                />
                            )}
                            <RoundsTab
                                rounds={rounds}
                                schedule={schedule}
                                clientById={clientById}
                                canRecord={board_can.record_administration}
                            />
                        </>
                    ) : null}
                    {view === 'asneeded' ? (
                        <AsNeededView
                            medications={prn_medications}
                            recorded={props.prn_recorded_today ?? []}
                            clients={clientById}
                            search={search}
                            person={person}
                            canRecord={(m) =>
                                canRecordMedication(m.is_controlled)
                            }
                            canReportError={extraCan.report_error}
                            onRecord={(m) => open({ kind: 'prn', med: m })}
                            chartFor={chartFor}
                            onReportError={(id) =>
                                open({ kind: 'error', clientId: id })
                            }
                            onContext={onContext}
                        />
                    ) : null}
                    {view === 'followups' ? (
                        <FollowUpsView
                            refusals={refusals}
                            effects={prn_follow_ups}
                            clients={clientById}
                            nowIso={props.server_now}
                            canRecordRefusal={(f) =>
                                canRecordMedication(f.is_controlled) &&
                                !!refusalTarget(f)
                            }
                            canRecordEffect={(f) =>
                                canRecordMedication(Boolean(f.is_controlled))
                            }
                            onReoffer={(f) => {
                                const target = refusalTarget(f);
                                if (target)
                                    open({
                                        kind: 'dose',
                                        target,
                                        mode: 'reoffer',
                                        rowKey: null,
                                    });
                            }}
                            onEffect={(f) =>
                                open({ kind: 'effect', followUp: f })
                            }
                            chartFor={chartFor}
                            onContext={onContext}
                        />
                    ) : null}
                    {view === 'stockalerts' ? (
                        <StockTab
                            alerts={stock_alerts}
                            canManage={board_can.manage_stock}
                        />
                    ) : null}
                    {view === 'activity' ? (
                        <ActivityView
                            page={props.activity_page}
                            range={range}
                            houseLabel={props.house_label ?? null}
                            canReportError={extraCan.report_error}
                            chartFor={chartFor}
                            onReportError={(id) =>
                                open({ kind: 'error', clientId: id })
                            }
                            onContext={onContext}
                        />
                    ) : null}
                </div>
            </PageLayout>

            {/* ── Overlays: the one recording dialog for every dose ── */}
            {overlay?.kind === 'dose' ? (
                <RecordDoseDialog
                    key={`${overlay.target.kind}:${overlay.target.orderId}:${overlay.target.kind === 'scheduled' ? overlay.target.scheduledFor : ''}:${overlay.mode}`}
                    target={overlay.target}
                    entry={
                        overlay.mode === 'reoffer' && overlay.rowKey === null
                            ? 'follow-up'
                            : 'meds-today'
                    }
                    mode={overlay.mode}
                    signedAs={board_user}
                    returnFocus={returnFocus(overlay.rowKey)}
                    onEligibility={() => setOverlay({ kind: 'eligibility' })}
                    onRecorded={(result) => {
                        if (result.status !== 'queued')
                            router.reload({ preserveScroll: true });
                    }}
                    nextLabel={
                        nextRow
                            ? `Next due: ${personOf(nextRow, clientById).preferred} · ${nextRow.medication_name}`
                            : null
                    }
                    onNext={
                        nextRow
                            ? () =>
                                  setOverlay({
                                      kind: 'dose',
                                      target: targetOf(nextRow),
                                      mode: 'record',
                                      rowKey: nextRow.key,
                                  })
                            : undefined
                    }
                    onClose={() => setOverlay(null)}
                />
            ) : null}
            {overlay?.kind === 'why' ? (
                <WhyDialog
                    target={overlay.target}
                    onClose={() => setOverlay(null)}
                    onRecordNotGiven={() =>
                        setOverlay({
                            kind: 'dose',
                            target: overlay.target,
                            mode: 'notgiven',
                            rowKey: overlay.rowKey,
                        })
                    }
                    onEligibility={() => setOverlay({ kind: 'eligibility' })}
                />
            ) : null}
            {overlay?.kind === 'prn-pick' ? (
                <AsNeededPicker
                    choices={prn_medications.filter((m) =>
                        canRecordMedication(m.is_controlled),
                    )}
                    onClose={() => setOverlay(null)}
                    onPick={(medId) => {
                        const med = prn_medications.find((m) => m.id === medId);
                        setOverlay(med ? { kind: 'prn', med } : null);
                    }}
                />
            ) : null}
            {overlay?.kind === 'prn' ? (
                <RecordDoseDialog
                    key={`prn:${overlay.med.id}`}
                    target={prnTarget(overlay.med)}
                    entry="as-needed"
                    signedAs={board_user}
                    returnFocus={returnFocus(`prn-${overlay.med.id}`)}
                    onEligibility={() => setOverlay({ kind: 'eligibility' })}
                    onRecorded={(result) => {
                        if (result.status !== 'queued')
                            router.reload({ preserveScroll: true });
                    }}
                    onClose={() => setOverlay(null)}
                />
            ) : null}
            {overlay?.kind === 'detail' ? (
                <RecordedDetailDialog
                    row={overlay.row}
                    person={personOf(overlay.row, clientById)}
                    dateLabel={props.date_label}
                    canViewMar={board_can.view_emar && !!overlay.row.mar_url}
                    onClose={() => setOverlay(null)}
                />
            ) : null}
            {overlay?.kind === 'effect' ? (
                <PrnEffectDialog
                    followUp={overlay.followUp}
                    client={clientById.get(overlay.followUp.client_id)}
                    onClose={() => setOverlay(null)}
                />
            ) : null}
            {overlay?.kind === 'eligibility' && props.my_eligibility ? (
                <MyEligibility
                    data={props.my_eligibility}
                    name={board_user.name}
                    onClose={() => setOverlay(null)}
                />
            ) : null}
            {overlay?.kind === 'error' ? (
                <ReportErrorModal
                    open
                    onClose={() => setOverlay(null)}
                    clients={clients.map((c) => ({
                        id: c.id,
                        name: c.name,
                        site: c.site_name,
                    }))}
                    initialClientId={overlay.clientId}
                />
            ) : null}
            {ctx ? (
                <EntityContextMenu
                    x={ctx.x}
                    y={ctx.y}
                    icon={Pill}
                    title={ctx.title}
                    items={ctx.items}
                    onClose={() => setCtx(null)}
                />
            ) : null}
        </AppLayout>
    );
}
