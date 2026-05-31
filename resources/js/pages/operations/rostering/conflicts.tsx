import { PageHero } from '@/components/page';
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
    ConflictFilterStrip,
    ConflictHeroFooter,
    ConflictQueueList,
    ConflictScanSettingsDialog,
    ConflictToasts,
    type ConflictsProps,
    type CoverageGap,
    type QueueAction,
    type QueueItem,
    type QueueShift,
    TYPE_META,
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
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    Download,
    Layers,
    LayoutGrid,
    type LucideIcon,
    MoreHorizontal,
    RefreshCcw,
    RefreshCw,
    Settings,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ReassignState = { shift: ReassignShift; item: QueueItem; done: string };
type UnassignState = { shift: UnassignMakeOpenShift; item: QueueItem };
type BroadcastState = { shift: BroadcastShift; item: QueueItem };
type ConfirmState = { kind: ConflictConfirmKind; item: QueueItem };

// Hero stat-tile value tints. The hero sits on the purple ops gradient, so the
// dark on-light --status-* tokens read as muddy (and --status-info IS --primary,
// i.e. purple-on-purple). Use the light on-gradient tints the design specifies
// (prototype --on-critical/warning/info) so every value is legible.
const HERO_TILE_TONE: Record<string, string> = {
    critical: 'text-[oklch(83%_0.13_22)]',
    warning: 'text-[oklch(89%_0.11_90)]',
    info: 'text-[oklch(87%_0.07_250)]',
    neutral: 'text-primary-foreground',
};

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
    const firstName = auth?.user?.name?.split(' ')?.[0] ?? 'team';
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
    const curLab = weekLabel(weekStartDate);
    const returnTo = `/operations/rostering/conflicts?week=${encodeURIComponent(props.weekStart)}`;

    const subFor = (item: QueueItem) =>
        `${TYPE_META[item.type].label} · ${item.who}`;

    /* ----------------------------- coverage create ----------------------------- */

    const buildCoverageCreateHref = (
        gap: CoverageGap,
        options?: { openShift?: boolean; repeatWeekly?: boolean },
        reservationToken?: string | null,
    ) => {
        const params = new URLSearchParams();
        params.set('site_id', String(gap.site_id));
        if (gap.rule_id) params.set('coverage_rule_id', String(gap.rule_id));
        if (gap.starts_at) params.set('starts_at', gap.starts_at);
        if (gap.ends_at) params.set('ends_at', gap.ends_at);
        if (gap.preferred_client_id) {
            params.set('client_id', String(gap.preferred_client_id));
        }
        params.set('coverage_rule_name', gap.rule_name);
        params.set('coverage_required_staff', String(gap.required_staff));
        params.set('coverage_missing_staff', String(gap.missing_staff));
        const actionRoles = coverageRolesForAction(gap);
        if (actionRoles.length > 0) {
            params.set('coverage_role_shortages', JSON.stringify(actionRoles));
        }
        params.set('return_to', returnTo);
        if (reservationToken) {
            params.set('coverage_reservation_token', reservationToken);
        }
        if (options?.openShift) params.set('open_shift', '1');
        if (options?.repeatWeekly) {
            params.set('repeat_weekly', '1');
            if (gap.starts_at) {
                const repeatEnd = new Date(gap.starts_at);
                repeatEnd.setDate(repeatEnd.getDate() + 28);
                params.set(
                    'repeat_end_date',
                    repeatEnd.toISOString().slice(0, 10),
                );
            }
        }
        return `/operations/shifts/create?${params.toString()}`;
    };

    const openCoverageCreate = async (
        gap: CoverageGap,
        options?: { openShift?: boolean; repeatWeekly?: boolean },
    ) => {
        if (!gap.starts_at || !gap.ends_at) {
            router.visit(buildCoverageCreateHref(gap, options));
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
            router.visit(buildCoverageCreateHref(gap, options, payload.token));
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

    const heroTiles: Array<{
        label: string;
        value: number;
        tone: keyof typeof HERO_TILE_TONE;
        icon: LucideIcon;
        filter: typeof filter;
    }> = [
        {
            label: 'Conflicts',
            value: blocking,
            tone: 'critical',
            icon: AlertTriangle,
            filter: 'all',
        },
        {
            label: 'Coverage gaps',
            value: counts.coverage_gap,
            tone: 'warning',
            icon: Layers,
            filter: 'coverage_gap',
        },
        {
            label: 'Open',
            value: counts.open_shift,
            tone: 'neutral',
            icon: CalendarClock,
            filter: 'open_shift',
        },
        {
            label: 'Replacing',
            value: counts.replacement,
            tone: 'info',
            icon: RefreshCw,
            filter: 'replacement',
        },
    ];

    const progressPct = seedTotal
        ? Math.round((resolvedToday / seedTotal) * 100)
        : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Conflict queue',
                    href: '/operations/rostering/conflicts',
                },
            ]}
        >
            <Head title="Rostering conflict queue" />
            <div className="space-y-4 p-4">
                <PageHero
                    category="ops"
                    icon={AlertTriangle}
                    backHref="/operations/rostering"
                    backLabel="Back to rostering"
                    title={
                        <span>
                            <span className="mb-2 flex items-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase">
                                <span
                                    aria-hidden="true"
                                    className="relative inline-flex h-2 w-2"
                                >
                                    {/* eslint-disable no-restricted-syntax -- emerald "live" ping dot, copied verbatim from the rostering index hero per the design handoff. */}
                                    <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-emerald-300/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-300 ring-2 ring-emerald-300/30" />
                                    {/* eslint-enable no-restricted-syntax */}
                                </span>
                                Conflict scan · live · last run 2 min ago
                            </span>
                            <span className="block">
                                <span className="font-normal text-primary-foreground/80">
                                    Kia ora {firstName},{' '}
                                    {pluralise(blocking, 'conflict')} need you —
                                </span>{' '}
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                    {range.startLabel} → {range.endLabel}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        <span>
                            {pluralise(
                                counts.staff_overlap,
                                'staff double-booking',
                            )}
                            ,{' '}
                            {pluralise(
                                counts.leave_clash,
                                'leave clash',
                                'leave clashes',
                            )}{' '}
                            and{' '}
                            {pluralise(
                                counts.tight_turnaround,
                                'tight turnaround',
                            )}{' '}
                            to clear.{' '}
                            {pluralise(counts.coverage_gap, 'coverage gap')} and{' '}
                            {pluralise(counts.open_shift, 'open shift')} still
                            need filling.
                        </span>
                    }
                    meta={[
                        { icon: CalendarDays, label: `${curLab} · Mon–Sun` },
                        {
                            icon: LayoutGrid,
                            label: pluralise(queue.siteOptions.length, 'site'),
                        },
                        {
                            icon: CheckCircle2,
                            label: `${resolvedToday} resolved today`,
                        },
                    ]}
                    badges={[
                        {
                            tone: 'warning',
                            icon: AlertTriangle,
                            label: `${pluralise(counts.open_shift, 'open shift')} · need cover`,
                        },
                        {
                            tone: 'critical',
                            label: pluralise(
                                counts.coverage_gap,
                                'coverage gap',
                            ),
                        },
                        {
                            tone: 'default',
                            dot: true,
                            label: `${pluralise(counts.replacement, 'replacement')} in flight`,
                        },
                    ]}
                    actions={
                        <div className="flex w-full flex-col items-stretch gap-3 md:w-[300px]">
                            <div className="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger
                                        aria-label="More actions"
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-60"
                                    >
                                        <DropdownMenuItem onSelect={rerunScan}>
                                            <RefreshCcw className="mr-2 h-4 w-4" />
                                            Re-run conflict scan
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={acknowledgeAllTurnarounds}
                                            disabled={
                                                counts.tight_turnaround === 0
                                            }
                                        >
                                            <CheckCircle2 className="mr-2 h-4 w-4" />
                                            Acknowledge all turnarounds
                                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                                {counts.tight_turnaround}
                                            </span>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={exportReport}
                                        >
                                            <Download className="mr-2 h-4 w-4" />
                                            Export conflict report
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setScanSettingsOpen(true)
                                            }
                                        >
                                            <Settings className="mr-2 h-4 w-4" />
                                            Scan settings
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                {heroTiles.map((tile) => {
                                    const TileIcon = tile.icon;
                                    return (
                                        // eslint-disable-next-line no-restricted-syntax -- tappable hero stat tile (quick filter) on the translucent gradient; not a shadcn Button.
                                        <button
                                            key={tile.label}
                                            type="button"
                                            onClick={() =>
                                                queue.setFilter(tile.filter)
                                            }
                                            className="rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2 text-left backdrop-blur-sm transition-colors hover:bg-primary-foreground/20"
                                        >
                                            <span className="flex items-center gap-2">
                                                <TileIcon className="h-4 w-4 text-primary-foreground/70" />
                                                <span
                                                    className={`text-lg font-bold tabular-nums ${HERO_TILE_TONE[tile.tone]}`}
                                                >
                                                    {tile.value}
                                                </span>
                                            </span>
                                            <span className="mt-0.5 block text-[10px] font-medium tracking-wider text-primary-foreground/60 uppercase">
                                                {tile.label}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="flex items-center justify-end gap-2.5">
                                <span className="text-[11px] font-medium text-primary-foreground/70 tabular-nums">
                                    {resolvedToday} of {seedTotal} resolved
                                </span>
                                <span className="h-1.5 w-[168px] overflow-hidden rounded-full bg-primary-foreground/20">
                                    {/* eslint-disable no-restricted-syntax -- light-green progress fill reads on the purple hero gradient, matching the emerald hero accent. */}
                                    <span
                                        className="block h-full rounded-full bg-emerald-300 transition-[width] duration-300"
                                        style={{ width: `${progressPct}%` }}
                                    />
                                    {/* eslint-enable no-restricted-syntax */}
                                </span>
                            </div>
                        </div>
                    }
                    footer={
                        <ConflictHeroFooter
                            weekStart={props.weekStart}
                            staffOptions={queue.staffOptions}
                            siteOptions={queue.siteOptions}
                            staffFilterValue={queue.staffFilterValue}
                            siteFilterValue={queue.siteFilterValue}
                            onStaffFilter={queue.setStaffFilterById}
                            onSiteFilter={queue.setSiteFilterById}
                            onResolveNext={queue.resolveNext}
                            resolveDisabled={open.length === 0}
                        />
                    }
                />

                <ConflictFilterStrip
                    filter={filter}
                    onFilter={queue.setFilter}
                    counts={counts}
                    total={open.length}
                    resolvedToday={resolvedToday}
                    seedTotal={seedTotal}
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,400px)]">
                    <ConflictQueueList
                        filter={filter}
                        visible={visible}
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
            </div>

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
        </AppLayout>
    );
}
