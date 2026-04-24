import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Building2, Download, ArrowLeft, AlertTriangle, CheckCircle2, DoorOpen, Calendar } from 'lucide-react';
import { useState } from 'react';

type Office = {
    id: number;
    name: string;
    ho_resources_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
    }>;
    calendar_events: Array<{
        id: number;
    }>;
};

type Props = {
    offices: Office[];
    stats: {
        total_offices: number;
        total_rooms: number;
        room_bookings: number;
        open_hazards: number;
        safety_compliance_rate: number;
    };
    dateRange: { from: string; to: string };
};

export default function HeadOfficeReports({ offices, stats, dateRange }: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: 'Head Office', href: '/sites/reports/head-office' },
        ]}>
            <Head title="Head Office Reports" />

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
                            <Building2 className="w-5 h-5 text-status-info" />
                            Head Office Reports
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Room utilization, safety compliance, and facilities
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href={`/sites/reports/export?type=head_office&format=csv&date_from=${dateFrom}&date_to=${dateTo}`}>
                            <Download className="w-4 h-4 mr-1" />
                            Export CSV
                        </Link>
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_offices}</div>
                            <div className="text-sm text-muted-foreground">Offices</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.total_rooms}</div>
                            <div className="text-sm text-muted-foreground">Rooms</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{stats.room_bookings}</div>
                            <div className="text-sm text-muted-foreground">Bookings</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-status-critical border-status-critical/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-critical">{stats.open_hazards}</div>
                            <div className="text-sm text-muted-foreground">Open Hazards</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-status-success border-status-success/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">{stats.safety_compliance_rate}%</div>
                            <div className="text-sm text-muted-foreground">Safety Compliance</div>
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
                                    onClick={() => router.get('/sites/reports/head-office', { date_from: dateFrom, date_to: dateTo }, { preserveState: true })}
                                >
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Offices List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Head Office Details ({offices.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-2">
                            {offices.map(office => {
                                const openHazards = office.hazards.filter(h => h.status === 'open');
                                const completedRuns = office.checklist_runs.filter(r => r.status === 'completed').length;
                                const totalRuns = office.checklist_runs.length;
                                const completionRate = totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;

                                return (
                                    <div key={office.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50">
                                        <div>
                                            <div className="font-medium">{office.name}</div>
                                            <div className="text-sm text-muted-foreground flex items-center gap-3">
                                                <span className="flex items-center gap-1">
                                                    <DoorOpen className="w-3.5 h-3.5" />
                                                    {office.ho_resources_count} rooms
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="w-3.5 h-3.5" />
                                                    {office.calendar_events.length} bookings
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {openHazards.length > 0 && (
                                                <Badge className="bg-status-critical-bg text-status-critical">
                                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                                    {openHazards.length} Open
                                                </Badge>
                                            )}
                                            <Badge variant="outline" className={completionRate >= 80 ? 'text-status-success' : completionRate >= 50 ? 'text-status-warning' : 'text-status-critical'}>
                                                <CheckCircle2 className="w-3 h-3 mr-1" />
                                                {completionRate}%
                                            </Badge>
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/sites/${office.id}`}>View</Link>
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
