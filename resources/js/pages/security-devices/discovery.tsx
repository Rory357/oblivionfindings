import { PageHero, PageTabs } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    Cable,
    CircleHelp,
    Network,
    Radar,
    RadioTower,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

export type Collector = {
    id: number;
    name: string;
    site: { id: number; name: string; href: string } | null;
    reported_status: string;
    freshness_state: string;
    last_seen_at: string | null;
    heartbeat_lag_seconds: number | null;
    monitor_load: number;
    device_load: number;
    affected_monitors: number;
    affected_devices: number;
    impact_note: string;
};

export type DiscoveryWorkspace = {
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: { title: string; description: string; runtime_note: string };
    summary: {
        monitors: number;
        direct_monitors: number;
        remote_monitors: number;
        collectors: number;
        online_collectors: number;
        collection_paths_unavailable: number;
        affected_devices: number;
    };
    direct_coverage: {
        path_label: string;
        monitors: number;
        devices: number;
        description: string;
    };
    collectors: Collector[];
    collection_paths: Array<{
        collector_id: number;
        collector_name: string;
        site: Collector['site'];
        state: string;
        monitor_load: number;
        device_load: number;
        affected_devices: number;
    }>;
    limitations: {
        unsupported_state: string;
        unsupported_note: string;
        not_configured_monitors: number;
        not_configured_note: string;
        capacity_note: string;
    };
};

