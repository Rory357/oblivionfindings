import { PageHero, PageTabs } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Cable,
    Gauge,
    Network,
    Radar,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

type NamedLink = { id: number; name: string; href: string };

export type MonitorRow = {
    id: number;
    name: string;
    kind: string;
    reported_state: string;
    effective_state: string;
    affects_availability: boolean;
    enabled: boolean;
    operational: boolean;
    suppressed_until: string | null;
    last_observation_at: string | null;
    freshness_state: string;
    device: NamedLink & { domain: string | null; category: string | null };
    site: NamedLink | null;
    collection:
        | {
              mode: 'remote_collector';
              collector_id: number;
              collector_name: string;
              state: string;
              last_seen_at: string | null;
          }
        | { mode: 'direct'; label: string; state: string };
    latest_observation: {
        state: string;
        value: string | null;
        unit: string | null;
        latency_ms: number | null;
        observed_at: string | null;
    } | null;
};

export type CollectionPath = {
    collector_id: number | null;
    collector_name: string;
    state: string;
    reported_status: string;
    last_seen_at: string | null;
    heartbeat_lag_seconds: number | null;
    site: NamedLink | null;
    affected_monitors: number;
    affected_devices: number;
};

export type MonitoringWorkspace = {
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: {
        title: string;
        description: string;
        privacy_note: string;
        control_room_note: string;
    };
    summary: {
        total_devices: number;
        total_monitors: number;
        enabled_monitors: number;
        direct_monitors: number;
        remote_monitors: number;
        monitored_devices: number;
        unmonitored_devices: number;
        healthy: number;
        degraded: number;
        failed: number;
        unknown: number;
        stale: number;
        pending: number;
        paused: number;
        collection_paths_unavailable: number;
        active_findings: number;
    };
    findings: {
        monitors: MonitorRow[];
        collection_paths: CollectionPath[];
        note: string;
    };
    monitors: MonitorRow[];
    inventory: { total: number; shown: number; truncated: boolean };
    coverage: {
        total_devices: number;
        monitored_devices: number;
        missing_devices: number;
        paused_monitors: number;
        fresh: number;
        stale: number;
        never_observed: number;
        unsupported_state: string;
        unsupported_note: string;
        by_kind: Record<string, number>;
        by_site: Array<{
            site: { id: number; name: string } | null;
            devices: number;
            monitored_devices: number;
            missing_devices: number;
        }>;
    };
    dependencies: {
        canonical_model_available: boolean;
        note: string;
        collection_paths: CollectionPath[];
    };
    trends: Array<{
        monitor_id: number;
        monitor_name: string;
        device: NamedLink;
        samples: number;
        latest_value: string | null;
        previous_value: string | null;
        unit: string | null;
        direction: string;
        state_changes: number;
        retained_from: string | null;
        retained_to: string | null;
    }>;
    collection: {
        direct: { label: string; monitors: number; devices: number };
        remote_paths: CollectionPath[];
    };
    filters: {
        search: string | null;
        state: string | null;
        kind: string | null;
        site_id: number | null;
        device_id: number | null;
        collection_mode: string | null;
    };
    filter_options: {
        states: string[];
        kinds: string[];
        sites: Array<{ value: number; label: string }>;
        devices: Array<{ value: number; label: string }>;
    };
};

