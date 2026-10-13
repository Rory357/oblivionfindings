import {
    EntityChip,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    CoverageIndicator,
    formatCoverage,
    OperationalStateBadge,
} from '@/components/security-devices/estate-operations';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CircleHelp,
    Eye,
    LayoutGrid,
    List,
    RadioTower,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

export interface SiteTechnologySummary {
    total: number;
    with_devices: number;
    requiring_attention: number;
    unknown: number;
}
export interface SiteTechnologyItem {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    is_active: boolean;
    health: 'critical' | 'warning' | 'healthy' | 'unknown';
    devices: number;
    attention_devices: number;
    offline_devices: number;
    monitored_devices: number | null;
    unmonitored_devices: number | null;
    coverage_percent: number | null;
    failed_monitors: number | null;
    active_findings: number | null;
    active_control_room_alerts: number | null;
    open_it_work: number | null;
    overdue_maintenance: number | null;
    collector: {
        state: 'online' | 'stale' | 'not_configured' | 'restricted';
        label: string;
        count: number | null;
        last_seen_at: string | null;
    };
    last_change_at: string | null;
    requires_action: boolean;
    href: string;
}
export type SiteTechnologyProps = {
    sites: SiteTechnologyItem[];
    summary: SiteTechnologySummary;
};
type SiteView = 'all' | 'with_devices' | 'attention' | 'unknown';

