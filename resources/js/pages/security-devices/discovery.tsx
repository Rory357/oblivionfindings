import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Activity, Clock3, Radar, RadioTower, ServerOff } from 'lucide-react';

import { StatCard } from './devices/shared';

interface CollectorItem {
    id: number;
    uuid: string;
    name: string;
    site: { id: number; name: string } | null;
    status: string;
    last_seen_at: string | null;
    monitor_count: number;
    is_stale: boolean;
}

function freshness(lastSeenAt: string | null): string {
    if (!lastSeenAt) return 'Never seen';
    return new Date(lastSeenAt).toLocaleString('en-NZ');
}

export default function DiscoveryCollectors({
    collectors,
    summary,
}: {
    collectors: CollectorItem[];
    summary: {
        collectors: number;
        online: number;
        stale: number;
        monitors: number;
        unassigned_monitors: number;
    };
}) {
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
                    description="Collector reachability and monitor assignment for sites that need local collection. The main application monitors SD-WAN reachable sites directly."
                    stats={[
                        { label: 'Collectors', value: summary.collectors },
                        { label: 'Online', value: summary.online },
                        { label: 'Monitors', value: summary.monitors },
                        {
                            label: 'Need action',
                            value: summary.stale + summary.unassigned_monitors,
                        },
                    ]}
                />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Collectors"
                        value={summary.collectors}
                        icon={RadioTower}
                    />
                    <StatCard
                        label="Online"
                        value={summary.online}
                        icon={Activity}
                    />
                    <StatCard
                        label="Stale / unseen"
                        value={summary.stale}
                        icon={Clock3}
                        variant={summary.stale > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Monitors without collector"
                        value={summary.unassigned_monitors}
                        icon={ServerOff}
                        variant={
                            summary.unassigned_monitors > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Collectors</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {collectors.length === 0 ? (
                            <EmptyState
                                icon={RadioTower}
                                title="No collectors configured"
                                description="The main application can monitor SD-WAN reachable sites directly. Add a hardened collector only for isolated or unreliable remote paths."
                                variant="compact"
                            />
                        ) : (
                            <div className="space-y-2">
                                {collectors.map((collector) => (
                                    <div
                                        key={collector.id}
                                        className="flex min-h-16 flex-col justify-between gap-3 rounded-xl border p-4 sm:flex-row sm:items-center"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-semibold">
                                                {collector.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {collector.site?.name ??
                                                    'No site assigned'}{' '}
                                                · {collector.monitor_count}{' '}
                                                monitors
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <Badge
                                                variant={
                                                    collector.status ===
                                                    'online'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {collector.status.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                            {collector.is_stale ? (
                                                <Badge variant="outline">
                                                    Stale
                                                </Badge>
                                            ) : null}
                                            <span>
                                                Last seen{' '}
                                                {freshness(
                                                    collector.last_seen_at,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
