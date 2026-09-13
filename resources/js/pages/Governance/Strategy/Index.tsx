import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import {
    create as createStrategy,
    show as showStrategy,
} from '@/routes/governance/strategy';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Calendar, Compass } from 'lucide-react';

interface StrategicPlan {
    id: number;
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    status: string;
    progress_pct: number;
    goals_count: number;
    version_number: number;
}

interface Props extends PageProps {
    plans: {
        data: StrategicPlan[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
}

export default function StrategyIndex({ auth, plans }: Props) {
    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getHorizonLabel = (horizon: string) => {
        return (
            {
                '1_year': '1 Year (Annual)',
                '3_year': '3 Year (Medium)',
                '5_year': '5 Year (Long)',
                '10_year': '10 Year (Vision)',
            }[horizon] ?? horizon
        );
    };

    const activePlans = plans.data.filter((p) => p.status === 'active');
    const consultationCount = plans.data.filter((p) =>
        ['draft', 'consultation'].includes(p.status),
    ).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategy', href: '/governance/strategy' },
            ]}
        >
            <Head title="Strategic Planning" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Compass}
                        title="Strategic Plans"
                        subline="Long-term strategy, operational horizons, and delivery milestones"
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Total plans"
                                    href="/governance/strategy"
                                >
                                    <PageHeaderMeterBig>
                                        {plans.data.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>All horizons</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Active"
                                    href="/governance/strategy?status=active"
                                    tone="brand"
                                >
                                    <PageHeaderMeterBig>
                                        {activePlans.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>In execution</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="In consultation"
                                    href="/governance/strategy?status=consultation"
                                    tone={consultationCount > 0 ? 'warning' : undefined}
                                >
                                    <PageHeaderMeterBig>
                                        {consultationCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>Drafting</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        actions={
                            <Button size="sm" asChild>
                                <Link href={createStrategy.url()}>
                                    New Strategic Plan
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                {/* Active Plan Highlight */}
                {plans.data.find((p) => p.status === 'approved') && (
                    <Card className="mb-6 border-status-success/30 bg-status-success-bg">
                        <CardContent className="pt-6">
                            {plans.data
                                .filter((p) => p.status === 'approved')
                                .map((plan) => (
                                    <div
                                        key={plan.id}
                                        className="flex items-start justify-between"
                                    >
                                        <div>
                                            <div className="mb-2 flex items-center gap-3">
                                                <Compass className="h-6 w-6 text-status-success" />
                                                <h2 className="text-xl font-semibold">
                                                    {plan.title}
                                                </h2>
                                                <Badge className="bg-status-success-bg text-status-success">
                                                    Active
                                                </Badge>
                                            </div>
                                            <p className="text-muted-foreground">
                                                {new Date(
                                                    plan.period_start,
                                                ).getFullYear()}{' '}
                                                -{' '}
                                                {new Date(
                                                    plan.period_end,
                                                ).getFullYear()}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-3xl font-bold text-status-success">
                                                {plan.progress_pct}%
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Complete
                                            </p>
                                        </div>
                                    </div>
                                ))}
                        </CardContent>
                    </Card>
                )}

                {/* Plans List */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {plans.data.map((plan) => (
                        <Card key={plan.id}>
                            <CardContent className="pt-6">
                                <div className="mb-4 flex items-start justify-between">
                                    <div>
                                        <div className="mb-1 flex items-center gap-2">
                                            <h3 className="text-lg font-semibold">
                                                <Link
                                                    href={showStrategy.url({
                                                        plan: plan.id,
                                                    })}
                                                    className="hover:text-status-info"
                                                >
                                                    {plan.title}
                                                </Link>
                                            </h3>
                                        </div>
                                        <Badge
                                            className={cn(
                                                getStatusColor(plan.status),
                                            )}
                                        >
                                            {plan.status}
                                        </Badge>
                                    </div>
                                    <Badge variant="outline">
                                        {getHorizonLabel(plan.planning_horizon)}
                                    </Badge>
                                </div>

                                <div className="space-y-3">
                                    <div className="flex justify-between text-sm text-muted-foreground">
                                        <span>Progress</span>
                                        <span>{plan.progress_pct}%</span>
                                    </div>
                                    <Progress value={plan.progress_pct} />
                                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-1">
                                            <Calendar className="h-4 w-4" />
                                            {new Date(
                                                plan.period_start,
                                            ).getFullYear()}{' '}
                                            -{' '}
                                            {new Date(
                                                plan.period_end,
                                            ).getFullYear()}
                                        </span>
                                        <span>v{plan.version_number}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
