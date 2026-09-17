import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    Eye,
    Tag,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import { policyCategoryLabel } from './_dialogs';
import {
    PolicyViewToggle,
    confirmationChip,
    confirmedOf,
    plural,
    type ConfirmationState,
    type MyConfirmation,
} from './_shared';

interface PolicyRow {
    id: number;
    title: string;
    category: string;
    version: number;
    effective_from: string | null;
    next_review_date: string | null;
    state: ConfirmationState;
    my_confirmation: MyConfirmation | null;
    confirmed_count: number | null;
    board_member_count: number | null;
}

interface Props extends PageProps {
    toConfirm: PolicyRow[];
    confirmed: PolicyRow[];
    upcoming: PolicyRow[];
    canManage: boolean;
    summary: {
        to_confirm: number;
        confirmed: number;
        upcoming: number;
        board_member_count: number;
    };
    categories: Array<{ value: string; label: string }>;
}

type ShowFilter = 'all' | 'to_confirm' | 'upcoming' | 'confirmed';

const SHOW_OPTIONS: { value: ShowFilter; label: string }[] = [
    { value: 'all', label: 'Everything' },
    { value: 'to_confirm', label: 'To confirm' },
    { value: 'upcoming', label: 'Coming into effect' },
    { value: 'confirmed', label: 'Confirmed' },
];

const confirmHref = (policy: PolicyRow) => `/governance/policies/${policy.id}#confirm`;
const policyHref = (policy: PolicyRow) => `/governance/policies/${policy.id}`;

