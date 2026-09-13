import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    BroadcastDialog,
    type BroadcastShift,
    ReassignDialog,
    type ReassignShift,
    UnassignMakeOpenDialog,
    type UnassignMakeOpenShift,
    formatWeekRange,
    startOfWeek,
    weekLabel,
} from '@/components/rostering';
import {
    ConflictConfirmDialog,
    type ConflictConfirmKind,
    type ConflictConfirmResult,
    ConflictDetailPanel,
    ConflictQueueList,
    ConflictScanSettingsDialog,
    ConflictToasts,
    type ConflictsProps,
    type CoverageGap,
    type QueueAction,
    type QueueItem,
    type QueueShift,
    TYPE_META,
    TYPE_ORDER,
    buildQueue,
    coverageRolesForAction,
    useConflictQueue,
} from '@/components/rostering/conflict-queue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { useCreateShiftLauncher } from '@/pages/operations/shifts/components/use-create-shift-launcher';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    LayoutGrid,
    MoreHorizontal,
    RefreshCcw,
    Settings,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ReassignState = { shift: ReassignShift; item: QueueItem; done: string };
type UnassignState = { shift: UnassignMakeOpenShift; item: QueueItem };
type BroadcastState = { shift: BroadcastShift; item: QueueItem };
type ConfirmState = { kind: ConflictConfirmKind; item: QueueItem };

function pluralise(count: number, word: string, plural?: string) {
    return `${count} ${count === 1 ? word : (plural ?? `${word}s`)}`;
}

/**
 * Laravel business-rule rejections come back as `back()->with('error', …)`, which
 * lands in `flash.error` (a 2xx visit) rather than `props.errors`, so Inertia
 * fires `onSuccess`. Guard server-action success on the absence of a flash error
 * so a rejected action never toasts success or drops a still-live conflict.
 */
function hasFlashError(page: unknown): boolean {
    const flash = (page as { props?: { flash?: { error?: unknown } } } | null)
        ?.props?.flash;
    return Boolean(flash?.error);
}

