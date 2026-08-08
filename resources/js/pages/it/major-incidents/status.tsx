import { ItModuleShell } from '@/components/it/it-module-shell';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    Clock3,
    Megaphone,
    Radio,
    ShieldCheck,
    Siren,
} from 'lucide-react';
import { majorIncidentLabel, majorIncidentStateVariant } from './index';

interface MajorIncidentStatusUpdate {
    id: number;
    update_kind: string;
    audience: 'staff' | 'public';
    summary: string;
    service_status: string | null;
    published_at: string | null;
}

interface MajorIncidentStatus {
    reference: string;
    title: string;
    severity: string;
    workflow_state: string;
    impact_summary: string | null;
    restored_at: string | null;
    updates: MajorIncidentStatusUpdate[];
}

interface Props {
    status: MajorIncidentStatus;
}

export default function ItMajorIncidentStatus({ status }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'IT & Support', href: '/it' },
        {
            title: `${status.reference} status`,
            href: '/it?tab=my-tickets',
        },
    ];
    const restored = status.restored_at !== null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${status.reference} · Service status`} />
            <ItModuleShell>
                <main className="mx-auto w-full max-w-[1100px] space-y-6 px-4 py-6 sm:px-6">
                    <header className="overflow-hidden rounded-2xl border border-status-critical/30 bg-card shadow-sm">
                        <div className="border-l-4 border-status-critical p-5 sm:p-6">
                            <Link
                                href="/it?tab=my-tickets"
                                className="frontline-focus inline-flex min-h-11 items-center gap-2 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground"
                            >
                                <ArrowLeft
                                    aria-hidden="true"
                                    className="h-4 w-4"
                                />
                                Back to my requests
                            </Link>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    variant={
                                        status.severity === 'sev1' ||
                                        status.severity === 'sev2'
                                            ? 'critical'
                                            : 'warning'
                                    }
                                >
                                    <Siren
                                        aria-hidden="true"
                                        className="h-3.5 w-3.5"
                                    />
                                    {status.severity.toUpperCase()}
                                </StatusBadge>
                                <StatusBadge
                                    variant={
                                        majorIncidentStateVariant[
                                            status.workflow_state
                                        ] ?? 'neutral'
                                    }
                                >
                                    {restored ? (
                                        <CheckCircle2
                                            aria-hidden="true"
                                            className="h-3.5 w-3.5"
                                        />
                                    ) : (
                                        <Radio
                                            aria-hidden="true"
                                            className="h-3.5 w-3.5"
                                        />
                                    )}
                                    {majorIncidentLabel(status.workflow_state)}
                                </StatusBadge>
                            </div>

                            <p className="mt-4 font-mono text-sm font-bold text-primary">
                                {status.reference}
                            </p>
                            <h1 className="mt-1 text-2xl font-bold tracking-tight">
                                {status.title}
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                Audience-safe service updates for affected
                                staff. Technical notes and internal response
                                details stay with the IT response team.
                            </p>
                        </div>
                    </header>

                    <section
                        aria-labelledby="incident-impact-heading"
                        className="rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <div className="flex items-start gap-3">
                            <CircleAlert
                                aria-hidden="true"
                                className="mt-0.5 h-5 w-5 flex-none text-status-critical"
                            />
                            <div>
                                <h2
                                    id="incident-impact-heading"
                                    className="font-semibold"
                                >
                                    Current impact
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {status.impact_summary ||
                                        'The affected service impact has not been described yet.'}
                                </p>
                            </div>
                        </div>
                        {status.restored_at ? (
                            <div className="mt-4 flex items-center gap-2 rounded-xl border border-status-success/30 bg-status-success-bg px-3 py-2.5 text-sm">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="h-4 w-4 flex-none text-status-success"
                                />
                                <span>
                                    Service restoration recorded{' '}
                                    <strong>
                                        {formatDateTime(
                                            status.restored_at,
                                            'time not recorded',
                                        )}
                                    </strong>
                                </span>
                            </div>
                        ) : null}
                    </section>

                    <section
                        aria-labelledby="incident-updates-heading"
                        className="rounded-2xl border border-border bg-card p-5 shadow-sm"
                    >
                        <div className="flex items-center gap-2">
                            <Megaphone
                                aria-hidden="true"
                                className="h-5 w-5 text-primary"
                            />
                            <h2
                                id="incident-updates-heading"
                                className="font-semibold"
                            >
                                Service updates
                            </h2>
                        </div>

                        {status.updates.length > 0 ? (
                            <ol className="mt-4 space-y-3">
                                {status.updates.map((update) => (
                                    <li
                                        key={update.id}
                                        className="rounded-xl border border-border/70 bg-muted/20 p-4"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                variant="info"
                                                size="sm"
                                            >
                                                <Megaphone
                                                    aria-hidden="true"
                                                    className="h-3 w-3"
                                                />
                                                {update.audience === 'public'
                                                    ? 'Public update'
                                                    : 'Staff update'}
                                            </StatusBadge>
                                            {update.service_status ? (
                                                <StatusBadge
                                                    variant={
                                                        update.service_status ===
                                                            'operational' ||
                                                        update.service_status ===
                                                            'restored'
                                                            ? 'success'
                                                            : 'warning'
                                                    }
                                                    size="sm"
                                                >
                                                    <Radio
                                                        aria-hidden="true"
                                                        className="h-3 w-3"
                                                    />
                                                    {majorIncidentLabel(
                                                        update.service_status,
                                                    )}
                                                </StatusBadge>
                                            ) : null}
                                            <span className="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                <Clock3
                                                    aria-hidden="true"
                                                    className="h-3.5 w-3.5"
                                                />
                                                {formatDateTime(
                                                    update.published_at,
                                                    'Time not recorded',
                                                )}
                                            </span>
                                        </div>
                                        <p className="mt-3 text-sm leading-6">
                                            {update.summary}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            <div className="mt-4 flex items-start gap-3 rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                                <Clock3
                                    aria-hidden="true"
                                    className="mt-0.5 h-4 w-4 flex-none"
                                />
                                No audience-safe service update has been
                                published yet. IT is still coordinating the
                                response.
                            </div>
                        )}
                    </section>
                </main>
            </ItModuleShell>
        </AppLayout>
    );
}
