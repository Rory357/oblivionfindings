import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Building2,
    Calendar,
    HeartHandshake,
    Percent,
    PiggyBank,
    Shield,
    Sparkles,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Enrolment {
    id: number;
    plan_name: string;
    plan_type: string;
    provider: string | null;
    description: string | null;
    status: string;
    employee_contribution_rate: number | null;
    employer_contribution_rate: number | null;
    enrollment_date: string | null;
    opt_out_date: string | null;
}

interface Props {
    myHr: MyHrShellData;
    enrolments: Enrolment[];
}

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const PLAN_TYPE_CONFIG: Record<
    string,
    { label: string; icon: typeof PiggyBank; accent: string }
> = {
    kiwisaver: { label: 'KiwiSaver', icon: PiggyBank, accent: '#10b981' },
    health_insurance: {
        label: 'Health Insurance',
        icon: Shield,
        accent: '#0ea5e9',
    },
    life_insurance: {
        label: 'Life Insurance',
        icon: HeartHandshake,
        accent: '#8b5cf6',
    },
    other: { label: 'Other benefit', icon: Sparkles, accent: '#f59e0b' },
};

const STATUS_BADGE: Record<string, { label: string; className: string }> = {
    active: {
        label: 'Active',
        className:
            'border-status-success/30 bg-status-success-bg text-status-success',
    },
    opted_out: {
        label: 'Opted out',
        className: 'border-border bg-muted text-muted-foreground',
    },
    suspended: {
        label: 'Suspended',
        className:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
    terminated: {
        label: 'Ended',
        className:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
    },
};

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyBenefits({ myHr, enrolments = [] }: Props) {
    return (
        <MyHrShell active="benefits" myHr={myHr} title="Benefits · My HR">
            <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-base font-semibold">
                    <HeartHandshake className="h-4 w-4" />
                    My benefits
                </h2>
                <p className="text-xs text-muted-foreground">
                    {enrolments.length} enrolment
                    {enrolments.length !== 1 ? 's' : ''}
                </p>
            </div>

            {enrolments.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center gap-3 py-12">
                        <HeartHandshake className="h-10 w-10 text-muted-foreground/30" />
                        <div className="text-center">
                            <p className="font-medium">
                                No benefit enrolments yet
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                KiwiSaver and other benefits will appear here
                                once HR enrols you. Questions? Contact your HR
                                team.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-3">
                    {enrolments.map((e) => {
                        const config =
                            PLAN_TYPE_CONFIG[e.plan_type] ??
                            PLAN_TYPE_CONFIG.other;
                        const Icon = config.icon;
                        const status =
                            STATUS_BADGE[e.status] ?? STATUS_BADGE.active;

                        return (
                            <Card
                                key={e.id}
                                className="overflow-hidden transition-all hover:shadow-sm"
                            >
                                <div
                                    className="h-0.5"
                                    style={{ backgroundColor: config.accent }}
                                />
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-4">
                                        <div
                                            className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                            style={{
                                                backgroundColor: `${config.accent}1a`,
                                                color: config.accent,
                                            }}
                                        >
                                            <Icon className="h-5 w-5" />
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <h3 className="text-sm font-semibold">
                                                        {e.plan_name}
                                                    </h3>
                                                    <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                                        <span>
                                                            {config.label}
                                                        </span>
                                                        {e.provider ? (
                                                            <span className="flex items-center gap-1">
                                                                <Building2 className="h-3 w-3" />
                                                                {e.provider}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    className={`shrink-0 ${status.className}`}
                                                >
                                                    {status.label}
                                                </Badge>
                                            </div>

                                            {e.description ? (
                                                <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                                    {e.description}
                                                </p>
                                            ) : null}

                                            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                                                {e.employee_contribution_rate !==
                                                null ? (
                                                    <span className="flex items-center gap-1">
                                                        <Percent className="h-3 w-3" />
                                                        You contribute{' '}
                                                        <span className="font-semibold text-foreground">
                                                            {
                                                                e.employee_contribution_rate
                                                            }
                                                            %
                                                        </span>
                                                    </span>
                                                ) : null}
                                                {e.employer_contribution_rate !==
                                                null ? (
                                                    <span className="flex items-center gap-1">
                                                        <Percent className="h-3 w-3" />
                                                        Employer contributes{' '}
                                                        <span className="font-semibold text-foreground">
                                                            {
                                                                e.employer_contribution_rate
                                                            }
                                                            %
                                                        </span>
                                                    </span>
                                                ) : null}
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    Since{' '}
                                                    {formatDate(
                                                        e.enrollment_date,
                                                    )}
                                                </span>
                                                {e.opt_out_date ? (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        Opted out{' '}
                                                        {formatDate(
                                                            e.opt_out_date,
                                                        )}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}

            <p className="mt-4 text-xs text-muted-foreground">
                Benefits are read-only here — to change a contribution rate or
                opt in/out, contact your HR team.
            </p>
        </MyHrShell>
    );
}
