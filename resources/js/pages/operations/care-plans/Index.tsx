/* Org-wide Care Plans landing — now a "coming soon" dashboard. Day-to-day care
 * planning lives inside each client's profile (Care & Support Plan tab); this page
 * keeps the read-only org snapshot (stats + status donut from the controller) and
 * points people to the clients list. The standalone Show/Create/Edit pages remain
 * reachable as deep-link fallbacks but are no longer linked from here. */
import {
    DonutChart,
    OPS_COLORS,
    OpsStatCard,
} from '@/components/ops-stat-card';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileWarning,
    Sparkles,
    Target,
    Users,
} from 'lucide-react';

type Props = {
    stats?: {
        total: number;
        active: number;
        review_due: number;
        draft: number;
        in_review: number;
        plans_without_goals: number;
        overdue_goals: number;
    };
    plans_by_status?: Record<string, number>;
};

const STATUS_DONUT_COLORS: Record<string, string> = {
    active: OPS_COLORS.success,
    draft: OPS_COLORS.neutral,
    review: OPS_COLORS.warning,
    archived: OPS_COLORS.muted,
};

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    draft: 'Draft',
    review: 'In review',
    archived: 'Archived',
};

export default function CarePlansIndex({ stats, plans_by_status = {} }: Props) {
    const s = stats ?? {
        total: 0,
        active: 0,
        review_due: 0,
        draft: 0,
        in_review: 0,
        plans_without_goals: 0,
        overdue_goals: 0,
    };

    const donutSegments = Object.entries(plans_by_status).map(([status, value]) => ({
        label: STATUS_LABELS[status] ?? status,
        value,
        color: STATUS_DONUT_COLORS[status] ?? OPS_COLORS.neutral,
    }));

    return (
        <AppLayout>
            <Head title="Care Plans" />
            <PageHero
                icon={ClipboardCheck}
                title="Care Plans"
                description="An org-wide care-plans dashboard is on the way. For now, plans are managed inside each client's profile."
                stats={[
                    { label: 'Total', value: s.total },
                    { label: 'Active', value: s.active },
                    { label: 'Review due', value: s.review_due },
                    { label: 'Drafts', value: s.draft },
                ]}
                actions={
                    <Button asChild size="sm">
                        <Link href="/operations/clients">
                            <Users className="mr-1.5 h-3.5 w-3.5" />
                            Browse clients
                        </Link>
                    </Button>
                }
            />
            <PageShell>
                {/* ─── Coming soon ─── */}
                <Card className="overflow-hidden border-primary/30">
                    <CardContent className="flex flex-col items-center gap-4 px-6 py-12 text-center">
                        <span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                            <Sparkles className="h-8 w-8" />
                        </span>
                        <div className="max-w-xl space-y-1.5">
                            <h2 className="text-lg font-semibold">A care-plans dashboard is coming soon</h2>
                            <p className="text-sm text-muted-foreground">
                                Care &amp; support plans now live inside each client&apos;s profile — open a client and
                                go to the <span className="font-medium text-foreground">Care &amp; Support Plan</span> tab to
                                create, view, edit, review and sign off a plan. This page will grow into an organisation-wide
                                overview of plan health, reviews due and goal progress.
                            </p>
                        </div>
                        <Button asChild>
                            <Link href="/operations/clients">
                                <Users className="mr-1.5 h-4 w-4" />
                                Go to clients
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                {/* ─── Read-only org snapshot ─── */}
                <div className="mt-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <ClipboardList className="h-3.5 w-3.5" /> Organisation snapshot
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <OpsStatCard label="Total Plans" value={s.total} icon={ClipboardList} color="indigo" />
                    <OpsStatCard label="Active" value={s.active} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Review Due" value={s.review_due} icon={AlertTriangle} color="amber" />
                    <OpsStatCard label="Draft" value={s.draft} icon={ClipboardCheck} color="slate" />
                    <OpsStatCard label="Without Goals" value={s.plans_without_goals} icon={Target} color="red" />
                    <OpsStatCard label="Overdue Goals" value={s.overdue_goals} icon={FileWarning} color="red" />
                </div>

                {donutSegments.length > 0 && s.total > 0 ? (
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <div className="flex h-6 w-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <ClipboardList className="h-3.5 w-3.5" />
                                </div>
                                Plans by status
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex justify-center pb-4">
                            <DonutChart segments={donutSegments} centerLabel="Total" centerValue={s.total} size={150} />
                        </CardContent>
                        <div className="border-t bg-muted/50 px-4 py-2.5">
                            <div className="flex flex-wrap gap-x-4 gap-y-1">
                                {donutSegments
                                    .filter((seg) => seg.value > 0)
                                    .map((seg) => (
                                        <div key={seg.label} className="flex items-center gap-1.5 text-[11px]">
                                            <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: seg.color }} />
                                            {seg.label} · {seg.value}
                                        </div>
                                    ))}
                            </div>
                        </div>
                    </Card>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
