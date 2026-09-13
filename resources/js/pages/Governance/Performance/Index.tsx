import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, Plus, Target, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    PerformanceReviewWizardDialog,
    RATING_LABELS,
    REVIEW_TYPE_LABELS,
    humanise,
    ratingVariant,
    reviewStatusVariant,
    type BoardMemberOption,
} from './_dialogs';

interface Review {
    id: number;
    review_cycle: string;
    review_type: string;
    period_start: string;
    period_end: string;
    status: string;
    /** Server-masked for a reviewee until the review is completed. */
    overall_rating: string | null;
    reviewee: { name: string } | null;
    goals?: unknown[];
}

interface Props extends PageProps {
    reviews: {
        data: Review[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        last_page?: number;
        total?: number;
    };
    review_cycles: Array<{ value: string; label: string }>;
    summary?: {
        total: number;
        active: number;
        completed: number;
        board_review: number;
    };
    filters?: {
        status: string | null;
        review_type: string | null;
        search: string | null;
    };
    can_create?: boolean;
    board_members?: BoardMemberOption[];
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'active', label: 'In progress' },
    { value: 'draft', label: 'Draft' },
    { value: 'self_review', label: 'Self review' },
    { value: 'peer_review', label: 'Peer review' },
    { value: 'board_review', label: 'Board review' },
    { value: 'completed', label: 'Completed' },
];

const shortDate = (value: string) => formatDateOnly(value?.slice(0, 10));

export default function PerformanceIndex({
    reviews,
    review_cycles,
    summary,
    filters = { status: null, review_type: null, search: null },
    can_create = false,
    board_members = [],
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', can_create);
    const ctxMenu = useEntityContextMenu<Review>();

    const counts = summary ?? {
        total: reviews.data.length,
        active: reviews.data.filter((r) => r.status !== 'completed').length,
        completed: reviews.data.filter((r) => r.status === 'completed').length,
        board_review: reviews.data.filter((r) => r.status === 'board_review')
            .length,
    };

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<NonNullable<Props['filters']>>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/performance', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(
        filters.status || filters.review_type || filters.search,
    );
    const open = (review: Review) =>
        router.visit(`/governance/performance/${review.id}`);
    const actionsFor = (review: Review): MenuItem[] =>
        compactMenu([
            {
                label: 'Open review',
                icon: ExternalLink,
                onClick: () => open(review),
            },
        ]);
    const titleFor = (review: Review) =>
        review.reviewee?.name ?? 'Performance review';

    const header = (
        <PageHeader
            icon={Target}
            title="CEO performance"
            subline="CEO and executive review cycles, goals, KPIs and board ratings"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search reviewee or cycle…"
                    />
                    {can_create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New review
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="In progress"
                        href="/governance/performance?status=active"
                    >
                        <PageHeaderMeterBig>{counts.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Reviews not yet completed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Board review"
                        href="/governance/performance?status=board_review"
                        tone={counts.board_review > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {counts.board_review}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Awaiting board sign-off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Completed"
                        href="/governance/performance?status=completed"
                        tone={counts.completed > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.completed}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Finalised reviews
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="All reviews"
                        href="/governance/performance"
                    >
                        <PageHeaderMeterBig>{counts.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Current cycle {review_cycles[0]?.label ?? '—'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            go({ status: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Type"
                        value={filters.review_type ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any type' },
                            ...Object.entries(REVIEW_TYPE_LABELS).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        ]}
                        onChange={(value) =>
                            go({ review_type: value === ALL ? null : value })
                        }
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'CEO performance', href: '/governance/performance' },
            ]}
        >
            <Head title="CEO performance" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Performance reviews"
                        caption={`${reviews.data.length} of ${reviews.total ?? reviews.data.length} shown`}
                    />

                    {reviews.data.length === 0 ? (
                        <EmptyState
                            icon={Target}
                            title={
                                hasFilters
                                    ? 'No reviews match your filters'
                                    : 'No performance reviews yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Reviews you can access appear here once a cycle is set up.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({
                                                status: null,
                                                review_type: null,
                                                search: null,
                                            });
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : can_create ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New review
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={reviews.data}
                            rowKey={(review) => review.id}
                            identityLabel="Reviewee"
                            identity={(review) => ({
                                icon: Target,
                                name: titleFor(review),
                                subline: review.review_cycle,
                            })}
                            hrefFor={(review) =>
                                `/governance/performance/${review.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (review) => (
                                        <EntityStatusChip
                                            variant={reviewStatusVariant(
                                                review.status,
                                            )}
                                        >
                                            {humanise(review.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    width: '0.7fr',
                                    cell: (review) => (
                                        <EntityChip>
                                            {REVIEW_TYPE_LABELS[
                                                review.review_type
                                            ] ?? humanise(review.review_type)}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'rating',
                                    label: 'Rating',
                                    width: '1fr',
                                    cell: (review) =>
                                        review.overall_rating ? (
                                            <EntityStatusChip
                                                variant={ratingVariant(
                                                    review.overall_rating,
                                                )}
                                            >
                                                {RATING_LABELS[
                                                    review.overall_rating
                                                ] ??
                                                    humanise(
                                                        review.overall_rating,
                                                    )}
                                            </EntityStatusChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'period',
                                    label: 'Period',
                                    width: '1.2fr',
                                    cell: (review) =>
                                        `${shortDate(review.period_start)} – ${shortDate(review.period_end)}`,
                                },
                                {
                                    key: 'goals',
                                    label: 'Goals',
                                    width: '0.5fr',
                                    align: 'right',
                                    cell: (review) => (
                                        <span className="tabular-nums">
                                            {review.goals?.length ?? 0}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    )}

                    {reviews.links ? (
                        <LaravelPagination
                            links={reviews.links}
                            lastPage={reviews.last_page}
                            preserveScroll
                        />
                    ) : null}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Target}
                    title={titleFor(ctxMenu.ctx.record)}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can_create ? (
                <PerformanceReviewWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    boardMembers={board_members}
                    reviewCycles={review_cycles}
                />
            ) : null}
        </AppLayout>
    );
}
