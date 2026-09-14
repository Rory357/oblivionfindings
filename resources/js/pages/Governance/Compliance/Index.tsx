import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
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
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    ExternalLink,
    FileCheck,
    LayoutDashboard,
    Plus,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    frameworkIcon,
    ObligationWizardDialog,
    type ObligationFormOptions,
} from './_dialogs';
import {
    daysUntil,
    dueLabel,
    OBLIGATION_STATUS_FILTERS,
    obligationStatusLabel,
    obligationStatusVariant,
    priorityVariant,
} from './_shared';

interface Obligation {
    id: number;
    framework: string;
    obligation_code: string | null;
    obligation_title: string;
    due_date: string | null;
    status: string;
    priority: string | null;
    evidence_provided: boolean;
    evidence_required: boolean;
    owner: { name: string } | null;
}

interface FrameworkSummary {
    total: number;
    complete: number;
    overdue: number;
    due_soon: number;
    not_due: number;
}

interface Filters {
    framework?: string;
    status?: string;
    owner_id?: string;
    search?: string;
}

interface Props extends PageProps {
    obligations: {
        data: Obligation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total?: number;
        last_page?: number;
    };
    summary: {
        by_framework: Record<string, FrameworkSummary>;
        total_overdue: number;
        total_due_soon: number;
        next_30_days: unknown[];
    };
    frameworks: Array<{ value: string; label: string }>;
    filters?: Filters;
    canCreate?: boolean;
    formOptions?: ObligationFormOptions | null;
}

function cleanFilters(filters: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value != null && value !== '',
        ),
    ) as Record<string, string>;
}

