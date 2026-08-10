import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Shield, TrendingUp } from 'lucide-react';

interface Risk {
    id: number;
    reference: string;
    title: string;
    category: string;
    description: string;
    inherent_score: number;
    residual_score: number;
    control_effectiveness: string;
    within_appetite: boolean;
    severity: string;
    owner: any;
    mitigation_strategy: string;
    treatments_count: number;
    active_treatments: number;
    next_review: string | null;
}

interface Props extends PageProps {
    risks: Risk[];
    summary: {
        critical: number;
        high: number;
        above_appetite: number;
        total_active: number;
    };
}

const severityBorder: Record<string, string> = {
    critical: 'border-l-red-500',
    high: 'border-l-orange-500',
    medium: 'border-l-yellow-500',
    low: 'border-l-green-500',
};

const severityBadge: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-success text-white',
};

export default function RiskNarrative({ auth, risks, summary }: Props) {
    const formatDate = (d: string | null) =>
        d
            ? new Date(d).toLocaleDateString('en-NZ', {
                  day: '2-digit',
                  month: 'short',
                  year: 'numeric',
              })
            : 'Not set';

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Reports', href: '/governance/reports' },
                { title: 'Risk Narrative', href: '#' },
            ]}
        >
            <Head title="Risk Narrative Report" />

            <PageLayout
                hero={
                    <PageHero
                        icon={AlertTriangle}
                        title="Risk Narrative Report"
                        description="Detailed narrative view of all active risks."
                        stats={[
                            { label: 'Critical', value: summary.critical },
                            { label: 'High', value: summary.high },
                            {
                                label: 'Above Appetite',
                                value: summary.above_appetite,
                            },
                            { label: 'Total', value: summary.total_active },
                        ]}
                    />
                }
            >
                {/* Summary Stats */}
                <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                    <Card className="border-status-critical/30">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-status-critical">
                                        Critical
                                    </p>
                                    <p className="text-3xl font-bold text-status-critical">
                                        {summary.critical}
                                    </p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-status-critical" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-warning/30">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-status-warning">
                                        High
                                    </p>
                                    <p className="text-3xl font-bold text-status-warning">
                                        {summary.high}
                                    </p>
                                </div>
                                <AlertCircle className="h-8 w-8 text-status-warning" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-primary">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-primary">
                                        Above Appetite
                                    </p>
                                    <p className="text-3xl font-bold text-primary">
                                        {summary.above_appetite}
                                    </p>
                                </div>
                                <TrendingUp className="h-8 w-8 text-primary" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Total Active
                                    </p>
                                    <p className="text-3xl font-bold">
                                        {summary.total_active}
                                    </p>
                                </div>
                                <Shield className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Risk Detail Cards */}
                <div className="space-y-4">
                    {risks.map((risk) => (
                        <Card
                            key={risk.id}
                            className={cn(
                                'border-l-4',
                                severityBorder[risk.severity] ??
                                    'border-l-gray-300',
                            )}
                        >
                            <CardHeader>
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <CardTitle className="flex flex-wrap items-center gap-2">
                                            {risk.title}
                                            <Badge variant="outline">
                                                {risk.reference}
                                            </Badge>
                                            <Badge
                                                className={
                                                    severityBadge[
                                                        risk.severity
                                                    ] ??
                                                    'bg-muted-foreground/80 text-white'
                                                }
                                            >
                                                {risk.severity}
                                            </Badge>
                                            {!risk.within_appetite && (
                                                <Badge className="bg-primary/10 text-primary">
                                                    Above Appetite
                                                </Badge>
                                            )}
                                        </CardTitle>
                                        <CardDescription className="mt-1 capitalize">
                                            {risk.category?.replace(/_/g, ' ')}
                                        </CardDescription>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <div className="text-sm text-muted-foreground">
                                            Residual Score
                                        </div>
                                        <div className="text-2xl font-bold">
                                            {risk.residual_score}
                                        </div>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {risk.description && (
                                        <div>
                                            <p className="mb-1 text-sm font-medium text-foreground">
                                                Description
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {risk.description}
                                            </p>
                                        </div>
                                    )}

                                    {risk.mitigation_strategy && (
                                        <div>
                                            <p className="mb-1 text-sm font-medium text-foreground">
                                                Mitigation Strategy
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {risk.mitigation_strategy}
                                            </p>
                                        </div>
                                    )}

                                    <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                                        <span>
                                            Inherent:{' '}
                                            <strong>
                                                {risk.inherent_score}
                                            </strong>
                                        </span>
                                        <span>
                                            Residual:{' '}
                                            <strong>
                                                {risk.residual_score}
                                            </strong>
                                        </span>
                                        <span>
                                            Control Effectiveness:{' '}
                                            <strong className="capitalize">
                                                {risk.control_effectiveness}
                                            </strong>
                                        </span>
                                        <span>
                                            Owner:{' '}
                                            <strong>
                                                {risk.owner?.name ??
                                                    'Unassigned'}
                                            </strong>
                                        </span>
                                        <span>
                                            Treatments:{' '}
                                            <Badge variant="outline">
                                                {risk.active_treatments} /{' '}
                                                {risk.treatments_count}
                                            </Badge>
                                        </span>
                                        <span>
                                            Next Review:{' '}
                                            <strong>
                                                {formatDate(risk.next_review)}
                                            </strong>
                                        </span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {risks.length === 0 && (
                        <div className="py-12 text-center text-sm text-muted-foreground">
                            No active risks found.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
