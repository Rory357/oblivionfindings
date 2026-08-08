import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDistance } from '@/lib/fleet-utils';
import {
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { FleetResponsiveTable } from '@/pages/fleet-assets/components/fleet-responsive-list';
import {
    InspectionCreateWizard,
    type WizardPreTripResult,
    type WizardVehicle,
} from '@/pages/fleet-assets/inspections/create-wizard';
import { HeroActionButton } from '@/pages/fleet-assets/maintenance/components/hero-action-button';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle,
    ClipboardCheck,
    Eye,
    Plus,
    Search,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Inspection = {
    id: number;
    type: string;
    asset: {
        id: number;
        name: string;
        registration_number?: string | null;
    } | null;
    user: { id: number; name: string } | null;
    passed: boolean;
    notes: string | null;
    odometer: number | null;
    overall_condition: string | null;
    completed_at: string | null;
    created_at: string | null;
};

type Props = {
    inspections: Inspection[];
    vehicles: WizardVehicle[];
    filters: {
        search?: string;
        vehicle_id?: string;
        result?: string;
        date_from?: string;
        date_to?: string;
    };
    stats?: {
        runs_30d: number;
        failed_30d: number;
        pass_rate: number | null;
    };
    preselected_asset_id?: number | string | null;
    preselected_type?: string;
    booking_id?: number | string | null;
    pre_trip_results?: WizardPreTripResult | null;
    can: {
        manage: boolean;
    };
};

function resultBadge(passed: boolean) {
    return passed ? (
        <Badge variant="default" className="bg-status-success">
            <CheckCircle className="mr-1 h-3 w-3" /> Pass
        </Badge>
    ) : (
        <Badge variant="destructive">
            <XCircle className="mr-1 h-3 w-3" /> Fail
        </Badge>
    );
}

function typeBadge(type: string) {
    if (type === 'pre-trip') return <Badge variant="outline">Pre-Trip</Badge>;
    if (type === 'post-trip')
        return <Badge variant="secondary">Post-Trip</Badge>;
    return <Badge variant="outline">{type}</Badge>;
}

export default function InspectionsIndex({
    inspections,
    vehicles,
    filters,
    stats,
    preselected_asset_id,
    preselected_type,
    booking_id,
    pre_trip_results,
    can,
}: Props) {
    const allInspections = inspections ?? [];
    const heroStats = stats ?? { runs_30d: 0, failed_30d: 0, pass_rate: null };

    const [wizardOpen, setWizardOpen] = useState(false);

    // Deep-link shim: /create redirects here with ?new=1 (opens the wizard modal).
    useEffect(() => {
        if (
            can.manage &&
            new URLSearchParams(window.location.search).get('new') === '1'
        ) {
            setWizardOpen(true);
        }
    }, [can.manage]);

    const [search, setSearch] = useState(filters?.search ?? '');
    const [vehicleId, setVehicleId] = useState(filters?.vehicle_id ?? '');
    const [result, setResult] = useState(filters?.result ?? '');
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');

    const applyFilters = () => {
        router.get(
            '/fleet-assets/inspections',
            {
                search: search || undefined,
                vehicle_id: vehicleId || undefined,
                result: result || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setVehicleId('');
        setResult('');
        setDateFrom('');
        setDateTo('');
        router.get('/fleet-assets/inspections', {}, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Inspections', href: '/fleet-assets/inspections' },
            ]}
        >
            <Head title="Vehicle Inspections" />
            <PageShell>
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={ClipboardCheck} />
                        <div className="min-w-0">
                            <HeroStatusPill>
                                Maintenance · inspections
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Vehicle Inspections
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Pre-trip and post-trip vehicle checks.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-3 gap-2 lg:ml-auto lg:max-w-xl">
                            <HeroClusterTile
                                label="Runs 30d"
                                value={fmt(heroStats.runs_30d)}
                                caption="inspections logged"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Failed 30d"
                                value={fmt(heroStats.failed_30d)}
                                caption="need follow-up"
                                tone={
                                    heroStats.failed_30d > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                label="Pass rate"
                                value={fmt(heroStats.pass_rate, '%')}
                                caption="last 30 days"
                                tone={
                                    heroStats.pass_rate === null
                                        ? 'neutral'
                                        : heroStats.pass_rate >= 90
                                          ? 'success'
                                          : 'warning'
                                }
                            />
                        </div>
                    </div>
                    {can.manage ? (
                        <div className="flex flex-wrap items-center gap-2">
                            <HeroActionButton
                                onClick={() => setWizardOpen(true)}
                                icon={Plus}
                                emphasis
                            >
                                New inspection
                            </HeroActionButton>
                        </div>
                    ) : null}
                </HeroShell>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[180px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    Search
                                </label>
                                <div className="relative">
                                    <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        placeholder="Inspector name..."
                                        className="pl-8"
                                        onKeyDown={(e) =>
                                            e.key === 'Enter' && applyFilters()
                                        }
                                    />
                                </div>
                            </div>
                            <div className="min-w-[160px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    Vehicle
                                </label>
                                <Select
                                    value={vehicleId || '__all__'}
                                    onValueChange={(v) =>
                                        setVehicleId(v === '__all__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All vehicles" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            All vehicles
                                        </SelectItem>
                                        {(vehicles ?? []).map((v) => (
                                            <SelectItem
                                                key={v.id}
                                                value={String(v.id)}
                                            >
                                                {v.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[120px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    Result
                                </label>
                                <Select
                                    value={result || '__all__'}
                                    onValueChange={(v) =>
                                        setResult(v === '__all__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">
                                            All
                                        </SelectItem>
                                        <SelectItem value="pass">
                                            Pass
                                        </SelectItem>
                                        <SelectItem value="fail">
                                            Fail
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    From
                                </label>
                                <Input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) =>
                                        setDateFrom(e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    To
                                </label>
                                <Input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                />
                            </div>
                            <Button onClick={applyFilters} size="sm">
                                Filter
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Inspections Table */}
                <Card>
                    <CardContent className="p-0">
                        <FleetResponsiveTable>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Vehicle</TableHead>
                                        <TableHead>Inspector</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Result</TableHead>
                                        <TableHead>Condition</TableHead>
                                        <TableHead>Odometer</TableHead>
                                        <TableHead className="w-[60px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {allInspections.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={8}
                                                className="py-12 text-center text-muted-foreground"
                                            >
                                                <ClipboardCheck className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                                No inspections found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {allInspections.map((insp) => (
                                        <TableRow
                                            key={insp.id}
                                            className="transition-colors hover:bg-muted/30"
                                        >
                                            <TableCell
                                                data-fleet-row-time
                                                className="text-sm whitespace-nowrap"
                                            >
                                                {insp.completed_at
                                                    ? formatDate(
                                                          insp.completed_at,
                                                      )
                                                    : insp.created_at
                                                      ? formatDate(
                                                            insp.created_at,
                                                        )
                                                      : '---'}
                                            </TableCell>
                                            <TableCell data-fleet-row-identity>
                                                {insp.asset ? (
                                                    <Link
                                                        href={`/fleet-assets/vehicles/${insp.asset.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {insp.asset.name}
                                                    </Link>
                                                ) : (
                                                    '---'
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {insp.user?.name ?? '---'}
                                            </TableCell>
                                            <TableCell>
                                                {typeBadge(
                                                    insp.type ?? 'inspection',
                                                )}
                                            </TableCell>
                                            <TableCell data-fleet-row-status>
                                                {resultBadge(insp.passed)}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                {insp.overall_condition ??
                                                    '---'}
                                            </TableCell>
                                            <TableCell>
                                                {insp.odometer != null
                                                    ? `${formatDistance(insp.odometer)}`
                                                    : '---'}
                                            </TableCell>
                                            <TableCell data-fleet-row-action>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/fleet-assets/inspections/${insp.id}`}
                                                    >
                                                        <Eye className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </FleetResponsiveTable>
                    </CardContent>
                </Card>

                {can.manage ? (
                    <InspectionCreateWizard
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        vehicles={vehicles ?? []}
                        preselectedAssetId={preselected_asset_id}
                        preselectedType={preselected_type}
                        bookingId={booking_id}
                        preTripResults={pre_trip_results}
                    />
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
