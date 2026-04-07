import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import {
    Car,
    CheckCircle,
    Clock,
    Gauge,
    MinusCircle,
    User,
    XCircle,
} from 'lucide-react';
import { formatDateTime, formatDistance } from '@/lib/fleet-utils';


type ChecklistItem = {
    result: 'pass' | 'fail' | 'na';
    notes?: string;
};

type Inspection = {
    id: number;
    type: string;
    asset: { id: number; name: string; registration_number?: string | null } | null;
    user: { id: number; name: string } | null;
    passed: boolean;
    notes: string | null;
    odometer: number | null;
    overall_condition: string | null;
    responses: Record<string, ChecklistItem> | null;
    completed_at: string | null;
    created_at: string | null;
};

type Props = {
    inspection: Inspection;
};

const ITEM_LABELS: Record<string, string> = {
    tyres_condition: 'Tyres - Condition & Pressure',
    lights_front: 'Lights - Front',
    lights_rear: 'Lights - Rear',
    body_damage: 'Body Damage',
    windscreen: 'Windscreen',
    mirrors: 'Mirrors',
    number_plates: 'Number Plates',
    seatbelts: 'Seatbelts',
    horn: 'Horn',
    wipers: 'Wipers',
    dashboard_warnings: 'Dashboard Warnings',
    cleanliness: 'Cleanliness',
    first_aid_kit: 'First Aid Kit',
    oil_level: 'Oil Level',
    coolant: 'Coolant Level',
    brake_fluid: 'Brake Fluid',
    battery: 'Battery',
};

const SECTION_MAP: Record<string, string> = {
    tyres_condition: 'Exterior',
    lights_front: 'Exterior',
    lights_rear: 'Exterior',
    body_damage: 'Exterior',
    windscreen: 'Exterior',
    mirrors: 'Exterior',
    number_plates: 'Exterior',
    seatbelts: 'Interior',
    horn: 'Interior',
    wipers: 'Interior',
    dashboard_warnings: 'Interior',
    cleanliness: 'Interior',
    first_aid_kit: 'Interior',
    oil_level: 'Under Bonnet',
    coolant: 'Under Bonnet',
    brake_fluid: 'Under Bonnet',
    battery: 'Under Bonnet',
};

const SECTION_COLORS: Record<string, string> = {
    Exterior: 'border-l-blue-500',
    Interior: 'border-l-purple-500',
    'Under Bonnet': 'border-l-amber-500',
    Other: 'border-l-gray-500',
};

function ResultIcon({ result }: { result: string }) {
    if (result === 'pass') return <CheckCircle className="h-5 w-5 text-green-600" />;
    if (result === 'fail') return <XCircle className="h-5 w-5 text-red-600" />;
    return <MinusCircle className="h-5 w-5 text-gray-400" />;
}

