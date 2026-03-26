import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { AlertTriangle, Bell, Clock, User } from 'lucide-react';

interface Props {
    alerts: {
        data: any[];
        links: any[];
    };
    filters: {
        site_id?: string;
        severity?: string;
        status?: string;
        provider?: string;
    };
    sites: Array<{ id: number; name: string }>;
}

const severityColors: Record<string, string> = {
    critical: 'bg-red-600 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-green-500 text-white',
};

const statusColors: Record<string, string> = {
    open: 'bg-red-100 text-red-800 border-red-200',
    ack: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    acknowledged: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    triaging: 'bg-blue-100 text-blue-800 border-blue-200',
    resolved: 'bg-green-100 text-green-800 border-green-200',
    closed: 'bg-gray-100 text-gray-800 border-gray-200',
};

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

export default function AlertsIndex({ alerts, filters, sites }: Props) {
    const applyFilter = (key: string, value: string) => {
        router.get(
            '/control-room/alerts',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Control Room', href: '/control-room' },
            { title: 'Alerts', href: '/control-room/alerts' },
        ]}>
            <Head title="Control Room Alerts" />

            <div className="flex flex-col gap-4 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Alerts</h1>
                        <p className="text-sm text-muted-foreground">All control room alerts across sites</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/control-room">Dashboard</Link>
                    </Button>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <Select
                                value={filters.site_id || 'all'}
                                onValueChange={(v) => applyFilter('site_id', v === 'all' ? '' : v)}
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Sites</SelectItem>
                                    {sites.map((site) => (
                                        <SelectItem key={site.id} value={String(site.id)}>{site.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={filters.severity || 'all'}
                                onValueChange={(v) => applyFilter('severity', v === 'all' ? '' : v)}
                            >
                                <SelectTrigger className="w-36">
                                    <SelectValue placeholder="Severity" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Severity</SelectItem>
                                    <SelectItem value="critical">Critical</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(v) => applyFilter('status', v === 'all' ? '' : v)}
                            >
                                <SelectTrigger className="w-36">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Status</SelectItem>
                                    <SelectItem value="open">Open</SelectItem>
                                    <SelectItem value="acknowledged">Acknowledged</SelectItem>
                                    <SelectItem value="triaging">Triaging</SelectItem>
                                    <SelectItem value="resolved">Resolved</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                </SelectContent>
                            </Select>

                            <Input
                                placeholder="Provider..."
                                defaultValue={filters.provider || ''}
                                className="w-40"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') applyFilter('provider', (e.target as HTMLInputElement).value);
                                }}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Alert List */}
                <div className="space-y-2">
                    {alerts.data.length === 0 ? (
                        <Card>
                            <CardContent className="pt-6">
                                <div className="py-8 text-center text-sm text-muted-foreground">
                                    <Bell className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>No alerts found matching your filters.</p>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        alerts.data.map((alert: any) => (
                            <Card key={alert.id} className="hover:bg-muted/30 transition-colors">
                                <CardContent className="pt-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-medium">{alert.alert_type ?? alert.type ?? 'Alert'}</span>
                                                <Badge className={severityColors[alert.severity] ?? 'bg-gray-500 text-white'}>
                                                    {alert.severity}
                                                </Badge>
                                                <Badge variant="outline" className={statusColors[alert.status] ?? ''}>
                                                    {alert.status}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {formatRelativeTime(alert.triggered_at)}
                                                </span>
                                                {alert.site?.name && (
                                                    <>
                                                        <span>|</span>
                                                        <span>{alert.site.name}</span>
                                                    </>
                                                )}
                                                {alert.assigned_to?.name && (
                                                    <>
                                                        <span>|</span>
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3" />
                                                            {alert.assigned_to.name}
                                                        </span>
                                                    </>
                                                )}
                                                {alert.provider && (
                                                    <>
                                                        <span>|</span>
                                                        <span>{alert.provider}</span>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/control-room/alerts/${alert.id}`}>View</Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>

                {/* Pagination */}
                {alerts.links?.length > 3 && (
                    <div className="flex justify-center gap-2">
                        {alerts.links.map((link: any, i: number) => (
                            <Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}>
                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
