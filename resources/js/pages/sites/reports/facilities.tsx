import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    Home,
    LayoutGrid,
    Package,
} from 'lucide-react';
import { useState } from 'react';

type Facility = {
    id: number;
    name: string;
    facility_zones_count: number;
    assets_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
        hazard_type: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
    }>;
};

type Props = {
    facilities: Facility[];
    stats: {
        total_facilities: number;
        total_zones: number;
        total_assets: number;
        open_hazards: number;
        equipment_failures: number;
        safety_walkthrough_completion: number;
    };
    dateRange: { from: string; to: string };
};

export default function FacilityReports({
    facilities,
    stats,
    dateRange,
}: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/sites/reports' },
                { title: 'Facilities', href: '/sites/reports/facilities' },
            ]}
        >
            <Head title="Facility Reports" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Home}
                        title="Facility Reports"
                        description="Equipment safety, zone utilization, and compliance"
                        stats={[
                            {
                                label: 'Facilities',
                                value: stats.total_facilities,
                            },
                            {
                                label: 'Open hazards',
                                value: stats.open_hazards,
                            },
                            {
                                label: 'Equipment issues',
                                value: stats.equipment_failures,
                            },
                            {
                                label: 'Walkthroughs',
                                value: `${stats.safety_walkthrough_completion}%`,
                            },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                variant="outline"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link
                                    href={`/sites/reports/export?type=facilities&format=csv&date_from=${dateFrom}&date_to=${dateTo}`}
                                >
                                    <Download className="mr-1 h-4 w-4" />
                                    Export CSV
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                {/* Date Filter */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Date Range</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-4">
                            <div>
                                <Label className="text-xs">From</Label>
                                <Input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) =>
                                        setDateFrom(e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label className="text-xs">To</Label>
                                <Input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                />
                            </div>
                            <div className="flex items-end">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.get(
                                            '/sites/reports/facilities',
                                            {
                                                date_from: dateFrom,
                                                date_to: dateTo,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Facilities List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Facility Details ({facilities.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-2">
                            {facilities.map((facility) => {
                                const openHazards = facility.hazards.filter(
                                    (h) => h.status === 'open',
                                );
                                const equipmentIssues = facility.hazards.filter(
                                    (h) => h.hazard_type === 'equipment',
                                );
                                const completedRuns =
                                    facility.checklist_runs.filter(
                                        (r) => r.status === 'completed',
                                    ).length;
                                const totalRuns =
                                    facility.checklist_runs.length;
                                const completionRate =
                                    totalRuns > 0
                                        ? Math.round(
                                              (completedRuns / totalRuns) * 100,
                                          )
                                        : 0;

                                return (
                                    <div
                                        key={facility.id}
                                        className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {facility.name}
                                            </div>
                                            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <LayoutGrid className="h-3.5 w-3.5" />
                                                    {
                                                        facility.facility_zones_count
                                                    }{' '}
                                                    zones
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Package className="h-3.5 w-3.5" />
                                                    {facility.assets_count}{' '}
                                                    assets
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {equipmentIssues.length > 0 && (
                                                <Badge className="bg-status-warning-bg text-status-warning">
                                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                                    {equipmentIssues.length}{' '}
                                                    Equipment
                                                </Badge>
                                            )}
                                            {openHazards.length > 0 && (
                                                <Badge
                                                    variant="outline"
                                                    className="text-status-warning"
                                                >
                                                    {openHazards.length} Open
                                                </Badge>
                                            )}
                                            <Badge
                                                variant="outline"
                                                className={
                                                    completionRate >= 80
                                                        ? 'text-status-success'
                                                        : completionRate >= 50
                                                          ? 'text-status-warning'
                                                          : 'text-status-critical'
                                                }
                                            >
                                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                                {completionRate}%
                                            </Badge>
                                            <Button
                                                asChild
                                                variant="ghost"
                                                size="sm"
                                            >
                                                <Link
                                                    href={`/sites/${facility.id}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
