import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Home, Download, ArrowLeft, AlertTriangle, CheckCircle2, BedDouble, Users } from 'lucide-react';
import { useState } from 'react';

type House = {
    id: number;
    name: string;
    region?: string;
    house_rooms_count: number;
    clients_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
        created_at: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
        completed_at?: string;
    }>;
};

type Props = {
    houses: House[];
    stats: {
        total_houses: number;
        total_bedrooms: number;
        total_clients: number;
        open_hazards: number;
        critical_hazards: number;
        checklist_completion_rate: number;
    };
    dateRange: { from: string; to: string };
    regions: string[];
};

const severityColors: Record<string, string> = {
    low: 'bg-slate-500/20 text-slate-400',
    medium: 'bg-yellow-500/20 text-yellow-400',
    high: 'bg-orange-500/20 text-orange-400',
    critical: 'bg-red-500/20 text-red-400',
};

export default function HouseReports({ houses, stats, dateRange, regions }: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: 'Houses', href: '/sites/reports/houses' },
        ]}>
            <Head title="House Reports" />

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
                            <Home className="w-5 h-5 text-emerald-400" />
                            House Reports
                        </h1>
                        <p className="text-sm text-slate-400">
                            Quality home checks, occupancy, and compliance
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={`/sites/reports/export?type=houses&format=csv&date_from=${dateFrom}&date_to=${dateTo}`}>
                            <Download className="w-4 h-4 mr-1" />
                            Export CSV
                        </Link>
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_houses}</div>
                            <div className="text-sm text-slate-400">Houses</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_bedrooms}</div>
                            <div className="text-sm text-slate-400">Bedrooms</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_clients}</div>
                            <div className="text-sm text-slate-400">Clients</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">{stats.open_hazards}</div>
                            <div className="text-sm text-slate-400">Open Hazards</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">{stats.critical_hazards}</div>
                            <div className="text-sm text-slate-400">Critical</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">{stats.checklist_completion_rate}%</div>
                            <div className="text-sm text-slate-400">Checklist Completion</div>
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
                            <div className="flex items-end">
                                <Button
                                    variant="outline"
                                    onClick={() => router.get('/sites/reports/houses', { date_from: dateFrom, date_to: dateTo }, { preserveState: true })}
                                >
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Houses List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">House Details ({houses.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-2">
                            {houses.map(house => {
                                const openHazards = house.hazards.filter(h => h.status === 'open');
                                const criticalHazards = openHazards.filter(h => h.severity === 'critical');
                                const completedRuns = house.checklist_runs.filter(r => r.status === 'completed').length;
                                const totalRuns = house.checklist_runs.length;
                                const completionRate = totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;

                                return (
                                    <div key={house.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50">
                                        <div>
                                            <div className="font-medium">{house.name}</div>
                                            <div className="text-sm text-slate-400 flex items-center gap-3">
                                                <span className="flex items-center gap-1">
                                                    <BedDouble className="w-3.5 h-3.5" />
                                                    {house.house_rooms_count} bedrooms
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Users className="w-3.5 h-3.5" />
                                                    {house.clients_count} clients
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {criticalHazards.length > 0 && (
                                                <Badge className="bg-red-500/20 text-red-400">
                                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                                    {criticalHazards.length} Critical
                                                </Badge>
                                            )}
                                            {openHazards.length > 0 && (
                                                <Badge variant="outline" className="text-amber-400">
                                                    {openHazards.length} Open Hazards
                                                </Badge>
                                            )}
                                            <Badge variant="outline" className={completionRate >= 80 ? 'text-emerald-400' : completionRate >= 50 ? 'text-amber-400' : 'text-red-400'}>
                                                <CheckCircle2 className="w-3 h-3 mr-1" />
                                                {completionRate}%
                                            </Badge>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/sites/${house.id}`}>View</Link>
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