function csrfToken() {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/** The shift a "reassign"/"unassign" should act on — the second of an overlap pair. */
function shiftToMove(item: QueueItem): QueueShift | null {
    if (item.shifts.length === 0) return null;
    return item.shifts.length > 1 ? item.shifts[1] : item.shifts[0];
}

export default function RosteringConflicts(props: ConflictsProps) {
    const { auth } = usePage().props as {
        auth?: {
            user?: { name?: string };
            can?: { shifts?: { manageAny?: boolean } };
        };
    };
    // Write actions hit endpoints gated on shifts.manageAny; the page itself is
    // only gated on rostering.viewAny. Disable management actions for viewers
    // without manage rights (matches the rostering index) so they never 403.
    const canManage = Boolean(auth?.can?.shifts?.manageAny);

    const items = useMemo(() => buildQueue(props), [props]);
    const queue = useConflictQueue(items, props.weekStart);
    const {
        counts,
        blocking,
        seedTotal,
        resolvedToday,
        open,
        visible,
        selected,
        filter,
        selectedId,
    } = queue;

    const [reassignState, setReassignState] = useState<ReassignState | null>(
        null,
    );
    const [unassignState, setUnassignState] = useState<UnassignState | null>(
        null,
    );
    const [broadcastState, setBroadcastState] = useState<BroadcastState | null>(
        null,
    );
    const [confirmState, setConfirmState] = useState<ConfirmState | null>(null);
    const [scanSettingsOpen, setScanSettingsOpen] = useState(false);

    const weekStartDate = useMemo(
        () => startOfWeek(new Date(`${props.weekStart}T00:00:00`)),
        [props.weekStart],
    );
    const range = useMemo(
        () => formatWeekRange(weekStartDate),
        [weekStartDate],
    );
    // End label without the year, to match the design ("Mon 25 May → Sun 31 May").
    // Strip the trailing year off the shared label so it stays consistent with
    // startLabel's format (no stray comma).
    const rangeEndLabel = range.endLabel.replace(/[ ,]+\d{4}$/, '');
    const curLab = weekLabel(weekStartDate);
    const returnTo = `/operations/rostering/conflicts?week=${encodeURIComponent(props.weekStart)}`;

    const subFor = (item: QueueItem) =>
        `${TYPE_META[item.type].label} · ${item.who}`;

    const createShiftLauncher = useCreateShiftLauncher();

    /* ----------------------------- coverage create ----------------------------- */

    // Build the inline-dialog params (was a /operations/shifts/create deep link).
    // return_to is omitted — the in-place dialog returns to this page on save.
    const buildCoverageCreateParams = (
        gap: CoverageGap,
        options?: { openShift?: boolean; repeatWeekly?: boolean },
        reservationToken?: string | null,
    ) => {
        const actionRoles = coverageRolesForAction(gap);
        let repeatEndDate: string | undefined;
        if (options?.repeatWeekly && gap.starts_at) {
            const repeatEnd = new Date(gap.starts_at);
            repeatEnd.setDate(repeatEnd.getDate() + 28);
            repeatEndDate = repeatEnd.toISOString().slice(0, 10);
        }
        return {
            site_id: gap.site_id,
            coverage_rule_id: gap.rule_id ?? undefined,
            client_id: gap.preferred_client_id ?? undefined,
            starts_at: gap.starts_at ?? undefined,
            ends_at: gap.ends_at ?? undefined,
            coverage_rule_name: gap.rule_name,
            coverage_required_staff: gap.required_staff,
            coverage_missing_staff: gap.missing_staff,
            coverage_role_shortages:
                actionRoles.length > 0
                    ? JSON.stringify(actionRoles)
                    : undefined,
            coverage_reservation_token: reservationToken ?? undefined,
            open_shift: options?.openShift,
            repeat_weekly: options?.repeatWeekly,
            repeat_end_date: repeatEndDate,
        };
    };

    const openCoverageCreate = async (
        gap: CoverageGap,
        options?: { openShift?: boolean; repeatWeekly?: boolean },
    ) => {
        if (!gap.starts_at || !gap.ends_at) {
            createShiftLauncher.openWith(
                buildCoverageCreateParams(gap, options),
            );
            return;
        }
        try {
            const response = await fetch('/operations/coverage/reservations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    site_id: gap.site_id,
                    coverage_rule_id: gap.rule_id ?? null,
                    starts_at: gap.starts_at,
                    ends_at: gap.ends_at,
                    role_key: coverageRolesForAction(gap)[0]?.key ?? null,
                    return_to: returnTo,
                }),
            });
            if (!response.ok) {
                router.reload({ only: ['coverageGaps'], preserveScroll: true });
                return;
            }
            const payload = (await response.json()) as {
                token?: string | null;
            };
            createShiftLauncher.openWith(
                buildCoverageCreateParams(gap, options, payload.token),
            );
        } catch {
            router.reload({ only: ['coverageGaps'], preserveScroll: true });
        }
    };

    const coverageLifecyclePayload = (gap: CoverageGap) => ({
        site_id: gap.site_id,
        coverage_requirement_id: gap.rule_id ?? null,
        window_starts_at: gap.starts_at,
        window_ends_at: gap.ends_at,
        return_to: returnTo,
    });

    const ackCoverage = (item: QueueItem) => {
        const gap = item.payload.gap as CoverageGap | undefined;
        if (!gap?.coverage_window_key || !gap.starts_at || !gap.ends_at) {
            queue.resolveLocally(item.id, 'Acknowledged', subFor(item));
            return;
        }
        router.post(
            `/operations/rostering/coverage/${encodeURIComponent(gap.coverage_window_key)}/ack`,
            coverageLifecyclePayload(gap),
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!hasFlashError(page)) {
                        queue.resolveLocally(
                            item.id,
                            'Acknowledged',
                            subFor(item),
                        );
                    }
                },
            },
        );
    };

    const dismissCoverage = (item: QueueItem, reason: string) => {
        const gap = item.payload.gap as CoverageGap | undefined;
        if (!gap?.coverage_window_key || !gap.starts_at || !gap.ends_at) {
            queue.resolveLocally(item.id, 'Gap dismissed', subFor(item));
            return;
        }
        router.post(
            `/operations/rostering/coverage/${encodeURIComponent(gap.coverage_window_key)}/dismiss`,
            { ...coverageLifecyclePayload(gap), reason },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!hasFlashError(page)) {
                        queue.resolveLocally(
                            item.id,
                            'Gap dismissed',
                            subFor(item),
                        );
                    }
                },
            },
        );
    };

    /* ------------------------------- dispatch ------------------------------- */

    const dispatchAction = (item: QueueItem, action: QueueAction) => {
        const sub = subFor(item);
        switch (action.key) {
            case 'reassign': {
                const shift = shiftToMove(item);
                if (!shift) return;
                setReassignState({
                    item,
                    done: action.done,
                    shift: {
                        id: shift.id,
                        starts_at: shift.startsAt,
                        ends_at: shift.endsAt,
                        client: shift.client,
                        staff: shift.staff,
                        isOpen: false,
                    },
                });
                return;
            }
            case 'assign': {
                const shift = item.shifts[0];
                if (!shift) return;
                setReassignState({
                    item,
                    done: action.done,
                    shift: {
                        id: shift.id,
                        starts_at: shift.startsAt,
                        ends_at: shift.endsAt,
                        client: shift.client,
                        staff: shift.staff,
                        isOpen: true,
                    },
                });
                return;
            }
            case 'open': {
                const shift = shiftToMove(item);
                if (!shift) return;
                setUnassignState({
                    item,
                    shift: {
                        id: shift.id,
                        starts_at: shift.startsAt,
                        client: shift.client,
                        staff: shift.staff,
                    },
                });
                return;
            }
            case 'broadcast': {
                const shift = item.shifts[0];
                if (!shift) return;
                setBroadcastState({
                    item,
                    shift: {
                        id: shift.id,
                        starts_at: shift.startsAt,
                        client: shift.client,
                        site: shift.location,
                    },
                });
                return;
            }
            case 'keep':
            case 'accept':
                setConfirmState({ kind: 'acknowledge', item });
                return;
            case 'cancel':
                setConfirmState({ kind: 'cancel', item });
                return;
            case 'ratio':
                setConfirmState({ kind: 'ratio', item });
                return;
            case 'dismiss':
                setConfirmState({ kind: 'dismiss', item });
                return;
            case 'ack':
                ackCoverage(item);
                return;
            case 'fill': {
                const openShiftIds =
                    (item.payload.open_shift_ids as number[]) ?? [];
                if (openShiftIds.length > 0) {
                    setReassignState({
                        item,
                        done: 'Open shift filled',
                        shift: { id: openShiftIds[0], isOpen: true },
                    });
                    return;
                }
                const gap = item.payload.gap as CoverageGap | undefined;
                if (gap) void openCoverageCreate(gap);
                return;
            }
            case 'create': {
                const gap = item.payload.gap as CoverageGap | undefined;
                if (gap) void openCoverageCreate(gap);
                return;
            }
            case 'approve': {
                const openPositionId = item.payload.open_position_id as
                    | number
                    | null;
                if (!openPositionId) {
                    queue.resolveLocally(item.id, action.done, sub);
                    return;
                }
                router.post(
                    `/operations/job-board/${openPositionId}/approve`,
                    {},
                    {
                        preserveScroll: true,
                        preserveState: true,
                        // The approved replacement leaves activeReplacements on
                        // the prop reload — just tally + toast on real success.
                        onSuccess: (page) => {
                            if (!hasFlashError(page)) {
                                queue.pushToast(action.done, sub);
                                queue.recordResolved();
                            }
                        },
                    },
                );
                return;
            }
            case 'board':
                router.visit('/operations/job-board');
                return;
            case 'edit':
            case 'retime': {
                // No conflict-page editor (Props are intentionally minimal); hand off
                // to the shift detail page where the full editor lives. For a tight
                // turnaround the recommendation targets the SECOND shift, so open
                // that one; otherwise the first shift.
                const target =
                    item.type === 'tight_turnaround'
                        ? shiftToMove(item)
                        : item.shifts[0];
                const id = target?.id ?? null;
                if (id) router.visit(`/operations/shifts/${id}`);
                return;
            }
            case 'leave':
                // Client-only acknowledgement — the shift stays open by choice.
                queue.resolveLocally(item.id, action.done, sub);
                return;
            case 'reject':
                // TODO: wire to a reject-claim endpoint when one exists.
                queue.resolveLocally(item.id, action.done, sub);
                return;
            default:
                queue.resolveLocally(item.id, action.done, sub);
        }
    };

    const handleConfirm = (result: ConflictConfirmResult) => {
        if (!confirmState) return;
        const { kind, item } = confirmState;
        const sub = subFor(item);
        if (kind === 'acknowledge') {
            queue.resolveLocally(
                item.id,
                'Acknowledged — both shifts kept',
                sub,
            );
        } else if (kind === 'cancel') {
            const timeOffId = item.payload.time_off_id as number | undefined;
            if (timeOffId) {
                router.delete(`/operations/rostering/time-off/${timeOffId}`, {
                    data: { return_to: returnTo, reason: result.reason ?? '' },
                    preserveScroll: true,
                    preserveState: true,
                    // The deleted leave block clears the clash on reload.
                    onSuccess: (page) => {
                        if (!hasFlashError(page)) {
                            queue.pushToast(
                                'Leave cancelled · shift retained',
                                sub,
                            );
                            queue.recordResolved();
                        }
                    },
                });
            } else {
                queue.resolveLocally(
                    item.id,
                    'Leave cancelled · shift retained',
                    sub,
                );
            }
        } else if (kind === 'ratio') {
            queue.resolveLocally(
                item.id,
                result.ratio === '2:1'
                    ? '2:1 exception approved'
                    : 'Set to 1:1 — overlap dropped',
                sub,
            );
        } else if (kind === 'dismiss') {
            dismissCoverage(item, result.reason ?? '');
        }
        setConfirmState(null);
    };

    /* --------------------------- existing dialogs --------------------------- */

    const handleReassignAssign = (
        shiftId: number,
        userId: number,
        override?: { reason: string },
    ) => {
        if (!reassignState) return;
        const { item, done } = reassignState;
        router.post(
            `/operations/shifts/${shiftId}/assign`,
            {
                user_id: userId,
                return_to: returnTo,
                ...(override
                    ? {
                          override_acknowledged: true,
                          override_reason: override.reason,
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                // Don't optimistically hide the conflict: a rejected assign comes
                // back as flash.error (still onSuccess), and a coverage "fill"
                // may only partially close the gap. Toast + tally on real success
                // and let the prop reload decide whether the item leaves.
                onSuccess: (page) => {
                    if (!hasFlashError(page)) {
                        queue.pushToast(done, subFor(item));
                        queue.recordResolved();
                    }
                },
                onFinish: () => setReassignState(null),
            },
        );
    };

    const handleUnassign = (
        shift: UnassignMakeOpenShift,
        reason: string | null,
    ) => {
        if (!unassignState) return;
        const { item } = unassignState;
        router.post(
            `/operations/shifts/${shift.id}/unassign`,
            { return_to: returnTo, ...(reason ? { reason } : {}) },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!hasFlashError(page)) {
                        queue.pushToast(
                            'Shift unassigned & opened',
                            subFor(item),
                        );
                        queue.recordResolved();
                    }
                },
                onFinish: () => setUnassignState(null),
            },
        );
    };

    const handleBroadcast = (shift: BroadcastShift, message: string | null) => {
        if (!broadcastState) return;
        const { item } = broadcastState;
        router.post(
            `/operations/shifts/${shift.id}/broadcast`,
            message ? { message } : {},
            {
                preserveScroll: true,
                preserveState: true,
                // The shift is still open after broadcasting — don't clear it,
                // just confirm the broadcast went out (and only on real success;
                // server guards reject via flash.error, which still hits onSuccess).
                onSuccess: (page) => {
                    if (!hasFlashError(page)) {
                        queue.pushToast('Broadcast sent', subFor(item));
                    }
                },
                onFinish: () => setBroadcastState(null),
            },
        );
    };

    /* ---------------------------- hero ⋯ actions ---------------------------- */

    const acknowledgeAllTurnarounds = () => {
        const ids = open
            .filter((item) => item.type === 'tight_turnaround')
            .map((item) => item.id);
        if (ids.length === 0) {
            queue.pushToast(
                'Nothing to acknowledge',
                'No open tight turnarounds',
            );
            return;
        }
        queue.resolveManyLocally(ids);
        queue.pushToast(
            `Acknowledged ${pluralise(ids.length, 'tight turnaround')}`,
            'Marked as reviewed',
        );
    };

    const exportReport = () => {
        const rows = [
            ['Type', 'Severity', 'Who', 'Summary'],
            ...open.map((item) => [
                TYPE_META[item.type].label,
                item.severity,
                item.who,
                item.summary,
            ]),
        ];
        const csv = rows
            .map((row) =>
                row
                    .map((cell) => `"${String(cell).replace(/"/g, '""')}"`)
                    .join(','),
            )
            .join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `conflict-report-${props.weekStart}.csv`;
        link.click();
        URL.revokeObjectURL(url);
        queue.pushToast(
            'Conflict report exported',
            `${open.length} open · ${curLab}`,
        );
    };

    const rerunScan = () => {
        router.reload({ preserveScroll: true });
        queue.pushToast('Scan complete', 'Conflict scan refreshed · just now');
    };

    /* -------------------------------- render -------------------------------- */

    const [search, setSearch] = useState('');
    const searchedVisible = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return visible;
        return visible.filter((item) =>
            `${item.who} ${item.summary} ${TYPE_META[item.type].label}`
                .toLowerCase()
                .includes(q),
        );
    }, [visible, search]);

    const progressPct = seedTotal
        ? Math.round((resolvedToday / seedTotal) * 100)
        : 0;

    const goToWeek = (date: Date) => {
        router.get(
            '/operations/rostering/conflicts',
            { week: date.toISOString().slice(0, 10) },
            { preserveScroll: true },
        );
    };
    const shiftWeek = (days: number) => {
        const d = new Date(weekStartDate);
        d.setDate(d.getDate() + days);
        goToWeek(d);
    };

    const railItems: PageHeaderRailItem<typeof filter>[] = [
        {
            key: 'all',
            label: 'All conflicts',
            icon: LayoutGrid,
            count: open.length,
        },
        ...TYPE_ORDER.map((type) => ({
            key: type as typeof filter,
            label: TYPE_META[type].short,
            icon: TYPE_META[type].icon,
            count: counts[type],
            alert: TYPE_META[type].severity === 'critical',
        })),
    ];

    const titleChip =
        blocking > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {blocking} blocking
            </PageHeaderStatusChip>
        ) : open.length > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {open.length} open
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                All clear
            </PageHeaderStatusChip>
        );

    const siteFilterOptions = [
        { value: 'all', label: 'All sites' },
        ...queue.siteOptions.map((option) => ({
            value: String(option.id),
            label: option.name,
        })),
    ];
    const staffFilterOptions = [
        { value: 'all', label: 'All staff' },
        ...queue.staffOptions.map((option) => ({
            value: String(option.id),
            label: option.name,
        })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/rostering"
            icon={AlertTriangle}
            title="Conflict queue"
            titleChip={titleChip}
            subline={`${range.startLabel} → ${rangeEndLabel} · ${pluralise(
                queue.siteOptions.length,
                'site',
            )} · ${resolvedToday} resolved today`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search conflicts…"
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <PageHeaderGlassButton
                                icon={MoreHorizontal}
                                aria-label="More actions"
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-60">
                            <DropdownMenuItem onSelect={rerunScan}>
                                <RefreshCcw className="mr-2 h-4 w-4" />
                                Re-run conflict scan
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={acknowledgeAllTurnarounds}
                                disabled={counts.tight_turnaround === 0}
                            >
                                <CheckCircle2 className="mr-2 h-4 w-4" />
                                Acknowledge all turnarounds
                                <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                    {counts.tight_turnaround}
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={exportReport}>
                                <Download className="mr-2 h-4 w-4" />
                                Export conflict report
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => setScanSettingsOpen(true)}
                            >
                                <Settings className="mr-2 h-4 w-4" />
                                Scan settings
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <PageHeaderPrimaryButton
                        icon={CheckCircle2}
                        onClick={queue.resolveNext}
                        disabled={open.length === 0}
                        className="disabled:pointer-events-none disabled:opacity-50"
                    >
                        Resolve next
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Blocking"
                        tone={blocking > 0 ? 'critical' : 'success'}
                        ariaLabel="View all conflicts"
                        onClick={() => queue.setFilter('all')}
                    >
                        <PageHeaderMeterBig>{blocking}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            conflicts blocking the roster
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Coverage gaps"
                        tone={counts.coverage_gap > 0 ? 'warning' : 'success'}
                        ariaLabel="View coverage gaps"
                        onClick={() => queue.setFilter('coverage_gap')}
                    >
                        <PageHeaderMeterBig>
                            {counts.coverage_gap}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            windows below required staffing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open shifts"
                        ariaLabel="View open shifts"
                        onClick={() => queue.setFilter('open_shift')}
                    >
                        <PageHeaderMeterBig>
                            {counts.open_shift}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            still need cover
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Replacing"
                        ariaLabel="View replacements in flight"
                        onClick={() => queue.setFilter('replacement')}
                    >
                        <PageHeaderMeterBig>
                            {counts.replacement}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            replacements in flight
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Resolved today"
                        value={`${resolvedToday}/${seedTotal}`}
                        ariaLabel="View all conflicts"
                        onClick={() => queue.setFilter('all')}
                    >
                        <PageHeaderMeterBar percent={progressPct} />
                        <PageHeaderMeterCaption>
                            {progressPct}% of today's queue cleared
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous week"
                        onClick={() => shiftWeek(-7)}
                    />
                    <PageHeaderFilterButton
                        icon={CalendarClock}
                        onClick={() => goToWeek(startOfWeek(new Date()))}
                    >
                        {curLab}
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next week"
                        onClick={() => shiftWeek(7)}
                    />
                    <PageHeaderFilterSelect
                        icon={Building2}
                        label="All sites"
                        value={String(queue.siteFilterValue ?? 'all')}
                        options={siteFilterOptions}
                        onChange={(v) =>
                            queue.setSiteFilterById(
                                v === 'all' ? null : Number(v),
                            )
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label="All staff"
                        value={String(queue.staffFilterValue ?? 'all')}
                        options={staffFilterOptions}
                        onChange={(v) =>
                            queue.setStaffFilterById(
                                v === 'all' ? null : Number(v),
                            )
                        }
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={filter}
                    onSelect={queue.setFilter}
                    ariaLabel="Conflict views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Conflict queue',
                    href: '/operations/rostering/conflicts',
                },
            ]}
        >
            <Head title="Rostering conflict queue" />

            <PageLayout hero={header}>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,540px)]">
                    <ConflictQueueList
                        filter={filter}
                        visible={searchedVisible}
                        selectedId={selectedId}
                        onSelect={queue.setSelectedId}
                        allResolved={open.length === 0}
                    />
                    <ConflictDetailPanel
                        item={selected}
                        onAction={dispatchAction}
                        canManage={canManage}
                    />
                </div>
            </PageLayout>

            <ConflictToasts toasts={queue.toasts} />

            <ReassignDialog
                open={Boolean(reassignState)}
                shift={reassignState?.shift ?? null}
                onOpenChange={(next) => {
                    if (!next) setReassignState(null);
                }}
                onAssign={handleReassignAssign}
            />
            <UnassignMakeOpenDialog
                open={Boolean(unassignState)}
                shift={unassignState?.shift ?? null}
                onOpenChange={(next) => {
                    if (!next) setUnassignState(null);
                }}
                onConfirm={handleUnassign}
            />
            <BroadcastDialog
                open={Boolean(broadcastState)}
                shift={broadcastState?.shift ?? null}
                onOpenChange={(next) => {
                    if (!next) setBroadcastState(null);
                }}
                onConfirm={handleBroadcast}
            />
            <ConflictConfirmDialog
                open={Boolean(confirmState)}
                kind={confirmState?.kind ?? 'acknowledge'}
                item={confirmState?.item ?? null}
                onOpenChange={(next) => {
                    if (!next) setConfirmState(null);
                }}
                onConfirm={handleConfirm}
            />
            <ConflictScanSettingsDialog
                open={scanSettingsOpen}
                onOpenChange={setScanSettingsOpen}
                onSave={() => {
                    setScanSettingsOpen(false);
                    queue.pushToast(
                        'Scan settings saved',
                        'Conflict scan updated',
                    );
                }}
            />
            {createShiftLauncher.dialog}
        </AppLayout>
    );
}
