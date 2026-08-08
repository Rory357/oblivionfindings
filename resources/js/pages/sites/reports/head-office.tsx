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
    Building,
    Calendar,
    CheckCircle2,
    DoorOpen,
    Download,
} from 'lucide-react';
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

export default function HeadOfficeReports({
    offices,
    stats,
    dateRange,
}: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports', href: '/sites/reports' },
                { title: 'Head Office', href: '/sites/reports/head-office' },
            ]}
        >
            <Head title="Head Office Reports" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Building}
                        title="Head Office Reports"
                        description="Room utilization, safety compliance, and facilities"
                        stats={[
                            { label: 'Offices', value: stats.total_offices },
                            { label: 'Rooms', value: stats.total_rooms },
                            {
                                label: 'Open hazards',
                                value: stats.open_hazards,
                            },
                            {
                                label: 'Safety compliance',
                                value: `${stats.safety_compliance_rate}%`,
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
                                    href={`/sites/reports/export?type=head_office&format=csv&date_from=${dateFrom}&date_to=${dateTo}`}
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
                                            '/sites/reports/head-office',
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

                {/* Offices List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Head Office Details ({offices.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-2">
                            {offices.map((office) => {
                                const openHazards = office.hazards.filter(
                                    (h) => h.status === 'open',
                                );
                                const completedRuns =
                                    office.checklist_runs.filter(
                                        (r) => r.status === 'completed',
                                    ).length;
                                const totalRuns = office.checklist_runs.length;
                                const completionRate =
                                    totalRuns > 0
                                        ? Math.round(
                                              (completedRuns / totalRuns) * 100,
                                          )
                                        : 0;

                                return (
                                    <div
                                        key={office.id}
                                        className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {office.name}
                                            </div>
                                            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <DoorOpen className="h-3.5 w-3.5" />
                                                    {office.ho_resources_count}{' '}
                                                    rooms
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="h-3.5 w-3.5" />
                                                    {
                                                        office.calendar_events
                                                            .length
                                                    }{' '}
                                                    bookings
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            {openHazards.length > 0 && (
                                                <Badge className="bg-status-critical-bg text-status-critical">
                                                    <AlertTriangle className="mr-1 h-3 w-3" />
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
                                                    href={`/sites/${office.id}`}
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
