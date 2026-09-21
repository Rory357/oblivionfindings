import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDistance } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
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

type ChecklistItem = {
    result: string;
    assessment?: string;
    notes?: string;
    evidence?: { name: string; url: string };
};

type Inspection = {
    id: number;
    type: string;
    asset: {
        id: number;
        name: string;
        category: string | null;
        registration_number?: string | null;
    } | null;
    user: { id: number; name: string } | null;
    passed: boolean;
    outcome: 'passed' | 'failed' | 'needs_assessment';
    notes: string | null;
    odometer: number | null;
    overall_condition: string | null;
    responses: Record<string, ChecklistItem> | null;
    answer_outcomes: Record<string, string>;
    presented_template: { name: string; items: Array<{ id?: string; key?: string; label?: string; section?: string; options?: unknown }> } | null;
    completed_at: string | null;
    created_at: string | null;
};

type Props = {
    inspection: Inspection;
    can_report: boolean;
};

const SECTION_COLORS: Record<string, string> = {
    Exterior: 'border-l-blue-500',
    Interior: 'border-l-purple-500',
    'Under Bonnet': 'border-l-amber-500',
    Other: 'border-l-gray-500',
};

function ResultIcon({ result }: { result: string }) {
    if (result === 'pass')
        return <CheckCircle className="h-5 w-5 text-status-success" />;
    if (result === 'fail')
        return <XCircle className="h-5 w-5 text-status-critical" />;
    return <MinusCircle className="h-5 w-5 text-muted-foreground" />;
}

