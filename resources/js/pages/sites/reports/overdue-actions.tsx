import { CategoryBars } from '@/components/control-room/reports/report-parts';
import {
    CounterPill,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Surface } from '@/components/signature/blocks';
import {
    DueCell,
    Pager,
    REPORTS_BASE,
    ReportBlock,
    ReportFilterRow,
    SEVERITY_LABEL,
    periodName,
    plural,
    reportHref,
    sentenceLabel,
    severityVariant,
    showBlock,
    siteReportHref,
    usePaged,
    type ReportScope,
    type SiteRef,
} from '@/components/sites/reports/report-kit';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, FileBarChart, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';

type OverdueAction = {
    id: number;
    reference_number: string | null;
    description: string;
    hazard_type: string | null;
    severity: string | null;
    risk_rating: string | null;
    status: string;
    due_date: string | null;
    days_overdue: number;
    assigned_to: string | null;
    actions_count: number;
    site: SiteRef | null;
};

type Props = ReportScope & {
    overdueActions: OverdueAction[];
    older: { count: number; oldest_due: string | null };
    severity: string | null;
};

type View = 'all' | 'critical' | 'high' | 'medium' | 'low';
type Focus = 'none' | 'unassigned' | 'long';

const PATH = `${REPORTS_BASE}/overdue-actions`;

const AGE_BUCKETS: {
    key: string;
    label: string;
    test: (days: number) => boolean;
}[] = [
    { key: 'week', label: 'Up to 7 days', test: (d) => d <= 7 },
    { key: 'month', label: '8 to 30 days', test: (d) => d > 7 && d <= 30 },
    { key: 'quarter', label: '31 to 90 days', test: (d) => d > 30 && d <= 90 },
    { key: 'older', label: 'Over 90 days', test: (d) => d > 90 },
];

/**
 * Overdue corrective actions — are our sites safe? Every hazard still open
 * after its due date, at the Sites the viewer can access. The period picks
 * which due dates count; actions that fell due earlier are one click away.
 */
