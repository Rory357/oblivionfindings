import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    BatteryLow,
    Calendar,
    Check,
    Clock,
    MonitorOff,
    Search,
    Wrench,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { type FilterOption, type Paginated, FilterSelect, StatCard } from './devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type MaintenanceRecord = {
    id: number;
    device_id: number;
    device_name: string | null;
    device_uid: string | null;
    type: string;
    status: string;
    description: string;
    scheduled_for: string | null;
    completed_at: string | null;
    performed_by: string | null;
    vendor_reference: string | null;
    cost: string | null;
    notes: string | null;
    is_overdue: boolean;
};

type AttentionDevice = {
    id: number;
    name: string;
    device_uid: string;
    domain: string;
    category: string;
    status: string;
    health_status: string;
    battery_level: number | null;
    last_seen_at: string | null;
};

type LowBatteryDevice = {
    id: number;
    name: string;
    device_uid: string;
    battery_level: number | null;
    battery_updated_at: string | null;
};

type Props = {
    stats: {
        overdue: number;
        upcoming: number;
        offline: number;
        degraded: number;
        lowBattery: number;
        critical: number;
    };
    records: Paginated<MaintenanceRecord>;
    attentionDevices: AttentionDevice[];
    lowBatteryDevices: LowBatteryDevice[];
    filters: Record<string, string>;
    can: { manage: boolean };
};

// ── Helpers ───────────────────────────────────────────────────────

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed': return 'default';
        case 'in_progress': return 'outline';
        case 'cancelled': return 'secondary';
        default: return 'outline';
    }
}

function healthVariant(h: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (h) { case 'healthy': return 'default'; case 'warning': return 'outline'; case 'critical': return 'destructive'; default: return 'secondary'; }
}

function deviceStatusVariant(s: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (s) { case 'active': return 'default'; case 'offline': case 'decommissioned': return 'secondary'; case 'degraded': return 'outline'; default: return 'outline'; }
}