function title(value: string): string {
    return value
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function tone(value: string): StatusVariant {
    if (['healthy', 'fresh', 'available', 'direct'].includes(value))
        return 'success';
    if (
        [
            'failed',
            'critical',
            'collection_unavailable',
            'unavailable',
        ].includes(value)
    )
        return 'critical';
    if (['degraded', 'stale', 'pending', 'paused', 'unknown'].includes(value))
        return 'warning';
    return 'neutral';
}

function when(value: string | null): string {
    if (!value) return 'Not observed';
    return new Date(value).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function Metric({
    label,
    value,
    note,
}: {
    label: string;
    value: number;
    note: string;
}) {
    return (
        <Card className="shadow-xs">
            <CardContent className="p-4">
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-sm font-medium">{label}</p>
                <p className="mt-1 text-xs text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
    );
}

function Boundary({ data }: { data: MonitoringWorkspace['boundary'] }) {
    return (
        <Card className="border-primary/20 bg-primary/5 shadow-xs">
            <CardContent className="grid gap-4 p-4 lg:grid-cols-[1.2fr_1fr]">
                <div>
                    <div className="flex items-center gap-2 font-semibold">
                        <ShieldCheck className="h-5 w-5 text-primary" />
                        {data.title}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {data.description}
                    </p>
                </div>
                <div className="space-y-1 text-xs text-muted-foreground">
                    <p>{data.privacy_note}</p>
                    <p>{data.control_room_note}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function CollectionPathCard({ path }: { path: CollectionPath }) {
    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold">{path.collector_name}</p>
                        <StatusBadge variant={tone(path.state)}>
                            {title(path.state)}
                        </StatusBadge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {path.site?.name ?? 'No site assigned'} ·{' '}
                        {path.affected_devices} affected devices ·{' '}
                        {path.affected_monitors} affected monitors
                    </p>
                </div>
                <p className="text-xs text-muted-foreground">
                    Last heartbeat {when(path.last_seen_at)}
                </p>
            </div>
        </div>
    );
}

export function MonitorCard({ monitor }: { monitor: MonitorRow }) {
    const collectionLabel =
        monitor.collection.mode === 'direct'
            ? 'Direct from main application'
            : monitor.collection.collector_name;

    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold">{monitor.name}</p>
                        <StatusBadge variant={tone(monitor.effective_state)}>
                            {title(monitor.effective_state)}
                        </StatusBadge>
                        {monitor.effective_state !== monitor.reported_state ? (
                            <StatusBadge variant="neutral">
                                Reported {title(monitor.reported_state)}
                            </StatusBadge>
                        ) : null}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        <Link
                            className="font-medium text-foreground hover:underline"
                            href={monitor.device.href}
                        >
                            {monitor.device.name}
                        </Link>
                        {monitor.site ? (
                            <>
                                {' · '}
                                <Link
                                    className="hover:underline"
                                    href={monitor.site.href}
                                >
                                    {monitor.site.name}
                                </Link>
                            </>
                        ) : null}
                    </p>
                </div>
                <div className="grid gap-1 text-xs text-muted-foreground lg:text-right">
                    <span>
                        {title(monitor.kind)} · {collectionLabel}
                    </span>
                    <span>
                        Last observation {when(monitor.last_observation_at)}
                    </span>
                    {monitor.latest_observation ? (
                        <span>
                            Latest value{' '}
                            {monitor.latest_observation.value ?? 'Not numeric'}
                            {monitor.latest_observation.unit
                                ? ` ${monitor.latest_observation.unit}`
                                : ''}
                        </span>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function Filters({ workspace }: { workspace: MonitoringWorkspace }) {
    const [search, setSearch] = useState(workspace.filters.search ?? '');
    const apply = (changes: Record<string, string | number | null>) =>
        router.get(
            '/security-devices/monitoring',
            { ...workspace.filters, tab: workspace.active_tab, ...changes },
            { preserveState: true, preserveScroll: true },
        );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply({ search: search || null });
    };

    return (
        <form
            className="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-2 xl:grid-cols-6"
            onSubmit={submit}
        >
            <label className="xl:col-span-2">
                <span className="sr-only">Search monitors</span>
                <div className="relative">
                    <Search className="absolute top-3.5 left-3 h-4 w-4 text-muted-foreground" />
                    <Input
                        className="min-h-11 pl-9"
                        placeholder="Search monitor, device, or site"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
            </label>
            <select
                aria-label="Filter by state"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.state ?? ''}
                onChange={(event) =>
                    apply({ state: event.target.value || null })
                }
            >
                <option value="">All states</option>
                {workspace.filter_options.states.map((state) => (
                    <option key={state} value={state}>
                        {title(state)}
                    </option>
                ))}
            </select>
            <select
                aria-label="Filter by site"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.site_id ?? ''}
                onChange={(event) =>
                    apply({ site_id: event.target.value || null })
                }
            >
                <option value="">All sites</option>
                {workspace.filter_options.sites.map((site) => (
                    <option key={site.value} value={site.value}>
                        {site.label}
                    </option>
                ))}
            </select>
            <select
                aria-label="Filter by check type"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.kind ?? ''}
                onChange={(event) =>
                    apply({ kind: event.target.value || null })
                }
            >
                <option value="">All check types</option>
                {workspace.filter_options.kinds.map((kind) => (
                    <option key={kind} value={kind}>
                        {title(kind)}
                    </option>
                ))}
            </select>
            <Button className="min-h-11" type="submit">
                Apply search
            </Button>
        </form>
    );
}

export function MonitoringContent({
    workspace,
}: {
    workspace: MonitoringWorkspace;
}) {
    if (workspace.active_tab === 'findings') {
        return (
            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Collection-path findings</CardTitle>
                        <CardDescription>
                            {workspace.findings.note}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {workspace.findings.collection_paths.length ? (
                            workspace.findings.collection_paths.map((path) => (
                                <CollectionPathCard
                                    key={
                                        path.collector_id ?? path.collector_name
                                    }
                                    path={path}
                                />
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={Cable}
                                title="All collection paths are reporting"
                            />
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Independent monitor findings</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {workspace.findings.monitors.length ? (
                            workspace.findings.monitors.map((monitor) => (
                                <MonitorCard
                                    key={monitor.id}
                                    monitor={monitor}
                                />
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={ShieldCheck}
                                title="No independent monitor findings"
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        );
    }

    if (workspace.active_tab === 'coverage') {
        return (
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Monitoring coverage by site</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {workspace.coverage.by_site.map((row) => (
                            <div
                                key={row.site?.id ?? 'unassigned'}
                                className="flex items-center justify-between rounded-lg border p-3"
                            >
                                <div>
                                    <p className="font-medium">
                                        {row.site?.name ?? 'No site assignment'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.devices} devices
                                    </p>
                                </div>
                                <div className="text-right text-sm">
                                    <p>{row.monitored_devices} monitored</p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.missing_devices} missing
                                    </p>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Known coverage limits</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span>Paused checks</span>
                            <strong>
                                {workspace.coverage.paused_monitors}
                            </strong>
                        </div>
                        <div className="flex items-center justify-between">
                            <span>Never observed</span>
                            <strong>{workspace.coverage.never_observed}</strong>
                        </div>
                        <div className="rounded-lg border border-dashed p-3">
                            <StatusBadge variant="neutral">
                                Not assessed
                            </StatusBadge>
                            <p className="mt-2 text-muted-foreground">
                                {workspace.coverage.unsupported_note}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        );
    }

    if (workspace.active_tab === 'dependencies') {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Dependency evidence</CardTitle>
                    <CardDescription>
                        {workspace.dependencies.note}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <StatusBadge variant="neutral">
                        Canonical dependency model not configured
                    </StatusBadge>
                    {workspace.dependencies.collection_paths.map((path) => (
                        <CollectionPathCard
                            key={path.collector_id ?? path.collector_name}
                            path={path}
                        />
                    ))}
                </CardContent>
            </Card>
        );
    }

    if (workspace.active_tab === 'trends') {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Retained trends</CardTitle>
                    <CardDescription>
                        Safe summaries from retained native observations; raw
                        probe messages and metrics stay private.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {workspace.trends.length ? (
                        workspace.trends.map((trend) => (
                            <div
                                key={trend.monitor_id}
                                className="grid gap-2 rounded-xl border p-4 sm:grid-cols-[1fr_auto]"
                            >
                                <div>
                                    <p className="font-semibold">
                                        {trend.monitor_name}
                                    </p>
                                    <Link
                                        href={trend.device.href}
                                        className="text-sm text-muted-foreground hover:underline"
                                    >
                                        {trend.device.name}
                                    </Link>
                                </div>
                                <div className="text-sm sm:text-right">
                                    <p>
                                        {trend.latest_value ??
                                            'No numeric value'}
                                        {trend.unit ? ` ${trend.unit}` : ''}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {trend.samples} samples ·{' '}
                                        {title(trend.direction)} ·{' '}
                                        {trend.state_changes} state changes
                                    </p>
                                </div>
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Gauge}
                            title="No retained trend samples"
                            description="Monitoring is configured, but no safe observation trend is available yet."
                        />
                    )}
                </CardContent>
            </Card>
        );
    }

    if (workspace.active_tab === 'collection') {
        return (
            <div className="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]">
                <Card>
                    <CardHeader>
                        <CardTitle>Main application</CardTitle>
                        <CardDescription>
                            {workspace.collection.direct.label}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid grid-cols-2 gap-3">
                        <Metric
                            label="Direct checks"
                            value={workspace.collection.direct.monitors}
                            note="No collector required"
                        />
                        <Metric
                            label="Devices"
                            value={workspace.collection.direct.devices}
                            note="Reached over site connectivity"
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Remote collection paths</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {workspace.collection.remote_paths.length ? (
                            workspace.collection.remote_paths.map((path) => (
                                <CollectionPathCard
                                    key={
                                        path.collector_id ?? path.collector_name
                                    }
                                    path={path}
                                />
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={Network}
                                title="No remote collectors required"
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Metric
                    label="Active findings"
                    value={workspace.summary.active_findings}
                    note="Independent findings plus failed paths"
                />
                <Metric
                    label="Monitored devices"
                    value={workspace.summary.monitored_devices}
                    note={`${workspace.summary.unmonitored_devices} devices need coverage`}
                />
                <Metric
                    label="Direct checks"
                    value={workspace.summary.direct_monitors}
                    note="Run by the main application"
                />
                <Metric
                    label="Remote checks"
                    value={workspace.summary.remote_monitors}
                    note={`${workspace.summary.collection_paths_unavailable} unavailable paths`}
                />
            </div>
            <Filters workspace={workspace} />
            <Card>
                <CardHeader>
                    <CardTitle>Monitor states</CardTitle>
                    <CardDescription>
                        {workspace.inventory.total} matching checks. Collection
                        failures are shown as uncertainty, not duplicated device
                        failures.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {workspace.monitors.length ? (
                        workspace.monitors.map((monitor) => (
                            <MonitorCard key={monitor.id} monitor={monitor} />
                        ))
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Radar}
                            title="No monitors match these filters"
                        />
                    )}
                    {workspace.inventory.truncated ? (
                        <p className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning">
                            Showing the first {workspace.inventory.shown} of{' '}
                            {workspace.inventory.total} checks. Narrow the
                            filters to reconcile the full set.
                        </p>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}

export default function Monitoring({
    workspace,
}: {
    workspace: MonitoringWorkspace;
}) {
    const changeTab = (tab: string) =>
        router.get(
            '/security-devices/monitoring',
            { ...workspace.filters, tab },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Monitoring', href: '/security-devices/monitoring' },
            ]}
        >
            <Head title="Monitoring - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Activity}
                    title="Monitoring"
                    description="Native estate-wide health, coverage, findings, dependencies, trends, and collection certainty."
                    stats={[
                        {
                            label: 'Checks',
                            value: workspace.summary.total_monitors,
                        },
                        { label: 'Healthy', value: workspace.summary.healthy },
                        {
                            label: 'Findings',
                            value: workspace.summary.active_findings,
                        },
                        { label: 'Paused', value: workspace.summary.paused },
                    ]}
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/control-room">
                                <AlertTriangle className="mr-2 h-4 w-4" />
                                Open Control Room
                            </Link>
                        </Button>
                    }
                />
                <Boundary data={workspace.boundary} />
                <PageTabs
                    value={workspace.active_tab}
                    onValueChange={changeTab}
                    items={workspace.tabs.map((tab) => ({
                        value: tab.key,
                        label: tab.label,
                    }))}
                />
                <MonitoringContent workspace={workspace} />
            </PageShell>
        </AppLayout>
    );
}
