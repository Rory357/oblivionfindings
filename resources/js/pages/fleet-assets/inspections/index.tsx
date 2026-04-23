import { FleetStatCard } from '@/components/fleet-stat-card';
import { FLEET_COLORS, ProgressRing } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
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
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle, ClipboardCheck, Eye, FileCheck, Plus, Search, XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDistance } from '@/lib/fleet-utils';


type Inspection = {
    id: number;
    type: string;
    asset: { id: number; name: string; registration_number?: string | null } | null;
    user: { id: number; name: string } | null;
    passed: boolean;
    notes: string | null;
    odometer: number | null;
    overall_condition: string | null;
    completed_at: string | null;
    created_at: string | null;
};

type Vehicle = { id: number; name: string };

type Props = {
    inspections: Inspection[];
    vehicles: Vehicle[];
    filters: {
        search?: string;
        vehicle_id?: string;
        result?: string;
        date_from?: string;
        date_to?: string;
    };
    can: {
        manage: boolean;
    };
};

function resultBadge(passed: boolean) {
    return passed ? (
        <Badge variant="default" className="bg-green-600"><CheckCircle className="mr-1 h-3 w-3" /> Pass</Badge>
    ) : (
        <Badge variant="destructive"><XCircle className="mr-1 h-3 w-3" /> Fail</Badge>
    );
}

function typeBadge(type: string) {
    if (type === 'pre-trip') return <Badge variant="outline">Pre-Trip</Badge>;
    if (type === 'post-trip') return <Badge variant="secondary">Post-Trip</Badge>;
    return <Badge variant="outline">{type}</Badge>;
}

export default function InspectionsIndex({ inspections, vehicles, filters, can }: Props) {
    const allInspections = inspections ?? [];
    const totalCount = allInspections.length;
    const passedCount = allInspections.filter((i) => i.passed).length;
    const failedCount = allInspections.filter((i) => !i.passed).length;
    const passRate = totalCount > 0 ? Math.round((passedCount / totalCount) * 100) : 0;

    const [search, setSearch] = useState(filters?.search ?? '');
    const [vehicleId, setVehicleId] = useState(filters?.vehicle_id ?? '');
    const [result, setResult] = useState(filters?.result ?? '');
    const [dateFrom, setDateFrom] = useState(filters?.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters?.date_to ?? '');

    const applyFilters = () => {
        router.get('/fleet-assets/inspections', {
            search: search || undefined, vehicle_id: vehicleId || undefined,
            result: result || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined,
        }, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setSearch(''); setVehicleId(''); setResult(''); setDateFrom(''); setDateTo('');
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
                <FleetHero
                    title="Vehicle Inspections"
                    actions={can.manage ? (
                        <Button asChild>
                            <Link href="/fleet-assets/inspections/create">
                                <Plus className="mr-2 h-4 w-4" /> New Inspection
                            </Link>
                        </Button>
                    ) : undefined}
                />

                {/* Dark KPI Cards + ProgressRing */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    <FleetStatCard label="TOTAL INSPECTIONS" value={totalCount} icon={ClipboardCheck} subtitle="All inspections" />
                    <FleetStatCard label="PASSED" value={passedCount} icon={CheckCircle} color="amber" valueClassName="text-green-400" subtitle="Passed inspections" />
                    <FleetStatCard label="FAILED" value={failedCount} icon={XCircle} color="red" valueClassName="text-red-400" subtitle="Failed inspections" />
                    <FleetStatCard label="PENDING" value={0} icon={FileCheck} subtitle="Awaiting review" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/20">
                        <CardContent className="flex items-center justify-center p-4">
                            <ProgressRing value={passRate} size={80} color={FLEET_COLORS.primary} label="Pass Rate" />
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[180px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">Search</label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Inspector name..." className="pl-8" onKeyDown={(e) => e.key === 'Enter' && applyFilters()} />
                                </div>
                            </div>
                            <div className="min-w-[160px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">Vehicle</label>
                                <Select value={vehicleId || '__all__'} onValueChange={(v) => setVehicleId(v === '__all__' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="All vehicles" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All vehicles</SelectItem>
                                        {(vehicles ?? []).map((v) => (<SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[120px]">
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">Result</label>
                                <Select value={result || '__all__'} onValueChange={(v) => setResult(v === '__all__' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="All" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__all__">All</SelectItem>
                                        <SelectItem value="pass">Pass</SelectItem>
                                        <SelectItem value="fail">Fail</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">From</label>
                                <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">To</label>
                                <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            </div>
                            <Button onClick={applyFilters} size="sm">Filter</Button>
                            <Button variant="ghost" size="sm" onClick={clearFilters}>Clear</Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Inspections Table */}
                <Card>
                    <CardContent className="p-0">
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
                                        <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                                            <ClipboardCheck className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                            No inspections found.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {allInspections.map((insp) => (
                                    <TableRow key={insp.id} className="transition-colors hover:bg-muted/30 transition-colors">
                                        <TableCell className="whitespace-nowrap text-sm">
                                            {insp.completed_at ? formatDate(insp.completed_at)
                                                : insp.created_at ? formatDate(insp.created_at) : '---'}
                                        </TableCell>
                                        <TableCell>
                                            {insp.asset ? (
                                                <Link href={`/fleet-assets/vehicles/${insp.asset.id}`} className="text-primary hover:underline">{insp.asset.name}</Link>
                                            ) : '---'}
                                        </TableCell>
                                        <TableCell>{insp.user?.name ?? '---'}</TableCell>
                                        <TableCell>{typeBadge(insp.type ?? 'inspection')}</TableCell>
                                        <TableCell>{resultBadge(insp.passed)}</TableCell>
                                        <TableCell className="capitalize">{insp.overall_condition ?? '---'}</TableCell>
                                        <TableCell>{insp.odometer != null ? `${formatDistance(insp.odometer)}` : '---'}</TableCell>
                                        <TableCell>
                                            <Button variant="ghost" size="icon" asChild>
                                                <Link href={`/fleet-assets/inspections/${insp.id}`}><Eye className="h-4 w-4" /></Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
