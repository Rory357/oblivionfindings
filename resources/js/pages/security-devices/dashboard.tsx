import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Battery,
    BatteryLow,
    Bell,
    Building2,
    Cctv,
    Cpu,
    GitBranch,
    HeartPulse,
    Key,
    LayoutDashboard,
    MonitorOff,
    Plus,
    Server,
    Shield,
    Siren,
    Smartphone,
    Wrench,
    Zap,
} from 'lucide-react';

import { StatCard } from './devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type Props = {
    stats: {
        totalDevices: number;
        active: number;
        offline: number;
        degraded: number;
        lowBattery: number;
        overdueMaintenance: number;
        criticalEvents24h: number;
        warningEvents24h: number;
    };
    domainSummary: Array<{ domain: string; label: string; count: number }>;
    healthSummary: Array<{ status: string; label: string; count: number }>;
    attentionDevices: Array<{
        id: number; name: string; device_uid: string; domain: string;
        category: string; status: string; health_status: string;
        battery_level: number | null; last_seen_at: string | null;
    }>;
    recentEvents: Array<{
        id: number; device_id: number; device_name: string | null;
        device_uid: string | null; event_type: string; severity: string;
        occurred_at: string;
    }>;
    overdueMaintenance: Array<{
        id: number; device_id: number; device_name: string | null;
        device_uid: string | null; type: string; description: string;
        scheduled_for: string | null;
    }>;
    groupCount: number;
};

// ── Helpers ───────────────────────────────────────────────────────

function healthVariant(h: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (h) { case 'healthy': return 'default'; case 'warning': return 'outline'; case 'critical': return 'destructive'; default: return 'secondary'; }
}

function statusVariant(s: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (s) { case 'active': return 'default'; case 'offline': case 'decommissioned': return 'secondary'; default: return 'outline'; }
}

function severityVariant(s: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (s) { case 'critical': return 'destructive'; case 'warning': return 'outline'; default: return 'secondary'; }
}

