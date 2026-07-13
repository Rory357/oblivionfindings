import { FLEET_COLORS, HorizontalBarChart } from '@/components/fleet-charts';
import { FleetStatCard } from '@/components/fleet-stat-card';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import PageShell from '@/components/page-shell';
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
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import {
    formatCurrency,
    formatDate,
    formatDistance,
} from '@/lib/fleet-utils';
import { toDateInput } from '@/lib/datetime';
import { Head, router, useForm } from '@inertiajs/react';
import {
    CheckCircle,
    ChevronDown,
    ChevronUp,
    ChevronsUpDown,
    Download,
    Fuel,
    Plus,
    TrendingDown,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';

type FuelLog = {
    id: number;
    logged_at: string | null;
    asset: { id: number; name: string; asset_tag?: string } | null;
    odometer_km: number | null;
    quantity_litres: number;
    total_cost: number;
    cost_per_litre: number;
    fuel_type: string | null;
    station_name: string | null;
    notes: string | null;
    user: { id: number; name: string } | null;
};

type Vehicle = {
    id: number;
    name: string;
};

type EfficiencyRow = {
    vehicle: string;
    asset_id: number;
    km_per_litre: number;
    total_litres: number;
    total_distance_km: number;
};

type Props = {
    fuel_logs: {
        data: FuelLog[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        meta: { current_page: number; last_page: number; total: number };
    };
    vehicles: Vehicle[];
    filters: {
        date_from?: string;
        date_to?: string;
        asset_id?: string;
    };
    summary: {
        total_fill_ups: number;
        total_litres: number;
        total_cost: number;
        avg_cost_per_litre: number;
        best_efficiency: EfficiencyRow | null;
        worst_efficiency: EfficiencyRow | null;
    };
    hero?: {
        spend_month: number;
        litres_month: number;
        entries_30d: number;
        avg_cost_per_litre: number;
    };
    efficiency: EfficiencyRow[];
    can: {
        log_fuel: boolean;
    };
};

const fuelLogSteps = [
    {
        key: 'purchase',
        label: 'Vehicle & purchase',
        blurb: 'Record the fill-up details',
        icon: Fuel,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
        icon: CheckCircle,
    },
] as const;

export default function FuelIndex({
    fuel_logs: rawFuelLogs,
    vehicles: rawVehicles,
    filters: rawFilters,
    summary: rawSummary,
    hero: rawHero,
    efficiency: rawEfficiency,
    can,
}: Props) {
    const fuelLogs = rawFuelLogs?.data ?? [];
    const meta = rawFuelLogs?.meta ?? {
        current_page: 1,
        last_page: 1,
        total: 0,
    };
    const links = rawFuelLogs?.links ?? [];
    const vehicles = rawVehicles ?? [];
    const filters = rawFilters ?? {};
    const summary = rawSummary ?? {
        total_fill_ups: 0,
        total_litres: 0,
        total_cost: 0,
        avg_cost_per_litre: 0,
        best_efficiency: null,
        worst_efficiency: null,
    };
    const efficiency = rawEfficiency ?? [];
    const hero = rawHero ?? {
        spend_month: 0,
        litres_month: 0,
        entries_30d: 0,
        avg_cost_per_litre: 0,
    };

    // Local-date strings for the hero tile drill-down links.
    const localDay = (offset = 0) => {
        const d = new Date();
        d.setDate(d.getDate() - offset);
        return toDateInput(d);
    };
    const monthStartStr = () => {
        const d = new Date();
        d.setDate(1);
        return toDateInput(d);
    };
    const monthHref = `/fleet-assets/fuel?date_from=${monthStartStr()}`;
    const last30Href = `/fleet-assets/fuel?date_from=${localDay(30)}`;

    const [dialogOpen, setDialogOpen] = useState(false);
    const [fuelStepIndex, setFuelStepIndex] = useState(0);
    const [sortField, setSortField] = useState<string>('');
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

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
                <div
                    className={`flex items-center gap-1 ${className?.includes('text-right') ? 'justify-end' : ''}`}
                >
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

    const form = useForm({
        asset_id: '',
        logged_at: toDateInput(new Date()),
        odometer_km: '',
        quantity_litres: '',
        total_cost: '',
        fuel_type: 'diesel',
        station_name: '',
        notes: '',
        full_tank: false,
    });

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get(
            '/fleet-assets/fuel',
            {
                ...filters,
                ...newFilters,
                page: 1,
            },
            { preserveState: true },
        );
    };

    const csvHref = () => {
        const params = new URLSearchParams();
        if (filters.date_from) params.set('date_from', filters.date_from);
        if (filters.date_to) params.set('date_to', filters.date_to);
        if (filters.asset_id) params.set('asset_id', filters.asset_id);
        params.set('export', 'csv');
        return `/fleet-assets/fuel?${params.toString()}`;
    };

    const handleSubmit = () => {
        form.post('/fleet-assets/fuel', {
            onSuccess: () => {
                setDialogOpen(false);
                setFuelStepIndex(0);
                form.reset();
            },
        });
    };

    const closeFuelDialog = () => {
        setFuelStepIndex(0);
        setDialogOpen(false);
    };
    const canReviewFuel = Boolean(
        form.data.asset_id &&
            form.data.logged_at &&
            form.data.quantity_litres &&
            form.data.total_cost,
    );
    const selectedFuelVehicle = vehicles.find(
        (vehicle) => String(vehicle.id) === form.data.asset_id,
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Fuel Logs', href: '/fleet-assets/fuel' },
            ]}
        >
            <Head title="Fuel Management" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Fuel} />
                        <div className="min-w-0">
                            <HeroStatusPill>Fuel register · this month</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Fuel Management
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Track fuel consumption, costs, and vehicle
                                efficiency.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                href={monthHref}
                                label="Spend this month"
                                value={formatCurrency(hero.spend_month)}
                                caption="fuel purchases (NZD)"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={monthHref}
                                label="Litres this month"
                                value={fmt(hero.litres_month, ' L')}
                                caption="fuel consumed"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={last30Href}
                                label="Entries 30d"
                                value={fmt(hero.entries_30d)}
                                caption="fill-ups logged"
                                tone={hero.entries_30d > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                label="Avg $/L"
                                value={`$${(hero.avg_cost_per_litre ?? 0).toFixed(3)}`}
                                caption="month to date"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {can.log_fuel && (
                            <Button
                                size="sm"
                                className="bg-primary-foreground font-extrabold text-primary shadow-sm hover:bg-primary-foreground/90"
                                onClick={() => {
                                    setFuelStepIndex(0);
                                    setDialogOpen(true);
                                }}
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Log fuel
                            </Button>
                        )}
                        <FleetHeroAction href={csvHref()} icon={Download} external>
                            Export CSV
                        </FleetHeroAction>
                    </div>
                </HeroShell>

                {can.log_fuel && (
                    <WizardShell
                        open={dialogOpen}
                        onClose={closeFuelDialog}
                        title="Log fuel purchase"
                        description="Record a Fleet fuel purchase and review it before saving."
                        railIcon={Fuel}
                        railTitle="Fuel log"
                        railSub="Fleet operations"
                        steps={fuelLogSteps}
                        stepIndex={fuelStepIndex}
                        onStepClick={(index) => {
                            if (index === 0 || canReviewFuel) setFuelStepIndex(index);
                        }}
                        footerStart={
                            <Button type="button" variant="outline" onClick={closeFuelDialog}>
                                Cancel
                            </Button>
                        }
                        footerEnd={
                            fuelStepIndex === 0 ? (
                                <Button
                                    type="button"
                                    disabled={!canReviewFuel}
                                    onClick={() => setFuelStepIndex(1)}
                                >
                                    Continue
                                </Button>
                            ) : (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setFuelStepIndex(0)}
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={handleSubmit}
                                        disabled={form.processing}
                                    >
                                        {form.processing ? 'Saving…' : 'Save fuel log'}
                                    </Button>
                                </>
                            )
                        }
                    >
                        {fuelStepIndex === 0 ? (
                            <WizardStepPane>
                                            <div className="grid gap-4 py-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="asset_id">
                                                        Vehicle *
                                                    </Label>
                                                    <Select
                                                        value={
                                                            form.data.asset_id
                                                        }
                                                        onValueChange={(v) =>
                                                            form.setData(
                                                                'asset_id',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger id="asset_id">
                                                            <SelectValue placeholder="Select vehicle" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {vehicles.map(
                                                                (v) => (
                                                                    <SelectItem
                                                                        key={
                                                                            v.id
                                                                        }
                                                                        value={String(
                                                                            v.id,
                                                                        )}
                                                                    >
                                                                        {v.name}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {form.errors.asset_id && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                form.errors
                                                                    .asset_id
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="logged_at">
                                                            Date *
                                                        </Label>
                                                        <Input
                                                            id="logged_at"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .logged_at
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'logged_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        {form.errors
                                                            .logged_at && (
                                                            <p className="text-xs text-destructive">
                                                                {
                                                                    form.errors
                                                                        .logged_at
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="odometer_km">
                                                            Odometer (km)
                                                        </Label>
                                                        <Input
                                                            id="odometer_km"
                                                            type="number"
                                                            step="0.1"
                                                            placeholder="e.g. 45230"
                                                            value={
                                                                form.data
                                                                    .odometer_km
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'odometer_km',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="quantity_litres">
                                                            Litres *
                                                        </Label>
                                                        <Input
                                                            id="quantity_litres"
                                                            type="number"
                                                            step="0.01"
                                                            placeholder="e.g. 55.5"
                                                            value={
                                                                form.data
                                                                    .quantity_litres
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'quantity_litres',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        {form.errors
                                                            .quantity_litres && (
                                                            <p className="text-xs text-destructive">
                                                                {
                                                                    form.errors
                                                                        .quantity_litres
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="total_cost">
                                                            Total Cost ($) *
                                                        </Label>
                                                        <Input
                                                            id="total_cost"
                                                            type="number"
                                                            step="0.01"
                                                            placeholder="e.g. 120.50"
                                                            value={
                                                                form.data
                                                                    .total_cost
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'total_cost',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        {form.errors
                                                            .total_cost && (
                                                            <p className="text-xs text-destructive">
                                                                {
                                                                    form.errors
                                                                        .total_cost
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="fuel_type">
                                                            Fuel Type
                                                        </Label>
                                                        <Select
                                                            value={
                                                                form.data
                                                                    .fuel_type
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                form.setData(
                                                                    'fuel_type',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger id="fuel_type">
                                                                <SelectValue placeholder="Select type" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="petrol">
                                                                    Petrol
                                                                </SelectItem>
                                                                <SelectItem value="diesel">
                                                                    Diesel
                                                                </SelectItem>
                                                                <SelectItem value="electric">
                                                                    Electric
                                                                </SelectItem>
                                                                <SelectItem value="hybrid">
                                                                    Hybrid
                                                                </SelectItem>
                                                                <SelectItem value="lpg">
                                                                    LPG
                                                                </SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="grid gap-2">
                                                        <Label htmlFor="station_name">
                                                            Station
                                                        </Label>
                                                        <Input
                                                            id="station_name"
                                                            placeholder="e.g. BP Penrose"
                                                            value={
                                                                form.data
                                                                    .station_name
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'station_name',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="notes">
                                                        Notes
                                                    </Label>
                                                    <Input
                                                        id="notes"
                                                        placeholder="Optional notes..."
                                                        value={form.data.notes}
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <input
                                                        id="full_tank"
                                                        type="checkbox"
                                                        className="rounded border-border"
                                                        checked={
                                                            form.data.full_tank
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'full_tank',
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor="full_tank"
                                                        className="text-sm font-normal"
                                                    >
                                                        Full tank fill-up
                                                    </Label>
                                                </div>
                                            </div>
                            </WizardStepPane>
                        ) : (
                            <WizardStepPane>
                                <dl className="grid gap-4 rounded-xl border border-border bg-card/70 p-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="text-muted-foreground">Vehicle</dt>
                                        <dd className="font-medium">
                                            {selectedFuelVehicle?.name ?? 'Selected vehicle'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Date</dt>
                                        <dd className="font-medium">{form.data.logged_at}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Fuel</dt>
                                        <dd className="font-medium">
                                            {form.data.quantity_litres} L
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">Total cost</dt>
                                        <dd className="font-medium">${form.data.total_cost}</dd>
                                    </div>
                                </dl>
                            </WizardStepPane>
                        )}
                    </WizardShell>
                )}

                {/* Dark KPI Cards - 2 rows of 3 */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <FleetStatCard
                        label="FILL-UPS (MTD)"
                        value={summary.total_fill_ups ?? 0}
                        icon={Fuel}
                        subtitle="This month"
                    />
                    <FleetStatCard
                        label="BEST EFFICIENCY"
                        value={
                            summary.best_efficiency
                                ? `${summary.best_efficiency.km_per_litre} km/L`
                                : '---'
                        }
                        icon={TrendingUp}
                        color="amber"
                        subtitle={summary.best_efficiency?.vehicle ?? ''}
                    />
                    <FleetStatCard
                        label="WORST EFFICIENCY"
                        value={
                            summary.worst_efficiency
                                ? `${summary.worst_efficiency.km_per_litre} km/L`
                                : '---'
                        }
                        icon={TrendingDown}
                        color="red"
                        subtitle={summary.worst_efficiency?.vehicle ?? ''}
                    />
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Input
                        type="date"
                        className="w-40"
                        value={filters.date_from ?? ''}
                        onChange={(e) =>
                            applyFilters({
                                date_from: e.target.value || undefined,
                            })
                        }
                        placeholder="From date"
                    />
                    <Input
                        type="date"
                        className="w-40"
                        value={filters.date_to ?? ''}
                        onChange={(e) =>
                            applyFilters({
                                date_to: e.target.value || undefined,
                            })
                        }
                        placeholder="To date"
                    />
                    <Select
                        value={filters.asset_id ?? 'all'}
                        onValueChange={(v) =>
                            applyFilters({
                                asset_id: v === 'all' ? undefined : v,
                            })
                        }
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="All vehicles" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All vehicles</SelectItem>
                            {vehicles.map((v) => (
                                <SelectItem key={v.id} value={String(v.id)}>
                                    {v.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Fuel Log Table + Efficiency Chart side by side */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Fuel Log Table */}
                    <div className="overflow-hidden rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                    {renderSortHeader('logged_at', 'Date')}
                                    <th className="px-4 py-3 text-left font-medium">
                                        Vehicle
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Odometer
                                    </th>
                                    {renderSortHeader(
                                        'quantity_litres',
                                        'Litres',
                                        'text-right',
                                    )}
                                    {renderSortHeader(
                                        'total_cost',
                                        'Cost',
                                        'text-right',
                                    )}
                                    <th className="px-4 py-3 text-right font-medium">
                                        Cost/L
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Logged By
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {fuelLogs.length > 0 ? (
                                    fuelLogs.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="border-b transition-colors hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                {log.logged_at
                                                    ? formatDate(log.logged_at)
                                                    : '---'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Fuel className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">
                                                        {log.asset?.name ??
                                                            '---'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {log.odometer_km != null
                                                    ? `${formatDistance(Number(log.odometer_km))}`
                                                    : '---'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {Number(
                                                    log.quantity_litres ?? 0,
                                                ).toFixed(1)}{' '}
                                                L
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                $
                                                {Number(
                                                    log.total_cost ?? 0,
                                                ).toFixed(2)}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                $
                                                {Number(
                                                    log.cost_per_litre ?? 0,
                                                ).toFixed(3)}
                                            </td>
                                            <td className="px-4 py-3">
                                                {log.user?.name ?? '---'}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No fuel logs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Efficiency Chart */}
                    {efficiency.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm">
                                    Fuel Efficiency (km/L)
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <HorizontalBarChart
                                    items={efficiency.map((row) => ({
                                        label: row.vehicle,
                                        value: Number(
                                            (row.km_per_litre ?? 0).toFixed(1),
                                        ),
                                        color: FLEET_COLORS.primary,
                                    }))}
                                    color={FLEET_COLORS.primary}
                                />
                            </CardContent>
                        </Card>
                    )}
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
