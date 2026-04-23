import { HalfMoonGauge, FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Calendar,
    CheckCircle,
    Clock,
    Search,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate } from '@/lib/fleet-utils';


type Vehicle = {
    id: number;
    name: string;
    asset_tag: string;
    registration_number: string | null;
    registration_expires_at: string | null;
    wof_expires_at: string | null;
    cof_expires_at: string | null;
    insurance_expires_at: string | null;
    home_site: { id: number; name: string } | null;
    status: 'ok' | 'warning' | 'critical' | 'expired';
    worst_days: number | null;
};

type Props = {
    vehicles: Vehicle[];
    summary: {
        total: number;
        expired_wof: number;
        expired_rego: number;
        expiring_30: number;
        expiring_60: number;
    };
    filters: {
        status?: string;
        search?: string;
    };
};

function daysUntil(dateStr: string | null): number | null {
    if (!dateStr) return null;
    const diff = (new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24);
    return Math.floor(diff);
}

function expiryColor(dateStr: string | null): string {
    const days = daysUntil(dateStr);
    if (days === null) return 'text-muted-foreground';
    if (days < 0) return 'text-red-600 dark:text-red-400';
    if (days <= 30) return 'text-orange-600 dark:text-orange-400';
    if (days <= 60) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-primary dark:text-primary';
}

function expiryBadge(dateStr: string | null): { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string } {
    const days = daysUntil(dateStr);
    if (days === null) return { variant: 'secondary', label: 'N/A' };
    if (days < 0) return { variant: 'destructive', label: 'Expired' };
    if (days <= 30) return { variant: 'destructive', label: `${days}d` };
    if (days <= 60) return { variant: 'default', label: `${days}d` };
    return { variant: 'outline', label: `${days}d` };
}

function statusBadge(status: string): { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string; icon: typeof CheckCircle } {
    switch (status) {
        case 'expired':
            return { variant: 'destructive', label: 'Expired', icon: XCircle };
        case 'critical':
            return { variant: 'destructive', label: 'Expiring Soon', icon: AlertTriangle };
        case 'warning':
            return { variant: 'default', label: 'Warning', icon: Clock };
        default:
            return { variant: 'outline', label: 'OK', icon: CheckCircle };
    }
}