export default function SiteTechnologyIndex(props: SiteTechnologyProps) {
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    const scope = JSON.stringify([
        auth?.user?.id,
        auth?.can,
        props.sites,
        props.summary,
    ]);
    return (
        <SiteTechnologyList
            key={scope}
            {...props}
            layout={layout}
            setLayout={setLayout}
        />
    );
}
function Facts({ items }: { items: Array<[string, ReactNode]> }) {
    return (
        <div className="flex flex-wrap gap-1">
            {items.map(([label, value]) => (
                <EntityChip key={label}>
                    {label}: <span>{value ?? 'Restricted'}</span>
                </EntityChip>
            ))}
        </div>
    );
}
function Collector({ site }: { site: SiteTechnologyItem }) {
    if (site.monitored_devices === null)
        return <span>Monitoring permission required</span>;
    return (
        <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-1">
                <span>{site.collector.label}</span>
                <OperationalStateBadge state={site.collector.state} />
            </div>
            <p className="text-caption">
                {site.collector.count}{' '}
                {site.collector.count === 1 ? 'collector' : 'collectors'} ·{' '}
                {site.collector.last_seen_at ? (
                    <span title={formatDateTime(site.collector.last_seen_at)}>
                        Last contact{' '}
                        {formatRelative(site.collector.last_seen_at)}
                    </span>
                ) : (
                    'No contact recorded'
                )}
            </p>
        </div>
    );
}
function Changed({ value }: { value: string | null }) {
    return value ? (
        <span title={formatDateTime(value)}>
            Changed {formatRelative(value)}
        </span>
    ) : (
        <span>No change recorded</span>
    );
}
function SiteTechnologyList({
    sites,
    summary,
    layout,
    setLayout,
}: SiteTechnologyProps & {
    layout: 'cards' | 'table';
    setLayout: (layout: 'cards' | 'table') => void;
}) {
    const [search, setSearch] = useState('');
    const [view, setView] = useState<SiteView>('all');
    const selectView = (next: SiteView, clearSearch = false) => {
        setView(next);
        if (clearSearch) setSearch('');
    };
    const clearFilters = () => {
        setSearch('');
        setView('all');
    };
    const rows = sites.filter((site) => {
        const matchesView =
            view === 'all' ||
            (view === 'with_devices' && site.devices > 0) ||
            (view === 'attention' &&
                ['critical', 'warning'].includes(site.health)) ||
            (view === 'unknown' && site.health === 'unknown');
        return (
            matchesView &&
            [site.name, site.city, site.type?.replaceAll('_', ' ')]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search.trim().toLowerCase())
        );
    });
    const actionsFor = (site: SiteTechnologyItem): MenuItem[] => [
        {
            label: 'Open Site technology',
            icon: Eye,
            onClick: () => router.visit(site.href),
        },
    ];
    const factsFor = (site: SiteTechnologyItem): Array<[string, ReactNode]> => [
        ['Devices', site.devices],
        ['Findings', site.active_findings],
        ['Open IT work', site.open_it_work],
        ['Overdue maintenance', site.overdue_maintenance],
        ['Control Room alerts', site.active_control_room_alerts],
    ];
    const alertsFor = (
        site: SiteTechnologyItem,
    ): Array<[string, ReactNode]> => [
        ['Need attention', site.attention_devices],
        ['Offline devices', site.offline_devices],
        ['Failed checks', site.failed_monitors],
        ['Without monitoring', site.unmonitored_devices],
    ];
    const columns: EntityTableColumn<SiteTechnologyItem>[] = [
        {
            key: 'health',
            label: 'Health',
            width: '110px',
            cell: (site) => <OperationalStateBadge state={site.health} />,
        },
        {
            key: 'monitoring',
            label: 'Monitoring',
            width: '1.1fr',
            cell: (site) =>
                site.monitored_devices === null ? (
                    <span>Restricted</span>
                ) : (
                    <CoverageIndicator
                        percent={site.coverage_percent}
                        monitored={site.monitored_devices ?? 0}
                        total={site.devices}
                    />
                ),
        },
        {
            key: 'work',
            label: 'Findings and work',
            width: '1.7fr',
            cell: (site) => <Facts items={factsFor(site)} />,
        },
        {
            key: 'attention',
            label: 'Device checks',
            width: '1.5fr',
            cell: (site) => <Facts items={alertsFor(site)} />,
        },
        {
            key: 'collector',
            label: 'Collection',
            width: '1.6fr',
            cell: (site) => <Collector site={site} />,
        },
        {
            key: 'changed',
            label: 'Last change',
            width: '1fr',
            cell: (site) => <Changed value={site.last_change_at} />,
        },
    ];
    const headerViews = [
        {
            key: 'all' as const,
            label: 'All Sites',
            icon: Building2,
            count: summary.total,
        },
        {
            key: 'with_devices' as const,
            label: 'With devices',
            count: summary.with_devices,
        },
        {
            key: 'attention' as const,
            label: 'Need attention',
            icon: AlertTriangle,
            count: summary.requiring_attention,
            alert: true,
        },
        {
            key: 'unknown' as const,
            label: 'Unknown health',
            icon: CircleHelp,
            count: summary.unknown,
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
            ]}
        >
            <Head title="Sites - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={Building2}
                    title="Sites"
                    subline="Technology health, monitoring coverage, findings, service work and collector state across your approved Sites."
                    mobileSummary={`${summary.total} Sites · ${summary.requiring_attention} need attention`}
                    actions={
                        <PageHeaderSearch
                            value={search}
                            onChange={(value) => {
                                setSearch(value);
                            }}
                            ariaLabel="Search Site technology"
                            placeholder="Search Sites, cities, types…"
                        />
                    }
                    meters={
                        <>
                            {headerViews.map((item) => (
                                <PageHeaderMeterBlock
                                    key={item.key}
                                    label={item.label}
                                    ariaLabel={`View ${item.label.toLowerCase()}`}
                                    onClick={() => selectView(item.key, true)}
                                >
                                    <PageHeaderMeterBig>
                                        {item.count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {item.key === 'all'
                                            ? 'Within your approved access'
                                            : item.key === 'with_devices'
                                              ? 'Registered equipment'
                                              : item.key === 'attention'
                                                ? 'Critical or warning health'
                                                : 'Health is not yet known'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            {search || view !== 'all' ? (
                                <PageHeaderFilterButton onClick={clearFilters}>
                                    Clear filters
                                </PageHeaderFilterButton>
                            ) : null}
                            <PageHeaderViewToggle
                                value={layout}
                                onChange={(value) => {
                                    setLayout(value as 'cards' | 'table');
                                }}
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail<SiteView>
                            items={headerViews}
                            value={view}
                            onSelect={(key) => selectView(key)}
                            ariaLabel="Site technology views"
                        />
                    }
                />
                <section
                    aria-label="Site technology records"
                    className="space-y-3"
                >
                    {sites.length === 0 ? (
                        <EmptyList
                            icon={Building2}
                            title="No accessible Sites"
                            description="No active Sites are available within your approved Site access."
                        />
                    ) : rows.length === 0 ? (
                        <EmptySearch
                            searchTerm={search}
                            onClear={clearFilters}
                        />
                    ) : (
                        <EvidenceRecords<SiteTechnologyItem>
                            key={JSON.stringify([search, view])}
                            title="Site technology"
                            description="Health, monitoring and work across your approved Sites. Full readings are available in each record's evidence view."
                            rows={rows}
                            total={summary.total}
                            view={layout}
                            icon={Building2}
                            rowKey={(site) => site.id}
                            identity={(site) => ({
                                icon: Building2,
                                name: site.name,
                                subline:
                                    [site.city, site.type?.replaceAll('_', ' ')]
                                        .filter(Boolean)
                                        .join(' · ') || 'Location not recorded',
                            })}
                            hrefFor={(site) => site.href}
                            actionsFor={actionsFor}
                            cardFor={(site) => ({
                                icon: Building2,
                                name: site.name,
                                subline:
                                    [site.city, site.type?.replaceAll('_', ' ')]
                                        .filter(Boolean)
                                        .join(' · ') || 'Location not recorded',
                                meridian:
                                    site.health === 'critical'
                                        ? 'critical'
                                        : site.health === 'warning'
                                          ? 'warning'
                                          : site.health === 'healthy'
                                            ? 'success'
                                            : 'neutral',
                                chips: (
                                    <>
                                        <OperationalStateBadge
                                            state={site.health}
                                        />
                                        <Facts items={factsFor(site)} />
                                    </>
                                ),
                                metric: {
                                    label: 'Monitoring coverage',
                                    value:
                                        site.monitored_devices === null ? (
                                            'Restricted'
                                        ) : (
                                            <>
                                                {formatCoverage(
                                                    site.coverage_percent,
                                                )}{' '}
                                                ·{' '}
                                                {site.monitored_devices ??
                                                    'Restricted'}{' '}
                                                of {site.devices}
                                            </>
                                        ),
                                    percent: site.coverage_percent,
                                    tone:
                                        site.coverage_percent === 100
                                            ? 'success'
                                            : 'warning',
                                },
                                alerts: <Facts items={alertsFor(site)} />,
                                footer: {
                                    personIcon: RadioTower,
                                    primary: <Collector site={site} />,
                                    secondary: (
                                        <Changed value={site.last_change_at} />
                                    ),
                                },
                            })}
                            columns={columns}
                            compactColumns={[
                                {
                                    key: 'health',
                                    label: 'Health',
                                    width: '110px',
                                    cell: (site) => (
                                        <OperationalStateBadge
                                            state={site.health}
                                        />
                                    ),
                                },
                                {
                                    key: 'devices',
                                    label: 'Devices',
                                    width: '85px',
                                    cell: (site) => (
                                        <EntityChip>{site.devices}</EntityChip>
                                    ),
                                },
                                {
                                    key: 'coverage',
                                    label: 'Monitoring',
                                    width: '1.2fr',
                                    cell: (site) => (
                                        <EntityChip>
                                            {site.monitored_devices === null
                                                ? 'Restricted'
                                                : formatCoverage(
                                                      site.coverage_percent,
                                                  )}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'attention',
                                    label: 'Need attention',
                                    width: '110px',
                                    cell: (site) => (
                                        <EntityChip>
                                            {site.attention_devices}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'work',
                                    label: 'Open IT work',
                                    width: '100px',
                                    cell: (site) => (
                                        <EntityChip>
                                            {site.open_it_work ?? 'Restricted'}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'changed',
                                    label: 'Last change',
                                    width: '1fr',
                                    cell: (site) => (
                                        <Changed value={site.last_change_at} />
                                    ),
                                },
                            ]}
                            empty="No Sites in this view."
                            minWidth={1100}
                        />
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
