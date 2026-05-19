import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Filter,
    ShieldAlert,
    XCircle,
} from 'lucide-react';

// --- Types ---

interface BreachRecord {
    id: number;
    alert_id: number;
    alert_type: string | null;
    severity: string | null;
    source: string | null;
    sla_name: string | null;
    sla_code: string | null;
    breach_types: string[];
    acknowledge_deadline: string | null;
    acknowledged_at: string | null;
    acknowledge_variance_minutes: number | null;
    response_deadline: string | null;
    responded_at: string | null;
    response_variance_minutes: number | null;
    resolution_deadline: string | null;
    resolved_at: string | null;
    resolution_variance_minutes: number | null;
    first_breach_at: string | null;
}

interface PaginatedBreaches {
    data: BreachRecord[];
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
    current_page: number;
    last_page: number;
    total: number;
}

interface Stats {
    total: number;
    acknowledge: number;
    response: number;
    resolution: number;
}

interface Filters {
    date_from: string;
    date_to: string;
    severity: string | null;
    breach_type: string | null;
}

interface Props {
    breaches: PaginatedBreaches;
    stats: Stats;
    filters: Filters;
}

// --- Constants ---

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-white',
    low: 'bg-status-info text-white',
};

const breachTypeColors: Record<string, string> = {
    acknowledge: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    response: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    resolution: 'bg-status-critical-bg text-status-critical border-status-critical/30',
};

// --- Helpers ---