export default function InspectionShow({ inspection, can_report }: Props) {
    const insp = inspection ?? ({} as Inspection);
    const responses = insp.responses ?? {};
    const passed = insp.outcome === 'passed';
    const failed = insp.outcome === 'failed';
    const outcomeLabel = passed ? 'Passed' : failed ? 'Failed' : 'Needs assessment';

    // Group responses by section
    const sections: Record<
        string,
        { key: string; label: string; item: ChecklistItem; options: unknown }[]
    > = {};
    const snapshot = insp.presented_template;
    const presented: Array<{ key: string; label: string; section: string; item: ChecklistItem; options: unknown }> = snapshot?.items?.map((question) => {
        const key = String(question.id ?? question.key ?? '');
        return { key, label: question.label ?? `Question ${key}`, section: question.section ?? 'Checklist',
            item: { ...(responses[key] ?? { result: 'unknown' }), assessment: insp.answer_outcomes[key] ?? 'needs_assessment' }, options: question.options ?? null };
    }) ?? Object.entries(responses).map(([key, item]) => ({
        key, label: `Question ${key} (original wording unavailable)`, section: 'Legacy responses', item, options: null,
    }));
    for (const key of Object.keys(responses)) {
        if (snapshot && !presented.some((question) => question.key === key)) {
            presented.push({ key, label: `Unmapped response ${key}`, section: 'Needs assessment', item: responses[key], options: null });
        }
    }
    for (const { key, label, section, item, options } of presented) {
        if (!sections[section]) sections[section] = [];
        sections[section].push({ key, label, item, options });
    }

    // Count pass/fail/na
    const assessment = (item: ChecklistItem) => item.assessment === 'passed' ? 'pass' : item.assessment === 'failed' ? 'fail' : item.assessment === 'not_applicable' ? 'na' : 'unknown';
    const counts = presented.reduce(
        (acc, { item }) => {
            if (assessment(item) === 'pass') acc.pass++;
            else if (assessment(item) === 'fail') acc.fail++;
            else if (assessment(item) === 'na') acc.na++;
            else acc.unknown++;
            return acc;
        },
        { pass: 0, fail: 0, na: 0, unknown: 0 },
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
                <FleetCompactHero
                    pill={`Asset check · ${outcomeLabel.toLowerCase()}`}
                    title={`Inspection #${insp.id ?? ''}`}
                    backHref="/fleet-assets/inspections"
                    backLabel="Inspections"
                />
                {can_report && insp.asset && <Card className="flex-row items-center justify-between gap-4 p-4">
                    <p className="text-sm text-muted-foreground">Found a problem? Send a linked report for assessment. These original answers and evidence are retained.</p>
                    <Button asChild><Link href={`/fleet-assets/maintenance/work-orders/create?asset_id=${insp.asset.id}&checklist_run_id=${insp.id}`}>Report a problem</Link></Button>
                </Card>}

                {/* Result Banner */}
                <div
                    className={cn(
                        'rounded-lg border px-5 py-4',
                        passed
                            ? 'border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/30 dark:text-primary/70'
                            : failed ? 'border-status-critical/30 bg-status-critical-bg text-status-critical' : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                    )}
                >
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            {passed ? (
                                <CheckCircle className="h-6 w-6 text-primary dark:text-primary" />
                            ) : (
                                <XCircle className="h-6 w-6 text-status-critical dark:text-status-critical" />
                            )}
                            <div>
                                <span className="text-lg font-bold">
                                    {`Check · ${outcomeLabel}`}
                                </span>
                                <span className="mx-2 opacity-50">|</span>
                                <span className="capitalize">
                                    {insp.type ?? '---'}
                                </span>
                            </div>
                        </div>
                        <Badge
                            variant={passed ? 'default' : failed ? 'destructive' : 'secondary'}
                            className="text-sm"
                        >
                            {outcomeLabel}
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
                                        <dt className="text-xs text-muted-foreground">
                                            Vehicle
                                        </dt>
                                        <dd className="font-medium">
                                            {insp.asset ? (
                                                <Link
                                                    href={`/fleet-assets/${insp.asset.category === 'vehicle' ? 'vehicles' : 'assets'}/${insp.asset.id}`}
                                                    className="text-primary hover:underline"
                                                >
                                                    {insp.asset.name}
                                                    {insp.asset
                                                        .registration_number
                                                        ? ` (${insp.asset.registration_number})`
                                                        : ''}
                                                </Link>
                                            ) : (
                                                '---'
                                            )}
                                        </dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <User className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Inspector
                                        </dt>
                                        <dd className="font-medium">
                                            {insp.user?.name ?? '---'}
                                        </dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <Clock className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Date
                                        </dt>
                                        <dd className="font-medium">
                                            {insp.completed_at
                                                ? formatDateTime(
                                                      insp.completed_at,
                                                  )
                                                : '---'}
                                        </dd>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                    <Gauge className="h-4 w-4 text-muted-foreground" />
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Odometer
                                        </dt>
                                        <dd className="font-medium">
                                            {insp.odometer != null
                                                ? `${formatDistance(insp.odometer)}`
                                                : '---'}
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
                            <div className="grid grid-cols-4 gap-3">
                                <div className="rounded-lg bg-status-success-bg p-3 text-center">
                                    <div className="text-2xl font-bold text-status-success">
                                        {counts.pass}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Passed
                                    </div>
                                </div>
                                <div className="rounded-lg bg-status-critical-bg p-3 text-center">
                                    <div className="text-2xl font-bold text-status-critical">
                                        {counts.fail}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Failed
                                    </div>
                                </div>
                                <div className="rounded-lg bg-muted p-3 text-center dark:bg-muted/20">
                                    <div className="text-2xl font-bold text-muted-foreground">
                                        {counts.na}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        N/A
                                    </div>
                                </div>
                                <div className="rounded-lg bg-status-warning-bg p-3 text-center">
                                    <div className="text-2xl font-bold text-status-warning">{counts.unknown}</div>
                                    <div className="mt-1 text-xs text-muted-foreground">Unknown</div>
                                </div>
                            </div>
                            <div className="mt-4 rounded-md bg-muted/40 p-3">
                                <div className="text-xs text-muted-foreground">
                                    Overall Condition
                                </div>
                                <div className="mt-1 text-lg font-bold capitalize">
                                    {insp.overall_condition ?? '---'}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Checklist Results - Grouped by Section */}
                {Object.entries(sections).map(([sectionName, items]) => (
                    <Card
                        key={sectionName}
                        className={cn(
                            'border-l-4',
                            SECTION_COLORS[sectionName] ?? SECTION_COLORS.Other,
                        )}
                    >
                        <CardHeader>
                            <CardTitle className="text-base">
                                {sectionName}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2">
                                {items.map(({ key, label, item, options }) => (
                                    <div
                                        key={key}
                                        className={cn(
                                            'flex items-center gap-3 rounded-lg border p-3 transition-colors',
                                            assessment(item) === 'fail'
                                                ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30'
                                                : assessment(item) === 'pass'
                                                  ? 'border-status-success/30 bg-status-success-bg dark:border-status-success/50'
                                                  : '',
                                        )}
                                    >
                                        <ResultIcon result={assessment(item)} />
                                        <span className="flex-1 text-sm font-medium">
                                            {label}
                                            <small className="mt-1 block font-normal text-muted-foreground">Recorded answer: {item.result === 'unknown' ? 'Not supplied' : item.result}</small>
                                            {Array.isArray(options) && options.length > 0 && <small className="mt-0.5 block text-xs font-normal text-muted-foreground">
                                                Presented options: {options.map((option: unknown) => typeof option === 'string' ? option
                                                    : option && typeof option === 'object' && 'label' in option ? String(option.label) : '').filter(Boolean).join(', ')}
                                            </small>}
                                        </span>
                                        <Badge
                                            variant={
                                                assessment(item) === 'pass'
                                                    ? 'default'
                                                    : assessment(item) === 'fail'
                                                      ? 'destructive'
                                                      : 'secondary'
                                            }
                                            className="text-xs"
                                        >
                                            {assessment(item) === 'na' ? 'N/A' : assessment(item) === 'unknown' ? 'Needs assessment' : assessment(item) === 'pass' ? 'Passed' : 'Failed'}
                                        </Badge>
                                        {item.evidence && <a className="text-xs font-medium text-primary underline" href={item.evidence.url}>{item.evidence.name}</a>}
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
                            <p className="text-sm whitespace-pre-wrap">
                                {insp.notes}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