export default function ComplianceIndex({ vehicles, summary, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get('/fleet-assets/compliance', {
            ...filters,
            ...newFilters,
        }, { preserveState: true });
    };

    const handleSearch = () => {
        applyFilters({ search });
    };

    const handleSendReminder = (vehicleName: string) => {
        alert(`Reminder sent for ${vehicleName}. (Placeholder - no actual notification sent)`);
    };

    // Compute compliance percentage
    const totalVehicles = summary.total ?? 0;
    const problemVehicles = (summary.expired_wof ?? 0) + (summary.expired_rego ?? 0) + (summary.expiring_30 ?? 0);
    const compliancePct = totalVehicles > 0 ? Math.round(((totalVehicles - Math.min(problemVehicles, totalVehicles)) / totalVehicles) * 100) : 100;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Compliance', href: '/fleet-assets/compliance' },
            ]}
        >
            <Head title="Compliance & Registrations" />
            <PageShell>
                <FleetHero
                    title="Compliance & Registrations"
                    description="Track vehicle registrations, WOF, and COF expiry dates."
                />

                {/* Top: KPI Cards + Gauge */}
                <div className="grid gap-4 lg:grid-cols-[1fr,1fr,1fr,1fr,auto]">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expired WOF</CardTitle>
                            <XCircle className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">{summary.expired_wof}</div>
                            <p className="text-xs text-muted-foreground">vehicles need attention</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expired Rego</CardTitle>
                            <XCircle className="h-4 w-4 text-red-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600 dark:text-red-400">{summary.expired_rego}</div>
                            <p className="text-xs text-muted-foreground">vehicles need attention</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expiring 30d</CardTitle>
                            <AlertTriangle className="h-4 w-4 text-orange-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-orange-600 dark:text-orange-400">{summary.expiring_30}</div>
                            <p className="text-xs text-muted-foreground">upcoming renewals</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expiring 60d</CardTitle>
                            <Clock className="h-4 w-4 text-yellow-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{summary.expiring_60}</div>
                            <p className="text-xs text-muted-foreground">plan ahead</p>
                        </CardContent>
                    </Card>
                    <Card className="flex items-center justify-center px-6">
                        <HalfMoonGauge
                            value={compliancePct}
                            label="Compliance"
                            size={120}
                            color={compliancePct >= 80 ? FLEET_COLORS.primary : compliancePct >= 50 ? FLEET_COLORS.warning : FLEET_COLORS.danger}
                        />
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search vehicles..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="pl-9"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="ok">OK</SelectItem>
                            <SelectItem value="warning">Warning (60d)</SelectItem>
                            <SelectItem value="critical">Critical (30d)</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Compliance Table */}
                <div className="rounded-lg border">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                    <th className="px-4 py-3 text-left font-medium">Vehicle</th>
                                    <th className="px-4 py-3 text-left font-medium">Registration #</th>
                                    <th className="px-4 py-3 text-left font-medium">Rego Expires</th>
                                    <th className="px-4 py-3 text-left font-medium">WOF Expires</th>
                                    <th className="px-4 py-3 text-left font-medium">CoF Expires</th>
                                    <th className="px-4 py-3 text-left font-medium">Insurance</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(vehicles ?? []).length > 0 ? (
                                    vehicles.map((vehicle) => {
                                        const badge = statusBadge(vehicle.status);
                                        const StatusIcon = badge.icon;
                                        return (
                                            <tr
                                                key={vehicle.id}
                                                className="cursor-pointer border-b transition-colors hover:bg-muted/50"
                                                onClick={() => router.visit(`/fleet-assets/vehicles/${vehicle.id}`)}
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="font-medium">{vehicle.name}</div>
                                                    {vehicle.asset_tag && (
                                                        <div className="text-xs text-muted-foreground">{vehicle.asset_tag}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs">
                                                    {vehicle.registration_number ?? '-'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {vehicle.registration_expires_at ? (
                                                        <span className={expiryColor(vehicle.registration_expires_at)}>
                                                            {formatDate(vehicle.registration_expires_at)}
                                                            {' '}
                                                            <Badge variant={expiryBadge(vehicle.registration_expires_at).variant} className="ml-1 text-xs">
                                                                {expiryBadge(vehicle.registration_expires_at).label}
                                                            </Badge>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {vehicle.wof_expires_at ? (
                                                        <span className={expiryColor(vehicle.wof_expires_at)}>
                                                            {formatDate(vehicle.wof_expires_at)}
                                                            {' '}
                                                            <Badge variant={expiryBadge(vehicle.wof_expires_at).variant} className="ml-1 text-xs">
                                                                {expiryBadge(vehicle.wof_expires_at).label}
                                                            </Badge>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {vehicle.cof_expires_at ? (
                                                        <span className={expiryColor(vehicle.cof_expires_at)}>
                                                            {formatDate(vehicle.cof_expires_at)}
                                                            {' '}
                                                            <Badge variant={expiryBadge(vehicle.cof_expires_at).variant} className="ml-1 text-xs">
                                                                {expiryBadge(vehicle.cof_expires_at).label}
                                                            </Badge>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {vehicle.insurance_expires_at ? (
                                                        <span className={expiryColor(vehicle.insurance_expires_at)}>
                                                            {formatDate(vehicle.insurance_expires_at)}
                                                            {' '}
                                                            <Badge variant={expiryBadge(vehicle.insurance_expires_at).variant} className="ml-1 text-xs">
                                                                {expiryBadge(vehicle.insurance_expires_at).label}
                                                            </Badge>
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant={badge.variant} className="gap-1">
                                                        <StatusIcon className="h-3 w-3" />
                                                        {badge.label}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleSendReminder(vehicle.name);
                                                        }}
                                                    >
                                                        <Bell className="mr-1 h-3 w-3" />
                                                        Remind
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center">
                                            <ShieldCheck className="mx-auto mb-2 h-12 w-12 text-muted-foreground/50" />
                                            <p className="text-sm text-muted-foreground">No vehicles found.</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
