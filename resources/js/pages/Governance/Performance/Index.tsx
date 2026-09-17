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
import {
    governanceStatus,
    performanceRatingLabel,
    performanceReviewTypeLabel,
    reviewCycleLabel,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, Lock, Plus, Target, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    CONFIDENTIAL_NOTICE,
    PerformanceReviewWizardDialog,
    type ReviewCycleOption,
    type RevieweeOption,
} from './_dialogs';

interface Review {
    id: number;
    review_cycle: string;
    cycle_label?: string;
    review_type: string;
    period_start: string;
    period_end: string;
    status: string;
    /** Server-masked for the person being reviewed until the review is done. */
    overall_rating: string | null;
    assessment_hidden?: boolean;
    reviewee: { id: number; name: string } | null;
    goals_count?: number;
}

interface Props extends PageProps {
    reviews: {
        data: Review[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        last_page?: number;
        total?: number;
    };
    review_cycles: ReviewCycleOption[];
    current_cycle?: string;
    current_financial_year?: string;
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
    reviewees?: RevieweeOption[];
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'active', label: 'Not done yet' },
    { value: 'draft', label: 'Draft' },
    { value: 'self_review', label: 'Waiting for self-assessment' },
    { value: 'board_review', label: 'Waiting for the board' },
    { value: 'completed', label: 'Done' },
];

const RATING_VARIANT: Record<
    string,
    'success' | 'info' | 'warning' | 'critical'
> = {
    exceeds: 'success',
    meets: 'info',
    needs_improvement: 'warning',
    unsatisfactory: 'critical',
};

const shortDate = (value: string) => formatDateOnly(value?.slice(0, 10));

export default function PerformanceIndex({
    reviews,
    review_cycles,
    current_cycle,
    current_financial_year,
    summary,
    filters = { status: null, review_type: null, search: null },
    can_create = false,
    reviewees = [],
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
    const cycleText = (review: Review) =>
        review.cycle_label ?? reviewCycleLabel(review.review_cycle);

    const header = (
        <PageHeader
            icon={Target}
            title="CEO performance"
            subline="The board's regular review of the CEO and executives against agreed goals"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search by name or cycle…"
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
                        label="Not done yet"
                        href="/governance/performance?status=active"
                    >
                        <PageHeaderMeterBig>{counts.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Reviews still in progress
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Waiting for the board"
                        href="/governance/performance?status=board_review"
                        tone={counts.board_review > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {counts.board_review}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Ready for the board&apos;s assessment
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Done"
                        href="/governance/performance?status=completed"
                        tone={counts.completed > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {counts.completed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Completed reviews
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="All reviews"
                        href="/governance/performance"
                    >
                        <PageHeaderMeterBig>{counts.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {current_cycle
                                ? `Current cycle: ${reviewCycleLabel(current_cycle)}`
                                : current_financial_year
                                  ? `Financial year ${current_financial_year}`
                                  : 'Every review you can see'}
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
                        label="Kind"
                        value={filters.review_type ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any kind' },
                            ...['annual', 'quarterly', 'ad_hoc'].map(
                                (value) => ({
                                    value,
                                    label: performanceReviewTypeLabel(value),
                                }),
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
                        right={
                            <span className="text-caption flex items-center gap-1.5">
                                <Lock className="h-3.5 w-3.5 shrink-0" />
                                {CONFIDENTIAL_NOTICE}
                            </span>
                        }
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
                                    : can_create
                                      ? 'Reviews you can see appear here once the board sets one up.'
                                      : "CEO performance reviews are confidential. They're shared with the board chair, the committee that runs the review and the person being reviewed."
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
                            identityLabel="Person"
                            identity={(review) => ({
                                icon: Target,
                                name: titleFor(review),
                                subline: cycleText(review),
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
                                    width: '1.1fr',
                                    cell: (review) => {
                                        const chip = governanceStatus(
                                            'performance_review_status',
                                            review.status,
                                        );
                                        return (
                                            <EntityStatusChip
                                                variant={chip.variant}
                                            >
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'type',
                                    label: 'Kind',
                                    width: '0.7fr',
                                    cell: (review) => (
                                        <EntityChip>
                                            {performanceReviewTypeLabel(
                                                review.review_type,
                                            )}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'rating',
                                    label: "Board's rating",
                                    width: '1.1fr',
                                    cell: (review) =>
                                        review.overall_rating ? (
                                            <EntityStatusChip
                                                variant={
                                                    RATING_VARIANT[
                                                        review.overall_rating
                                                    ] ?? 'neutral'
                                                }
                                            >
                                                {performanceRatingLabel(
                                                    review.overall_rating,
                                                )}
                                            </EntityStatusChip>
                                        ) : review.assessment_hidden ? (
                                            <span className="text-caption">
                                                Shared when the review is done
                                            </span>
                                        ) : review.status === 'completed' ? (
                                            <EmptyValue />
                                        ) : (
                                            <span className="text-caption">
                                                Not rated yet
                                            </span>
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
                                            {review.goals_count ?? 0}
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
                    reviewees={reviewees}
                    reviewCycles={review_cycles}
                />
            ) : null}
        </AppLayout>
    );
}
