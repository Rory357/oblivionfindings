import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, CheckCircle, AlertTriangle } from 'lucide-react';

type DPIA = {
    id: number;
    assessment_name: string;
    project_or_process: string;
    description: string | null;
    assessment_type: string;
    personal_data_types: string[] | null;
    data_subjects: string[] | null;
    processing_purpose: string;
    legal_basis: string;
    identified_risks: string[] | null;
    overall_risk_level: string;
    mitigation_measures: string[] | null;
    residual_risk_level: string | null;
    outcome: string | null;
    review_date: string | null;
    assessment_date: string;
    assessor?: { name: string } | null;
    approved_by?: { name: string } | null;
    approved_at?: string | null;
};

type Props = {
    dpia: DPIA;
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const renderList = (items?: string[] | null) => {
    if (!items || items.length === 0) {
        return <span className="text-sm text-muted-foreground">None provided.</span>;
    }
    return (
        <div className="flex flex-wrap gap-2">
            {items.map((item, idx) => (
                <Badge key={`${item}-${idx}`} variant="outline">
                    {item}
                </Badge>
            ))}
        </div>
    );
};

export default function ShowDPIA({ dpia }: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};
    const riskLabels: Record<string, string> = {
        low: 'low',
        medium: 'medium',
        high: 'high',
        very_high: 'very high',
    };
    const outcomeLabels: Record<string, string> = {
        approved: 'approved',
        approved_with_conditions: 'approved with conditions',
        requires_dpo_review: 'requires DPO review',
        rejected: 'rejected',
    };

    const outcomeLabel = dpia.outcome ? outcomeLabels[dpia.outcome] ?? dpia.outcome.replace(/_/g, ' ') : 'Pending review';

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Impact Assessments', href: '/privacy/dpia' },
            { title: dpia.assessment_name, href: `/privacy/dpia/${dpia.id}` },
        ]}>
            <Head title={`DPIA - ${dpia.assessment_name}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <Activity className="h-5 w-5 text-status-success" />
                            {dpia.assessment_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{outcomeLabel}</Badge>
                            <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                Risk: {riskLabels[dpia.overall_risk_level] ?? dpia.overall_risk_level}
                            </Badge>
                            {dpia.residual_risk_level && (
                                <Badge variant="outline">Residual: {riskLabels[dpia.residual_risk_level] ?? dpia.residual_risk_level}</Badge>
                            )}
                        </div>
                        <div className="mt-2 text-xs text-muted-foreground">
                            Assessed: {formatDate(dpia.assessment_date)}
                            {dpia.assessor && ` by ${dpia.assessor.name}`}
                            {dpia.review_date && ` - Review: ${formatDate(dpia.review_date)}`}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/privacy/dpia" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {can.conductDPIA && (
                            <Link href={`/privacy/dpia/${dpia.id}/edit`}>
                                <Button size="sm" variant="outline">Edit</Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Overview</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="text-sm text-muted-foreground">{dpia.project_or_process}</div>
                        {dpia.description && (
                            <div className="text-sm text-muted-foreground whitespace-pre-wrap">{dpia.description}</div>
                        )}
                        <div className="text-xs text-muted-foreground">Assessment type: {dpia.assessment_type.replace(/_/g, ' ')}</div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Processing Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-xs text-muted-foreground">Processing purpose</div>
                                <div className="text-sm text-foreground whitespace-pre-wrap">{dpia.processing_purpose}</div>
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">Legal basis</div>
                                <div className="text-sm text-foreground whitespace-pre-wrap">{dpia.legal_basis}</div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Risk Summary</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm text-muted-foreground">
                                Overall risk level: <span className="font-medium">{riskLabels[dpia.overall_risk_level] ?? dpia.overall_risk_level}</span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Residual risk level: <span className="font-medium">{dpia.residual_risk_level ? (riskLabels[dpia.residual_risk_level] ?? dpia.residual_risk_level) : 'Not set'}</span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Outcome: <span className="font-medium">{outcomeLabel}</span>
                            </div>
                            {dpia.approved_at && (
                                <div className="text-xs text-muted-foreground">
                                    Approved: {formatDate(dpia.approved_at)}
                                    {dpia.approved_by && ` by ${dpia.approved_by.name}`}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Personal Data Types</CardTitle>
                        </CardHeader>
                        <CardContent>{renderList(dpia.personal_data_types)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Data Subjects</CardTitle>
                        </CardHeader>
                        <CardContent>{renderList(dpia.data_subjects)}</CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Identified Risks</CardTitle>
                        </CardHeader>
                        <CardContent>{renderList(dpia.identified_risks)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Mitigation Measures</CardTitle>
                        </CardHeader>
                        <CardContent>{renderList(dpia.mitigation_measures)}</CardContent>
                    </Card>
                </div>

                {can.conductDPIA && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            {dpia.outcome !== 'approved' && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        if (confirm('Approve this DPIA?')) {
                                            router.post(`/privacy/dpia/${dpia.id}/approve`);
                                        }
                                    }}
                                >
                                    <CheckCircle className="mr-1 h-4 w-4" />
                                    Approve
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const notes = prompt('Enter review notes:');
                                    if (notes) {
                                        router.post(`/privacy/dpia/${dpia.id}/review`, {
                                            review_notes: notes,
                                        });
                                    }
                                }}
                            >
                                <AlertTriangle className="mr-1 h-4 w-4" />
                                Request DPO Review
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