export default function ComplianceIndex({
    auth,
    obligations,
    summary,
    frameworks,
    filters = {},
    canCreate = false,
    formOptions = null,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    // Retired /compliance/create deep links arrive as ?create=1.
    const [wizardOpen, setWizardOpen] = useDialogDeepLink(
        'create',
        canCreate && formOptions != null,
    );
    const ctxMenu = useEntityContextMenu<Obligation>();
    const today = toDateInput(new Date());
    const userId = auth?.user?.id != null ? String(auth.user.id) : null;
    const canViewCentre = Boolean(
        (auth?.can?.compliance as { view?: boolean } | undefined)?.view,
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Filters) => {
        router.get(
            '/governance/compliance',
            cleanFilters({ ...filters, ...patch }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if ((filters.search ?? '') !== search.trim()) {
                go({ search: search.trim() || undefined });
            }
        }, 350);
        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const frameworkLabel = (value: string) =>
        frameworks.find((f) => f.value === value)?.label ?? value;

    const frameworkTotals = Object.values(summary.by_framework);
    const totalTracked = frameworkTotals.reduce((a, f) => a + f.total, 0);
    const totalComplete = frameworkTotals.reduce((a, f) => a + f.complete, 0);
    const complianceRate =
        totalTracked > 0 ? (totalComplete / totalTracked) * 100 : 0;
    const frameworkEntries = Object.entries(summary.by_framework).filter(
        ([, data]) => data.total > 0,
    );

    const hasFilters = Object.keys(cleanFilters(filters)).length > 0;
    const shown = obligations.data.length;
    const total = obligations.total ?? shown;

    const actionsFor = (obligation: Obligation): MenuItem[] =>
        compactMenu([
            {
                label: 'Open obligation',
                icon: ExternalLink,
                onClick: () =>
                    router.visit(`/governance/compliance/${obligation.id}`),
            },
        ]);

    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Compliance"
            titleChip={
                summary.total_overdue > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {summary.total_overdue} overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        On track
                    </PageHeaderStatusChip>
                )
            }
            subline={`Regulatory obligations, deadlines and evidence · ${frameworks.length} frameworks · ${totalTracked} tracked`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search obligations or references…"
                    />
                    <PageHeaderGlassButton
                        icon={CalendarDays}
                        onClick={() =>
                            router.visit('/governance/compliance/calendar')
                        }
                    >
                        Calendar
                    </PageHeaderGlassButton>
                    {canViewCentre ? (
                        <PageHeaderGlassButton
                            icon={LayoutDashboard}
                            onClick={() => router.visit('/compliance')}
                        >
                            Compliance centre
                        </PageHeaderGlassButton>
                    ) : null}
                    {canCreate && formOptions ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            Add obligation
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Obligations"
                        ariaLabel="View all obligations"
                        href="/governance/compliance"
                    >
                        <PageHeaderMeterBig>{totalTracked}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {frameworkEntries.length} frameworks
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        ariaLabel="View overdue obligations"
                        href="/governance/compliance?status=overdue"
                        tone={summary.total_overdue > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.total_overdue}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Requires attention
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due in 30 days"
                        ariaLabel="View upcoming obligations on the calendar"
                        href="/governance/compliance/calendar"
                        tone={summary.total_due_soon > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.total_due_soon}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.next_30_days.length} open items on the
                            calendar
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Compliance rate"
                        value={`${totalComplete}/${totalTracked}`}
                        ariaLabel="View completed obligations"
                        href="/governance/compliance?status=complete"
                        tone={complianceRate >= 80 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterDonut
                            percent={complianceRate}
                            caption={`${totalComplete} of ${totalTracked} complete`}
                        />
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Framework"
                        value={filters.framework ?? 'all'}
                        options={[
                            { value: 'all', label: 'All frameworks' },
                            ...frameworks,
                        ]}
                        onChange={(v) =>
                            go({ framework: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? 'all'}
                        options={OBLIGATION_STATUS_FILTERS}
                        onChange={(v) =>
                            go({ status: v === 'all' ? undefined : v })
                        }
                    />
                    {userId ? (
                        <PageHeaderFilterCheck
                            label="Owned by me"
                            checked={filters.owner_id === userId}
                            onChange={(checked) =>
                                go({ owner_id: checked ? userId : undefined })
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
                { title: 'Compliance', href: '/governance/compliance' },
            ]}
        >
            <Head title="Compliance" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {frameworkEntries.length > 0 ? (
                        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                            {frameworkEntries.map(([framework, data]) => {
                                const Icon = frameworkIcon(framework);
                                const rate =
                                    data.total > 0
                                        ? (data.complete / data.total) * 100
                                        : 0;
                                const active = filters.framework === framework;
                                return (
                                    <Card
                                        key={framework}
                                        className={
                                            active
                                                ? 'border-primary ring-1 ring-primary/40'
                                                : undefined
                                        }
                                    >
                                        <CardContent className="flex flex-col gap-3 pt-5">
                                            <div className="flex items-center gap-2">
                                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-primary/15 text-primary">
                                                    <Icon className="size-4" />
                                                </span>
                                                <span className="min-w-0 truncate text-sm font-semibold">
                                                    {frameworkLabel(framework)}
                                                </span>
                                            </div>
                                            <ProgressValue
                                                percent={rate}
                                                tone={
                                                    data.overdue > 0
                                                        ? 'critical'
                                                        : 'brand'
                                                }
                                            >
                                                {data.complete} of {data.total}{' '}
                                                complete · {Math.round(rate)}%
                                            </ProgressValue>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {data.overdue > 0 ? (
                                                    <StatusBadge
                                                        variant="critical"
                                                        size="sm"
                                                    >
                                                        {data.overdue} overdue
                                                    </StatusBadge>
                                                ) : null}
                                                {data.due_soon > 0 ? (
                                                    <StatusBadge
                                                        variant="warning"
                                                        size="sm"
                                                    >
                                                        {data.due_soon} due in
                                                        30 days
                                                    </StatusBadge>
                                                ) : null}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="ml-auto"
                                                    onClick={() =>
                                                        go({
                                                            framework: active
                                                                ? undefined
                                                                : framework,
                                                        })
                                                    }
                                                >
                                                    {active
                                                        ? 'Show all'
                                                        : 'View'}
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : null}

                    <ListCaption
                        title={
                            hasFilters
                                ? 'Matching obligations'
                                : 'All obligations'
                        }
                        caption={`${shown} of ${total} shown · soonest due first`}
                    />

                    {obligations.data.length === 0 ? (
                        <EmptyState
                            icon={ShieldCheck}
                            title={
                                hasFilters
                                    ? 'No obligations match your filters'
                                    : 'No compliance obligations yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canCreate
                                      ? 'Add the first regulatory obligation to start tracking deadlines and evidence.'
                                      : 'Obligations added by the compliance lead will appear here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.get(
                                                '/governance/compliance',
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable<Obligation>
                            rows={obligations.data}
                            rowKey={(o) => o.id}
                            identityLabel="Obligation"
                            identity={(o) => ({
                                icon: frameworkIcon(o.framework),
                                name: o.obligation_title,
                                linkLabel: `Open ${o.obligation_title}`,
                                subline: [
                                    frameworkLabel(o.framework),
                                    o.obligation_code,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(o) => `/governance/compliance/${o.id}`}
                            onOpen={(o) =>
                                router.visit(`/governance/compliance/${o.id}`)
                            }
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (o) => (
                                        <EntityStatusChip
                                            variant={obligationStatusVariant(
                                                o.status,
                                            )}
                                        >
                                            {obligationStatusLabel(o.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'due',
                                    label: 'Due',
                                    width: '1.1fr',
                                    cell: (o) =>
                                        o.due_date ? (
                                            <span className="flex min-w-0 flex-col">
                                                <span className="truncate">
                                                    {formatDateOnly(
                                                        o.due_date.slice(0, 10),
                                                    )}
                                                </span>
                                                {o.status !== 'complete' ? (
                                                    <span className="text-caption truncate">
                                                        {dueLabel(
                                                            daysUntil(
                                                                o.due_date,
                                                                today,
                                                            ),
                                                        )}
                                                    </span>
                                                ) : null}
                                            </span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'priority',
                                    label: 'Priority',
                                    width: '0.8fr',
                                    cell: (o) =>
                                        o.priority ? (
                                            <EntityStatusChip
                                                variant={priorityVariant(
                                                    o.priority,
                                                )}
                                            >
                                                {o.priority
                                                    .charAt(0)
                                                    .toUpperCase() +
                                                    o.priority.slice(1)}
                                            </EntityStatusChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1.2fr',
                                    cell: (o) => (
                                        <PersonCell name={o.owner?.name} />
                                    ),
                                },
                                {
                                    key: 'evidence',
                                    label: 'Evidence',
                                    width: '0.9fr',
                                    cell: (o) =>
                                        o.evidence_provided ? (
                                            <EntityStatusChip
                                                variant="success"
                                                icon={FileCheck}
                                            >
                                                Provided
                                            </EntityStatusChip>
                                        ) : o.evidence_required ? (
                                            <EntityStatusChip variant="warning">
                                                Required
                                            </EntityStatusChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, o) => ctxMenu.open(e, o)}
                        />
                    )}

                    <LaravelPagination
                        links={obligations.links}
                        lastPage={obligations.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={frameworkIcon(ctxMenu.ctx.record.framework)}
                    title={ctxMenu.ctx.record.obligation_title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canCreate && formOptions ? (
                <ObligationWizardDialog
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    options={formOptions}
                />
            ) : null}
        </AppLayout>
    );
}
