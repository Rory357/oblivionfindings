import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Home, Download, AlertTriangle, CheckCircle2, BedDouble, Users } from 'lucide-react';
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
    low: 'bg-muted-foreground/20 text-muted-foreground',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
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

            <PageLayout
                hero={
                    <PageHero
                        icon={Home}
                        title="House Reports"
                        description="Quality home checks, occupancy, and compliance"
                        stats={[
                            { label: 'Houses', value: stats.total_houses },
                            { label: 'Open hazards', value: stats.open_hazards },
                            { label: 'Critical hazards', value: stats.critical_hazards },
                            { label: 'Checklist completion', value: `${stats.checklist_completion_rate}%` },
                        ]}
                        actions={
                            <Button
                                size="sm"
                                variant="outline"
                                asChild
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Link href={`/sites/reports/export?type=houses&format=csv&date_from=${dateFrom}&date_to=${dateTo}`}>
                                    <Download className="w-4 h-4 mr-1" />
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
                                            <div className="text-sm text-muted-foreground flex items-center gap-3">
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
                                                <Badge className="bg-status-critical-bg text-status-critical">
                                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                                    {criticalHazards.length} Critical
                                                </Badge>
                                            )}
                                            {openHazards.length > 0 && (
                                                <Badge variant="outline" className="text-status-warning">
                                                    {openHazards.length} Open Hazards
                                                </Badge>
                                            )}
                                            <Badge variant="outline" className={completionRate >= 80 ? 'text-status-success' : completionRate >= 50 ? 'text-status-warning' : 'text-status-critical'}>
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
            </PageLayout>
        </AppLayout>
    );
}