export default function OverdueActions(props: Props) {
    const { overdueActions, older, filters } = props;
    const scope: ReportScope = props;
    const [view, setView] = useState<View>(
        (props.severity as View | null) ?? 'all',
    );
    const [focus, setFocus] = useState<Focus>('none');
    const [query, setQuery] = useState('');
    const menu = useEntityContextMenu<OverdueAction>();

    const q = query.trim().toLowerCase();
    const inView = useMemo(
        () =>
            overdueActions.filter(
                (action) => view === 'all' || action.severity === view,
            ),
        [overdueActions, view],
    );
    const shown = useMemo(
        () =>
            inView.filter((action) => {
                if (focus === 'unassigned' && action.assigned_to) return false;
                if (focus === 'long' && action.days_overdue <= 30) return false;
                if (!q) return true;
                return `${action.description} ${action.reference_number ?? ''} ${action.site?.name ?? ''} ${action.assigned_to ?? ''}`
                    .toLowerCase()
                    .includes(q);
            }),
        [inView, focus, q],
    );
    const paged = usePaged(shown, `${view}|${focus}|${q}`);

    const unassigned = inView.filter((action) => !action.assigned_to).length;
    const longOverdue = inView.filter(
        (action) => action.days_overdue > 30,
    ).length;
    const oldest = inView.reduce(
        (max, action) => Math.max(max, action.days_overdue),
        0,
    );
    const siteCount = new Set(inView.map((action) => action.site?.id)).size;

    const ageRows = AGE_BUCKETS.map((bucket) => ({
        key: bucket.key,
        label: bucket.label,
        value: inView.filter((action) => bucket.test(action.days_overdue))
            .length,
    }));
    const siteRows = useMemo(() => {
        const counts = new Map<number, { label: string; value: number }>();
        for (const action of inView) {
            if (!action.site) continue;
            const row = counts.get(action.site.id) ?? {
                label: action.site.name,
                value: 0,
            };
            row.value += 1;
            counts.set(action.site.id, row);
        }
        return [...counts.entries()]
            .map(([id, row]) => ({ key: String(id), ...row }))
            .sort((a, b) => b.value - a.value)
            .slice(0, 10);
    }, [inView]);

    const countFor = (severity: string) =>
        overdueActions.filter((action) => action.severity === severity).length;
    const toggleFocus = (next: Focus) => {
        setFocus((current) => (current === next ? 'none' : next));
        showBlock('overdue-list');
    };
    const earlierHref =
        older.count > 0 && older.oldest_due
            ? reportHref(PATH, {
                  ...filters,
                  period: 'custom',
                  from: older.oldest_due,
                  to: filters.to,
              })
            : null;

    const actionsFor = (action: OverdueAction): MenuItem[] => [
        {
            label: 'Open hazard',
            icon: AlertTriangle,
            onClick: () => router.visit(`/hazards/${action.id}`),
        },
        ...(action.site
            ? [
                  {
                      label: 'Open site report',
                      icon: FileBarChart,
                      onClick: () =>
                          router.visit(
                              siteReportHref(action.site!.id, filters),
                          ),
                  },
              ]
            : []),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: reportHref(REPORTS_BASE, filters) },
                {
                    title: 'Overdue corrective actions',
                    href: reportHref(PATH, filters),
                },
            ]}
        >
            <Head title="Overdue corrective actions" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={AlertTriangle}
                        title="Overdue corrective actions"
                        subline={`Hazards still open after their due date · due ${periodName(scope)} · ${filters.region ?? 'all regions'}`}
                        actions={
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search hazards, sites, owners…"
                            />
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="No owner"
                                    tone={
                                        unassigned > 0 ? 'critical' : 'success'
                                    }
                                    pressed={focus === 'unassigned'}
                                    ariaLabel="Show overdue actions with no owner"
                                    onClick={() => toggleFocus('unassigned')}
                                >
                                    <PageHeaderMeterBig>
                                        {unassigned}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        nobody is assigned to fix them
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Over 30 days late"
                                    tone={
                                        longOverdue > 0 ? 'warning' : 'success'
                                    }
                                    pressed={focus === 'long'}
                                    ariaLabel="Show actions more than 30 days overdue"
                                    onClick={() => toggleFocus('long')}
                                >
                                    <PageHeaderMeterBig>
                                        {longOverdue}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {oldest > 0
                                            ? `the oldest is ${plural(oldest, 'day')} late`
                                            : 'none overdue'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Sites affected"
                                    ariaLabel="Show overdue actions by site"
                                    onClick={() => showBlock('overdue-by-site')}
                                >
                                    <PageHeaderMeterBig>
                                        {siteCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        with at least one overdue action
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {earlierHref ? (
                                    <PageHeaderMeterBlock
                                        label="Due earlier"
                                        tone="warning"
                                        ariaLabel="Include actions that fell due before this period"
                                        href={earlierHref}
                                    >
                                        <PageHeaderMeterBig>
                                            {older.count}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {`fell due before ${formatDateOnly(filters.from)}`}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        filters={
                            <ReportFilterRow
                                scope={scope}
                                path={PATH}
                                extra={{
                                    severity: view !== 'all' ? view : null,
                                }}
                            />
                        }
                        rail={
                            <PageHeaderRail<View>
                                ariaLabel="Severity"
                                value={view}
                                onSelect={(next) => {
                                    setView(next);
                                    setFocus('none');
                                }}
                                items={[
                                    {
                                        key: 'all',
                                        label: 'All',
                                        count: overdueActions.length,
                                    },
                                    {
                                        key: 'critical',
                                        label: 'Critical',
                                        count: countFor('critical'),
                                        alert: true,
                                    },
                                    {
                                        key: 'high',
                                        label: 'High',
                                        count: countFor('high'),
                                    },
                                    {
                                        key: 'medium',
                                        label: 'Medium',
                                        count: countFor('medium'),
                                    },
                                    {
                                        key: 'low',
                                        label: 'Low',
                                        count: countFor('low'),
                                    },
                                ]}
                            />
                        }
                    />
                }
            >
                {inView.length === 0 ? (
                    <Surface>
                        <EmptyState
                            icon={ShieldCheck}
                            title={
                                view === 'all'
                                    ? 'No overdue corrective actions'
                                    : `No ${SEVERITY_LABEL[view].toLowerCase()} actions overdue`
                            }
                            description={
                                older.count > 0
                                    ? `Nothing that fell due in this period is still open, but ${plural(older.count, 'action')} from before ${formatDateOnly(filters.from)} ${older.count === 1 ? 'is' : 'are'}.`
                                    : 'Every hazard with a due date in this period is closed or still within its date.'
                            }
                            action={
                                earlierHref ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={earlierHref}>
                                            Include earlier actions
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                            className="border-0"
                        />
                    </Surface>
                ) : (
                    <div className="grid gap-5">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <ReportBlock
                                title="How late they are"
                                sub="Days past the due date"
                            >
                                <CategoryBars
                                    rows={ageRows}
                                    unit="Actions"
                                    labelHeading="Days late"
                                    emptyTitle="Nothing overdue"
                                />
                            </ReportBlock>
                            <ReportBlock
                                id="overdue-by-site"
                                title="By site"
                                sub="The sites with the most overdue actions"
                            >
                                <CategoryBars
                                    rows={siteRows}
                                    unit="Actions"
                                    labelHeading="Site"
                                    emptyTitle="Nothing overdue"
                                />
                            </ReportBlock>
                        </div>

                        <section
                            id="overdue-list"
                            tabIndex={-1}
                            aria-label="Overdue actions"
                            className="grid scroll-mt-5 gap-3 outline-none"
                        >
                            <ListCaption
                                title="Overdue actions"
                                caption={`${shown.length} of ${inView.length} · oldest first`}
                                right={
                                    focus !== 'none' || q ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setFocus('none');
                                                setQuery('');
                                            }}
                                        >
                                            Show all
                                        </Button>
                                    ) : null
                                }
                            />
                            {shown.length === 0 ? (
                                <Surface>
                                    <EmptyState
                                        variant="compact"
                                        icon={AlertTriangle}
                                        title="No overdue actions match"
                                        className="border-0"
                                    />
                                </Surface>
                            ) : (
                                <>
                                    <EntityTable
                                        rows={paged.rows}
                                        rowKey={(action) => action.id}
                                        identityLabel="Hazard"
                                        identityWidth="2.4fr"
                                        identity={(action) => ({
                                            icon: AlertTriangle,
                                            name: action.description,
                                            subline: [
                                                sentenceLabel(
                                                    action.hazard_type,
                                                ),
                                                action.reference_number,
                                            ]
                                                .filter(Boolean)
                                                .join(' · '),
                                        })}
                                        columns={[
                                            {
                                                key: 'severity',
                                                label: 'Severity',
                                                width: '0.9fr',
                                                cell: (action) => (
                                                    <EntityStatusChip
                                                        variant={severityVariant(
                                                            action.severity,
                                                        )}
                                                    >
                                                        {SEVERITY_LABEL[
                                                            action.severity ??
                                                                ''
                                                        ] ?? 'Not rated'}
                                                    </EntityStatusChip>
                                                ),
                                            },
                                            {
                                                key: 'site',
                                                label: 'Site',
                                                width: '1.3fr',
                                                cell: (action) =>
                                                    action.site ? (
                                                        <Link
                                                            href={siteReportHref(
                                                                action.site.id,
                                                                filters,
                                                            )}
                                                            onClick={(event) =>
                                                                event.stopPropagation()
                                                            }
                                                            className="truncate underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                                        >
                                                            {action.site.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ),
                                            },
                                            {
                                                key: 'owner',
                                                label: 'Owner',
                                                width: '1.2fr',
                                                cell: (action) => (
                                                    <PersonCell
                                                        name={
                                                            action.assigned_to
                                                        }
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'due',
                                                label: 'Due',
                                                width: '1.4fr',
                                                cell: (action) => (
                                                    <DueCell
                                                        date={action.due_date}
                                                        daysOverdue={
                                                            action.days_overdue
                                                        }
                                                        none="No due date"
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'steps',
                                                label: 'Steps',
                                                width: '0.6fr',
                                                align: 'center',
                                                cell: (action) => (
                                                    <CounterPill tone="neutral">
                                                        {action.actions_count}
                                                    </CounterPill>
                                                ),
                                            },
                                        ]}
                                        hrefFor={(action) =>
                                            `/hazards/${action.id}`
                                        }
                                        onOpen={(action) =>
                                            router.visit(
                                                `/hazards/${action.id}`,
                                            )
                                        }
                                        actionsFor={actionsFor}
                                        onRowContextMenu={menu.open}
                                        minWidth={980}
                                    />
                                    <Pager {...paged} />
                                </>
                            )}
                        </section>
                    </div>
                )}
                {menu.ctx ? (
                    <EntityContextMenu
                        {...menu.ctx}
                        onClose={menu.close}
                        title={menu.ctx.record.description}
                        items={actionsFor(menu.ctx.record)}
                    />
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
