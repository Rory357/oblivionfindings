import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Warehouse, Download, ArrowLeft, AlertTriangle, CheckCircle2, LayoutGrid, Package } from 'lucide-react';
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

export default function FacilityReports({ facilities, stats, dateRange }: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: 'Facilities', href: '/sites/reports/facilities' },
        ]}>>
            <Head title="Facility Reports" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <Button asChild variant="ghost" size="sm" className="mb-2">
                            <Link href="/sites/reports">
                                <ArrowLeft className="w-4 h-4 mr-1" />
                                Back
                            </Link>
                        </Button>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <Warehouse className="w-5 h-5 text-amber-400" />
                            Facility Reports
                        </h1>
                        <p className="text-sm text-slate-400">
                            Equipment safety, zone utilization, and compliance
                        </p>
                    </div>
                    <Button variant="outline">
                        <Download className="w-4 h-4 mr-1" />
                        Export CSV
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_facilities}</div>
                            <div className="text-sm text-slate-400">Facilities</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_zones}</div>
                            <div className="text-sm text-slate-400">Zones</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_assets}</div>
                            <div className="text-sm text-slate-400">Assets</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">{stats.open_hazards}</div>
                            <div className="text-sm text-slate-400">Open Hazards</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-orange-500/5 border-orange-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-orange-400">{stats.equipment_failures}</div>
                            <div className="text-sm text-slate-400">Equipment Issues</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">{stats.safety_walkthrough_completion}%</div>
                            <div className="text-sm text-slate-400">Walkthroughs</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Date Filter */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Date Range</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-4">
                            <div>
                                <Label className="text-xs">From</Label>
                                <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            </div>
                            <div>
                                <Label className="text-xs">To</Label>
                                <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Facilities List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Facility Details ({facilities.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-2">
                            {facilities.map(facility => {
                                const openHazards = facility.hazards.filter(h => h.status === 'open');
                                const equipmentIssues = facility.hazards.filter(h => h.hazard_type === 'equipment');
                                const completedRuns = facility.checklist_runs.filter(r => r.status === 'completed').length;
                                const totalRuns = facility.checklist_runs.length;
                                const completionRate = totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;

                                return (
                                    <div key={facility.id} className="flex items-center justify-between p-3 rounded-lg border border-slate-700 hover:bg-slate-800/50">
                                        <div>
                                            <div className="font-medium">{facility.name}</div>
                                            <div className="text-sm text-slate-400 flex items-center gap-3">
                                                <span className="flex items-center gap-1">
                                                    <LayoutGrid className="w-3.5 h-3.5" />
                                                    {facility.facility_zones_count} zones
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Package className="w-3.5 h-3.5" />
                                                    {facility.assets_count} assets
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {equipmentIssues.length > 0 && (
                                                <Badge className="bg-orange-500/20 text-orange-400">
                                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                                    {equipmentIssues.length} Equipment
                                                </Badge>
                                            )}
                                            {openHazards.length > 0 && (
                                                <Badge variant="outline" className="text-amber-400">
                                                    {openHazards.length} Open
                                                </Badge>
                                            )}
                                            <Badge variant="outline" className={completionRate >= 80 ? 'text-emerald-400' : completionRate >= 50 ? 'text-amber-400' : 'text-red-400'}>
                                                <CheckCircle2 className="w-3 h-3 mr-1" />
                                                {completionRate}%
                                            </Badge>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/sites/${facility.id}`}>View</Link>
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
