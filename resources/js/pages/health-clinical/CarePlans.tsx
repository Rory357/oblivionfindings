import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    HealthClinicalShell,
    RegisterStatStrip,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ClipboardList, Info } from 'lucide-react';

type CarePlanRow = {
    id: number;
    title: string;
    plan_type: string | null;
    status: string;
    next_review_at: string | null;
    review_overdue: boolean;
    goals_count: number;
    unsigned: boolean;
    client: { id: number; name: string } | null;
};

type Props = {
    plans: CarePlanRow[];
    stats: { active: number; reviews_overdue: number; awaiting_sign_off: number };
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

function titleCase(s: string | null): string {
    return s ? s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—';
}

export default function CarePlansLens({ plans, stats, kpis, tab_counts }: Props) {
    return (
        <HealthClinicalShell activeTab="care_plans" kpis={kpis} tabCounts={tab_counts}>
            {/* Read-only lens banner */}
            <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/25 bg-primary/5 px-4 py-3">
                <Info className="h-4 w-4 shrink-0 text-primary" />
                <p className="flex-1 text-[13px] text-foreground">
                    A read-only review &amp; sign-off lens. Care plans are created, reviewed and signed off in the
                    <strong> Care Plans module</strong> — this surfaces what clinically needs attention.
                </p>
                <Link href="/operations/care-plans">
                    <Button size="sm" variant="outline" className="gap-1.5">
                        Open Care Plans module <ArrowUpRight className="h-3.5 w-3.5" />
                    </Button>
                </Link>
            </div>

            <RegisterStatStrip
                stats={[
                    { label: 'Active plans', value: stats.active },
                    { label: 'Reviews overdue', value: stats.reviews_overdue, tone: stats.reviews_overdue > 0 ? 'critical' : 'default' },
                    { label: 'Awaiting sign-off', value: stats.awaiting_sign_off, tone: stats.awaiting_sign_off > 0 ? 'warning' : 'default' },
                ]}
            />

            <Card>
                <CardContent className="p-0">
                    {plans.length === 0 ? (
                        <div className="p-12 text-center">
                            <ClipboardList className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                            <p className="font-medium text-muted-foreground">No active care plans</p>
                            <p className="mt-1 text-sm text-muted-foreground/70">Nothing needs clinical review right now.</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        <th className="px-4 py-2.5">Plan</th>
                                        <th className="px-4 py-2.5">Type</th>
                                        <th className="px-4 py-2.5">Goals</th>
                                        <th className="px-4 py-2.5">Next review</th>
                                        <th className="px-4 py-2.5">Sign-off</th>
                                        <th className="px-4 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {plans.map((p) => (
                                        <tr key={p.id} className="transition-colors hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <p className="font-medium">{p.title}</p>
                                                {p.client ? (
                                                    <Link href={`/operations/clients/${p.client.id}`} className="text-xs text-status-info hover:underline">{p.client.name}</Link>
                                                ) : <span className="text-xs text-muted-foreground">—</span>}
                                            </td>
                                            <td className="px-4 py-3"><Badge variant="outline" className="text-[10px]">{titleCase(p.plan_type)}</Badge></td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">{p.goals_count}</td>
                                            <td className="px-4 py-3 text-xs">
                                                {p.next_review_at ? (
                                                    <span className={p.review_overdue ? 'font-semibold text-status-critical' : 'text-muted-foreground'}>
                                                        {p.next_review_at}{p.review_overdue ? ' · overdue' : ''}
                                                    </span>
                                                ) : <span className="text-muted-foreground">Not set</span>}
                                            </td>
                                            <td className="px-4 py-3">
                                                {p.unsigned ? (
                                                    <Badge variant="outline" className="border-status-warning/40 text-[10px] text-status-warning">Unsigned</Badge>
                                                ) : (
                                                    <Badge variant="outline" className="border-status-success/40 text-[10px] text-status-success">Signed</Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={`/operations/care-plans/${p.id}`} className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                                                    Open <ArrowUpRight className="h-3 w-3" />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </HealthClinicalShell>
    );
}