function formatTimeSince(iso: string | null): string {
    if (!iso) return 'Never';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

const domainIcons: Record<string, React.ComponentType<{ className?: string }>> = {
    security: Shield, tracking: Smartphone, iot_healthcare: HeartPulse,
    it_infrastructure: Server, facilities: Building2,
};

const domainHrefs: Record<string, string> = {
    security: '/security-devices/alarms',
    tracking: '/security-devices/tracking-devices',
    iot_healthcare: '/security-devices/smart-iot-healthcare',
    it_infrastructure: '/security-devices/it-infrastructure',
    facilities: '/security-devices/facilities',
};

// ── Component ─────────────────────────────────────────────────────

export default function Dashboard({ stats, domainSummary, healthSummary, attentionDevices, recentEvents, overdueMaintenance, groupCount }: Props) {
    const totalAttention = stats.offline + stats.degraded + stats.lowBattery;

    return (
        <AppLayout breadcrumbs={[{ title: 'Security & Devices', href: '/security-devices' }]}>
            <Head title="Dashboard - Security & Devices" />

            <PageShell>
                <PageHeader
                    title={<span className="flex items-center gap-3"><LayoutDashboard className="h-6 w-6 text-primary" /> Security & Devices</span>}
                    description="Operational overview of hardware, device health, and maintenance posture."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/security-devices/devices"><Cpu className="mr-2 h-4 w-4" /> All Devices</Link>
                            </Button>
                            <Button size="sm" asChild>
                                <Link href="/security-devices/devices/create"><Plus className="mr-2 h-4 w-4" /> Register Device</Link>
                            </Button>
                        </div>
                    }
                />

                {/* ── Stats row ─────────────────────────────────── */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total Devices" value={stats.totalDevices} icon={Cpu} />
                    <StatCard label="Active" value={stats.active} icon={Activity} />
                    <StatCard label="Offline / Degraded" value={stats.offline + stats.degraded} icon={MonitorOff} variant={totalAttention > 0 ? 'warning' : 'default'} />
                    <StatCard label="Overdue Maintenance" value={stats.overdueMaintenance} icon={Wrench} variant={stats.overdueMaintenance > 0 ? 'warning' : 'default'} />
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Low Battery" value={stats.lowBattery} icon={BatteryLow} variant={stats.lowBattery > 0 ? 'warning' : 'default'} />
                    <StatCard label="Critical Events (24h)" value={stats.criticalEvents24h} icon={AlertTriangle} variant={stats.criticalEvents24h > 0 ? 'warning' : 'default'} />
                    <StatCard label="Warning Events (24h)" value={stats.warningEvents24h} icon={Zap} variant={stats.warningEvents24h > 0 ? 'warning' : 'default'} />
                    <StatCard label="Device Groups" value={groupCount} icon={GitBranch} />
                </div>

                {/* ── Main content grid ─────────────────────────── */}
                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">

                    {/* Left column */}
                    <div className="space-y-6">

                        {/* Domain distribution */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Device Estate by Domain</CardTitle>
                                <CardDescription>Distribution across hardware domains</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {stats.totalDevices > 0 ? (
                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        {domainSummary.map((d) => {
                                            const Icon = domainIcons[d.domain] ?? Cpu;
                                            return (
                                                <Link
                                                    key={d.domain}
                                                    href={domainHrefs[d.domain] ?? '/security-devices/devices'}
                                                    className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="rounded-md bg-muted p-2">
                                                        <Icon className="h-4 w-4 text-primary" />
                                                    </div>
                                                    <div>
                                                        <p className="text-xl font-semibold">{d.count}</p>
                                                        <p className="text-xs text-muted-foreground">{d.label}</p>
                                                    </div>
                                                </Link>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        icon={Shield}
                                        title="No devices registered"
                                        description="Register your first device to see the domain distribution."
                                        variant="compact"
                                        action={<Button size="sm" asChild><Link href="/security-devices/devices/create">Register Device</Link></Button>}
                                    />
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent critical/warning events */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2"><Bell className="h-4 w-4" /> Recent Events</CardTitle>
                                        <CardDescription>Critical and warning events</CardDescription>
                                    </div>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href="/security-devices/alerts-events">View all</Link>
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {recentEvents.length > 0 ? (
                                    <div className="space-y-1">
                                        {recentEvents.map((evt) => (
                                            <div
                                                key={evt.id}
                                                className={`flex items-center justify-between rounded-md border p-3 text-sm ${
                                                    evt.severity === 'critical' ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30' :
                                                    'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20'
                                                }`}
                                            >
                                                <div className="flex items-center gap-2 min-w-0">
                                                    <Badge variant={severityVariant(evt.severity)} className="text-[10px] shrink-0">{evt.severity}</Badge>
                                                    <span className="font-medium truncate">{evt.event_type.replace(/_/g, ' ')}</span>
                                                    {evt.device_name && (
                                                        <Link href={`/security-devices/devices/${evt.device_id}`} className="text-xs text-primary hover:underline truncate">
                                                            {evt.device_name}
                                                        </Link>
                                                    )}
                                                </div>
                                                <span className="text-xs text-muted-foreground shrink-0 ml-2">{formatTimeSince(evt.occurred_at)}</span>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground italic">No critical or warning events recently.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Overdue maintenance */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2"><Wrench className="h-4 w-4" /> Overdue Maintenance</CardTitle>
                                        <CardDescription>{stats.overdueMaintenance} record{stats.overdueMaintenance !== 1 ? 's' : ''} overdue</CardDescription>
                                    </div>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href="/security-devices/maintenance-health">View all</Link>
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {overdueMaintenance.length > 0 ? (
                                    <div className="space-y-2">
                                        {overdueMaintenance.map((m) => (
                                            <div key={m.id} className="flex items-center justify-between rounded-md border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20 p-3 text-sm">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-medium">{m.type.replace(/_/g, ' ')}</span>
                                                        <Badge variant="destructive" className="text-[10px]">Overdue</Badge>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground truncate">{m.description}</p>
                                                    <div className="mt-1 flex gap-3 text-xs text-muted-foreground">
                                                        {m.device_name && (
                                                            <Link href={`/security-devices/devices/${m.device_id}`} className="text-primary hover:underline">
                                                                {m.device_name}
                                                            </Link>
                                                        )}
                                                        {m.scheduled_for && <span>Due: {formatDate(m.scheduled_for)}</span>}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground italic">No overdue maintenance. All clear.</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right sidebar */}
                    <div className="space-y-6">

                        {/* Health distribution */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Health Distribution</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {healthSummary.map((h) => (
                                        <div key={h.status} className="flex items-center justify-between text-sm">
                                            <div className="flex items-center gap-2">
                                                <Badge variant={healthVariant(h.status)} className="text-[10px] w-16 justify-center">{h.label}</Badge>
                                            </div>
                                            <span className="font-semibold">{h.count}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Devices needing attention */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <AlertTriangle className="h-4 w-4 text-amber-500" /> Attention Required
                                    </CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {attentionDevices.length > 0 ? (
                                    <div className="space-y-2 max-h-96 overflow-y-auto">
                                        {attentionDevices.map((d) => (
                                            <Link
                                                key={d.id}
                                                href={`/security-devices/devices/${d.id}`}
                                                className="flex items-center justify-between rounded-md border p-2.5 text-sm hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium truncate">{d.name}</p>
                                                    <p className="text-[10px] text-muted-foreground">{d.category.replace(/_/g, ' ')}</p>
                                                </div>
                                                <div className="flex flex-col items-end gap-0.5 shrink-0">
                                                    <Badge variant={statusVariant(d.status)} className="text-[10px]">{d.status}</Badge>
                                                    <Badge variant={healthVariant(d.health_status)} className="text-[10px]">{d.health_status}</Badge>
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground italic">All devices healthy.</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Quick links */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Quick Links</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-1">
                                    {[
                                        { href: '/security-devices/devices', label: 'All Devices', icon: Cpu },
                                        { href: '/security-devices/alarms', label: 'Alarms', icon: Siren },
                                        { href: '/security-devices/cctv', label: 'CCTV', icon: Cctv },
                                        { href: '/security-devices/access-control', label: 'Access Control', icon: Key },
                                        { href: '/security-devices/tracking-devices', label: 'Tracking', icon: Smartphone },
                                        { href: '/security-devices/smart-iot-healthcare', label: 'IoT & Healthcare', icon: HeartPulse },
                                        { href: '/security-devices/it-infrastructure', label: 'IT Infrastructure', icon: Server },
                                        { href: '/security-devices/facilities', label: 'Facilities', icon: Building2 },
                                        { href: '/security-devices/device-groups', label: 'Device Groups', icon: GitBranch },
                                        { href: '/security-devices/maintenance-health', label: 'Maintenance & Health', icon: Wrench },
                                        { href: '/security-devices/alerts-events', label: 'Alerts & Events', icon: Bell },
                                    ].map(({ href, label, icon: Icon }) => (
                                        <Link
                                            key={href}
                                            href={href}
                                            className="flex items-center gap-3 rounded-md p-2 text-sm transition-colors hover:bg-muted/50"
                                        >
                                            <Icon className="h-4 w-4 text-muted-foreground" />
                                            <span>{label}</span>
                                        </Link>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
