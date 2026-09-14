import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
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
import { formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { BookOpen, FileText, Pencil, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    CeoReportWizardDialog,
    ceoReportStatusLabel,
    ceoReportStatusVariant,
    type MeetingOption,
} from './_dialogs';

interface Report {
    id: number;
    title: string;
    status: 'draft' | 'submitted' | 'presented' | string;
    meeting: { id: number; title: string; scheduled_at: string } | null;
    author: { id: number; name: string } | null;
    period_label?: string | null;
    deadline?: string | null;
    is_overdue?: boolean;
    days_until_deadline?: number | null;
    submitted_at?: string | null;
    presented_at?: string | null;
    created_at: string;
}

interface Props extends PageProps {
    reports: {
        data: Report[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        last_page?: number;
        total?: number;
    };
    meetings: MeetingOption[];
    can_create?: boolean;
    filters?: { status: string | null; search: string | null };
    summary?: {
        total: number;
        draft: number;
        submitted: number;
        presented: number;
        overdue: number;
    };
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'presented', label: 'Presented' },
    { value: 'overdue', label: 'Overdue drafts' },
];

function deadlineChip(report: Report) {
    if (report.status !== 'draft') return null;
    if (report.is_overdue) {
        return <EntityStatusChip variant="critical">Overdue</EntityStatusChip>;
    }
    if (report.days_until_deadline == null) return null;
    const label =
        report.days_until_deadline <= 0
            ? 'Due today'
            : `Due in ${report.days_until_deadline} day${report.days_until_deadline === 1 ? '' : 's'}`;
    return (
        <EntityStatusChip
            variant={report.days_until_deadline <= 7 ? 'warning' : 'neutral'}
        >
            {label}
        </EntityStatusChip>
    );
}

export default function CeoReportsIndex({
    reports,
    meetings,
    can_create = false,
    filters = { status: null, search: null },
    summary,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', can_create);
    const ctxMenu = useEntityContextMenu<Report>();

    const counts = summary ?? {
        total: reports.data.length,
        draft: reports.data.filter((r) => r.status === 'draft').length,
        submitted: reports.data.filter((r) => r.status === 'submitted').length,
        presented: reports.data.filter((r) => r.status === 'presented').length,
        overdue: reports.data.filter((r) => r.is_overdue).length,
    };

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<NonNullable<Props['filters']>>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/ceo-reports', query, {
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

    const hasFilters = Boolean(filters.status || filters.search);
    const open = (report: Report) =>
        router.visit(`/governance/ceo-reports/${report.id}`);
    const actionsFor = (report: Report): MenuItem[] =>
        compactMenu([
            {
                label: report.status === 'draft' ? 'Continue draft' : 'Read report',
                icon: report.status === 'draft' ? Pencil : BookOpen,
                onClick: () => open(report),
            },
        ]);

    const header = (
        <PageHeader
            icon={FileText}
            title="CEO Board Reports"
            subline="CEO updates for the board — narrative, KPIs, decisions sought and matters arising"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search meetings or authors…"
                    />
                    {can_create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                            dusk="new-ceo-report-button"
                        >
                            New report
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        href="/governance/ceo-reports?status=draft"
                    >
                        <PageHeaderMeterBig>{counts.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Being prepared
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        href="/governance/ceo-reports?status=overdue"
                        tone={counts.overdue > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.overdue}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Drafts past their deadline
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Submitted"
                        href="/governance/ceo-reports?status=submitted"
                    >
                        <PageHeaderMeterBig>
                            {counts.submitted}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Ready for the board
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Presented"
                        href="/governance/ceo-reports?status=presented"
                        tone={counts.presented > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {counts.presented}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {counts.total} reports
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={filters.status ?? ALL}
                    allValue={ALL}
                    options={STATUS_OPTIONS}
                    onChange={(value) =>
                        go({ status: value === ALL ? null : value })
                    }
                />
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'CEO reports', href: '/governance/ceo-reports' },
            ]}
        >
            <Head title="CEO Board Reports" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Reports"
                        caption={`${reports.data.length} of ${reports.total ?? reports.data.length} shown`}
                    />

                    {reports.data.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={
                                hasFilters
                                    ? 'No CEO reports match your filters'
                                    : 'No CEO reports yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'CEO reports are prepared for each board meeting.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({ status: null, search: null });
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
                                        New report
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={reports.data}
                            rowKey={(report) => report.id}
                            identityLabel="Report"
                            identity={(report) => ({
                                icon: FileText,
                                name: report.title,
                                subline: report.meeting?.scheduled_at
                                    ? `Meeting ${formatDateLong(report.meeting.scheduled_at)}`
                                    : 'No meeting linked',
                            })}
                            hrefFor={(report) =>
                                `/governance/ceo-reports/${report.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.8fr',
                                    cell: (report) => (
                                        <EntityStatusChip
                                            variant={ceoReportStatusVariant(
                                                report.status,
                                            )}
                                        >
                                            {ceoReportStatusLabel(
                                                report.status,
                                            )}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'period',
                                    label: 'Period',
                                    width: '0.9fr',
                                    cell: (report) =>
                                        report.period_label ?? <EmptyValue />,
                                },
                                {
                                    key: 'deadline',
                                    label: 'Deadline',
                                    width: '0.9fr',
                                    cell: (report) =>
                                        deadlineChip(report) ?? <EmptyValue />,
                                },
                                {
                                    key: 'author',
                                    label: 'Author',
                                    width: '1fr',
                                    cell: (report) => (
                                        <PersonCell name={report.author?.name} />
                                    ),
                                },
                                {
                                    key: 'milestone',
                                    label: 'Submitted / presented',
                                    width: '1fr',
                                    cell: (report) =>
                                        report.status === 'presented' &&
                                        report.presented_at ? (
                                            `Presented ${formatDateLong(report.presented_at)}`
                                        ) : report.submitted_at ? (
                                            `Submitted ${formatDateLong(report.submitted_at)}`
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={reports.links}
                        lastPage={reports.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={FileText}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can_create ? (
                <CeoReportWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    meetings={meetings ?? []}
                />
            ) : null}
        </AppLayout>
    );
}
