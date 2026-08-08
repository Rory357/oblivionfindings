import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

type Props = {
    stay: any;
    activations: any[];
    planTypes: string[];
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

export default function RiskPlanActivationsForStay({
    stay,
    activations,
    planTypes,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                {
                    title: 'Risk Plan Activations',
                    href: '/respite/risk-plan-activations',
                },
                { title: 'For Stay', href: '#' },
            ]}
        >
            <Head title="Risk Plans for Stay" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/respite/stays/${stay.id}`}
                        title={`Risk Plans for ${stay.client?.first_name ?? ''} ${stay.client?.last_name ?? ''}`.trim()}
                        description={`Stay #${stay.id} — ${formatDateTimeLong(stay.check_in)} to ${formatDateTimeLong(stay.check_out)}`}
                        actions={
                            <Link
                                href={`/respite/risk-plan-activations/create?stay_id=${stay.id}`}
                            >
                                <Button size="sm" variant="outline">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Activation
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {activations.map((a: any) => (
                        <Card key={a.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {a.plan_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge
                                                    className={
                                                        typeColors[
                                                            a.plan_type
                                                        ] || ''
                                                    }
                                                >
                                                    {a.plan_type?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                <Badge
                                                    className={
                                                        statusColors[
                                                            a.status
                                                        ] || ''
                                                    }
                                                >
                                                    {a.status?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {formatDateTimeLong(
                                                    a.created_at,
                                                )}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/respite/risk-plan-activations/${a.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!activations.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No risk plan activations for this stay.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
