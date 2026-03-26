import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertTriangle, AlertCircle, TrendingUp, Shield } from 'lucide-react';

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
    critical: 'bg-red-500 text-white',
    high: 'bg-orange-500 text-white',
    medium: 'bg-yellow-500 text-black',
    low: 'bg-green-500 text-white',
};

export default function RiskNarrative({ auth, risks, summary }: Props) {
    const formatDate = (d: string | null) =>
        d ? new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Not set';

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

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Risk Narrative Report</h1>
                    <p className="text-gray-500 mt-1">Detailed narrative view of all active risks</p>
                </div>

                {/* Summary Stats */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <Card className="border-red-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-red-600">Critical</p>
                                    <p className="text-3xl font-bold text-red-600">{summary.critical}</p>
                                </div>
                                <AlertTriangle className="w-8 h-8 text-red-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-orange-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-orange-600">High</p>
                                    <p className="text-3xl font-bold text-orange-600">{summary.high}</p>
                                </div>
                                <AlertCircle className="w-8 h-8 text-orange-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-purple-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-purple-600">Above Appetite</p>
                                    <p className="text-3xl font-bold text-purple-600">{summary.above_appetite}</p>
                                </div>
                                <TrendingUp className="w-8 h-8 text-purple-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Total Active</p>
                                    <p className="text-3xl font-bold">{summary.total_active}</p>
                                </div>
                                <Shield className="w-8 h-8 text-gray-400" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Risk Detail Cards */}
                <div className="space-y-4">
                    {risks.map((risk) => (
                        <Card key={risk.id} className={cn('border-l-4', severityBorder[risk.severity] ?? 'border-l-gray-300')}>
                            <CardHeader>
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <CardTitle className="flex items-center gap-2 flex-wrap">
                                            {risk.title}
                                            <Badge variant="outline">{risk.reference}</Badge>
                                            <Badge className={severityBadge[risk.severity] ?? 'bg-gray-500 text-white'}>
                                                {risk.severity}
                                            </Badge>
                                            {!risk.within_appetite && (
                                                <Badge className="bg-purple-100 text-purple-800">Above Appetite</Badge>
                                            )}
                                        </CardTitle>
                                        <CardDescription className="capitalize mt-1">{risk.category?.replace(/_/g, ' ')}</CardDescription>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <div className="text-sm text-gray-500">Residual Score</div>
                                        <div className="text-2xl font-bold">{risk.residual_score}</div>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-4">
                                    {risk.description && (
                                        <div>
                                            <p className="text-sm font-medium text-gray-700 mb-1">Description</p>
                                            <p className="text-sm text-gray-600">{risk.description}</p>
                                        </div>
                                    )}

                                    {risk.mitigation_strategy && (
                                        <div>
                                            <p className="text-sm font-medium text-gray-700 mb-1">Mitigation Strategy</p>
                                            <p className="text-sm text-gray-600">{risk.mitigation_strategy}</p>
                                        </div>
                                    )}

                                    <div className="flex flex-wrap gap-4 text-sm text-gray-500">
                                        <span>Inherent: <strong>{risk.inherent_score}</strong></span>
                                        <span>Residual: <strong>{risk.residual_score}</strong></span>
                                        <span>Control Effectiveness: <strong className="capitalize">{risk.control_effectiveness}</strong></span>
                                        <span>Owner: <strong>{risk.owner?.name ?? 'Unassigned'}</strong></span>
                                        <span>
                                            Treatments:{' '}
                                            <Badge variant="outline">
                                                {risk.active_treatments} / {risk.treatments_count}
                                            </Badge>
                                        </span>
                                        <span>Next Review: <strong>{formatDate(risk.next_review)}</strong></span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {risks.length === 0 && (
                        <div className="py-12 text-center text-sm text-gray-500">No active risks found.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
