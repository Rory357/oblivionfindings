import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, toDateInput } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Clock3,
    ListChecks,
    UserPlus,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

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
        scope?: string;
        site?: { id: number; name: string } | null;
        service_context?: { id: number; name: string } | null;
    };
    capabilities?: { complete: boolean; open_person: boolean };
    requires_separate_service_start?: boolean;
    care_handovers?: {
        id: number;
        handed_over_at: string;
        handover_version?: number;
        status: 'current' | 'review_required';
        care: {
            goals?: string;
            participation?: string;
            safety_needs?: string;
        } | null;
        source_url: string | null;
    }[];
    errors?: Record<string, string>;
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

    return formatDateLong(value);
}

export default function OnboardingShow({
    workflow,
    capabilities,
    requires_separate_service_start,
    care_handovers = [],
    errors = {},
}: Props) {
    const [search, setSearch] = useState('');
    const [completing, setCompleting] = useState(false);
    const [overdueOnly, setOverdueOnly] = useState(false);
    const stepsRef = useRef<HTMLDivElement>(null);
    const today = toDateInput(new Date());

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
            s.due_date.slice(0, 10) < today,
    ).length;

    const clientHref =
        capabilities?.open_person && workflow.client
            ? `/operations/clients/${workflow.client.id}?tab=onboarding`
            : undefined;

    const shownSteps = useMemo(() => {
        const q = search.trim().toLowerCase();
        return workflow.steps.filter(
            (s) =>
                (!overdueOnly ||
                    (s.status === 'pending' &&
                        s.due_date &&
                        s.due_date.slice(0, 10) < today)) &&
                `${s.step_name} ${s.status} ${s.notes ?? ''}`
                    .toLowerCase()
                    .includes(q),
        );
    }, [workflow.steps, search, overdueOnly, today]);
    function reviewSteps(onlyOverdue: boolean) {
        setSearch('');
        setOverdueOnly(onlyOverdue);
        requestAnimationFrame(() => {
            stepsRef.current?.scrollIntoView({
                block: 'start',
                behavior: 'smooth',
            });
            stepsRef.current?.focus();
        });
    }

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
            subline={`${workflow.scope === 'additional_support' ? 'Additional support onboarding' : 'Person onboarding'}${workflow.service_context ? ` · ${workflow.service_context.name}` : ''}${workflow.site ? ` · ${workflow.site.name}` : ''} · started ${formatDate(
                workflow.started_at,
            )}${
                workflow.completed_at
                    ? ` · completed ${formatDate(workflow.completed_at)}`
                    : ''
            }`}
            actions={
                <>
                    {clientHref && (
                        <PageHeaderGlassButton icon={UserPlus} asChild>
                            <a href={clientHref}>
                                {capabilities?.complete
                                    ? 'Manage onboarding steps'
                                    : 'Open person'}
                            </a>
                        </PageHeaderGlassButton>
                    )}
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search steps…"
                    />
                    {workflow.status === 'in_progress' &&
                    capabilities?.complete ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            disabled={completing}
                            onClick={() =>
                                router.post(
                                    `/operations/onboarding/${workflow.id}/complete`,
                                    {},
                                    {
                                        onStart: () => setCompleting(true),
                                        onFinish: () => setCompleting(false),
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            {completing ? 'Completing…' : 'Complete workflow'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Progress"
                        value={`${doneSteps}/${totalSteps}`}
                        ariaLabel="Review all onboarding steps"
                        onClick={() => reviewSteps(false)}
                    >
                        <PageHeaderMeterBar percent={pct} />
                        <PageHeaderMeterCaption>
                            {pct}% of steps completed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue steps"
                        tone={overdueSteps > 0 ? 'critical' : 'success'}
                        ariaLabel="Review overdue onboarding steps"
                        onClick={() => reviewSteps(true)}
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
                {requires_separate_service_start && (
                    <Alert variant="info">
                        <AlertDescription>
                            Completing this workflow records the onboarding
                            work. A separate approved service commencement is
                            required before referral support starts. Existing
                            active support is preserved.
                        </AlertDescription>
                    </Alert>
                )}
                {errors.workflow && (
                    <Alert variant="warning">
                        <AlertDescription>{errors.workflow}</AlertDescription>
                    </Alert>
                )}
                {care_handovers.map((handover) => (
                    <Card key={handover.id}>
                        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                            <div>
                                <CardTitle>
                                    Reviewed care handover
                                    {handover.handover_version
                                        ? ` · version ${handover.handover_version}`
                                        : ''}
                                </CardTitle>
                                <p className="text-subtle">
                                    Prepared{' '}
                                    {formatDate(handover.handed_over_at)} · use
                                    to prepare the care plan
                                </p>
                            </div>
                            {handover.source_url && (
                                <Button asChild variant="outline" size="sm">
                                    <a href={handover.source_url}>
                                        Review referral
                                    </a>
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            {handover.status === 'review_required' ? (
                                <Alert variant="warning">
                                    <AlertDescription>
                                        The assessment or authority needs a new
                                        review. Ask the onboarding owner to
                                        arrange an updated care handover before
                                        relying on the earlier information.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <dl className="grid gap-4 md:grid-cols-3">
                                    {(
                                        [
                                            ['goals', 'Goals and priorities'],
                                            [
                                                'participation',
                                                'Participation and communication',
                                            ],
                                            [
                                                'safety_needs',
                                                'Safety and support needs',
                                            ],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <div key={key} className="min-w-0">
                                            <dt className="text-sm font-semibold">
                                                {label}
                                            </dt>
                                            <dd className="mt-1 text-sm break-words whitespace-pre-wrap text-muted-foreground">
                                                {handover.care?.[key] ||
                                                    'Not recorded — confirm with the onboarding owner'}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </CardContent>
                    </Card>
                ))}
                {overdueOnly && (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-subtle">Showing overdue steps</p>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => reviewSteps(false)}
                        >
                            Show all steps
                        </Button>
                    </div>
                )}
                <div ref={stepsRef} tabIndex={-1} className="space-y-2">
                    {shownSteps.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title="No steps"
                            description={
                                search.trim() !== '' || overdueOnly
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
