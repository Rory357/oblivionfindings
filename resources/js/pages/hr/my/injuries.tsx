import {
    INJURY_TYPES,
    injuryReference,
    severityLabel,
    statusLabel,
    treatmentLabel,
} from '@/components/health-safety/injury-constants';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { HeartPulse } from 'lucide-react';
import type { ReactNode } from 'react';

type Duty = {
    id: number;
    status: string | null;
    start_date: string | null;
    end_date: string | null;
    description: string | null;
    restrictions: string | null;
    accommodations: string | null;
    hours_per_day: number | null;
};

type Stage = {
    name: string | null;
    start_date: string | null;
    end_date: string | null;
    hours_per_week: number | null;
    duties: string | null;
};

type Plan = {
    id: number;
    status: string | null;
    start_date: string | null;
    end_date: string | null;
    next_review_date: string | null;
    manager: string | null;
    goals: string[];
    stages: Stage[];
    workplace_modifications: string | null;
    medical_clearance_date: string | null;
    medical_clearance_provider: string | null;
    worker_agreed: boolean;
    worker_agreed_at: string | null;
    duties: Duty[];
};

export type OwnInjury = {
    id: number;
    reference: string | null;
    status: string | null;
    severity: string | null;
    injury_type: string | null;
    body_part: string | null;
    description: string | null;
    injury_date: string | null;
    site: string | null;
    lost_time_days: number | null;
    expected_return_date: string | null;
    actual_return_date: string | null;
    next_review_date: string | null;
    coordinator: string | null;
    worksafe_notifiable: boolean;
    immediate_treatment: string | null;
    medical_treatment_type: string | null;
    acc_claim_lodged: boolean;
    acc_claim_number: string | null;
    plans: Plan[];
};

const STATUS_VARIANT: Record<string, StatusVariant> = {
    reported: 'warning',
    under_treatment: 'info',
    return_to_work: 'info',
    recovered: 'success',
    closed: 'neutral',
};

const SEVERITY_VARIANT: Record<string, StatusVariant> = {
    minor: 'success',
    moderate: 'warning',
    serious: 'critical',
    critical: 'critical',
};

function typeLabel(value: string | null): string {
    if (!value) return 'Workplace injury';
    return (
        INJURY_TYPES.find((type) => type.key === value)?.label ??
        value.replace(/_/g, ' ')
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-caption text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm break-words">{children}</dd>
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3 border-t border-border pt-4">
            <h3 className="text-section-title">{title}</h3>
            {children}
        </section>
    );
}

function dateRange(start: string | null, end: string | null): string {
    if (!start && !end) return '—';
    return `${formatDateOnly(start)} to ${end ? formatDateOnly(end) : 'ongoing'}`;
}

