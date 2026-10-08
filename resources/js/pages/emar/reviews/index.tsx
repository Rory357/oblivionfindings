import { MedicationJourneyReturn } from '@/components/emar/medication-journey-return';
import { displayTime } from '@/components/fleet-assets/maintenance/time-picker';
import { PersonCell, PersonDisc } from '@/components/lists/entity-cells';
import {
    compactMenu,
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarClock,
    CalendarPlus,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    EyeOff,
    FileSignature,
    GitCompare,
    Home,
    MoveRight,
    Repeat,
    Stethoscope,
    UserRound,
    XCircle,
} from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type MouseEvent,
    type ReactNode,
} from 'react';
import {
    ChangeDialog,
    ControlledOutcomeDialog,
    DecisionDialog,
} from './_change-dialogs';
import { ReviewDetailDialog } from './_detail-dialog';
import { BookReviewDialog, RecordReviewDialog } from './_review-dialogs';
import { Notice } from './_ui';
import {
    AppointmentDialog,
    CancelReviewDialog,
    IntervalDialog,
    MoveReviewDialog,
} from './_upkeep-dialogs';
import {
    cadenceLabel,
    changeStatus,
    daysBetween,
    itemOutcomeLabel,
    kindLabel,
    medicationRecordUrl,
    reviewStatus,
} from './model';
import type {
    Review,
    ReviewAction,
    ReviewItem,
    ReviewPageProps,
    ReviewView,
} from './types';

const VIEWS = [
    { value: 'due', label: 'To do' },
    { value: 'changes', label: 'Changes' },
    { value: 'booked', label: 'Booked later' },
    { value: 'recorded', label: 'Recorded' },
    { value: 'closed', label: 'Cancelled or closed' },
];
const RAIL = [
    { key: 'orders', label: 'Orders', icon: ClipboardList },
    { key: 'to_check', label: 'To check', icon: ClipboardCheck },
    { key: 'covert', label: 'Covert', icon: EyeOff },
    { key: 'reconciliation', label: 'Reconciliation', icon: GitCompare },
    { key: 'reviews', label: 'Medication reviews', icon: Stethoscope },
];
const VIEW_TITLES: Record<ReviewView, string> = {
    due: 'Reviews to do',
    changes: 'Review outcomes and changes',
    booked: 'Booked later',
    recorded: 'Recorded reviews',
    closed: 'Cancelled or closed',
};
type ChangeRow = { review: Review; item: ReviewItem };

