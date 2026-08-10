import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle } from 'lucide-react';

type PIA = {
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
    dpia: PIA;
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const renderList = (items?: string[] | null) => {
    if (!items || items.length === 0) {
        return (
            <span className="text-sm text-muted-foreground">
                None provided.
            </span>
        );
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

export default function ShowPIA({ dpia }: Props) {
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
        requires_dpo_review: 'requires Privacy Officer review',
        rejected: 'rejected',
    };

    const outcomeLabel = dpia.outcome
        ? (outcomeLabels[dpia.outcome] ?? dpia.outcome.replace(/_/g, ' '))
        : 'Pending review';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Impact Assessments', href: '/privacy/pia' },
                {
                    title: dpia.assessment_name,
                    href: `/privacy/pia/${dpia.id}`,
                },
            ]}
        >
            <Head title={`PIA - ${dpia.assessment_name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/privacy/pia"
                        backLabel="Back to list"
                        title={dpia.assessment_name}
                        description={`Assessed: ${formatDate(dpia.assessment_date)}${dpia.assessor ? ` by ${dpia.assessor.name}` : ''}${dpia.review_date ? ` - Review: ${formatDate(dpia.review_date)}` : ''}`}
                        actions={
                            can.conductDPIA ? (
                                <Link href={`/privacy/pia/${dpia.id}/edit`}>
                                    <Button size="sm" variant="outline">
                                        Edit
                                    </Button>
                                </Link>
                            ) : undefined
                        }
                    >
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">{outcomeLabel}</Badge>
                            <Badge className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                Risk:{' '}
                                {riskLabels[dpia.overall_risk_level] ??
                                    dpia.overall_risk_level}
                            </Badge>
                            {dpia.residual_risk_level && (
                                <Badge variant="outline">
                                    Residual:{' '}
                                    {riskLabels[dpia.residual_risk_level] ??
                                        dpia.residual_risk_level}
                                </Badge>
                            )}
                        </div>
                    </PageHero>
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Overview</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="text-sm text-muted-foreground">
                            {dpia.project_or_process}
                        </div>
                        {dpia.description && (
                            <div className="text-sm whitespace-pre-wrap text-muted-foreground">
                                {dpia.description}
                            </div>
                        )}
                        <div className="text-xs text-muted-foreground">
                            Assessment type:{' '}
                            {dpia.assessment_type.replace(/_/g, ' ')}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Processing Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-xs text-muted-foreground">
                                    Processing purpose
                                </div>
                                <div className="text-sm whitespace-pre-wrap text-foreground">
                                    {dpia.processing_purpose}
                                </div>
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">
                                    Legal basis
                                </div>
                                <div className="text-sm whitespace-pre-wrap text-foreground">
                                    {dpia.legal_basis}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Risk Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm text-muted-foreground">
                                Overall risk level:{' '}
                                <span className="font-medium">
                                    {riskLabels[dpia.overall_risk_level] ??
                                        dpia.overall_risk_level}
                                </span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Residual risk level:{' '}
                                <span className="font-medium">
                                    {dpia.residual_risk_level
                                        ? (riskLabels[
                                              dpia.residual_risk_level
                                          ] ?? dpia.residual_risk_level)
                                        : 'Not set'}
                                </span>
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Outcome:{' '}
                                <span className="font-medium">
                                    {outcomeLabel}
                                </span>
                            </div>
                            {dpia.approved_at && (
                                <div className="text-xs text-muted-foreground">
                                    Approved: {formatDate(dpia.approved_at)}
                                    {dpia.approved_by &&
                                        ` by ${dpia.approved_by.name}`}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Personal Data Types
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {renderList(dpia.personal_data_types)}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                People affected
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {renderList(dpia.data_subjects)}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Identified Risks
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {renderList(dpia.identified_risks)}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Mitigation Measures
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {renderList(dpia.mitigation_measures)}
                        </CardContent>
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
                                        if (confirm('Approve this PIA?')) {
                                            router.post(
                                                `/privacy/pia/${dpia.id}/approve`,
                                            );
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
                                        router.post(
                                            `/privacy/pia/${dpia.id}/review`,
                                            {
                                                review_notes: notes,
                                            },
                                        );
                                    }
                                }}
                            >
                                <AlertTriangle className="mr-1 h-4 w-4" />
                                Request Privacy Officer Review
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