function formatDateTime(isoString: string | null): string {
    if (!isoString) return '-';
    const date = new Date(isoString);
    return date.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatVariance(minutes: number | null): string {
    if (minutes === null || minutes === undefined) return '-';
    const absMinutes = Math.abs(Math.round(minutes));
    if (absMinutes < 60) return `${absMinutes}m late`;
    const hours = Math.floor(absMinutes / 60);
    const mins = absMinutes % 60;
    if (mins === 0) return `${hours}h late`;
    return `${hours}h ${mins}m late`;
}

// --- Main Page ---

export default function SlaBreaches({ breaches, stats, filters }: Props) {
    const applyFilter = (key: string, value: string | null) => {
        const newFilters: Record<string, string | undefined> = {
            date_from: filters.date_from,
            date_to: filters.date_to,
            severity: filters.severity ?? undefined,
            breach_type: filters.breach_type ?? undefined,
        };

        if (value) {
            newFilters[key] = value;
        } else {
            delete newFilters[key];
        }

        router.get('/control-room/sla/breaches', newFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        router.get('/control-room/sla/breaches', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const hasActiveFilters = filters.severity || filters.breach_type;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'SLA Management', href: '/control-room/sla' },
                { title: 'Breach Report', href: '#' },
            ]}
        >
            <Head title="SLA Breach Report - Control Room" />
            <PageShell>
                <PageHero
                    variant="compact"
                    title="SLA Breach Report"
                    description="View and analyse SLA breaches across all alert types."
                    backHref="/control-room/sla"
                    backLabel="Back to SLA Management"
                />

                {/* Stats Cards */}
                <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Total Breaches
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-bold ${stats.total > 0 ? 'text-status-critical' : ''}`}>
                                {stats.total}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Acknowledge Breaches
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-bold ${stats.acknowledge > 0 ? 'text-status-warning' : ''}`}>
                                {stats.acknowledge}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Response Breaches
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-bold ${stats.response > 0 ? 'text-status-warning' : ''}`}>
                                {stats.response}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs font-medium text-muted-foreground">
                                Resolution Breaches
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-bold ${stats.resolution > 0 ? 'text-status-critical' : ''}`}>
                                {stats.resolution}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Bar */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="space-y-2">
                                <Label className="text-xs">From</Label>
                                <Input
                                    type="date"
                                    value={filters.date_from}
                                    onChange={(e) => applyFilter('date_from', e.target.value)}
                                    className="w-40"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs">To</Label>
                                <Input
                                    type="date"
                                    value={filters.date_to}
                                    onChange={(e) => applyFilter('date_to', e.target.value)}
                                    className="w-40"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs">Severity</Label>
                                <Select
                                    value={filters.severity ?? 'all'}
                                    onValueChange={(v) => applyFilter('severity', v === 'all' ? null : v)}
                                >
                                    <SelectTrigger className="w-36">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Severities</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="low">Low</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs">Breach Type</Label>
                                <Select
                                    value={filters.breach_type ?? 'all'}
                                    onValueChange={(v) => applyFilter('breach_type', v === 'all' ? null : v)}
                                >
                                    <SelectTrigger className="w-40">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Types</SelectItem>
                                        <SelectItem value="acknowledge">Acknowledge</SelectItem>
                                        <SelectItem value="response">Response</SelectItem>
                                        <SelectItem value="resolution">Resolution</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {hasActiveFilters && (
                                <Button variant="ghost" size="sm" onClick={clearFilters}>
                                    <XCircle className="mr-1 h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Breach Table */}
                {breaches.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <ShieldAlert className="mb-4 h-12 w-12 text-status-success" />
                            <p className="text-lg font-medium text-muted-foreground">No breaches found</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                No SLA breaches match the current filters. Great work!
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-3 text-left font-medium">Alert</th>
                                        <th className="px-4 py-3 text-left font-medium">Type</th>
                                        <th className="px-4 py-3 text-left font-medium">Severity</th>
                                        <th className="px-4 py-3 text-left font-medium">Breach Type</th>
                                        <th className="px-4 py-3 text-left font-medium">Deadline</th>
                                        <th className="px-4 py-3 text-left font-medium">Actual</th>
                                        <th className="px-4 py-3 text-left font-medium">Variance</th>
                                        <th className="px-4 py-3 text-left font-medium">SLA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {breaches.data.map((breach) => (
                                        <BreachRow key={breach.id} breach={breach} />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {breaches.links?.length > 3 && (
                            <div className="flex justify-center gap-2 border-t p-4">
                                {breaches.links.map((link, i: number) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(link.url, {}, { preserveState: true, preserveScroll: true })
                                        }
                                    >
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    </Button>
                                ))}
                            </div>
                        )}
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}

// --- Breach Row ---

function BreachRow({ breach }: { breach: BreachRecord }) {
    // Pick the most severe breach to show deadline/actual/variance for
    // If multiple, show the worst one (resolution > response > acknowledge)
    const primaryBreach = breach.breach_types.includes('resolution')
        ? 'resolution'
        : breach.breach_types.includes('response')
          ? 'response'
          : 'acknowledge';

    const deadlineMap: Record<string, string | null> = {
        acknowledge: breach.acknowledge_deadline,
        response: breach.response_deadline,
        resolution: breach.resolution_deadline,
    };

    const actualMap: Record<string, string | null> = {
        acknowledge: breach.acknowledged_at,
        response: breach.responded_at,
        resolution: breach.resolved_at,
    };

    const varianceMap: Record<string, number | null> = {
        acknowledge: breach.acknowledge_variance_minutes,
        response: breach.response_variance_minutes,
        resolution: breach.resolution_variance_minutes,
    };

    return (
        <tr className="border-b transition-colors hover:bg-muted/50">
            <td className="px-4 py-3">
                <Link
                    href={`/control-room/alerts/${breach.alert_id}`}
                    className="font-medium text-primary hover:underline"
                >
                    #{breach.alert_id}
                </Link>
            </td>
            <td className="px-4 py-3 text-xs">
                {breach.alert_type?.replace(/_/g, ' ') ?? '-'}
            </td>
            <td className="px-4 py-3">
                {breach.severity ? (
                    <Badge className={`text-[10px] ${severityColors[breach.severity] ?? ''}`}>
                        {breach.severity}
                    </Badge>
                ) : (
                    '-'
                )}
            </td>
            <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                    {breach.breach_types.map((bt) => (
                        <Badge
                            key={bt}
                            variant="outline"
                            className={`text-[10px] ${breachTypeColors[bt] ?? ''}`}
                        >
                            {bt}
                        </Badge>
                    ))}
                </div>
            </td>
            <td className="px-4 py-3 text-xs text-muted-foreground">
                {formatDateTime(deadlineMap[primaryBreach])}
            </td>
            <td className="px-4 py-3 text-xs text-muted-foreground">
                {actualMap[primaryBreach] ? formatDateTime(actualMap[primaryBreach]) : 'Pending'}
            </td>
            <td className="px-4 py-3">
                {varianceMap[primaryBreach] !== null ? (
                    <span className="text-xs font-semibold text-status-critical">
                        {formatVariance(varianceMap[primaryBreach])}
                    </span>
                ) : (
                    <span className="text-xs text-status-critical font-semibold">Ongoing</span>
                )}
            </td>
            <td className="px-4 py-3">
                {breach.sla_name ? (
                    <div>
                        <span className="text-xs">{breach.sla_name}</span>
                        {breach.sla_code && (
                            <Badge variant="outline" className="ml-1 font-mono text-[10px]">
                                {breach.sla_code}
                            </Badge>
                        )}
                    </div>
                ) : (
                    '-'
                )}
            </td>
        </tr>
    );
}