export default function ReviewsPage(props: ReviewPageProps) {
    const {
        reviews,
        meters,
        filters,
        sites,
        can,
        selected,
        person,
        default_interval: defaultInterval,
        today,
        as_at: asAt,
    } = props;
    const selectedItem = selected?.items.find(
        (item) => item.id === props.selected_item_id,
    );
    const selectedAction = useMemo<ReviewAction | null>(
        () =>
            selected
                ? selectedItem
                    ? { type: 'change', review: selected, item: selectedItem }
                    : { type: 'detail', review: selected }
                : null,
        [selected, selectedItem],
    );
    const [search, setSearch] = useState(filters.search ?? '');
    const [action, setAction] = useState<ReviewAction | null>(() =>
        can.manage &&
        new URLSearchParams(
            typeof window === 'undefined' ? '' : window.location.search,
        ).get('book') === '1'
            ? { type: 'book', clientId: person?.id, clientName: person?.name }
            : selectedAction,
    );
    const ctx = useEntityContextMenu<Review | ChangeRow>();
    const view = filters.view ?? 'due';
    const filterParams = {
        return_to: filters.return_to || undefined,
        view,
        search: filters.search || undefined,
        site_id: filters.site_id || undefined,
        client_id: filters.client_id || undefined,
        kind: filters.kind || undefined,
    };
    const navigate = (patch: Record<string, string | number | undefined>) =>
        router.get(
            '/emar/reviews',
            { ...filterParams, ...patch },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);
    const searchTimer = useRef<number | null>(null);
    useEffect(() => {
        if (action || search === (filters.search ?? '')) return;
        searchTimer.current = window.setTimeout(
            () =>
                router.get(
                    '/emar/reviews',
                    { ...filterParams, search: search || undefined },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                    },
                ),
            350,
        );
        return () => {
            if (searchTimer.current !== null)
                window.clearTimeout(searchTimer.current);
            searchTimer.current = null;
        };
        // Filters only change after a server response; keep an interrupted query until it is sent.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, action]);
    const selectionKey = `${selected?.id ?? ''}:${props.selected_item_id ?? ''}`;
    const previousSelection = useRef(selectionKey);
    const pendingAction = useRef<ReviewAction | null>(null);
    useEffect(() => {
        if (previousSelection.current !== selectionKey) {
            previousSelection.current = selectionKey;
            const pending = pendingAction.current;
            pendingAction.current = null;
            setAction(
                selected
                    ? pending &&
                      'review' in pending &&
                      pending.review.id === selected.id
                        ? withReview(pending, selected)
                        : selectedAction
                    : null,
            );
        }
    }, [selected, selectionKey, selectedAction]);
    const close = () => {
        pendingAction.current = null;
        setAction(null);
        if (selected || action?.type === 'book')
            navigate({ review: undefined, item: undefined, book: undefined });
    };
    const open = (next: ReviewAction) => {
        if (searchTimer.current !== null)
            window.clearTimeout(searchTimer.current);
        searchTimer.current = null;
        if (!('review' in next)) {
            setAction(next);
            return;
        }
        const review = next.review;
        if (
            selected?.id === review.id &&
            selected.current_orders !== undefined
        ) {
            setAction(withReview(next, selected));
            return;
        }
        pendingAction.current = next;
        router.get(
            '/emar/reviews',
            { ...filterParams, search: search || undefined, review: review.id },
            { preserveScroll: true, preserveState: true },
        );
    };
    const reviewMenu = (review: Review): MenuItem[] =>
        compactMenu([
            {
                label: 'Open the review',
                icon: Stethoscope,
                onClick: () => open({ type: 'detail', review }),
            },
            can.manage &&
                review.status === 'scheduled' && {
                    label: 'Record the outcome',
                    icon: FileSignature,
                    onClick: () => open({ type: 'record', review }),
                },
            can.manage &&
                review.status === 'scheduled' && {
                    label: review.appointment_date
                        ? 'Change the appointment'
                        : 'Book the appointment',
                    icon: CalendarPlus,
                    onClick: () => open({ type: 'appointment', review }),
                },
            can.manage &&
                review.status === 'scheduled' && {
                    label: 'Move the review',
                    icon: MoveRight,
                    onClick: () => open({ type: 'move', review }),
                },
            can.manage &&
                review.status === 'scheduled' &&
                review.review_type === 'triggered' && {
                    label: 'Cancel the review',
                    icon: XCircle,
                    danger: true,
                    onClick: () => open({ type: 'cancel', review }),
                },
            { separator: true },
            {
                label: 'Open the medication record',
                icon: UserRound,
                onClick: () =>
                    router.visit(medicationRecordUrl(review.client_id)),
            },
            can.manage && {
                label: 'Change how often',
                icon: Repeat,
                onClick: () =>
                    open({
                        type: 'interval',
                        clientId: review.client_id,
                        clientName: review.client_name,
                        cadence: review.cadence ??
                            person?.cadence ?? {
                                months: defaultInterval.months,
                                own: false,
                                reviewed: defaultInterval.reviewed,
                            },
                    }),
            },
        ]);
    const changeMenu = ({ review, item }: ChangeRow): MenuItem[] =>
        compactMenu([
            {
                label: 'Open the change',
                icon: GitCompare,
                onClick: () => open({ type: 'change', review, item }),
            },
            item.outcome === 'pending_controlled' &&
                !item.classification_pending &&
                can.manage &&
                can.controlled &&
                !item.controlled_hidden && {
                    label: 'Add the outcome',
                    icon: FileSignature,
                    onClick: () => open({ type: 'outcome', review, item }),
                },
            item.decision === 'waiting' &&
                can.manage &&
                !item.controlled_hidden && {
                    label: 'Record the prescriber’s decision',
                    icon: FileSignature,
                    onClick: () => open({ type: 'decision', review, item }),
                },
            !!item.order_url &&
                !item.controlled_hidden && {
                    label: item.linked_order_version_id
                        ? 'Open the order in Orders'
                        : 'Enter the change in Orders',
                    icon: ArrowUpRight,
                    onClick: () =>
                        !!item.order_url && router.visit(item.order_url),
                },
            !!item.followup_url &&
                !item.controlled_hidden && {
                    label: 'Open the follow-up',
                    icon: ArrowUpRight,
                    onClick: () =>
                        !!item.followup_url && router.visit(item.followup_url),
                },
            { separator: true },
            {
                label: 'Open the review',
                icon: Stethoscope,
                onClick: () => open({ type: 'detail', review }),
            },
        ]);
    const changes: ChangeRow[] = reviews.data.flatMap((review) =>
        review.items
            .filter((item) => item.outcome !== 'continue')
            .map((item) => ({ review, item })),
    );
    const openContext = (event: MouseEvent, row: Review | ChangeRow) =>
        ctx.open(event, row);
    const dueRows = reviews.data.filter(
        (review) => daysBetween(review.scheduled_date, today) < 0,
    );
    const upcoming = reviews.data.filter(
        (review) => daysBetween(review.scheduled_date, today) >= 0,
    );
    const noFilters = !filters.search && !filters.site_id && !filters.kind;
    const section = (
        title: string,
        caption: string,
        rows: Review[],
        empty: string,
    ): ReactNode => (
        <div className="space-y-3" key={title}>
            <ListCaption title={title} caption={caption} />
            {rows.length ? (
                <>
                    <div className="hidden md:block">
                        <EntityTable
                            rows={rows}
                            rowKey={(review) => review.id}
                            identityLabel="Person"
                            identityWidth="1.6fr"
                            identity={(review) => ({
                                mark: (
                                    <PersonDisc
                                        name={review.client_name}
                                        size={30}
                                    />
                                ),
                                name: review.client_name,
                                subline: `${review.site_name ?? 'No house'} · Review ${review.id}`,
                            })}
                            columns={[
                                {
                                    key: 'kind',
                                    label: 'Kind',
                                    width: '0.75fr',
                                    cell: (review) => (
                                        <span className="text-sm">
                                            {kindLabel(review)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'due',
                                    label:
                                        view === 'recorded'
                                            ? 'Recorded review'
                                            : 'Due',
                                    width: '1fr',
                                    cell: (review) => (
                                        <div>
                                            <span className="block text-sm font-medium">
                                                {formatDateOnly(
                                                    view === 'recorded'
                                                        ? review.completed_date
                                                        : review.scheduled_date,
                                                )}
                                            </span>
                                            <ReviewStatus
                                                review={review}
                                                today={today}
                                            />
                                        </div>
                                    ),
                                },
                                {
                                    key: 'appointment',
                                    label:
                                        view === 'recorded'
                                            ? 'Clinician'
                                            : 'Appointment',
                                    width: '1.3fr',
                                    cell: (review) => (
                                        <div className="text-sm">
                                            <span className="block">
                                                {review.reviewer_name ??
                                                    'Not booked with a clinician yet'}
                                            </span>
                                            {review.appointment_date && (
                                                <span className="text-caption">
                                                    {formatDateOnly(
                                                        review.appointment_date,
                                                    )}
                                                    {review.appointment_time
                                                        ? ` · ${displayTime(review.appointment_time)}`
                                                        : ''}
                                                </span>
                                            )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1fr',
                                    cell: (review) => (
                                        <PersonCell name={review.owner_name} />
                                    ),
                                },
                            ]}
                            rowHeight="content"
                            minWidth={760}
                            actionsFor={reviewMenu}
                            onOpen={(review) =>
                                open({ type: 'detail', review })
                            }
                            onRowContextMenu={openContext}
                        />
                    </div>
                    <ul className="space-y-3 md:hidden" aria-label={title}>
                        {rows.map((review) => (
                            <li key={review.id}>
                                <Card
                                    className="gap-3 p-4"
                                    onContextMenu={(event) =>
                                        openContext(event, review)
                                    }
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="h-auto min-w-0 justify-start gap-2 p-0 text-left whitespace-normal"
                                            onClick={() =>
                                                open({ type: 'detail', review })
                                            }
                                        >
                                            <PersonDisc
                                                name={review.client_name}
                                                size={30}
                                            />
                                            <span>
                                                <span className="block font-semibold">
                                                    {review.client_name}
                                                </span>
                                                <span className="text-caption block">
                                                    {review.site_name ??
                                                        'No house'}{' '}
                                                    · Review {review.id}
                                                </span>
                                            </span>
                                        </Button>
                                        <EntityKebab
                                            actions={reviewMenu(review)}
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <ReviewStatus
                                            review={review}
                                            today={today}
                                        />
                                        <span className="text-caption">
                                            {kindLabel(review)} ·{' '}
                                            {formatDateOnly(
                                                view === 'recorded'
                                                    ? review.completed_date
                                                    : review.scheduled_date,
                                            )}
                                        </span>
                                    </div>
                                    <div className="text-sm">
                                        <p>
                                            {review.reviewer_name ??
                                                'Not booked with a clinician yet'}
                                        </p>
                                        <p className="text-caption">
                                            Owner:{' '}
                                            {review.owner_name ?? 'Unassigned'}
                                        </p>
                                    </div>
                                </Card>
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <Card className="p-2">
                    <EmptyState
                        icon={CalendarClock}
                        title={empty}
                        description={
                            filters.search
                                ? 'Try another search or clear the filters.'
                                : 'Reviews appear here when they are due.'
                        }
                    />
                </Card>
            )}
        </div>
    );
    const shown =
        reviews.from && reviews.to
            ? `${reviews.from}–${reviews.to} of ${reviews.total} reviews`
            : `${reviews.total} reviews`;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar/mar' },
                { title: 'Orders & reviews', href: '/emar/prescriptions' },
                { title: 'Medication reviews', href: '/emar/reviews' },
            ]}
        >
            <Head title="Medication reviews" />
            <div className="flex min-w-0 flex-col gap-5">
                <PageHeader
                    variant="index"
                    icon={ClipboardList}
                    title="Orders & reviews"
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            {person
                                ? 'Person'
                                : filters.site_id
                                  ? '1 house'
                                  : `${sites.length} ${sites.length === 1 ? 'house' : 'houses'}`}
                        </PageHeaderStatusChip>
                    }
                    subline={`Medication reviews${person ? ` for ${person.name}` : ''} · Pacific/Auckland`}
                    actions={
                        <>
                            <MedicationJourneyReturn />
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search people or medicines…"
                            />
                            {can.manage && (
                                <PageHeaderPrimaryButton
                                    icon={CalendarPlus}
                                    onClick={() =>
                                        open({
                                            type: 'book',
                                            clientId: person?.id,
                                            clientName: person?.name,
                                        })
                                    }
                                >
                                    Book a review
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <>
                            <Meter
                                label="Overdue"
                                value={meters.overdue}
                                caption={
                                    meters.overdue
                                        ? 'The due date has passed'
                                        : 'None overdue'
                                }
                                warning="critical"
                                onClick={() => navigate({ view: 'due' })}
                            />
                            <Meter
                                label="Due in 30 days"
                                value={meters.due_30}
                                caption="In the next 30 days"
                                onClick={() => navigate({ view: 'due' })}
                            />
                            <Meter
                                label="Booked"
                                value={meters.booked}
                                caption="More than 30 days away"
                                onClick={() => navigate({ view: 'booked' })}
                            />
                            <Meter
                                label="Waiting for the prescriber"
                                value={meters.waiting_prescriber}
                                caption="Asked, no decision yet"
                                warning="warning"
                                onClick={() => navigate({ view: 'changes' })}
                            />
                            <Meter
                                label="Changes to make"
                                value={meters.changes_to_make}
                                caption="Agreed — enter in Orders"
                                warning="warning"
                                onClick={() => navigate({ view: 'changes' })}
                            />
                            <Meter
                                label="Done"
                                value={meters.recorded}
                                caption="In the last 90 days"
                                onClick={() => navigate({ view: 'recorded' })}
                            />
                        </>
                    }
                    filters={
                        <>
                            {sites.length > 1 && !person && (
                                <PageHeaderFilterSelect
                                    icon={Home}
                                    label="House"
                                    value={
                                        filters.site_id
                                            ? String(filters.site_id)
                                            : 'all'
                                    }
                                    allValue="all"
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All your houses',
                                        },
                                        ...sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        navigate({
                                            site_id:
                                                value === 'all'
                                                    ? undefined
                                                    : value,
                                        })
                                    }
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="Kind"
                                value={filters.kind ?? 'all'}
                                allValue="all"
                                options={[
                                    {
                                        value: 'all',
                                        label: 'Regular and triggered',
                                    },
                                    { value: 'regular', label: 'Regular' },
                                    { value: 'triggered', label: 'Triggered' },
                                ]}
                                onChange={(value) =>
                                    navigate({
                                        kind:
                                            value === 'all' ? undefined : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Review view"
                                value={view}
                                allValue="due"
                                options={VIEWS}
                                onChange={(value) => navigate({ view: value })}
                            />
                            <PageHeaderFilterButton
                                icon={Clock3}
                                onClick={() =>
                                    router.reload({ preserveScroll: true })
                                }
                            >
                                As at {formatTime(asAt)} · refresh
                            </PageHeaderFilterButton>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={RAIL}
                            value="reviews"
                            ariaLabel="Orders & reviews views"
                            onSelect={(value) =>
                                value === 'reviews'
                                    ? navigate({ view: 'due' })
                                    : router.visit(
                                          `/emar/prescriptions${value === 'orders' ? '' : `?view=${value}`}`,
                                      )
                            }
                        />
                    }
                />
                {person && (
                    <Card className="gap-2 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold">
                                    {person.name}’s regular reviews
                                </p>
                                <p className="text-caption">
                                    {cadenceLabel(person.cadence.months)} ·{' '}
                                    {person.cadence.own
                                        ? 'Set for this person'
                                        : 'Organisation default'}{' '}
                                    · Next:{' '}
                                    {formatDateOnly(
                                        person.next_review_date,
                                        'Not booked yet',
                                    )}
                                </p>
                            </div>
                            {can.manage && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        open({
                                            type: 'interval',
                                            clientId: person.id,
                                            clientName: person.name,
                                            cadence: person.cadence,
                                        })
                                    }
                                >
                                    <Repeat className="size-4" /> Change how
                                    often
                                </Button>
                            )}
                        </div>
                        {!person.cadence.reviewed && (
                            <p className="text-caption">
                                The current interval is not reviewed. It is the
                                existing default, awaiting clinical-lead review.
                            </p>
                        )}
                    </Card>
                )}
                {meters.pending_outcomes > 0 && view === 'changes' && (
                    <Notice
                        warning
                        title={`${meters.pending_outcomes} ${meters.pending_outcomes === 1 ? 'outcome' : 'outcomes'} to add`}
                    >
                        A review recorded without controlled-medicine access
                        keeps the outcome pending. A lead with access adds it.
                    </Notice>
                )}
                {reviews.total === 0 && noFilters ? (
                    <Card className="p-2">
                        <EmptyState
                            icon={Stethoscope}
                            title={
                                view === 'due'
                                    ? 'No reviews to do'
                                    : `No ${VIEW_TITLES[view].toLowerCase()} yet`
                            }
                            description="Recording a regular review books the next one. Book the first review to start the cycle."
                            action={
                                can.manage ? (
                                    <Button
                                        onClick={() =>
                                            open({
                                                type: 'book',
                                                clientId: person?.id,
                                                clientName: person?.name,
                                            })
                                        }
                                    >
                                        Book a review
                                    </Button>
                                ) : undefined
                            }
                        />
                    </Card>
                ) : view === 'changes' ? (
                    <div className="space-y-3">
                        <ListCaption
                            title="Review outcomes and changes"
                            caption={shown}
                        />
                        {changes.length ? (
                            <ChangeList
                                rows={changes}
                                menus={changeMenu}
                                open={(row) => open({ type: 'change', ...row })}
                                onContext={openContext}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState
                                    icon={GitCompare}
                                    title="No changes match"
                                    description="Try another search or clear the filters."
                                />
                            </Card>
                        )}
                    </div>
                ) : view === 'due' ? (
                    <>
                        {section(
                            'Overdue',
                            'The date has passed — being away does not pause it',
                            dueRows,
                            'Nothing overdue',
                        )}
                        {section(
                            'Due in the next 30 days',
                            shown,
                            upcoming,
                            'Nothing due in the next 30 days',
                        )}
                    </>
                ) : (
                    section(
                        VIEW_TITLES[view],
                        shown,
                        reviews.data,
                        'No reviews match',
                    )
                )}
                <LaravelPagination
                    links={reviews.links}
                    lastPage={reviews.last_page}
                    preserveScroll
                />
                {ctx.ctx && (
                    <EntityContextMenu
                        x={ctx.ctx.x}
                        y={ctx.ctx.y}
                        icon={Stethoscope}
                        title={
                            'item' in ctx.ctx.record
                                ? ctx.ctx.record.item.name
                                : ctx.ctx.record.client_name
                        }
                        items={
                            'item' in ctx.ctx.record
                                ? changeMenu(ctx.ctx.record)
                                : reviewMenu(ctx.ctx.record)
                        }
                        onClose={ctx.close}
                    />
                )}
                {action?.type === 'book' && (
                    <BookReviewDialog
                        action={action}
                        today={today}
                        onClose={close}
                    />
                )}
                {action?.type === 'detail' && (
                    <ReviewDetailDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        can={can}
                        today={today}
                        defaultInterval={defaultInterval}
                        onClose={close}
                        onAction={open}
                    />
                )}
                {action?.type === 'record' && (
                    <RecordReviewDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        can={can}
                        today={today}
                        asAt={asAt}
                        defaultInterval={defaultInterval}
                        onClose={close}
                    />
                )}
                {action?.type === 'move' && (
                    <MoveReviewDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        today={today}
                        onClose={close}
                    />
                )}
                {action?.type === 'cancel' && (
                    <CancelReviewDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        onClose={close}
                    />
                )}
                {action?.type === 'appointment' && (
                    <AppointmentDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        onClose={close}
                    />
                )}
                {action?.type === 'interval' && (
                    <IntervalDialog
                        action={action}
                        defaultInterval={defaultInterval}
                        onClose={close}
                    />
                )}
                {action?.type === 'change' && (
                    <ChangeDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        item={action.item}
                        can={can}
                        onClose={close}
                        onAction={open}
                    />
                )}
                {action?.type === 'decision' && (
                    <DecisionDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        item={action.item}
                        asAt={asAt}
                        onClose={close}
                    />
                )}
                {action?.type === 'outcome' && (
                    <ControlledOutcomeDialog
                        review={
                            selected?.id === action.review.id
                                ? selected
                                : action.review
                        }
                        item={action.item}
                        onClose={close}
                    />
                )}
            </div>
        </AppLayout>
    );
}
function Meter({
    label,
    value,
    caption,
    warning,
    onClick,
}: {
    label: string;
    value: number;
    caption: string;
    warning?: 'warning' | 'critical';
    onClick: () => void;
}) {
    return (
        <PageHeaderMeterBlock
            label={label}
            tone={value > 0 && warning ? warning : 'brand'}
            onClick={onClick}
            ariaLabel={`View ${value ?? 0} ${label.toLowerCase()}`}
        >
            <PageHeaderMeterBig>{value ?? 0}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}
function ReviewStatus({ review, today }: { review: Review; today: string }) {
    const status = reviewStatus(review, today);
    return (
        <StatusBadge variant={status.variant} className="rounded-[8px]">
            {status.label}
        </StatusBadge>
    );
}
function ChangeList({
    rows,
    menus,
    open,
    onContext,
}: {
    rows: ChangeRow[];
    menus: (row: ChangeRow) => MenuItem[];
    open: (row: ChangeRow) => void;
    onContext: (event: MouseEvent, row: ChangeRow) => void;
}) {
    return (
        <>
            <div className="hidden md:block">
                <EntityTable
                    rows={rows}
                    rowKey={({ review, item }) => `${review.id}:${item.id}`}
                    identityLabel="Person and medicine"
                    identityWidth="1.8fr"
                    identity={({ review, item }) => ({
                        mark: (
                            <PersonDisc name={review.client_name} size={30} />
                        ),
                        name: review.client_name,
                        subline: item.name,
                    })}
                    columns={[
                        {
                            key: 'outcome',
                            label: 'Outcome',
                            width: '1.2fr',
                            cell: ({ item }) => (
                                <span className="text-sm">
                                    {itemOutcomeLabel(item)}
                                </span>
                            ),
                        },
                        {
                            key: 'status',
                            label: 'Next step',
                            width: '1.5fr',
                            cell: ({ item }) => {
                                const status = changeStatus(item);
                                return (
                                    <StatusBadge
                                        variant={status.variant}
                                        className="rounded-[8px] whitespace-normal"
                                    >
                                        {status.label}
                                    </StatusBadge>
                                );
                            },
                        },
                        {
                            key: 'review',
                            label: 'Review',
                            width: '1fr',
                            cell: ({ review }) => (
                                <span className="text-sm">
                                    {formatDateOnly(review.completed_date)}
                                    <span className="text-caption block">
                                        Review {review.id}
                                    </span>
                                </span>
                            ),
                        },
                    ]}
                    minWidth={720}
                    rowHeight="content"
                    actionsFor={menus}
                    onOpen={open}
                    onRowContextMenu={onContext}
                />
            </div>
            <ul className="space-y-3 md:hidden">
                {rows.map((row) => {
                    const status = changeStatus(row.item);
                    return (
                        <li key={`${row.review.id}:${row.item.id}`}>
                            <Card
                                className="gap-3 p-4"
                                onContextMenu={(event) => onContext(event, row)}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="h-auto min-w-0 p-0 text-left whitespace-normal"
                                        onClick={() => open(row)}
                                    >
                                        <span>
                                            <span className="block font-semibold">
                                                {row.review.client_name}
                                            </span>
                                            <span className="text-caption block">
                                                {row.item.name}
                                            </span>
                                        </span>
                                    </Button>
                                    <EntityKebab actions={menus(row)} />
                                </div>
                                <StatusBadge
                                    variant={status.variant}
                                    className="w-fit rounded-[8px] whitespace-normal"
                                >
                                    {status.label}
                                </StatusBadge>
                                <p className="text-caption">
                                    {itemOutcomeLabel(row.item)} ·{' '}
                                    {formatDateOnly(row.review.completed_date)}
                                </p>
                            </Card>
                        </li>
                    );
                })}
            </ul>
        </>
    );
}

function withReview(next: ReviewAction, full: Review): ReviewAction {
    return 'item' in next
        ? {
              ...next,
              review: full,
              item:
                  full.items.find((item) => item.id === next.item.id) ??
                  next.item,
          }
        : 'review' in next
          ? { ...next, review: full }
          : next;
}
