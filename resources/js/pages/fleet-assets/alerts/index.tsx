import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { Card, CardContent } from '@/components/ui/card';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    ExternalLink,
    ShieldAlert,
    X,
    Zap,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { formatDateTime } from '@/lib/fleet-utils';


type ControlRoomAlert = {
    id: number;
    source: 'control_room';
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    context: unknown;
    notes: string | null;
    asset: { id: number; name: string; asset_tag?: string } | null;
    assigned_to: { id: number; name: string } | null;
};

type AssetAlert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    context: unknown;
    asset: { id: number; name: string; asset_tag?: string } | null;
    tracker: { id: number; vendor: string; device_uid: string } | null;
};

type Props = {
    control_room_alerts: {
        data: ControlRoomAlert[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        meta?: { current_page: number; last_page: number; total: number };
    };
    archived_asset_alerts: AssetAlert[];
    filters: {
        severity?: string;
        status?: string;
        asset_id?: string;
    };
    can: {
        manage: boolean;
    };
};

const SEVERITY_BORDER: Record<string, string> = {
    critical: 'border-l-4 border-l-red-600',
    high: 'border-l-4 border-l-orange-500',
    medium: 'border-l-4 border-l-yellow-500',
    low: 'border-l-4 border-l-blue-400',
};

function severityVariant(severity: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (severity) {
        case 'critical': return 'destructive';
        case 'high': return 'destructive';
        case 'medium': return 'default';
        case 'low': return 'secondary';
        default: return 'outline';
    }
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'open': return 'destructive';
        case 'ack': return 'default';
        case 'triaging': return 'outline';
        case 'resolved': return 'secondary';
        case 'closed': return 'secondary';
        default: return 'outline';
    }
}

