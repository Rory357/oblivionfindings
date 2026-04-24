import { ProgressRing, FLEET_COLORS } from '@/components/fleet-charts';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    CheckCircle,
    Clock,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

type Vehicle = {
    id: number;
    name: string;
    asset_tag: string | null;
    status: string;
    checked_today: boolean;
    check_result: 'good' | 'issue' | null;
    check_notes: string | null;
    checked_at: string | null;
    checked_by: string | null;
};

type Props = {
    vehicles: Vehicle[];
    summary: {
        total: number;
        checked: number;
        unchecked: number;
    };
};

export default function DailyCheck({ vehicles: rawVehicles, summary: rawSummary }: Props) {
    const vehicles = rawVehicles ?? [];
    const summary = rawSummary ?? { total: 0, checked: 0, unchecked: 0 };

    const [activeCheck, setActiveCheck] = useState<number | null>(null);
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [submitting, setSubmitting] = useState<number | null>(null);

    const handleSubmit = (vehicleId: number, condition: 'good' | 'issue') => {
        setSubmitting(vehicleId);
        router.post('/fleet-assets/daily-check', {
            asset_id: vehicleId,
            condition,
            notes: notes[vehicleId] ?? '',
        }, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                setSubmitting(null);
                setActiveCheck(null);
            },
        });
    };

    const checkedPercentage = summary.total > 0 ? Math.round((summary.checked / summary.total) * 100) : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Daily Checks', href: '/fleet-assets/daily-check' },
            ]}
        >
            <Head title="Daily Vehicle Checks" />
            <PageShell>
                <FleetHero
                    title="Daily Vehicle Checks"
                    description="Complete a quick visual check for each vehicle at your site."
                />

                {/* Grid: ProgressRing (left) + KPI cards (right) */}
                <div className="grid gap-4 lg:grid-cols-[auto,1fr]">
                    <Card className="flex items-center justify-center px-8 py-6">
                        <ProgressRing
                            value={checkedPercentage}
                            size={140}
                            color={checkedPercentage === 100 ? FLEET_COLORS.primary : checkedPercentage >= 50 ? FLEET_COLORS.secondary : FLEET_COLORS.danger}
                            label="Completion"
                        />
                    </Card>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                    <Car className="h-4 w-4" />
                                    Total Vehicles
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{summary.total}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium text-primary">
                                    <CheckCircle className="h-4 w-4" />
                                    Checked Today
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold text-primary">{summary.checked}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium text-red-600">
                                    <XCircle className="h-4 w-4" />
                                    Not Checked
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold text-red-600">{summary.unchecked}</div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* Progress Bar */}
                <div className="space-y-1">
                    <div className="flex justify-between text-sm">
                        <span className="text-muted-foreground">Today&apos;s progress</span>
                        <span className="font-medium">{checkedPercentage}%</span>
                    </div>
                    <div className="h-3 w-full rounded-full bg-muted">
                        <div
                            className="h-full rounded-full transition-all bg-primary"
                            style={{ width: `${checkedPercentage}%` }}
                        />
                    </div>
                </div>

                {/* Vehicle Grid (2-3 columns) */}
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {vehicles.length > 0 ? (
                        vehicles.map((vehicle) => (
                            <Card
                                key={vehicle.id}
                                className={`transition-colors ${
                                    vehicle.checked_today
                                        ? vehicle.check_result === 'good'
                                            ? 'border-primary/30 bg-primary/10/30 dark:bg-primary/10'
                                            : 'border-orange-500/30 bg-orange-50/30 dark:bg-orange-950/10'
                                        : 'border-red-500/30 bg-red-50/30 dark:bg-red-950/10'
                                }`}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3 min-w-0">
                                            {vehicle.checked_today ? (
                                                vehicle.check_result === 'good' ? (
                                                    <CheckCircle className="h-5 w-5 text-primary shrink-0" />
                                                ) : (
                                                    <AlertTriangle className="h-5 w-5 text-orange-500 shrink-0" />
                                                )
                                            ) : (
                                                <XCircle className="h-5 w-5 text-red-500 shrink-0" />
                                            )}
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-semibold text-sm truncate">{vehicle.name}</span>
                                                    {vehicle.asset_tag && (
                                                        <Badge variant="outline" className="font-mono text-xs shrink-0">{vehicle.asset_tag}</Badge>
                                                    )}
                                                </div>
                                                {vehicle.checked_today && (
                                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                                        Checked {vehicle.checked_at ? new Date(vehicle.checked_at).toLocaleTimeString() : ''}
                                                        {vehicle.checked_by && ` by ${vehicle.checked_by}`}
                                                        {vehicle.check_notes && ` - ${vehicle.check_notes}`}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2 shrink-0">
                                            {vehicle.checked_today ? (
                                                <Badge variant={vehicle.check_result === 'good' ? 'default' : 'destructive'}>
                                                    {vehicle.check_result === 'good' ? 'Good' : 'Issue'}
                                                </Badge>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => setActiveCheck(activeCheck === vehicle.id ? null : vehicle.id)}
                                                >
                                                    <Clock className="mr-1.5 h-3.5 w-3.5" />
                                                    Check
                                                </Button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Check Form */}
                                    {activeCheck === vehicle.id && (
                                        <div className="mt-4 rounded-lg border bg-background p-4 space-y-3">
                                            <div>
                                                <label className="text-sm font-medium">Quick Notes (optional)</label>
                                                <Input
                                                    value={notes[vehicle.id] ?? ''}
                                                    onChange={(e) => setNotes((prev) => ({ ...prev, [vehicle.id]: e.target.value }))}
                                                    placeholder="Any notes about the vehicle condition..."
                                                />
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    onClick={() => handleSubmit(vehicle.id, 'good')}
                                                    disabled={submitting === vehicle.id}
                                                    className="bg-primary hover:bg-primary"
                                                >
                                                    <CheckCircle className="mr-1.5 h-4 w-4" />
                                                    Good
                                                </Button>
                                                <Button
                                                    variant="destructive"
                                                    onClick={() => handleSubmit(vehicle.id, 'issue')}
                                                    disabled={submitting === vehicle.id}
                                                >
                                                    <AlertTriangle className="mr-1.5 h-4 w-4" />
                                                    Issue
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setActiveCheck(null)}
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <div className="col-span-full flex flex-col items-center justify-center py-12 text-center">
                            <Car className="h-12 w-12 text-muted-foreground/50 mb-4" />
                            <h3 className="text-lg font-semibold">No vehicles at your site</h3>
                            <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                                Vehicles assigned to your site will appear here for daily checks.
                            </p>
                        </div>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
