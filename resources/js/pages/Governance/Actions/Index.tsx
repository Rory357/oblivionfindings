import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    ProgressValue,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';
import { show as showAction } from '@/routes/governance/actions';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ClipboardList, Link2, ListChecks, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    actionChip,
    actionPriorityLabel,
    actionPriorityVariant,
    daysUntilDue,
    dueWording,
    evidenceState,
    isActionOverdue,
} from './_helpers';

interface ActionRow {
    id: number;
    action_reference: string;
    title?: string | null;
    description: string;
    due_date: string;
    status: string;
    priority: string;
    assigned_to?: { id: number; name: string } | null;
    source_type?: string | null;
    source_id?: number | null;
    progress_pct?: number | null;
    evidence_required?: boolean;
    evidence_count?: number;
}

interface Filters {
    status: string | null;
    priority: string | null;
    source_type: string | null;
    assignee: string | null;
    assigned_to_me: boolean;
    search: string | null;
}

interface Props extends PageProps {
    items: {
        data: ActionRow[];
        total: number;
        last_page: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    summary: {
        total_open: number;
        overdue: number;
        my_open: number;
        high_priority: number;
        blocked: number;
    };
    filters: Filters;
    source_types: Array<{ value: string; label: string }>;
    assignees: Array<{ id: number; name: string }>;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'active', label: 'Still to do' },
    { value: 'open', label: 'Not started' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'complete', label: 'Done' },
];