export default function AlertsIndex({
    control_room_alerts: rawCrAlerts,
    archived_asset_alerts: rawArchivedAssetAlerts,
    filters: rawFilters,
    can,
}: Props) {
    const crAlerts = rawCrAlerts?.data ?? [];
    const crMeta = rawCrAlerts?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const crLinks = rawCrAlerts?.links ?? [];
    const archivedAssetAlerts = rawArchivedAssetAlerts ?? [];
    const filters = rawFilters ?? {};
    const canManage = can.manage;

    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [bulkAction, setBulkAction] = useState<string | null>(null);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

    function handleSort(field: string) {
        const newDir = sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(window.location.pathname, { ...filters, sort: field, direction: newDir }, { preserveState: true });
    }

    function SortHeader({ field, children, className }: { field: string; children: React.ReactNode; className?: string }) {
        const active = sortField === field;
        return (
            <th className={`px-4 py-3 cursor-pointer select-none hover:bg-muted/50 font-medium ${className ?? 'text-left'}`} onClick={() => handleSort(field)}>
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (sortDir === 'asc' ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />) : <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />}
                </div>
            </th>
        );
    }

    const operationalAlerts = crAlerts;
    const unresolvedOperationalAlerts = operationalAlerts.filter((a) => !['resolved', 'closed'].includes(a.status));
    const criticalCount = unresolvedOperationalAlerts.filter((a) => a.severity === 'critical').length;
    const highCount = unresolvedOperationalAlerts.filter((a) => a.severity === 'high').length;
    const mediumCount = unresolvedOperationalAlerts.filter((a) => a.severity === 'medium').length;
    const lowAlertCount = unresolvedOperationalAlerts.filter((a) => a.severity === 'low').length;

    const severityTotal = criticalCount + highCount + mediumCount + lowAlertCount;
    const severityBars = [
        { label: 'Critical', count: criticalCount, color: 'bg-status-critical' },
        { label: 'High', count: highCount, color: 'bg-status-warning' },
        { label: 'Medium', count: mediumCount, color: 'bg-status-warning' },
        { label: 'Low', count: lowAlertCount, color: 'bg-status-info' },
    ];

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get('/fleet-assets/alerts', {
            ...filters,
            ...newFilters,
            cr_page: 1,
        }, { preserveState: true });
    };

    const toggleSelect = useCallback((id: string) => {
        setSelectedIds((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
    }, []);

    const toggleSelectAll = useCallback(() => {
        const crIds = operationalAlerts.map((a) => `cr-${a.id}`);
        if (selectedIds.length === crIds.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(crIds);
        }
    }, [operationalAlerts, selectedIds.length]);

    const handleBulkAction = useCallback((action: string) => {
        if (selectedIds.length === 0) return;
        const numericIds = selectedIds.map((id) => Number(id.replace('cr-', ''))).filter((id) => !isNaN(id));
        router.post('/fleet-assets/alerts/bulk-action', { action, ids: numericIds } as any, {
            preserveState: true,
            onSuccess: () => setSelectedIds([]),
        });
    }, [selectedIds]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Alerts', href: '/fleet-assets/alerts' },
            ]}
        >
            <Head title="Alerts" />
            <PageShell>
                <FleetHero
                    title="Alerts"
                    description="Active fleet and asset operations alerts from Control Room, with archived legacy asset alert history kept separately below."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/control-room">
                                <ExternalLink className="mr-2 h-4 w-4" />
                                Control Room
                            </Link>
                        </Button>
                    }
                />

                {/* Dark KPI Cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="TOTAL OPEN" value={unresolvedOperationalAlerts.length} icon={Bell} subtitle="Operational Control Room alerts" />
                    <FleetStatCard label="CRITICAL" value={criticalCount} icon={Zap} color="red" valueClassName="text-status-critical" subtitle="Immediate attention" />
                    <FleetStatCard label="HIGH" value={highCount} icon={ShieldAlert} color="amber" valueClassName="text-status-warning" subtitle="High priority" />
                    <FleetStatCard label="MEDIUM" value={mediumCount} icon={AlertTriangle} color="amber" valueClassName="text-status-warning" subtitle="Medium severity" />
                    {severityTotal > 0 && (
                        <Card className="border bg-primary/10 dark:bg-primary/20 sm:col-span-2 md:col-span-3 lg:col-span-4">
                            <CardContent className="p-4">
                                <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-3">SEVERITY DISTRIBUTION</p>
                                <div className="flex h-3 w-full overflow-hidden rounded-full">
                                    {severityBars.map((bar) => (
                                        bar.count > 0 && (
                                            <div
                                                key={bar.label}
                                                className={`${bar.color} transition-all duration-300`}
                                                style={{ width: `${(bar.count / severityTotal) * 100}%` }}
                                                title={`${bar.label}: ${bar.count}`}
                                            />
                                        )
                                    ))}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-4 text-xs">
                                    {severityBars.map((bar) => (
                                        <div key={bar.label} className="flex items-center gap-1.5">
                                            <span className={`inline-block h-2.5 w-2.5 rounded-sm ${bar.color}`} />
                                            <span className="text-muted-foreground">{bar.label}</span>
                                            <span className="font-medium text-white">{bar.count}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <Select
                        value={filters.severity || 'all'}
                        onValueChange={(v) => applyFilters({ severity: v === 'all' ? '' : v })}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Severity" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All severities</SelectItem>
                            <SelectItem value="critical">Critical</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}
                    >
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="ack">Acknowledged</SelectItem>
                            <SelectItem value="triaging">Triaging</SelectItem>
                            <SelectItem value="resolved">Resolved</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table with severity left borders */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                {canManage && (
                                    <th className="px-4 py-3 text-left font-medium w-8">
                                        <input
                                            type="checkbox"
                                            checked={operationalAlerts.length > 0 && selectedIds.length === operationalAlerts.length}
                                            onChange={toggleSelectAll}
                                            className="h-3.5 w-3.5 rounded border-border"
                                        />
                                    </th>
                                )}
                                <th className="px-4 py-3 text-left font-medium">Type</th>
                                <SortHeader field="severity">Severity</SortHeader>
                                <SortHeader field="status">Status</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">Asset</th>
                                <SortHeader field="triggered_at">Triggered</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {operationalAlerts.length > 0 ? (
                                operationalAlerts.map((alert) => (
                                    <tr key={alert.id} className={`border-b transition-colors hover:bg-muted/30 transition-colors ${SEVERITY_BORDER[alert.severity] ?? ''}`}>
                                        {canManage && (
                                            <td className="px-4 py-3">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.includes(`cr-${alert.id}`)}
                                                    onChange={() => toggleSelect(`cr-${alert.id}`)}
                                                    className="h-3.5 w-3.5 rounded border-border"
                                                />
                                            </td>
                                        )}
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <AlertTriangle className={`h-4 w-4 ${alert.severity === 'critical' ? 'text-status-critical' : 'text-status-warning'}`} />
                                                <span className="font-medium">{(alert.alert_type ?? '').replace(/_/g, ' ')}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={severityVariant(alert.severity)} className="text-xs font-bold uppercase">
                                                {alert.severity}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge variant={statusVariant(alert.status)}>{alert.status}</Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            {alert.asset ? (
                                                <Link href={`/fleet-assets/assets/${alert.asset.id}`} className="text-primary hover:underline">
                                                    {alert.asset.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">---</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {alert.triggered_at ? formatDateTime(alert.triggered_at) : '---'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {canManage ? (
                                                <div className="flex gap-1">
                                                    {alert.status === 'open' && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => router.post(`/fleet-assets/alerts/${alert.id}/acknowledge`)}
                                                        >
                                                            Acknowledge
                                                        </Button>
                                                    )}
                                                    {['open', 'ack', 'triaging'].includes(alert.status) && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => router.post(`/fleet-assets/alerts/${alert.id}/resolve`)}
                                                        >
                                                            <CheckCircle className="mr-1 h-3 w-3" />
                                                            Resolve
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">View only</span>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={canManage ? 7 : 6} className="px-4 py-12">
                                        <FleetEmptyState icon={Bell} title="No operational alerts" description="Control Room fleet alerts appear here when triggered by geofence breaches, speed violations, or other configured rules." />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Card>
                    <CardContent className="p-4 space-y-3">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Archived Asset Alert History</h2>
                                <p className="text-sm text-muted-foreground">
                                    Legacy <code>asset_alerts</code> records are retained for historical visibility only and are no longer part of the active operational alert workflow.
                                </p>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/alerts">Open Archive</Link>
                            </Button>
                        </div>

                        {archivedAssetAlerts.length > 0 ? (
                            <div className="space-y-2">
                                {archivedAssetAlerts.map((alert) => (
                                    <div key={alert.id} className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm">
                                        <div className="min-w-0">
                                            <div className="font-medium">{alert.alert_type.replace(/_/g, ' ')}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {alert.asset ? (
                                                    <Link href={`/fleet-assets/assets/${alert.asset.id}`} className="text-primary hover:underline">
                                                        {alert.asset.name}
                                                    </Link>
                                                ) : (
                                                    'Unknown asset'
                                                )}
                                                {alert.tracker ? ` • ${alert.tracker.vendor} ${alert.tracker.device_uid}` : ''}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Triggered {alert.triggered_at ? formatDateTime(alert.triggered_at) : '---'}
                                                {alert.acknowledged_at ? ` • Acknowledged ${formatDateTime(alert.acknowledged_at)}` : ''}
                                                {alert.resolved_at ? ` • Resolved ${formatDateTime(alert.resolved_at)}` : ''}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge variant={severityVariant(alert.severity)} className="text-xs font-bold uppercase">
                                                {alert.severity}
                                            </Badge>
                                            <Badge variant={statusVariant(alert.status)}>{alert.status}</Badge>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No archived asset alerts matched the current filters.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Bulk Action Bar */}
                {canManage && selectedIds.length > 0 && (
                    <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
                        <span className="text-sm font-medium">{selectedIds.length} alert{selectedIds.length !== 1 ? 's' : ''} selected</span>
                        <div className="flex items-center gap-2">
                            <Button size="sm" variant="outline" onClick={() => setBulkAction('acknowledge')}>
                                Acknowledge Selected
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => setBulkAction('resolve')}>
                                Resolve Selected
                            </Button>
                        </div>
                        <Button size="sm" variant="ghost" onClick={() => setSelectedIds([])}>
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                )}

                {/* Pagination */}
                {(crMeta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {crLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
                <ConfirmDialog
                    open={canManage && bulkAction !== null}
                    onClose={() => setBulkAction(null)}
                    onConfirm={() => { if (bulkAction) handleBulkAction(bulkAction); }}
                    title={bulkAction === 'acknowledge' ? 'Acknowledge Alerts' : 'Resolve Alerts'}
                    description={`Are you sure you want to ${bulkAction ?? ''} ${selectedIds.length} selected alert${selectedIds.length !== 1 ? 's' : ''}?`}
                    confirmText={bulkAction === 'acknowledge' ? 'Acknowledge' : 'Resolve'}
                    variant="default"
                />
            </PageShell>
        </AppLayout>
    );
}
