import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Shield, Users } from 'lucide-react';

type SuccessionPlan = {
    id: number;
    role_title: string;
    department: string | null;
    risk_level: string;
    current_holder_name: string | null;
    candidates_count: number;
    is_active: boolean;
};

type Props = {
    plans: {
        data: SuccessionPlan[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    can: { manage?: boolean };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Succession Planning', href: '/hr/succession' },
];

const riskConfig: Record<string, { className: string; label: string }> = {
    critical: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Critical',
    },
    high: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'High',
    },
    medium: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Medium',
    },
    low: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Low',
    },
};

export default function SuccessionIndex({ plans, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Succession Planning" />
            <PageHero
                icon={Users}
                title="Succession Planning"
                description="Identify and develop talent for key roles."
                stats={[
                    { label: 'Total plans', value: plans.total },
                    { label: 'Critical', value: plans.data.filter((p) => p.risk_level === 'critical').length },
                    { label: 'High risk', value: plans.data.filter((p) => p.risk_level === 'high').length },
                ]}
                actions={
                    can.manage ? (
                        <Button asChild>
                            <Link href="/hr/succession/create">
                                <Plus className="mr-2 h-4 w-4" />
                                New Plan
                            </Link>
                        </Button>
                    ) : undefined
                }
            />
            <PageShell>
                {plans.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-50" />
                            <p>No succession plans created yet.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {plans.data.map((plan) => {
                            const risk =
                                riskConfig[plan.risk_level] || riskConfig.low;
                            return (
                                <Card
                                    key={plan.id}
                                    className="cursor-pointer transition-colors hover:border-primary/30"
                                    onClick={() =>
                                        router.get(`/hr/succession/${plan.id}`)
                                    }
                                >
                                    <CardHeader className="pb-2">
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="text-base">
                                                {plan.role_title}
                                            </CardTitle>
                                            <Badge
                                                variant="outline"
                                                className={risk.className}
                                            >
                                                {risk.label}
                                            </Badge>
                                        </div>
                                        {plan.department && (
                                            <p className="text-sm text-muted-foreground">
                                                {plan.department}
                                            </p>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-2 text-sm">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Current Holder
                                                </span>
                                                <span>
                                                    {plan.current_holder_name ||
                                                        'Vacant'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">
                                                    Candidates
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Users className="h-3 w-3" />
                                                    {plan.candidates_count}
                                                </span>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
