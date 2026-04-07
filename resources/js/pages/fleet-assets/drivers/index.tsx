import { FleetEmptyState } from '@/components/fleet-empty-state';
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
    Car,
    CheckCircle,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Clock,
    Download,
    Search,
    Shield,
    User,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate } from '@/lib/fleet-utils';


type Driver = {
    id: number;
    name: string;
    email: string | null;
    eligibility: {
        licence_class: string | null;
        licence_expires_at: string | null;
        status: string;
        can_drive_clients: boolean;
    } | null;
    assigned_vehicles: Array<{ id: number; name: string; asset_tag: string }>;
    session_count: number;
};

type PaginatedDrivers = {
    data: Driver[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: { current_page: number; last_page: number; total: number };
};

type Props = {
    drivers: PaginatedDrivers;
    filters: { search?: string; status?: string };
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'eligible':
            return 'default';
        case 'pending':
            return 'outline';
        case 'suspended':
            return 'destructive';
        case 'expired':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function getLicenceExpiryDays(dateStr: string | null): number | null {
    if (!dateStr) return null;
    const diff = (new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24);
    return Math.ceil(diff);
}

function getLicenceRowClass(driver: Driver): string {
    const days = getLicenceExpiryDays(driver.eligibility?.licence_expires_at ?? null);
    if (days === null) return '';
    if (days < 0) return 'bg-red-500/10 hover:bg-red-500/15';
    if (days <= 30) return 'bg-orange-500/10 hover:bg-orange-500/15';
    if (days <= 60) return 'bg-yellow-500/10 hover:bg-yellow-500/15';
    return '';
}

export default function DriversIndex({ drivers: rawDrivers, filters: rawFilters }: Props) {
    const drivers = rawDrivers?.data ?? [];
    const meta = rawDrivers?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const links = rawDrivers?.links ?? [];
    const filters = rawFilters ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

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

    const handleSearch = () => {
        router.get('/fleet-assets/drivers', { ...filters, search, page: 1 }, { preserveState: true });
    };

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get('/fleet-assets/drivers', { ...filters, ...newFilters, page: 1 }, { preserveState: true });
    };

    // Compute stats from the current page data
    const stats = {
        total: meta.total ?? drivers.length,
        eligible: drivers.filter((d) => d.eligibility?.status === 'eligible').length,
        pending: drivers.filter((d) => d.eligibility?.status === 'pending').length,
        suspended: drivers.filter((d) => d.eligibility?.status === 'suspended').length,
        expired: drivers.filter((d) => d.eligibility?.status === 'expired').length,
    };

    // Licence expiry warnings
    const expiredCount = drivers.filter((d) => {
        const days = getLicenceExpiryDays(d.eligibility?.licence_expires_at ?? null);
        return days !== null && days < 0;
    }).length;

    const expiringSoonCount = drivers.filter((d) => {
        const days = getLicenceExpiryDays(d.eligibility?.licence_expires_at ?? null);
        return days !== null && days >= 0 && days <= 60;
    }).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Drivers', href: '/fleet-assets/drivers' },
            ]}
        >
            <Head title="Drivers" />
            <PageShell>
                <FleetHero
                    title="Drivers"
                    description="Manage fleet drivers, licenses, and assignments."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <a href="/fleet-assets/drivers?export=csv">
                                <Download className="mr-2 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    }
                />

                {/* Expired license warning banner */}
                {(expiredCount > 0 || expiringSoonCount > 0) && (
                    <div className="rounded-lg border border-red-500/30 bg-red-500/10 p-4">
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-red-500" />
                            <div className="space-y-0.5">
                                {expiredCount > 0 && (
                                    <p className="text-sm font-medium text-red-600 dark:text-red-400">
                                        {expiredCount} driver{expiredCount !== 1 ? 's have' : ' has'} expired licences
                                    </p>
                                )}
                                {expiringSoonCount > 0 && (
                                    <p className="text-sm text-orange-600 dark:text-orange-400">
                                        {expiringSoonCount} driver{expiringSoonCount !== 1 ? 's have' : ' has'} licences expiring within 60 days
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Summary Cards + Safety Score Gauge */}
                <div className="grid gap-4 lg:grid-cols-[1fr,auto]">
                    <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Total</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{stats.total}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-1 text-sm font-medium text-purple-600">
                                    <CheckCircle className="h-4 w-4" /> Eligible
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{stats.eligible}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-1 text-sm font-medium text-amber-600">
                                    <Clock className="h-4 w-4" /> Pending
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{stats.pending}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-1 text-sm font-medium text-red-600">
                                    <XCircle className="h-4 w-4" /> Suspended
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{stats.suspended}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-1 text-sm font-medium text-red-600">
                                    <AlertTriangle className="h-4 w-4" /> Expired
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{stats.expired}</div>
                            </CardContent>
                        </Card>
                    </div>
                    <Card className="flex items-center justify-center px-6 py-4">
                        <HalfMoonGauge
                            value={stats.total > 0 ? Math.round((stats.eligible / stats.total) * 100) : 0}
                            label="Fleet Safety"
                            sublabel={`${stats.eligible} eligible of ${stats.total}`}
                            size={130}
                            color={FLEET_COLORS.primary}
                        />
                    </Card>
                </div>

                {/* Search & Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative sm:max-w-xs">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search drivers..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="pl-9"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Filter by status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="eligible">Eligible</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="expiring_soon">Expiring Soon</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <div className="rounded-lg border overflow-hidden">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                <SortHeader field="name">Name</SortHeader>
                                <th className="px-4 py-3 text-left font-medium">Licence Class</th>
                                <th className="px-4 py-3 text-left font-medium">Expiry</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Assigned Vehicles</th>
                                <th className="px-4 py-3 text-left font-medium">Sessions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {drivers.length > 0 ? (
                                drivers.map((driver) => {
                                    const expiryDays = getLicenceExpiryDays(driver.eligibility?.licence_expires_at ?? null);
                                    return (
                                        <tr
                                            key={driver.id}
                                            className={`border-b cursor-pointer ${getLicenceRowClass(driver) || 'hover:bg-muted/30'}`}
                                            onClick={() => router.visit(`/fleet-assets/drivers/${driver.id}`)}
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <User className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">{driver.name}</span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">{driver.eligibility?.licence_class ?? '---'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    {driver.eligibility?.licence_expires_at
                                                        ? formatDate(driver.eligibility.licence_expires_at)
                                                        : '---'}
                                                    {expiryDays !== null && expiryDays < 0 && (
                                                        <Badge variant="destructive" className="text-[10px]">Expired</Badge>
                                                    )}
                                                    {expiryDays !== null && expiryDays >= 0 && expiryDays <= 30 && (
                                                        <Badge className="bg-orange-500 text-white text-[10px]">{expiryDays}d left</Badge>
                                                    )}
                                                    {expiryDays !== null && expiryDays > 30 && expiryDays <= 60 && (
                                                        <Badge className="bg-yellow-500 text-white text-[10px]">{expiryDays}d left</Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant={statusVariant(driver.eligibility?.status ?? '')}>
                                                    {driver.eligibility?.status ?? 'unknown'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                {(driver.assigned_vehicles ?? []).length > 0 ? (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Car className="h-3 w-3" />
                                                        {(driver.assigned_vehicles ?? []).map((v) => v.name).join(', ')}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">---</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {driver.session_count ?? 0}
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12">
                                        <FleetEmptyState icon={User} title="No drivers found" description="Drivers appear here when staff have driver eligibility set up in HR." />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {(meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {links.map((link, i) => (
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
            </PageShell>
        </AppLayout>
    );
}