function title(value: string): string {
    return value
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function tone(value: string): StatusVariant {
    if (['available', 'online'].includes(value)) return 'success';
    if (['unavailable', 'offline', 'failed'].includes(value)) return 'critical';
    if (['pending', 'stale'].includes(value)) return 'warning';
    return 'neutral';
}

function when(value: string | null): string {
    if (!value) return 'Never reported';
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

export function CollectorCard({ collector }: { collector: Collector }) {
    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold">{collector.name}</p>
                        <StatusBadge variant={tone(collector.freshness_state)}>
                            {title(collector.freshness_state)}
                        </StatusBadge>
                        <StatusBadge variant="neutral">
                            Reported {title(collector.reported_status)}
                        </StatusBadge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {collector.site ? (
                            <Link
                                href={collector.site.href}
                                className="hover:underline"
                            >
                                {collector.site.name}
                            </Link>
                        ) : (
                            'No site assigned'
                        )}
                        {' · '}
                        {collector.device_load} devices ·{' '}
                        {collector.monitor_load} checks
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {collector.impact_note}
                    </p>
                </div>
                <div className="text-xs text-muted-foreground lg:text-right">
                    <p>Last heartbeat {when(collector.last_seen_at)}</p>
                    {collector.heartbeat_lag_seconds !== null ? (
                        <p>
                            {Math.round(collector.heartbeat_lag_seconds / 60)}{' '}
                            minutes ago
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default function DiscoveryCollectors({
    workspace,
}: {
    workspace: DiscoveryWorkspace;
}) {
    const [activeTab, setActiveTab] = useState(workspace.active_tab);
    const changeTab = (tab: string) => {
        setActiveTab(tab);
        router.get(
            '/security-devices/discovery',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Discovery & collectors',
                    href: '/security-devices/discovery',
                },
            ]}
        >
            <Head title="Discovery & collectors - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Radar}
                    title="Discovery & collectors"
                    description="Understand how Oblivion Findings reaches every site: directly over site connectivity, or through an explicit collector for a difficult remote path."
                    stats={[
                        {
                            label: 'Direct checks',
                            value: workspace.summary.direct_monitors,
                        },
                        {
                            label: 'Remote checks',
                            value: workspace.summary.remote_monitors,
                        },
                        {
                            label: 'Collectors',
                            value: workspace.summary.collectors,
                        },
                        {
                            label: 'Paths unavailable',
                            value: workspace.summary
                                .collection_paths_unavailable,
                        },
                    ]}
                />

                <Card className="border-primary/20 bg-primary/5 shadow-xs">
                    <CardContent className="grid gap-4 p-4 lg:grid-cols-[1.2fr_1fr]">
                        <div>
                            <div className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="h-5 w-5 text-primary" />
                                {workspace.boundary.title}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {workspace.boundary.description}
                            </p>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {workspace.boundary.runtime_note}
                        </p>
                    </CardContent>
                </Card>

                <PageTabs
                    value={activeTab}
                    onValueChange={changeTab}
                    items={workspace.tabs.map((tab) => ({
                        value: tab.key,
                        label: tab.label,
                    }))}
                />

                {activeTab === 'overview' ? (
                    <div className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Metric
                                label="Direct checks"
                                value={workspace.summary.direct_monitors}
                                note="Main application path"
                            />
                            <Metric
                                label="Remote checks"
                                value={workspace.summary.remote_monitors}
                                note="Explicit collector path"
                            />
                            <Metric
                                label="Collectors online"
                                value={workspace.summary.online_collectors}
                                note={`${workspace.summary.collectors} configured`}
                            />
                            <Metric
                                label="Devices affected"
                                value={workspace.summary.affected_devices}
                                note="By unavailable collector paths"
                            />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Main application coverage
                                    </CardTitle>
                                    <CardDescription>
                                        {workspace.direct_coverage.path_label}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-3">
                                        <Metric
                                            label="Checks"
                                            value={
                                                workspace.direct_coverage
                                                    .monitors
                                            }
                                            note="No collector required"
                                        />
                                        <Metric
                                            label="Devices"
                                            value={
                                                workspace.direct_coverage
                                                    .devices
                                            }
                                            note="Reached directly"
                                        />
                                    </div>
                                    <p className="mt-4 text-sm text-muted-foreground">
                                        {workspace.direct_coverage.description}
                                    </p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Remote collection health
                                    </CardTitle>
                                    <CardDescription>
                                        A failed path is grouped once so
                                        downstream checks are not misrepresented
                                        as separate failures.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {workspace.collectors.length ? (
                                        workspace.collectors.map(
                                            (collector) => (
                                                <CollectorCard
                                                    key={collector.id}
                                                    collector={collector}
                                                />
                                            ),
                                        )
                                    ) : (
                                        <EmptyState
                                            variant="compact"
                                            icon={RadioTower}
                                            title="No remote collectors configured"
                                            description="That is expected when all sites are reachable directly."
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                ) : null}

                {activeTab === 'collectors' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Remote collectors</CardTitle>
                            <CardDescription>
                                Heartbeat, site scope, exact monitor load, and
                                affected-device impact. No invented capacity
                                percentage.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {workspace.collectors.length ? (
                                workspace.collectors.map((collector) => (
                                    <CollectorCard
                                        key={collector.id}
                                        collector={collector}
                                    />
                                ))
                            ) : (
                                <EmptyState
                                    variant="compact"
                                    icon={RadioTower}
                                    title="No remote collectors configured"
                                />
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                {activeTab === 'paths' ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Direct path</CardTitle>
                            </CardHeader>
                            <CardContent className="rounded-xl border p-4">
                                <div className="flex items-center gap-2">
                                    <Network className="h-5 w-5 text-primary" />
                                    <p className="font-semibold">
                                        {workspace.direct_coverage.path_label}
                                    </p>
                                    <StatusBadge variant="success">
                                        Expected
                                    </StatusBadge>
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {workspace.direct_coverage.devices} devices
                                    · {workspace.direct_coverage.monitors}{' '}
                                    checks
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Remote paths</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {workspace.collection_paths.map((path) => (
                                    <div
                                        key={path.collector_id}
                                        className="rounded-xl border p-4"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Cable className="h-5 w-5 text-primary" />
                                            <p className="font-semibold">
                                                {path.collector_name}
                                            </p>
                                            <StatusBadge
                                                variant={tone(path.state)}
                                            >
                                                {title(path.state)}
                                            </StatusBadge>
                                        </div>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            {path.site?.name ??
                                                'No site assigned'}{' '}
                                            · {path.device_load} devices ·{' '}
                                            {path.monitor_load} checks
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    </div>
                ) : null}

                {activeTab === 'limitations' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Known limitations</CardTitle>
                            <CardDescription>
                                Unknown evidence stays explicit instead of being
                                filled with guesses.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 lg:grid-cols-3">
                            <div className="rounded-xl border border-dashed p-4">
                                <div className="flex items-center gap-2">
                                    <CircleHelp className="h-5 w-5" />
                                    <StatusBadge variant="neutral">
                                        Not assessed
                                    </StatusBadge>
                                </div>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {workspace.limitations.unsupported_note}
                                </p>
                            </div>
                            <div className="rounded-xl border border-dashed p-4">
                                <div className="flex items-center gap-2">
                                    <Radar className="h-5 w-5" />
                                    <p className="font-semibold">
                                        Not configured
                                    </p>
                                </div>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {workspace.limitations.not_configured_note}
                                </p>
                            </div>
                            <div className="rounded-xl border border-dashed p-4">
                                <div className="flex items-center gap-2">
                                    <Activity className="h-5 w-5" />
                                    <p className="font-semibold">
                                        Collector capacity
                                    </p>
                                </div>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {workspace.limitations.capacity_note}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