function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatTimeSince(iso: string | null): string {
    if (!iso) return 'Never';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

// ── Component ─────────────────────────────────────────────────────

export default function MaintenanceHealth({ stats, records, attentionDevices, lowBatteryDevices, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const pageUrl = '/security-devices/maintenance-health';

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(pageUrl, { ...filters, ...newFilters, page: '1' }, { preserveState: true });
    };

    const clearFilters = () => {
        router.get(pageUrl, {}, { preserveState: true });
        setSearch('');
    };

    const hasActiveFilters = Object.values(filters).some((v) => v && v !== 'all');
    const totalAttention = stats.offline + stats.degraded + stats.critical + stats.lowBattery;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Maintenance & Health', href: pageUrl },
            ]}
        >
            <Head title="Maintenance & Health - Security & Devices" />

            <PageShell>
                <PageHero
                    icon={Wrench}
                    title="Maintenance & Health"
                    description="Device maintenance scheduling, health monitoring, and operational attention tracking."
                    stats={[
                        { label: 'Overdue', value: stats.overdue },
                        { label: 'Upcoming', value: stats.upcoming },
                        { label: 'Attention', value: totalAttention },
                        { label: 'Critical', value: stats.critical },
                    ]}
                />

                {/* ── Stats row ─────────────────────────────── */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <StatCard label="Overdue" value={stats.overdue} icon={AlertTriangle} variant={stats.overdue > 0 ? 'warning' : 'default'} />
                    <StatCard label="Upcoming (14d)" value={stats.upcoming} icon={Calendar} />
                    <StatCard label="Offline" value={stats.offline} icon={MonitorOff} variant={stats.offline > 0 ? 'warning' : 'default'} />
                    <StatCard label="Degraded" value={stats.degraded} icon={Zap} variant={stats.degraded > 0 ? 'warning' : 'default'} />
                    <StatCard label="Low Battery" value={stats.lowBattery} icon={BatteryLow} variant={stats.lowBattery > 0 ? 'warning' : 'default'} />
                    <StatCard label="Critical Health" value={stats.critical} icon={AlertTriangle} variant={stats.critical > 0 ? 'warning' : 'default'} />
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    {/* ── Left: Maintenance records ────────────── */}
                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <h2 className="text-lg font-semibold">Maintenance Records</h2>
                        </div>

                        {/* Filters */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            <div className="relative flex-1 sm:max-w-xs">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search description, device, vendor ref..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                                    className="pl-9"
                                />
                            </div>
                            <FilterSelect
                                value={filters.status}
                                onChange={(v) => applyFilters({ status: v })}
                                placeholder="Status"
                                options={[
                                    { value: 'scheduled', label: 'Scheduled' },
                                    { value: 'in_progress', label: 'In Progress' },
                                    { value: 'completed', label: 'Completed' },
                                    { value: 'cancelled', label: 'Cancelled' },
                                ]}
                            />
                            <FilterSelect
                                value={filters.type}
                                onChange={(v) => applyFilters({ type: v })}
                                placeholder="Type"
                                options={[
                                    { value: 'scheduled_service', label: 'Scheduled Service' },
                                    { value: 'repair', label: 'Repair' },
                                    { value: 'firmware_update', label: 'Firmware Update' },
                                    { value: 'inspection', label: 'Inspection' },
                                    { value: 'replacement', label: 'Replacement' },
                                    { value: 'calibration', label: 'Calibration' },
                                    { value: 'connectivity_check', label: 'Connectivity Check' },
                                    { value: 'battery_replacement', label: 'Battery Replacement' },
                                ]}
                            />
                            {hasActiveFilters && (
                                <Button variant="ghost" size="sm" onClick={clearFilters}>Clear</Button>
                            )}
                        </div>

                        {/* Records list */}
                        {records.data.length > 0 ? (
                            <div className="space-y-2">
                                {records.data.map((r) => (
                                    <div key={r.id} className={`rounded-lg border p-4 text-sm ${r.is_overdue ? 'border-status-warning/30 bg-status-warning-bg' : ''}`}>
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{r.type.replace(/_/g, ' ')}</span>
                                                    <Badge variant={statusBadgeVariant(r.status)} className="text-[10px]">{r.status.replace(/_/g, ' ')}</Badge>
                                                    {r.is_overdue && <Badge variant="destructive" className="text-[10px]">Overdue</Badge>}
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground line-clamp-2">{r.description}</p>
                                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                    {r.device_name && (
                                                        <Link href={`/security-devices/devices/${r.device_id}`} className="text-primary hover:underline">
                                                            {r.device_name} ({r.device_uid})
                                                        </Link>
                                                    )}
                                                    {r.scheduled_for && <span>Due: {formatDate(r.scheduled_for)}</span>}
                                                    {r.completed_at && <span>Completed: {formatDate(r.completed_at)}</span>}
                                                    {r.performed_by && <span>By: {r.performed_by}</span>}
                                                    {r.vendor_reference && <span>Ref: {r.vendor_reference}</span>}
                                                    {r.cost && <span>Cost: ${r.cost}</span>}
                                                </div>
                                            </div>
                                            {can.manage && r.status === 'scheduled' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.post(`/security-devices/maintenance/${r.id}/complete`, {}, { preserveScroll: true })}
                                                >
                                                    <Check className="mr-1 h-3 w-3" /> Complete
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : hasActiveFilters ? (
                            <EmptySearch onClear={clearFilters} title="No matching maintenance records" />
                        ) : (
                            <EmptyState
                                icon={Wrench}
                                title="No maintenance records"
                                description="Maintenance records will appear here when created from device detail pages."
                                variant="compact"
                            />
                        )}

                        {/* Pagination */}
                        {(records.meta.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-center gap-1">
                                {records.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>

                    {/* ── Right: Health attention sidebar ──────── */}
                    <div className="space-y-6">
                        {/* Devices needing attention */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-4 w-4 text-status-warning" /> Devices Needing Attention
                                </CardTitle>
                                <CardDescription>{totalAttention} device{totalAttention !== 1 ? 's' : ''} require attention</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {attentionDevices.length > 0 ? (
                                    <div className="space-y-2 max-h-80 overflow-y-auto">
                                        {attentionDevices.map((d) => (
                                            <Link
                                                key={d.id}
                                                href={`/security-devices/devices/${d.id}`}
                                                className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50 transition-colors"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium truncate">{d.name}</p>
                                                    <p className="text-[10px] text-muted-foreground">{d.category.replace(/_/g, ' ')} | Seen: {formatTimeSince(d.last_seen_at)}</p>
                                                </div>
                                                <div className="flex flex-col items-end gap-1 shrink-0">
                                                    <Badge variant={deviceStatusVariant(d.status)} className="text-[10px]">{d.status}</Badge>
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

                        {/* Low battery devices */}
                        {lowBatteryDevices.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <BatteryLow className="h-4 w-4 text-status-warning" /> Low Battery
                                    </CardTitle>
                                    <CardDescription>{lowBatteryDevices.length} device{lowBatteryDevices.length !== 1 ? 's' : ''} below 20%</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {lowBatteryDevices.map((d) => (
                                            <Link
                                                key={d.id}
                                                href={`/security-devices/devices/${d.id}`}
                                                className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50 transition-colors"
                                            >
                                                <div>
                                                    <p className="font-medium">{d.name}</p>
                                                    <p className="text-[10px] text-muted-foreground font-mono">{d.device_uid}</p>
                                                </div>
                                                <div className="flex items-center gap-1 text-status-warning dark:text-status-warning">
                                                    <Battery className="h-4 w-4" />
                                                    <span className="text-sm font-semibold">{d.battery_level}%</span>
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
