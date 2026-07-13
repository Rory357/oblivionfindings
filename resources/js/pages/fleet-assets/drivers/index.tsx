import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/fleet-utils';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, router } from '@inertiajs/react';
import {
    Car,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Download,
    Search,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useState } from 'react';

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
    hero: {
        total: number;
        active: number;
        expiring_30: number;
        at_risk: number;
        licence_expired: number;
        sessions_today: number;
    };
    filters: { search?: string; status?: string };
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
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
    const diff =
        (new Date(dateStr).getTime() - new Date().getTime()) /
        (1000 * 60 * 60 * 24);
    return Math.ceil(diff);
}

function getLicenceRowClass(driver: Driver): string {
    const days = getLicenceExpiryDays(
        driver.eligibility?.licence_expires_at ?? null,
    );
    if (days === null) return '';
    if (days < 0) return 'bg-status-critical hover:bg-status-critical';
    if (days <= 30) return 'bg-status-warning hover:bg-status-warning';
    if (days <= 60) return 'bg-status-warning hover:bg-status-warning';
    return '';
}

export default function DriversIndex({
    drivers: rawDrivers,
    hero,
    filters: rawFilters,
}: Props) {
    const drivers = rawDrivers?.data ?? [];
    const meta = rawDrivers?.meta ?? {
        current_page: 1,
        last_page: 1,
        total: 0,
    };
    const links = rawDrivers?.links ?? [];
    const filters = rawFilters ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';
        setSortField(field);
        setSortDir(newDir);
        router.get(
            window.location.pathname,
            { ...filters, sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
                onClick={() => handleSort(field)}
            >
                <div className="flex items-center gap-1">
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </div>
            </th>
        );
    };

    const handleSearch = () => {
        router.get(
            '/fleet-assets/drivers',
            { ...filters, search, page: 1 },
            { preserveState: true },
        );
    };

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get(
            '/fleet-assets/drivers',
            { ...filters, ...newFilters, page: 1 },
            { preserveState: true },
        );
    };

    const heroStats = hero ?? {
        total: 0,
        active: 0,
        expiring_30: 0,
        at_risk: 0,
        licence_expired: 0,
        sessions_today: 0,
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Drivers', href: '/fleet-assets/drivers' },
            ]}
        >
            <Head title="Drivers" />
            <PageShell>
                <HeroShell
                    footer={
                        <HeroSummaryStrip label="Licence compliance">
                            <HeroSummaryMetric tone={heroStats.licence_expired > 0 ? 'critical' : 'success'}>
                                {heroStats.licence_expired > 0
                                    ? `${heroStats.licence_expired} licence${heroStats.licence_expired !== 1 ? 's' : ''} expired`
                                    : 'No expired licences'}
                            </HeroSummaryMetric>
                            <HeroSummaryMetric tone={heroStats.expiring_30 > 0 ? 'warning' : 'success'}>
                                {heroStats.expiring_30 > 0
                                    ? `${heroStats.expiring_30} expiring within 30 days`
                                    : 'None expiring within 30 days'}
                            </HeroSummaryMetric>
                            <HeroSummaryMetric tone="neutral">
                                {heroStats.active} of {heroStats.total} drivers fully eligible
                            </HeroSummaryMetric>
                        </HeroSummaryStrip>
                    }
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={ShieldCheck} />
                        <div className="min-w-0">
                            <HeroStatusPill>Driver compliance · licence watch</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Drivers</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Manage fleet drivers, licences, and assignments.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href="/fleet-assets/drivers?status=eligible"
                                label="Active drivers"
                                value={fmt(heroStats.active)}
                                caption="eligible to drive"
                                tone={heroStats.active > 0 ? 'success' : 'warning'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/drivers?status=expiring_30"
                                label="Expiring 30d"
                                value={fmt(heroStats.expiring_30)}
                                caption="licences due to renew"
                                tone={heroStats.expiring_30 > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                href="/fleet-assets/drivers?status=at_risk"
                                label="Expired / suspended"
                                value={fmt(heroStats.at_risk)}
                                caption="must not drive"
                                tone={heroStats.at_risk > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                label="Sessions today"
                                value={fmt(heroStats.sessions_today)}
                                caption="driving sessions started"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <FleetHeroAction href="/fleet-assets/drivers?export=csv" icon={Download} external>
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {/* Search & Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="relative sm:max-w-xs">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search drivers..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && handleSearch()
                            }
                            className="pl-9"
                        />
                    </div>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) =>
                            applyFilters({ status: v === 'all' ? '' : v })
                        }
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
                            <SelectItem value="expiring_soon">
                                Expiring Soon (60d)
                            </SelectItem>
                            <SelectItem value="expiring_30">
                                Expiring Soon (30d)
                            </SelectItem>
                            <SelectItem value="at_risk">
                                Expired / Suspended
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <div data-fleet-narrow-strategy="horizontal-scroll" className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                {renderSortHeader('name', 'Name')}
                                <th className="px-4 py-3 text-left font-medium">
                                    Licence Class
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Expiry
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Assigned Vehicles
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Sessions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {drivers.length > 0 ? (
                                drivers.map((driver) => {
                                    const expiryDays = getLicenceExpiryDays(
                                        driver.eligibility
                                            ?.licence_expires_at ?? null,
                                    );
                                    return (
                                        <tr
                                            key={driver.id}
                                            className={`cursor-pointer border-b ${getLicenceRowClass(driver) || 'hover:bg-muted/30'}`}
                                            onClick={() =>
                                                router.visit(
                                                    `/fleet-assets/drivers/${driver.id}`,
                                                )
                                            }
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <User className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">
                                                        {driver.name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                {driver.eligibility
                                                    ?.licence_class ?? '---'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    {driver.eligibility
                                                        ?.licence_expires_at
                                                        ? formatDate(
                                                              driver.eligibility
                                                                  .licence_expires_at,
                                                          )
                                                        : '---'}
                                                    {expiryDays !== null &&
                                                        expiryDays < 0 && (
                                                            <Badge
                                                                variant="destructive"
                                                                className="text-[10px]"
                                                            >
                                                                Expired
                                                            </Badge>
                                                        )}
                                                    {expiryDays !== null &&
                                                        expiryDays >= 0 &&
                                                        expiryDays <= 30 && (
                                                            <Badge className="bg-status-warning text-[10px] text-white">
                                                                {expiryDays}d
                                                                left
                                                            </Badge>
                                                        )}
                                                    {expiryDays !== null &&
                                                        expiryDays > 30 &&
                                                        expiryDays <= 60 && (
                                                            <Badge className="bg-status-warning text-[10px] text-white">
                                                                {expiryDays}d
                                                                left
                                                            </Badge>
                                                        )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant={statusVariant(
                                                        driver.eligibility
                                                            ?.status ?? '',
                                                    )}
                                                >
                                                    {driver.eligibility
                                                        ?.status ?? 'unknown'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                {(
                                                    driver.assigned_vehicles ??
                                                    []
                                                ).length > 0 ? (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Car className="h-3 w-3" />
                                                        {(
                                                            driver.assigned_vehicles ??
                                                            []
                                                        )
                                                            .map((v) => v.name)
                                                            .join(', ')}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        ---
                                                    </span>
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
                                        <FleetEmptyState
                                            icon={User}
                                            title="No drivers found"
                                            description="Drivers appear here when staff have driver eligibility set up in HR."
                                        />
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