const PRIORITY_OPTIONS = [
    { value: ALL, label: 'Any priority' },
    { value: 'elevated', label: 'High or critical' },
    { value: 'critical', label: 'Critical' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
];

function dueCell(row: ActionRow) {
    if (!row.due_date) return <EmptyValue />;
    if (row.status === 'complete') {
        return (
            <span className="text-muted-foreground">
                {formatDateOnly(row.due_date.slice(0, 10))}
            </span>
        );
    }
    const days = daysUntilDue(row.due_date);
    const overdue = isActionOverdue(row.status, row.due_date);
    return (
        <span className="flex min-w-0 flex-col">
            <span className="truncate">
                {formatDateOnly(row.due_date.slice(0, 10))}
            </span>
            {days != null ? (
                <span
                    className={
                        overdue
                            ? 'text-xs font-semibold text-status-critical'
                            : days <= 3
                              ? 'text-xs font-semibold text-status-warning'
                              : 'text-xs text-muted-foreground'
                    }
                >
                    {dueWording(row.due_date)}
                </span>
            ) : null}
        </span>
    );
}

export default function ActionsIndex({
    items,
    summary,
    filters,
    source_types,
    assignees,
}: Props) {
    const page = usePage<{ auth?: { user?: { id?: number } } }>();
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<ActionRow>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = {};
        if (merged.status) query.status = merged.status;
        if (merged.priority) query.priority = merged.priority;
        if (merged.source_type) query.source_type = merged.source_type;
        if (merged.assignee) query.assignee = merged.assignee;
        if (merged.assigned_to_me) query.assigned_to_me = '1';
        if (merged.search) query.search = merged.search;
        router.get('/governance/actions', query, {
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
        filters.status ||
        filters.priority ||
        filters.source_type ||
        filters.assignee ||
        filters.assigned_to_me ||
        filters.search,
    );

    const sourceLabel = (type: string | null | undefined) =>
        source_types.find((option) => option.value === type)?.label ?? null;

    const open = (row: ActionRow) =>
        router.visit(showAction.url({ action: row.id }));

    const actionsFor = (row: ActionRow): MenuItem[] =>
        compactMenu([
            {
                label: 'Open action',
                icon: ClipboardList,
                onClick: () => open(row),
            },
        ]);

    const myId = page.props.auth?.user?.id;

    const header = (
        <PageHeader
            icon={ListChecks}
            title="Actions"
            subline={`Follow-up work from board decisions — open yours to update or mark done · ${summary.total_open} still to do`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search actions…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Still to do"
                        href="/governance/actions?status=active"
                    >
                        <PageHeaderMeterBig>
                            {summary.total_open}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.blocked > 0
                                ? `${summary.blocked} blocked`
                                : 'Not started or in progress'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        href="/governance/actions?status=overdue"
                        tone={summary.overdue > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.overdue}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Mine"
                        href="/governance/actions?assigned_to_me=1&status=active"
                    >
                        <PageHeaderMeterBig>
                            {summary.my_open}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Assigned to you
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="High priority"
                        href="/governance/actions?priority=elevated&status=active"
                        tone={summary.high_priority > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.high_priority}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            High or critical, still open
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterCheck
                        label="Assigned to me"
                        checked={filters.assigned_to_me}
                        onChange={(checked) =>
                            go({ assigned_to_me: checked, assignee: null })
                        }
                    />
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
                        label="Priority"
                        value={filters.priority ?? ALL}
                        allValue={ALL}
                        options={PRIORITY_OPTIONS}
                        onChange={(value) =>
                            go({ priority: value === ALL ? null : value })
                        }
                    />
                    {source_types.length > 0 ? (
                        <PageHeaderFilterSelect
                            icon={Link2}
                            label="Source"
                            value={filters.source_type ?? ALL}
                            allValue={ALL}
                            options={[
                                { value: ALL, label: 'Any source' },
                                ...source_types,
                            ]}
                            onChange={(value) =>
                                go({
                                    source_type: value === ALL ? null : value,
                                })
                            }
                        />
                    ) : null}
                    {assignees.length > 0 ? (
                        <PageHeaderFilterSelect
                            label="Owner"
                            value={filters.assignee ?? ALL}
                            allValue={ALL}
                            options={[
                                { value: ALL, label: 'Anyone' },
                                ...assignees.map((user) => ({
                                    value: String(user.id),
                                    label:
                                        user.id === myId
                                            ? `${user.name} (you)`
                                            : user.name,
                                })),
                            ]}
                            onChange={(value) =>
                                go({
                                    assignee: value === ALL ? null : value,
                                    assigned_to_me: false,
                                })
                            }
                        />
                    ) : null}
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
                { title: 'Actions', href: '/governance/actions' },
            ]}
        >
            <Head title="Actions" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5" dusk="actions-list-card">
                    <ListCaption
                        title="Actions"
                        caption={`${items.data.length} of ${items.total ?? items.data.length} shown`}
                    />

                    {items.data.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title={
                                hasFilters
                                    ? 'No actions match your filters'
                                    : 'No actions yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Actions appear here when the board passes a resolution with follow-up work.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            router.get(
                                                '/governance/actions',
                                                {},
                                                { replace: true },
                                            );
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={items.data}
                            rowKey={(row) => row.id}
                            identityLabel="Action"
                            identity={(row) => ({
                                icon: ClipboardList,
                                name: row.title || row.description,
                                subline: [
                                    sourceLabel(row.source_type),
                                    refSuffix(row.action_reference),
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(row) =>
                                showAction.url({ action: row.id })
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            mutedFor={(row) => row.status === 'complete'}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (row) => {
                                        const chip = actionChip(
                                            row.status,
                                            row.due_date,
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
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1fr',
                                    cell: (row) => (
                                        <PersonCell
                                            name={row.assigned_to?.name}
                                        />
                                    ),
                                },
                                {
                                    key: 'due',
                                    label: 'Due',
                                    width: '0.9fr',
                                    cell: dueCell,
                                },
                                {
                                    key: 'priority',
                                    label: 'Priority',
                                    width: '0.7fr',
                                    cell: (row) => (
                                        <EntityStatusChip
                                            variant={actionPriorityVariant(
                                                row.priority,
                                            )}
                                        >
                                            {actionPriorityLabel(row.priority)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'progress',
                                    label: 'Progress',
                                    width: '0.8fr',
                                    cell: (row) => (
                                        <ProgressValue
                                            percent={row.progress_pct ?? 0}
                                            tone={
                                                row.status === 'complete'
                                                    ? 'success'
                                                    : 'brand'
                                            }
                                        >
                                            {row.progress_pct ?? 0}%
                                        </ProgressValue>
                                    ),
                                },
                                {
                                    key: 'evidence',
                                    label: 'Evidence',
                                    width: '1fr',
                                    cell: (row) => {
                                        const state = evidenceState(
                                            row.evidence_required,
                                            row.evidence_count,
                                        );
                                        return state.variant === 'neutral' ? (
                                            <EntityChip>{state.label}</EntityChip>
                                        ) : (
                                            <EntityStatusChip
                                                variant={state.variant}
                                            >
                                                {state.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={items.links}
                        lastPage={items.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={ClipboardList}
                    title={
                        ctxMenu.ctx.record.title ||
                        ctxMenu.ctx.record.description
                    }
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}
