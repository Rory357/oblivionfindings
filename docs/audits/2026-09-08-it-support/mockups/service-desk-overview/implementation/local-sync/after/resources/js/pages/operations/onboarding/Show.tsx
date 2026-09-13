import {
    PageHeader,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Clock3,
    ListChecks,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Step = {
    id: number;
    step_name: string;
    status: string;
    due_date?: string | null;
    notes?: string | null;
};

type Props = {
    workflow: {
        id: number;
        status: string;
        started_at?: string | null;
        completed_at?: string | null;
        client?: { id: number; first_name: string; last_name: string } | null;
        steps: Step[];
    };
};

const WORKFLOW_BADGE: Record<
    string,
    { label: string; variant: StatusVariant }
> = {
    in_progress: { label: 'In progress', variant: 'info' },
    completed: { label: 'Completed', variant: 'success' },
    cancelled: { label: 'Cancelled', variant: 'neutral' },
};

const STEP_BADGE: Record<string, { label: string; variant: StatusVariant }> = {
    completed: { label: 'Completed', variant: 'success' },
    pending: { label: 'Pending', variant: 'neutral' },
    skipped: { label: 'Skipped', variant: 'neutral' },
};

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function OnboardingShow({ workflow }: Props) {
    const [search, setSearch] = useState('');

    const name = workflow.client
        ? `${workflow.client.first_name} ${workflow.client.last_name}`
        : `Onboarding workflow #${workflow.id}`;
    const badge = WORKFLOW_BADGE[workflow.status] ?? {
        label: workflow.status,
        variant: 'neutral' as StatusVariant,
    };

    const totalSteps = workflow.steps.length;
    const doneSteps = workflow.steps.filter(
        (s) => s.status === 'completed',
    ).length;
    const pct = totalSteps > 0 ? Math.round((doneSteps / totalSteps) * 100) : 0;
    const overdueSteps = workflow.steps.filter(
        (s) =>
            s.status === 'pending' &&
            s.due_date &&
            new Date(s.due_date).getTime() < Date.now(),
    ).length;

    const clientHref = workflow.client
        ? `/operations/clients/${workflow.client.id}?tab=onboarding`
        : '/operations/onboarding';

    const shownSteps = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return workflow.steps;
        return workflow.steps.filter((s) =>
            `${s.step_name} ${s.status} ${s.notes ?? ''}`
                .toLowerCase()
                .includes(q),
        );
    }, [workflow.steps, search]);

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/onboarding"
            icon={UserPlus}
            title={name}
            titleChip={
                <PageHeaderStatusChip variant={badge.variant}>
                    {badge.label}
                </PageHeaderStatusChip>
            }
            subline={`Onboarding workflow · started ${formatDate(
                workflow.started_at,
            )}${
                workflow.completed_at
                    ? ` · completed ${formatDate(workflow.completed_at)}`
                    : ''
            }`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search steps…"
                    />
                    {workflow.status === 'in_progress' ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            onClick={() =>
                                router.post(
                                    `/operations/onboarding/${workflow.id}/complete`,
                                )
                            }
                        >
                            Complete workflow
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Progress"
                        value={`${doneSteps}/${totalSteps}`}
                        ariaLabel="Open the onboarding tab on the client profile"
                        href={clientHref}
                    >
                        <PageHeaderMeterBar percent={pct} />
                        <PageHeaderMeterCaption>
                            {pct}% of steps completed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue steps"
                        tone={overdueSteps > 0 ? 'critical' : 'success'}
                        ariaLabel="Open the onboarding tab on the client profile"
                        href={clientHref}
                    >
                        <PageHeaderMeterBig>{overdueSteps}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            pending past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Onboarding', href: '/operations/onboarding' },
                {
                    title: name,
                    href: `/operations/onboarding/${workflow.id}`,
                },
            ]}
        >
            <Head title={`Onboarding · ${name}`} />

            <PageLayout hero={header}>
                <div className="space-y-2">
                    {shownSteps.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title="No steps"
                            description={
                                search.trim() !== ''
                                    ? 'No steps match your search.'
                                    : 'This workflow has no steps.'
                            }
                        />
                    ) : (
                        shownSteps.map((step) => {
                            const stepBadge = STEP_BADGE[step.status] ?? {
                                label: step.status,
                                variant: 'neutral' as StatusVariant,
                            };
                            return (
                                <Card key={step.id}>
                                    <CardContent className="flex items-start gap-3 p-4">
                                        <div className="mt-0.5">
                                            {step.status === 'completed' ? (
                                                <CheckCircle2 className="h-5 w-5 text-status-success" />
                                            ) : (
                                                <Circle className="h-5 w-5 text-muted-foreground" />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <p className="text-sm font-semibold">
                                                    {step.step_name}
                                                </p>
                                                <StatusBadge
                                                    variant={stepBadge.variant}
                                                >
                                                    {stepBadge.label}
                                                </StatusBadge>
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                <span className="inline-flex items-center gap-1">
                                                    <Clock3 className="h-3 w-3" />
                                                    Due{' '}
                                                    {formatDate(step.due_date)}
                                                </span>
                                                {step.notes && (
                                                    <span>{step.notes}</span>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