export default function InspectionShow({ inspection }: Props) {
    const insp = inspection ?? ({} as Inspection);
    const responses = insp.responses ?? {};

    // Group responses by section
    const sections: Record<string, { key: string; label: string; item: ChecklistItem }[]> = {};
    for (const [key, item] of Object.entries(responses)) {
        const section = SECTION_MAP[key] ?? 'Other';
        if (!sections[section]) sections[section] = [];
        sections[section].push({
            key,
            label: ITEM_LABELS[key] ?? key.replace(/_/g, ' '),
            item,
        });
    }

    // Count pass/fail/na
    const counts = Object.values(responses).reduce(
        (acc, item) => {
            if (item.result === 'pass') acc.pass++;
            else if (item.result === 'fail') acc.fail++;
            else acc.na++;
            return acc;
        },
        { pass: 0, fail: 0, na: 0 }
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Inspections', href: '/fleet-assets/inspections' },
                { title: `Inspection #${insp.id ?? ''}`, href: '#' },
            ]}
        >
            <Head title={`Inspection #${insp.id ?? ''}`} />
            <PageShell>
                <FleetHero
                    title={`Inspection #${insp.id ?? ''}`}
                    backHref="/fleet-assets/inspections"
                    backLabel="Back to Inspections"
                />

                {/* Result Banner */}
                <div className={cn(
                    'rounded-lg border px-5 py-4',
                    insp.passed
                        ? 'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200'
                        : 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200'
                )}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            {insp.passed ? (
                                <CheckCircle className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                            ) : (
                                <XCircle className="h-6 w-6 text-red-600 dark:text-red-400" />
                            )}
                            <div>
                                <span className="text-lg font-bold">{insp.passed ? 'Inspection Passed' : 'Inspection Failed'}</span>
                                <span className="mx-2 opacity-50">|</span>
                                <span className="capitalize">{insp.type ?? '---'}</span>
                            </div>
                        </div>
                        <Badge variant={insp.passed ? 'default' : 'destructive'} className="text-sm">
                            {insp.passed ? 'Pass' : 'Fail'}
                        </Badge>
                    </div>
                </div>

                {/* Details + Summary */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <Car className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Vehicle</dt>
                                        <dd className="font-medium">
                                            {insp.asset ? (
                                                <Link href={`/fleet-assets/vehicles/${insp.asset.id}`} className="text-primary hover:underline">
                                                    {insp.asset.name}
                                                    {insp.asset.registration_number ? ` (${insp.asset.registration_number})` : ''}
                                                </Link>
                                            ) : '---'}
                                        </dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <User className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Inspector</dt>
                                        <dd className="font-medium">{insp.user?.name ?? '---'}</dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <Clock className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Date</dt>
                                        <dd className="font-medium">
                                            {insp.completed_at ? formatDateTime(insp.completed_at) : '---'}
                                        </dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <Gauge className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Odometer</dt>
                                        <dd className="font-medium">
                                            {insp.odometer != null ? `${formatDistance(insp.odometer)}` : '---'}
                                        </dd>
                                    </div>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    {/* Summary Card */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Summary</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-3 gap-3">
                                <div className="rounded-lg bg-green-50 p-3 text-center dark:bg-green-950/20">
                                    <div className="text-2xl font-bold text-green-600">{counts.pass}</div>
                                    <div className="mt-1 text-xs text-muted-foreground">Passed</div>
                                </div>
                                <div className="rounded-lg bg-red-50 p-3 text-center dark:bg-red-950/20">
                                    <div className="text-2xl font-bold text-red-600">{counts.fail}</div>
                                    <div className="mt-1 text-xs text-muted-foreground">Failed</div>
                                </div>
                                <div className="rounded-lg bg-gray-50 p-3 text-center dark:bg-gray-950/20">
                                    <div className="text-2xl font-bold text-gray-500">{counts.na}</div>
                                    <div className="mt-1 text-xs text-muted-foreground">N/A</div>
                                </div>
                            </div>
                            <div className="mt-4 rounded-md bg-muted/40 p-3">
                                <div className="text-xs text-muted-foreground">Overall Condition</div>
                                <div className="mt-1 text-lg font-bold capitalize">{insp.overall_condition ?? '---'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Checklist Results - Grouped by Section */}
                {Object.entries(sections).map(([sectionName, items]) => (
                    <Card key={sectionName} className={cn('border-l-4', SECTION_COLORS[sectionName] ?? SECTION_COLORS.Other)}>
                        <CardHeader>
                            <CardTitle className="text-base">{sectionName}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {items.map(({ key, label, item }) => (
                                    <div
                                        key={key}
                                        className={cn(
                                            'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                                            item.result === 'fail'
                                                ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30'
                                                : item.result === 'pass'
                                                ? 'border-green-100 bg-green-50/30 dark:border-green-900/50 dark:bg-green-950/10'
                                                : ''
                                        )}
                                    >
                                        <ResultIcon result={item.result} />
                                        <span className="flex-1 text-sm font-medium">{label}</span>
                                        <Badge
                                            variant={item.result === 'pass' ? 'default' : item.result === 'fail' ? 'destructive' : 'secondary'}
                                            className="text-xs"
                                        >
                                            {item.result === 'na' ? 'N/A' : item.result}
                                        </Badge>
                                        {item.notes && (
                                            <span className="max-w-[200px] truncate text-xs text-muted-foreground italic">
                                                {item.notes}
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ))}

                {/* Notes */}
                {insp.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">{insp.notes}</p>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
