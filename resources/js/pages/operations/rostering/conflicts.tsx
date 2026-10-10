import {
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
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
    WeekPicker,
    formatWeekRange,
    startOfWeek,
    weekLabel,
    weekPickerYmd,
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
    TYPE_META,
    TYPE_ORDER,
    buildQueue,
    coverageRolesForAction,
    useConflictQueue,
} from '@/components/rostering/conflict-queue';
import {
    confirmedCoverageReview,
    csvCell,
} from '@/components/rostering/conflict-queue/coverage-review-result';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import AppLayout from '@/layouts/app-layout';
import { useCreateShiftLauncher } from '@/pages/operations/shifts/components/use-create-shift-launcher';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    Download,
    LayoutGrid,
    MoreHorizontal,
    RefreshCcw,
    Settings,
    Users,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

type ConfirmState = { kind: ConflictConfirmKind; item: QueueItem };
function flashError(page: unknown) {
    return Boolean(
        (page as { props?: { flash?: { error?: unknown } } } | null)?.props
            ?.flash?.error,
    );
}
function csrfToken() {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}
export default function RosteringConflicts(props: ConflictsProps) {
    const { auth } = usePage().props as {
        auth?: {
            user?: { id?: number };
            can?: { shifts?: { manageAny?: boolean } };
        };
    };
    const canManage = Boolean(auth?.can?.shifts?.manageAny);
    const items = useMemo(
        () => buildQueue(props, canManage),
        [props, canManage],
    );
    const queue = useConflictQueue(items, props.weekStart);
    const { counts, open, visible, filter, selectedId } = queue;
    const [search, setSearch] = useState('');
    const searchedVisible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return q
            ? visible.filter((item) =>
                  (
                      item.who +
                      ' ' +
                      item.summary +
                      ' ' +
                      TYPE_META[item.type].label
                  )
                      .toLowerCase()
                      .includes(q),
              )
            : visible;
    }, [visible, search]);
    const selected =
        searchedVisible.find((item) => item.id === selectedId) ?? null;
    const [confirmState, setConfirmState] = useState<ConfirmState | null>(null);
    const [pending, setPending] = useState(false);
    const actionLock = useRef(false);
    const [reviewError, setReviewError] = useState<string | null>(null);
    const [workflowError, setWorkflowError] = useState<string | null>(null);
    const [reviewNeedsRefresh, setReviewNeedsRefresh] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [scanSettingsOpen, setScanSettingsOpen] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const pickerAnchor = useRef<HTMLButtonElement>(null);
    const listAnchor = useRef<HTMLDivElement>(null);
    const detailAnchor = useRef<HTMLElement>(null);
    function showSelectedFinding() {
        if (!window.matchMedia('(max-width: 1023px)').matches) return;
        requestAnimationFrame(() => {
            detailAnchor.current?.focus({ preventScroll: true });
            detailAnchor.current?.scrollIntoView({ block: 'start' });
        });
    }
    const weekStartDate = useMemo(
        () => startOfWeek(new Date(props.weekStart + 'T00:00:00')),
        [props.weekStart],
    );
    const range = formatWeekRange(weekStartDate);
    const returnTo =
        '/operations/rostering/conflicts?week=' +
        encodeURIComponent(props.weekStart);
    const rosterUrl =
        '/operations/rostering?week=' + encodeURIComponent(props.weekStart);
    const createShiftLauncher = useCreateShiftLauncher();

    async function openCoverageCreate(gap: CoverageGap) {
        if (actionLock.current || reviewNeedsRefresh) return;
        if (
            !gap.starts_at ||
            !gap.ends_at ||
            gap.source_assessment !== 'assessed' ||
            !canManage
        ) {
            setWorkflowError('Refresh this window before creating cover.');
            return;
        }
        actionLock.current = true;
        setPending(true);
        setWorkflowError(null);
        try {
            const roles = coverageRolesForAction(gap);
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
                    role_key: roles[0]?.key ?? null,
                    return_to: returnTo,
                }),
            });
            if (!response.ok) throw new Error('reservation');
            const payload = (await response.json()) as {
                token?: string | null;
            };
            if (!payload.token) throw new Error('reservation identity');
            await createShiftLauncher.openWith({
                site_id: gap.site_id,
                coverage_rule_id: gap.rule_id,
                client_id: gap.preferred_client_id,
                starts_at: gap.starts_at,
                ends_at: gap.ends_at,
                coverage_rule_name: gap.rule_name,
                coverage_required_staff: gap.required_staff,
                coverage_missing_staff: gap.missing_staff,
                coverage_role_shortages: roles.length
                    ? JSON.stringify(roles)
                    : undefined,
                coverage_reservation_token: payload.token,
            });
        } catch {
            setWorkflowError(
                'Could not open cover for this window. Refresh the queue and try again; no staffing change is confirmed.',
            );
        } finally {
            actionLock.current = false;
            setPending(false);
        }
    }
    function dispatchAction(item: QueueItem, action: QueueAction) {
        if (actionLock.current) return;
        if (action.href) {
            router.visit(action.href);
            return;
        }
        if (reviewNeedsRefresh) return;
        if (action.key === 'create') {
            const gap = item.payload.gap as CoverageGap | undefined;
            if (gap) void openCoverageCreate(gap);
            return;
        }
        if (
            action.key === 'ack' ||
            action.key === 'dismiss' ||
            action.key === 'clear'
        ) {
            setReviewError(null);
            setConfirmState({ kind: action.key, item });
        }
    }
    async function handleConfirm(result: ConflictConfirmResult) {
        if (!confirmState || actionLock.current) return;
        const { kind, item } = confirmState;
        const gap = item.payload.gap as CoverageGap | undefined;
        const actorId = auth?.user?.id;
        const url = gap?.urls[kind];
        if (
            !gap?.action_window ||
            !gap.coverage_window_key ||
            !url ||
            !actorId
        ) {
            setReviewError(
                'This review no longer has a valid source window. Refresh the queue before saving.',
            );
            return;
        }
        const requestId = crypto.randomUUID();
        actionLock.current = true;
        setPending(true);
        setReviewError(null);
        const unknownResult =
            'The saved review could not be confirmed. Your reason is retained. Refresh and check the window before trying again.';
        let failureMessage = unknownResult;
        try {
            const response = await fetch(url, {
                method: kind === 'clear' ? 'DELETE' : 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    ...gap.action_window,
                    request_id: requestId,
                    return_to: returnTo,
                    ...(result.reason ? { reason: result.reason } : {}),
                }),
            });
            const payload = (await response.json()) as {
                result?: unknown;
                errors?: Record<string, unknown>;
            };
            if (!response.ok) {
                const values =
                    response.status === 422
                        ? Object.values(payload.errors ?? {}).flat()
                        : [];
                const message = values.find(
                    (value) => typeof value === 'string',
                );
                failureMessage =
                    typeof message === 'string'
                        ? message
                        : response.status === 403
                          ? 'Your access to this window could not be confirmed. Your reason is retained; refresh before proceeding.'
                          : unknownResult;
                throw new Error('Review request failed');
            }
            const receipt = confirmedCoverageReview(payload.result, {
                gap,
                action: kind,
                actorId,
                requestId,
                reason: result.reason,
            });
            if (!receipt) throw new Error(unknownResult);
            setConfirmState(null);
            setReviewNeedsRefresh(true);
            queue.pushToast(
                receipt.outcome === 'unchanged'
                    ? 'No active acknowledgement to clear'
                    : kind === 'clear'
                      ? 'Acknowledgement cleared'
                      : 'Coverage review recorded',
                'Staffing remains unchanged. Refreshing the source findings.',
            );
            router.reload({
                preserveScroll: true,
                onSuccess: (page) => {
                    if (flashError(page)) {
                        setWorkflowError(
                            'The review was recorded, but current findings could not be refreshed. Refresh the queue before another decision.',
                        );
                    } else {
                        setReviewNeedsRefresh(false);
                    }
                },
                onError: () =>
                    setWorkflowError(
                        'The review was recorded, but current findings could not be refreshed. Refresh the queue before another decision.',
                    ),
            });
        } catch {
            setReviewError(failureMessage);
        } finally {
            actionLock.current = false;
            setPending(false);
        }
    }
    function refreshQueue() {
        if (refreshing || actionLock.current) return;
        setRefreshing(true);
        setWorkflowError(null);
        router.reload({
            preserveScroll: true,
            onSuccess: (page) => {
                if (flashError(page)) {
                    setWorkflowError(
                        'The queue could not be refreshed. Review the current records before proceeding.',
                    );
                    return;
                }
                queue.pushToast(
                    'Queue refreshed',
                    'Current findings loaded. Publication checks run separately.',
                );
                setReviewNeedsRefresh(false);
            },
            onError: () =>
                setWorkflowError(
                    'The queue could not be refreshed. Try again.',
                ),
            onFinish: () => setRefreshing(false),
        });
    }
    function exportReport() {
        const categoryKeys = {
            staff_overlap: 'staff_overlaps',
            client_overlap: 'client_overlaps',
            leave_clash: 'time_off_conflicts',
            tight_turnaround: 'tight_turnarounds',
            coverage_gap: 'coverage_gaps',
            open_shift: 'open_shifts',
            replacement: 'active_replacements',
            recurring_alignment: 'recurring_alignment',
        };
        const rows = [
            [
                'Type',
                'Review priority',
                'Who',
                'Summary',
                'Week',
                'Time zone',
                'Assessment',
                'Limited results',
                'Scope',
                'Publication assessed',
            ],
            ...searchedVisible.map((item) => {
                const meta =
                    props.assessment?.categories[categoryKeys[item.type]];
                return [
                    TYPE_META[item.type].label,
                    item.severity,
                    item.who,
                    item.summary,
                    props.weekStart,
                    props.workerTimezone,
                    meta?.status ?? 'not returned',
                    meta?.truncated ? 'Yes' : 'No',
                    'Returned records within your access',
                    'No',
                ];
            }),
        ];
        const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
        const url = URL.createObjectURL(
            new Blob([csv], { type: 'text/csv;charset=utf-8;' }),
        );
        const a = document.createElement('a');
        a.href = url;
        a.download = 'visible-scheduling-findings-' + props.weekStart + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        queue.pushToast(
            'Visible findings exported',
            searchedVisible.length +
                ' rows · current filters · partial assessments remain labelled on screen',
        );
    }
    function goToWeek(date: Date) {
        if (pending) return;
        setPickerOpen(false);
        router.get(
            '/operations/rostering/conflicts',
            { week: weekPickerYmd(date) },
            { preserveScroll: true },
        );
    }
    function shiftWeek(days: number) {
        const date = new Date(weekStartDate);
        date.setDate(date.getDate() + days);
        goToWeek(date);
    }
    const railItems: PageHeaderRailItem<typeof filter>[] = [
        {
            key: 'all',
            label: 'All findings',
            icon: LayoutGrid,
            count: open.length,
        },
        ...TYPE_ORDER.map((type) => ({
            key: type,
            label: TYPE_META[type].short,
            icon: TYPE_META[type].icon,
            count: counts[type],
            alert: TYPE_META[type].severity === 'critical' && counts[type] > 0,
        })),
    ];
    const coverageMeta = props.assessment?.categories.coverage_gaps;
    const limited = Object.entries(props.assessment?.categories ?? {}).filter(
        ([, category]) => category.status !== 'assessed' || category.truncated,
    );
    const headerPrimaryAction = (
        <PageHeaderPrimaryButton
            icon={LayoutGrid}
            onClick={() => {
                setSearch('');
                queue.reviewNext();
                showSelectedFinding();
            }}
            disabled={!open.length || pending}
        >
            Review next
        </PageHeaderPrimaryButton>
    );
    const headerSecondaryActions = (
        <>
            <PageHeaderSearch
                value={search}
                onChange={setSearch}
                placeholder="Search findings…"
            />
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <PageHeaderGlassButton
                        icon={MoreHorizontal}
                        aria-label="More actions"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-60">
                    <DropdownMenuItem
                        onSelect={refreshQueue}
                        disabled={refreshing || pending}
                    >
                        <RefreshCcw className="mr-2 h-4 w-4" />
                        {refreshing ? 'Refreshing…' : 'Refresh findings'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem onSelect={exportReport}>
                        <Download className="mr-2 h-4 w-4" />
                        Export visible findings
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={() => setScanSettingsOpen(true)}
                    >
                        <Settings className="mr-2 h-4 w-4" />
                        Current scan criteria
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    const header = (
        <WorkforcePageHeader
            variant="profile"
            backHref={rosterUrl}
            icon={AlertTriangle}
            title="Conflict queue"
            titleChip={
                <PageHeaderStatusChip
                    variant={open.length ? 'warning' : 'info'}
                >
                    {open.length
                        ? open.length + ' findings shown'
                        : 'No findings returned'}
                </PageHeaderStatusChip>
            }
            subline={
                range.startLabel +
                ' → ' +
                range.endLabel.replace(/[ ,]+\d{4}$/, '') +
                ' · ' +
                props.workerTimezone +
                ' · approved sites'
            }
            actions={
                <>
                    {headerSecondaryActions}
                    {headerPrimaryAction}
                </>
            }
            mobilePrimaryAction={headerPrimaryAction}
            mobileSecondaryActions={headerSecondaryActions}
            mobileSummary={`${range.startLabel} → ${range.endLabel}`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Visible findings"
                        ariaLabel="View all findings"
                        onClick={() => queue.setFilter('all')}
                    >
                        <PageHeaderMeterBig>{open.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            returned in your access
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Coverage gaps"
                        tone={counts.coverage_gap ? 'warning' : undefined}
                        ariaLabel="View coverage gaps"
                        onClick={() => queue.setFilter('coverage_gap')}
                    >
                        <PageHeaderMeterBig>
                            {!coverageMeta ||
                            coverageMeta.status === 'not_assessed'
                                ? '—'
                                : counts.coverage_gap}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {!coverageMeta ||
                            coverageMeta.status === 'not_assessed'
                                ? 'not assessed for this view'
                                : coverageMeta.truncated ||
                                    coverageMeta.status === 'partially_assessed'
                                  ? 'shown · assessment incomplete'
                                  : 'reported staffing windows'}
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
                            unassigned duties shown
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Replacements"
                        ariaLabel="View replacements"
                        onClick={() => queue.setFilter('replacement')}
                    >
                        <PageHeaderMeterBig>
                            {counts.replacement}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active requests shown
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Duties checked"
                        href={rosterUrl}
                        ariaLabel="View the duty roster"
                    >
                        <PageHeaderMeterBig>
                            {props.assessment?.actionable_duty_count ?? '—'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            completed/cancelled excluded
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous week"
                        disabled={pending}
                        onClick={() => shiftWeek(-7)}
                    />
                    <PageHeaderFilterButton
                        ref={pickerAnchor}
                        icon={CalendarClock}
                        disabled={pending}
                        onClick={() => setPickerOpen(!pickerOpen)}
                    >
                        {weekLabel(weekStartDate)} · Choose week
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next week"
                        disabled={pending}
                        onClick={() => shiftWeek(7)}
                    />
                    <PageHeaderFilterSelect
                        icon={Building2}
                        label="All sites"
                        value={String(queue.siteFilterValue ?? 'all')}
                        options={[
                            { value: 'all', label: 'All sites' },
                            ...queue.siteOptions.map((option) => ({
                                value: String(option.id),
                                label: option.name,
                            })),
                        ]}
                        onChange={(value) =>
                            queue.setSiteFilterById(
                                value === 'all' ? null : Number(value),
                            )
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label="All staff"
                        value={String(queue.staffFilterValue ?? 'all')}
                        options={[
                            { value: 'all', label: 'All staff' },
                            ...queue.staffOptions.map((option) => ({
                                value: String(option.id),
                                label: option.name,
                            })),
                        ]}
                        onChange={(value) =>
                            queue.setStaffFilterById(
                                value === 'all' ? null : Number(value),
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
                    ariaLabel="Scheduling finding views"
                />
            }
        />
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: rosterUrl },
                { title: 'Rostering', href: rosterUrl },
                { title: 'Conflict queue', href: returnTo },
            ]}
        >
            <Head title="Rostering conflict queue" />
            <PageLayout hero={header}>
                <Card className="mb-4">
                    <CardContent className="p-4 text-sm">
                        <p className="font-semibold">
                            Review recorded scheduling findings
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Figures cover the returned records within your
                            access. Overlapping client duties may be planned
                            support. Acknowledgements do not provide staff, and
                            publication runs separate checks.
                        </p>
                        {limited.length ? (
                            <ul className="mt-2 space-y-1 text-muted-foreground">
                                {limited.map(([key, category]) => (
                                    <li key={key}>
                                        {key.replace(/_/g, ' ')}:{' '}
                                        {category.status === 'not_assessed'
                                            ? 'not assessed'
                                            : 'partially assessed'}
                                        {category.truncated
                                            ? ' · limited results shown'
                                            : ''}
                                        .{' '}
                                        {!canManage &&
                                        category.status === 'not_assessed' &&
                                        (key === 'coverage_gaps' ||
                                            key === 'recurring_alignment')
                                            ? 'House staffing details are not available with your current access. Ask your workforce administrator to check your access.'
                                            : category.description}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        {!props.assessment ? (
                            <p className="mt-2 text-muted-foreground">
                                Assessment details were not returned. Refresh
                                before making a decision.
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
                {workflowError || createShiftLauncher.error ? (
                    <p
                        role="alert"
                        className="mb-4 rounded-lg border bg-card p-3 text-sm text-status-critical"
                    >
                        {workflowError ?? createShiftLauncher.error}
                    </p>
                ) : null}
                <div className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,540px)]">
                    <div ref={listAnchor} className="min-w-0">
                        <ConflictQueueList
                            filter={filter}
                            visible={searchedVisible}
                            selectedId={selectedId}
                            onSelect={(id) => {
                                queue.setSelectedId(id);
                                showSelectedFinding();
                            }}
                            allResolved={!open.length}
                        />
                    </div>
                    <section
                        ref={detailAnchor}
                        tabIndex={-1}
                        aria-label="Selected finding"
                        className="min-w-0 scroll-mt-5"
                    >
                        {selected ? (
                            <Button
                                variant="outline"
                                className="frontline-tap mb-3 lg:hidden"
                                onClick={() => {
                                    listAnchor.current?.scrollIntoView({
                                        block: 'start',
                                    });
                                    listAnchor.current
                                        ?.querySelector<HTMLButtonElement>(
                                            'button[aria-pressed="true"]',
                                        )
                                        ?.focus({ preventScroll: true });
                                }}
                            >
                                <ChevronLeft className="mr-2 h-4 w-4" />
                                Back to findings
                            </Button>
                        ) : null}
                        <ConflictDetailPanel
                            item={selected}
                            onAction={dispatchAction}
                            pending={
                                pending ||
                                reviewNeedsRefresh ||
                                createShiftLauncher.loading
                            }
                        />
                    </section>
                </div>
                {pickerOpen ? (
                    <WeekPicker
                        selectedWeekStart={weekStartDate}
                        anchorRef={pickerAnchor}
                        onSelect={goToWeek}
                        onClose={() => setPickerOpen(false)}
                    />
                ) : null}
            </PageLayout>
            <ConflictToasts toasts={queue.toasts} />
            <ConflictConfirmDialog
                open={Boolean(confirmState)}
                kind={confirmState?.kind ?? 'ack'}
                item={confirmState?.item ?? null}
                pending={pending}
                error={reviewError}
                onOpenChange={(next) => {
                    if (!next && !pending) setConfirmState(null);
                }}
                onConfirm={handleConfirm}
            />
            <ConflictScanSettingsDialog
                open={scanSettingsOpen}
                onOpenChange={setScanSettingsOpen}
                assessment={props.assessment}
            />
            {createShiftLauncher.dialog}
        </AppLayout>
    );
}
