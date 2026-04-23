import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, Calendar } from 'lucide-react';

interface Props extends PageProps {
    committee: string;
    risks: any[];
}

const COMMITTEE_LABELS: Record<string, string> = {
    audit_risk: 'Audit & Risk',
    people: 'People',
    finance: 'Finance',
    clinical: 'Clinical',
    governance: 'Governance',
};

const getSeverityColor = (score: number) => {
    if (score >= 20) return 'bg-red-500 text-white';
    if (score >= 15) return 'bg-orange-500 text-white';
    if (score >= 10) return 'bg-yellow-500 text-black';
    return 'bg-green-500 text-white';
};

const getSeverityBorder = (score: number) => {
    if (score >= 20) return 'border-l-red-500';
    if (score >= 15) return 'border-l-orange-500';
    if (score >= 10) return 'border-l-yellow-500';
    return 'border-l-green-500';
};

export default function CommitteeRisks({ auth, committee, risks }: Props) {
    const title = COMMITTEE_LABELS[committee] ?? committee.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    const sorted = [...risks].sort((a, b) => (b.residual_score ?? 0) - (a.residual_score ?? 0));

    const formatDate = (d: string | null) =>
        d ? new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Not set';

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risks', href: '/governance/risks' },
                { title: 'Committee', href: '#' },
            ]}
        >
            <Head title={`${title} Committee Risks`} />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-foreground">{title}</h1>
                    <p className="text-muted-foreground mt-1">Committee risk oversight</p>
                </div>

                <div className="space-y-3">
                    {sorted.map((risk) => (
                        <Card key={risk.id} className={cn('border-l-4', getSeverityBorder(risk.residual_score ?? 0))}>
                            <CardContent className="pt-6">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="font-semibold text-foreground">{risk.title}</span>
                                            <Badge variant="outline">{risk.risk_reference}</Badge>
                                            {risk.category && (
                                                <Badge variant="secondary" className="capitalize">
                                                    {risk.category.replace(/_/g, ' ')}
                                                </Badge>
                                            )}
                                        </div>
                                        {risk.mitigation_strategy && (
                                            <p className="text-sm text-muted-foreground mt-2 line-clamp-2">
                                                {risk.mitigation_strategy}
                                            </p>
                                        )}
                                        <div className="flex items-center gap-4 mt-2 text-xs text-muted-foreground">
                                            <span className="flex items-center gap-1">
                                                <Calendar className="w-3 h-3" />
                                                Next review: {formatDate(risk.next_review_date)}
                                            </span>
                                            {risk.within_appetite !== undefined && (
                                                <span className="flex items-center gap-1">
                                                    {risk.within_appetite ? (
                                                        <CheckCircle2 className="w-3 h-3 text-green-500" />
                                                    ) : (
                                                        <AlertTriangle className="w-3 h-3 text-primary" />
                                                    )}
                                                    {risk.within_appetite ? 'Within appetite' : 'Above appetite'}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <Badge className={cn('shrink-0 text-lg px-3 py-1', getSeverityColor(risk.residual_score ?? 0))}>
                                        {risk.residual_score ?? '-'}
                                    </Badge>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    {sorted.length === 0 && (
                        <div className="py-12 text-center text-sm text-muted-foreground">
                            No risks assigned to this committee.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
