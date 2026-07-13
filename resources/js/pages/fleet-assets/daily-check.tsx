import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import {
    FleetComplianceBadges,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    CheckCircle,
    ClipboardCheck,
    Clock,
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
    compliance: {
        wof_due: number;
        wof_expired: number;
        rego_due: number;
        rego_expired: number;
        cof_due: number;
        cof_expired: number;
        insurance_expiring: number | null;
        insurance_expired: number | null;
        open_alerts: number;
        critical_alerts: number;
    };
};

export default function DailyCheck({ vehicles: rawVehicles, summary: rawSummary, compliance: rawCompliance }: Props) {
    const vehicles = rawVehicles ?? [];
    const summary = rawSummary ?? { total: 0, checked: 0, unchecked: 0 };
    const compliance = rawCompliance ?? {
        wof_due: 0, wof_expired: 0, rego_due: 0, rego_expired: 0,
        cof_due: 0, cof_expired: 0, insurance_expiring: null,
        insurance_expired: null, open_alerts: 0, critical_alerts: 0,
    };

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
                <HeroShell
                    footer={
                        <FleetComplianceBadges
                            wofDue={compliance.wof_due}
                            wofExpired={compliance.wof_expired}
                            regoDue={compliance.rego_due}
                            regoExpired={compliance.rego_expired}
                            cofDue={compliance.cof_due}
                            cofExpired={compliance.cof_expired}
                            insuranceExpiring={compliance.insurance_expiring}
                            insuranceExpired={compliance.insurance_expired}
                            openAlerts={compliance.open_alerts}
                            criticalAlerts={compliance.critical_alerts}
                            hrefs={{
                                wof: '/fleet-assets/compliance',
                                rego: '/fleet-assets/compliance',
                                cof: '/fleet-assets/compliance',
                                insurance: '/fleet-assets/compliance',
                                alerts: '/fleet-assets/alerts',
                            }}
                        />
                    }
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={ClipboardCheck} />
                        <div className="min-w-0">
                            <HeroStatusPill>Daily vehicle checks · today</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">Daily Vehicle Checks</h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Complete a quick visual check for each vehicle at your site.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile label="Vehicles" value={fmt(summary.total)} caption="at your site" tone="neutral" />
                            <HeroClusterTile
                                label="Checked today"
                                value={fmt(summary.checked)}
                                caption="done"
                                tone={summary.checked > 0 ? 'success' : 'neutral'}
                            />
                            <HeroClusterTile
                                label="Not checked"
                                value={fmt(summary.unchecked)}
                                caption="still due"
                                tone={summary.unchecked > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Completion"
                                value={`${checkedPercentage}%`}
                                caption="of today's checks"
                                tone={checkedPercentage === 100 ? 'success' : 'warning'}
                            />
                        </div>
                    </div>
                </HeroShell>

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
                                            ? 'border-primary/30 bg-primary/5 dark:bg-primary/10'
                                            : 'border-status-critical/30 bg-status-critical-bg'
                                        : 'border-status-warning/30 bg-status-warning-bg'
                                }`}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3 min-w-0">
                                            {vehicle.checked_today ? (
                                                vehicle.check_result === 'good' ? (
                                                    <CheckCircle className="h-5 w-5 text-primary shrink-0" />
                                                ) : (
                                                    <AlertTriangle className="h-5 w-5 text-status-critical shrink-0" />
                                                )
                                            ) : (
                                                <Clock className="h-5 w-5 text-status-warning shrink-0" />
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
                                        <article className="mt-4 space-y-3 rounded-lg border bg-background p-4">
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
                                        </article>
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
