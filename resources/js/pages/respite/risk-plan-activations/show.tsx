import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    activation: any;
    hasAcknowledged: boolean;
};

const statusColors: Record<string, string> = {
    pending_review: 'bg-status-warning-bg text-status-warning',
    active: 'bg-status-success-bg text-status-success',
    modified: 'bg-status-info-bg text-status-info',
    suspended: 'bg-muted text-muted-foreground',
    completed: 'bg-muted text-foreground',
};

const typeColors: Record<string, string> = {
    behaviour: 'bg-primary/10 text-primary',
    safety: 'bg-status-critical-bg text-status-critical',
    medical: 'bg-status-info-bg text-status-info',
    mobility: 'bg-status-warning-bg text-status-warning',
    communication: 'bg-status-info-bg text-status-info',
};

export default function RiskPlanActivationShow({
    activation,
    hasAcknowledged,
}: Props) {
    const [reviewNotes, setReviewNotes] = useState('');
    const [deactivateReason, setDeactivateReason] = useState('');
    const [suspendReason, setSuspendReason] = useState('');

    const base = `/respite/risk-plan-activations/${activation.id}`;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                {
                    title: 'Risk Plan Activations',
                    href: '/respite/risk-plan-activations',
                },
                { title: activation.plan_name, href: base },
            ]}
        >
            <Head title="Risk Plan Activation" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/risk-plan-activations"
                        title={activation.plan_name}
                        description={
                            `${activation.stay?.client?.first_name ?? ''} ${activation.stay?.client?.last_name ?? ''}`.trim() ||
                            undefined
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Plan Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm text-muted-foreground">
                        <div className="flex flex-wrap gap-2">
                            <Badge
                                className={
                                    typeColors[activation.plan_type] || ''
                                }
                            >
                                {activation.plan_type?.replace(/_/g, ' ')}
                            </Badge>
                            <Badge
                                className={
                                    statusColors[activation.status] || ''
                                }
                            >
                                {activation.status?.replace(/_/g, ' ')}
                            </Badge>
                        </div>

                        {activation.plan_details?.length > 0 && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Plan Details
                                </div>
                                <ul className="mt-1 list-disc space-y-1 pl-5">
                                    {activation.plan_details.map(
                                        (d: string, i: number) => (
                                            <li key={i}>{d}</li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}

                        {activation.triggers?.length > 0 && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Triggers
                                </div>
                                <ul className="mt-1 list-disc space-y-1 pl-5">
                                    {activation.triggers.map(
                                        (t: string, i: number) => (
                                            <li key={i}>{t}</li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}

                        {activation.interventions?.length > 0 && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Interventions
                                </div>
                                <ul className="mt-1 list-disc space-y-1 pl-5">
                                    {activation.interventions.map(
                                        (v: string, i: number) => (
                                            <li key={i}>{v}</li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}

                        {activation.escalation_steps?.length > 0 && (
                            <div>
                                <div className="font-medium text-foreground">
                                    Escalation Steps
                                </div>
                                <ol className="mt-1 list-decimal space-y-1 pl-5">
                                    {activation.escalation_steps.map(
                                        (s: string, i: number) => (
                                            <li key={i}>{s}</li>
                                        ),
                                    )}
                                </ol>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Review Info</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        {activation.reviewed_by ? (
                            <>
                                <div>
                                    Reviewed by:{' '}
                                    {activation.reviewed_by?.name || 'Unknown'}
                                </div>
                                <div>
                                    Review notes:{' '}
                                    {activation.review_notes || 'None'}
                                </div>
                            </>
                        ) : (
                            <div className="text-muted-foreground">
                                Not yet reviewed.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Staff Acknowledgments
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {activation.acknowledgments?.length ? (
                            <div className="space-y-2">
                                <div className="text-sm text-muted-foreground">
                                    {activation.acknowledgments.length} staff
                                    acknowledged
                                </div>
                                <ul className="space-y-1 text-sm">
                                    {activation.acknowledgments.map(
                                        (ack: any, i: number) => (
                                            <li
                                                key={i}
                                                className="flex justify-between"
                                            >
                                                <span>
                                                    {ack.user?.name ||
                                                        'Unknown'}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatDateTimeLong(
                                                        ack.acknowledged_at,
                                                    )}
                                                </span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No acknowledgments yet.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Actions</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {activation.status === 'pending_review' && (
                            <div className="space-y-2">
                                <Label>Review Notes</Label>
                                <Textarea
                                    value={reviewNotes}
                                    onChange={(e) =>
                                        setReviewNotes(e.target.value)
                                    }
                                    placeholder="Enter review notes..."
                                />
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(`${base}/review`, {
                                                review_notes: reviewNotes,
                                            })
                                        }
                                    >
                                        Review
                                    </Button>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(`${base}/activate`)
                                        }
                                    >
                                        Activate
                                    </Button>
                                </div>
                            </div>
                        )}

                        {activation.status === 'active' && (
                            <>
                                <div className="space-y-2">
                                    <Label>Deactivation Reason</Label>
                                    <Textarea
                                        value={deactivateReason}
                                        onChange={(e) =>
                                            setDeactivateReason(e.target.value)
                                        }
                                        placeholder="Reason for deactivation..."
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(`${base}/deactivate`, {
                                                reason: deactivateReason,
                                            })
                                        }
                                    >
                                        Deactivate
                                    </Button>
                                </div>

                                <div className="space-y-2 border-t pt-4">
                                    <Label>Suspension Reason</Label>
                                    <Textarea
                                        value={suspendReason}
                                        onChange={(e) =>
                                            setSuspendReason(e.target.value)
                                        }
                                        placeholder="Reason for suspension..."
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(`${base}/suspend`, {
                                                reason: suspendReason,
                                            })
                                        }
                                    >
                                        Suspend
                                    </Button>
                                </div>
                            </>
                        )}

                        {!hasAcknowledged && (
                            <div className="border-t pt-4">
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(`${base}/acknowledge`)
                                    }
                                >
                                    Acknowledge
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