export default function PoliciesToConfirm({
    auth,
    toConfirm,
    confirmed,
    upcoming,
    canManage,
    summary,
    categories,
}: Props) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [show, setShow] = useState<ShowFilter>('all');
    const ctxMenu = useEntityContextMenu<PolicyRow>();

    const matches = useMemo(() => {
        const q = search.trim().toLowerCase();
        return (policy: PolicyRow) =>
            (category === 'all' || policy.category === category) &&
            (q === '' || policy.title.toLowerCase().includes(q));
    }, [search, category]);

    const visibleToConfirm = toConfirm.filter(matches);
    const visibleUpcoming = upcoming.filter(matches);
    const visibleConfirmed = confirmed.filter(matches);
    const inEffect = summary.to_confirm + summary.confirmed;
    const donePercent = inEffect > 0 ? (summary.confirmed / inEffect) * 100 : 0;
    const filtered = search.trim() !== '' || category !== 'all';

    const actionsFor = (policy: PolicyRow): MenuItem[] =>
        compactMenu([
            (policy.state === 'to_confirm' || policy.state === 'due_again') && {
                label: 'Read and confirm',
                icon: ClipboardCheck,
                onClick: () => router.visit(confirmHref(policy)),
            },
            {
                label: 'Open policy',
                icon: Eye,
                onClick: () => router.visit(policyHref(policy)),
            },
        ]);

    const baseColumns: EntityTableColumn<PolicyRow>[] = [
        {
            key: 'category',
            label: 'Category',
            width: '1fr',
            cell: (p) => (
                <EntityChip icon={Tag}>{policyCategoryLabel(p.category)}</EntityChip>
            ),
        },
        {
            key: 'version',
            label: 'Version',
            width: '0.6fr',
            cell: (p) => (
                <span className="text-muted-foreground tabular-nums">
                    Version {p.version}
                </span>
            ),
        },
    ];

    const boardColumn: EntityTableColumn<PolicyRow>[] = canManage
        ? [
              {
                  key: 'board',
                  label: 'Board members confirmed',
                  width: '0.9fr',
                  cell: (p) =>
                      p.confirmed_count !== null && p.board_member_count !== null ? (
                          <span className="tabular-nums">
                              {confirmedOf(p.confirmed_count, p.board_member_count)}
                          </span>
                      ) : (
                          <EmptyValue />
                      ),
              },
          ]
        : [];

    const toConfirmColumns: EntityTableColumn<PolicyRow>[] = [
        ...baseColumns,
        {
            key: 'state',
            label: 'Your confirmation',
            width: '1.1fr',
            cell: (p) => {
                const chip = confirmationChip(p.state, p.effective_from);
                return (
                    <EntityStatusChip variant={chip.variant}>
                        {chip.label}
                    </EntityStatusChip>
                );
            },
        },
        ...boardColumn,
    ];

    const upcomingColumns: EntityTableColumn<PolicyRow>[] = [
        ...baseColumns,
        {
            key: 'effective',
            label: 'Comes into effect',
            width: '1.1fr',
            cell: (p) =>
                p.effective_from ? formatDateLong(p.effective_from) : <EmptyValue />,
        },
    ];

    const confirmedColumns: EntityTableColumn<PolicyRow>[] = [
        ...baseColumns,
        {
            key: 'confirmed',
            label: 'You confirmed',
            width: '1.1fr',
            cell: (p) =>
                p.my_confirmation ? (
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate">
                            Version {p.my_confirmation.version} on{' '}
                            {formatDateLong(p.my_confirmation.confirmed_at)}
                        </span>
                        {p.my_confirmation.due_again_on ? (
                            <span className="text-caption truncate">
                                Due again on{' '}
                                {formatDateOnly(p.my_confirmation.due_again_on)}
                            </span>
                        ) : null}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        ...boardColumn,
    ];

    const header = (
        <PageHeader
            icon={ClipboardCheck}
            title="Policies to confirm"
            titleChip={
                summary.to_confirm > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {summary.to_confirm} to confirm
                    </PageHeaderStatusChip>
                ) : summary.confirmed > 0 ? (
                    <PageHeaderStatusChip variant="success">
                        All confirmed
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        Nothing to confirm
                    </PageHeaderStatusChip>
                )
            }
            subline="Read each policy and confirm you have read the current version"
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search policies to confirm…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="To confirm"
                        tone={summary.to_confirm > 0 ? 'warning' : 'brand'}
                        ariaLabel="Show policies you still need to confirm"
                        onClick={() => setShow('to_confirm')}
                    >
                        <PageHeaderMeterBig>{summary.to_confirm}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.to_confirm === 1
                                ? 'policy waiting for you'
                                : 'policies waiting for you'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Confirmed"
                        value={`${summary.confirmed} of ${inEffect}`}
                        ariaLabel="Show policies you have confirmed"
                        onClick={() => setShow('confirmed')}
                    >
                        <PageHeaderMeterBar percent={donePercent} />
                        <PageHeaderMeterCaption>
                            of the policies in effect
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Coming into effect"
                        ariaLabel="Show policies that come into effect later"
                        onClick={() => setShow('upcoming')}
                    >
                        <PageHeaderMeterBig>{summary.upcoming}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            confirm once they are in effect
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PolicyViewToggle value="confirm" />
                    <PageHeaderFilterSelect
                        icon={Tag}
                        label="All categories"
                        value={category}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories,
                        ]}
                        onChange={setCategory}
                    />
                    <PageHeaderFilterSelect
                        label="Everything"
                        value={show}
                        options={SHOW_OPTIONS}
                        onChange={(v) => setShow(v as ShowFilter)}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    const table = (rows: PolicyRow[], columns: EntityTableColumn<PolicyRow>[], href: (p: PolicyRow) => string) => (
        <EntityTable
            rows={rows}
            rowKey={(p) => p.id}
            identityLabel="Policy"
            identity={(p) => ({
                icon: BookOpen,
                name: p.title,
                linkLabel: `Open ${p.title}`,
                subline: p.effective_from
                    ? `In effect from ${formatDateOnly(p.effective_from)}`
                    : undefined,
            })}
            columns={columns}
            actionsFor={actionsFor}
            hrefFor={href}
            onOpen={(p) => router.visit(href(p))}
            onRowContextMenu={(e, p) => ctxMenu.open(e, p)}
            minWidth={760}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Policies', href: '/governance/policies' },
                {
                    title: 'Policies to confirm',
                    href: '/governance/policies/attestations',
                },
            ]}
        >
            <Head title="Policies to confirm" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {show === 'all' || show === 'to_confirm' ? (
                        <section className="flex flex-col gap-3">
                            <ListCaption
                                title={
                                    <span className="inline-flex items-center gap-1">
                                        To confirm
                                        <GovernanceTermHint term="read_and_confirm" />
                                    </span>
                                }
                                caption={`${visibleToConfirm.length} of ${toConfirm.length} shown`}
                            />
                            {visibleToConfirm.length === 0 ? (
                                <EmptyState
                                    icon={filtered ? BookOpen : CheckCircle2}
                                    variant="compact"
                                    title={
                                        filtered
                                            ? 'No policies to confirm match'
                                            : "You're up to date"
                                    }
                                    description={
                                        filtered
                                            ? 'Try clearing the search or category.'
                                            : 'There are no policies waiting for you to read and confirm.'
                                    }
                                />
                            ) : (
                                table(visibleToConfirm, toConfirmColumns, confirmHref)
                            )}
                        </section>
                    ) : null}

                    {(show === 'all' || show === 'upcoming') &&
                    (upcoming.length > 0 || show === 'upcoming') ? (
                        <section className="flex flex-col gap-3">
                            <ListCaption
                                title="Coming into effect"
                                caption={`${plural(visibleUpcoming.length, 'policy', 'policies')} · you can confirm these once they are in effect`}
                            />
                            {visibleUpcoming.length === 0 ? (
                                <EmptyState
                                    icon={CalendarClock}
                                    variant="compact"
                                    title="No policies are waiting to come into effect"
                                />
                            ) : (
                                table(visibleUpcoming, upcomingColumns, policyHref)
                            )}
                        </section>
                    ) : null}

                    {show === 'all' || show === 'confirmed' ? (
                        <section className="flex flex-col gap-3">
                            <ListCaption
                                title="Confirmed"
                                caption={`${visibleConfirmed.length} of ${confirmed.length} shown`}
                            />
                            {visibleConfirmed.length === 0 ? (
                                <EmptyState
                                    icon={ClipboardCheck}
                                    variant="compact"
                                    title={
                                        filtered
                                            ? 'No confirmed policies match'
                                            : 'You haven’t confirmed any policies yet'
                                    }
                                    description={
                                        filtered
                                            ? 'Try clearing the search or category.'
                                            : 'Policies you confirm appear here with the version and date.'
                                    }
                                />
                            ) : (
                                table(visibleConfirmed, confirmedColumns, policyHref)
                            )}
                        </section>
                    ) : null}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={BookOpen}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}