function PlanBlock({ plan }: { plan: Plan }) {
    return (
        <div className="space-y-3 rounded-lg border border-border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">Return-to-work plan</p>
                {plan.status ? (
                    <StatusBadge status={plan.status} size="sm" />
                ) : null}
            </div>
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Dates">
                    {dateRange(plan.start_date, plan.end_date)}
                </Fact>
                <Fact label="Next review">
                    {formatDateOnly(plan.next_review_date)}
                </Fact>
                <Fact label="Manager">{plan.manager ?? '—'}</Fact>
                <Fact label="You agreed">
                    {plan.worker_agreed
                        ? `Yes${plan.worker_agreed_at ? `, ${formatDateOnly(plan.worker_agreed_at)}` : ''}`
                        : 'Not yet'}
                </Fact>
                <Fact label="Medical clearance">
                    {plan.medical_clearance_date
                        ? `${formatDateOnly(plan.medical_clearance_date)}${plan.medical_clearance_provider ? ` · ${plan.medical_clearance_provider}` : ''}`
                        : '—'}
                </Fact>
                {plan.workplace_modifications ? (
                    <Fact label="Workplace changes">
                        {plan.workplace_modifications}
                    </Fact>
                ) : null}
            </dl>
            {plan.goals.length > 0 ? (
                <div>
                    <p className="text-caption text-muted-foreground">Goals</p>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
                        {plan.goals.map((goal, index) => (
                            <li key={index}>{goal}</li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {plan.stages.length > 0 ? (
                <div>
                    <p className="text-caption text-muted-foreground">Stages</p>
                    <ol className="mt-1 space-y-2 text-sm">
                        {plan.stages.map((stage, index) => (
                            <li key={index}>
                                <span className="font-medium">
                                    {stage.name ?? `Stage ${index + 1}`}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {dateRange(
                                        stage.start_date,
                                        stage.end_date,
                                    )}
                                    {stage.hours_per_week !== null
                                        ? ` · ${stage.hours_per_week} hours a week`
                                        : ''}
                                </span>
                                {stage.duties ? (
                                    <p className="text-subtle">
                                        {stage.duties}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </div>
            ) : null}
            {plan.duties.length > 0 ? (
                <div>
                    <p className="text-caption text-muted-foreground">
                        Modified duties
                    </p>
                    <ul className="mt-1 space-y-2 text-sm">
                        {plan.duties.map((duty) => (
                            <li
                                key={duty.id}
                                className="rounded-md bg-muted/50 p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {dateRange(
                                            duty.start_date,
                                            duty.end_date,
                                        )}
                                    </span>
                                    {duty.hours_per_day !== null ? (
                                        <span className="text-muted-foreground">
                                            {duty.hours_per_day} hours a day
                                        </span>
                                    ) : null}
                                    {duty.status ? (
                                        <StatusBadge
                                            status={duty.status}
                                            size="sm"
                                        />
                                    ) : null}
                                </div>
                                {duty.description ? (
                                    <p className="mt-1">{duty.description}</p>
                                ) : null}
                                {duty.restrictions ? (
                                    <p className="text-subtle mt-1">
                                        Restrictions: {duty.restrictions}
                                    </p>
                                ) : null}
                                {duty.accommodations ? (
                                    <p className="text-subtle mt-1">
                                        Support: {duty.accommodations}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

function InjuryCard({ injury }: { injury: OwnInjury }) {
    return (
        <Card>
            <CardContent className="space-y-4 p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="text-section-title">
                            {typeLabel(injury.injury_type)}
                            {injury.body_part ? ` · ${injury.body_part}` : ''}
                        </h2>
                        <p className="text-subtle">
                            {injuryReference(injury)} ·{' '}
                            {formatDateTime(injury.injury_date)}
                            {injury.site ? ` · ${injury.site}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {injury.status ? (
                            <StatusBadge
                                variant={
                                    STATUS_VARIANT[injury.status] ?? 'neutral'
                                }
                            >
                                {statusLabel(injury.status)}
                            </StatusBadge>
                        ) : null}
                        {injury.severity ? (
                            <StatusBadge
                                variant={
                                    SEVERITY_VARIANT[injury.severity] ??
                                    'neutral'
                                }
                            >
                                {severityLabel(injury.severity)}
                            </StatusBadge>
                        ) : null}
                    </div>
                </div>

                {injury.description ? (
                    <p className="text-sm">{injury.description}</p>
                ) : null}

                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="Coordinator">
                        {injury.coordinator ?? 'Not assigned yet'}
                    </Fact>
                    <Fact label="Days off work">
                        {injury.lost_time_days ?? 0}
                    </Fact>
                    <Fact label="Expected back">
                        {formatDateOnly(injury.expected_return_date)}
                    </Fact>
                    <Fact label="Back at work">
                        {formatDateOnly(injury.actual_return_date)}
                    </Fact>
                    <Fact label="Next review">
                        {formatDateOnly(injury.next_review_date)}
                    </Fact>
                    <Fact label="WorkSafe notifiable">
                        {injury.worksafe_notifiable ? 'Yes' : 'No'}
                    </Fact>
                </dl>

                <Section title="Care">
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Treatment">
                            {treatmentLabel(injury.medical_treatment_type)}
                        </Fact>
                        <Fact label="ACC claim">
                            {injury.acc_claim_lodged
                                ? (injury.acc_claim_number ?? 'Lodged')
                                : 'Not lodged'}
                        </Fact>
                        {injury.immediate_treatment ? (
                            <Fact label="Care given at the time">
                                {injury.immediate_treatment}
                            </Fact>
                        ) : null}
                    </dl>
                </Section>

                {injury.plans.length > 0 ? (
                    <Section title="Getting back to work">
                        <div className="space-y-3">
                            {injury.plans.map((plan) => (
                                <PlanBlock key={plan.id} plan={plan} />
                            ))}
                        </div>
                    </Section>
                ) : null}
            </CardContent>
        </Card>
    );
}

/**
 * /hr/my/injuries — the worker's own workplace injuries, read-only (Stephan,
 * 30 Oct: facts, care and the return-to-work plan). Internal notes and files
 * stay with the coordinator; questions go to them.
 */
export default function MyInjuries({
    myHr,
    injuries = [],
}: {
    myHr: MyHrShellData;
    injuries: OwnInjury[];
}) {
    return (
        <MyHrShell active="injuries" myHr={myHr} title="Injuries · My HR">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-section-title flex items-center gap-2">
                    <HeartPulse className="h-4 w-4" aria-hidden="true" />
                    My workplace injuries
                </h2>
                <p className="text-subtle">
                    Read-only. Ask your coordinator if something looks wrong.
                </p>
            </div>

            {injuries.length === 0 ? (
                <Card>
                    <CardContent className="py-12 text-center">
                        <p className="font-medium">
                            No workplace injuries recorded for you
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-4">
                    {injuries.map((injury) => (
                        <InjuryCard key={injury.id} injury={injury} />
                    ))}
                </div>
            )}
        </MyHrShell>
    );
}
