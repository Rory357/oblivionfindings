import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { ConfirmChip } from '@/components/control-room/alert-workspace-dialog';
import { PlaybookWizard } from '@/components/control-room/playbook-wizard';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    ExternalLink,
    Layers,
    Lock,
    Pencil,
    Power,
    Search as SearchIcon,
    Shield,
    Star,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';

// --- Types ---

interface PlaybookStep {
    id: number;
    order: number;
    title: string;
    type: string;
    instructions: string | null;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: number | null;
    decision_options: string[] | null;
    notify_config: Record<string, any> | null;
    evidence_config: Record<string, any> | null;
}

interface PlaybookDetail {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    trigger_alert_types: string[];
    trigger_severities: string[];
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    required_evidence: string[];
    requires_approval: boolean;
    approval_roles: string[];
    escalation_after_minutes: number | null;
    escalation_targets: string[];
    created_by: { id: number; name: string } | null;
    updated_by: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
    steps: PlaybookStep[];
}

interface RunAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
}

interface RunEntry {
    id: number;
    alert_id: number;
    alert: RunAlert | null;
    status: string;
    current_step: number;
    completed_steps: number;
    total_steps: number;
    progress: number;
    started_at: string | null;
    completed_at: string | null;
    started_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
}

interface StepEditForm {
    id: number | null;
    title: string;
    type: string;
    instructions: string;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: string;
}

interface Props {
    playbook: PlaybookDetail;
    recentRuns: RunEntry[];
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const categoryConfig: Record<
    string,
    { color: string; icon: typeof AlertTriangle }
> = {
    emergency: {
        color: 'bg-status-critical-bg text-status-critical border-status-critical/30',
        icon: AlertTriangle,
    },
    safety: {
        color: 'bg-status-warning-bg text-status-warning border-status-warning/30',
        icon: Shield,
    },
    compliance: {
        color: 'bg-status-info-bg text-status-info border-status-info/30',
        icon: CheckCircle,
    },
    maintenance: {
        color: 'bg-muted text-foreground border-border',
        icon: Wrench,
    },
    investigation: {
        color: 'bg-primary/10 text-primary border-primary',
        icon: SearchIcon,
    },
};

const stepTypeColors: Record<string, string> = {
    task: 'bg-status-info-bg text-status-info',
    decision: 'bg-primary/10 text-primary',
    notification: 'bg-status-warning-bg text-status-warning',
    escalation: 'bg-status-critical-bg text-status-critical',
    evidence: 'bg-status-success-bg text-status-success',
    approval: 'bg-status-warning-bg text-status-warning',
};

const runStatusColors: Record<string, string> = {
    pending: 'bg-muted text-foreground',
    in_progress: 'bg-status-info-bg text-status-info',
    completed: 'bg-status-success-bg text-status-success',
    failed: 'bg-status-critical-bg text-status-critical',
    cancelled: 'bg-muted text-muted-foreground',
};

const severityColors: Record<string, string> = {
    critical: 'bg-status-critical text-white',
    high: 'bg-status-warning text-white',
    medium: 'bg-status-warning text-black',
    low: 'bg-status-success text-white',
};

function formatDuration(
    startIso: string | null,
    endIso: string | null,
): string {
    if (!startIso || !endIso) return '-';
    const start = new Date(startIso).getTime();
    const end = new Date(endIso).getTime();
    const diffMs = end - start;
    const mins = Math.floor(diffMs / 60000);
    const hrs = Math.floor(mins / 60);
    if (hrs > 0) return `${hrs}h ${mins % 60}m`;
    return `${mins}m`;
}

// --- Component ---

export default function PlaybookShow({
    playbook,
    recentRuns,
    categories,
    stepTypes,
    can,
}: Props) {
    const [wizardOpen, setWizardOpen] = useState(false);

    const catConfig =
        categoryConfig[playbook.category] ?? categoryConfig.maintenance;
    const CatIcon = catConfig.icon;

    const toggleActive = () => {
        router.post(
            `/control-room/playbooks/${playbook.id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Playbooks', href: '/control-room/playbooks' },
                {
                    title: playbook.name,
                    href: `/control-room/playbooks/${playbook.id}`,
                },
            ]}
        >
            <Head title={`${playbook.name} - Playbooks`} />

            <div className="flex flex-col gap-6 p-6">
                <PageShell>
                    {/* Header */}
                    <CommandCentrePage
                        variant="compact"
                        current="/control-room/playbooks"
                        icon={CatIcon}
                        title={playbook.name}
                        description={
                            playbook.description ??
                            'Review this response procedure and its ordered steps.'
                        }
                        status={`${categories[playbook.category] ?? playbook.category} · v${playbook.version} · ${playbook.is_active ? 'active' : 'inactive'}`}
                        actions={
                            can.manage ? (
                                <div className="flex items-center gap-2">
                                    <ConfirmChip
                                        label={
                                            playbook.is_active
                                                ? 'Deactivate'
                                                : 'Activate'
                                        }
                                        icon={Power}
                                        destructive={playbook.is_active}
                                        onConfirm={toggleActive}
                                        title={
                                            playbook.is_active
                                                ? 'Stop this playbook auto-attaching to new alerts'
                                                : 'Make this playbook available'
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setWizardOpen(true)}
                                    >
                                        <Pencil className="mr-1 h-3 w-3" />
                                        Edit
                                    </Button>
                                </div>
                            ) : undefined
                        }
                    >
                        {
                            /* ==================== VIEW MODE ==================== */
                            <div className="space-y-6">
                                {/* Info Cards Row */}
                                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {/* Trigger Conditions */}
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Trigger Conditions
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-2">
                                                <div>
                                                    <span className="text-xs font-medium text-muted-foreground">
                                                        Alert Types
                                                    </span>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {playbook
                                                            .trigger_alert_types
                                                            .length > 0 ? (
                                                            playbook.trigger_alert_types.map(
                                                                (t) => (
                                                                    <Badge
                                                                        key={t}
                                                                        variant="outline"
                                                                        className="text-xs"
                                                                    >
                                                                        {t}
                                                                    </Badge>
                                                                ),
                                                            )
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                Any
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div>
                                                    <span className="text-xs font-medium text-muted-foreground">
                                                        Severities
                                                    </span>
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {playbook
                                                            .trigger_severities
                                                            .length > 0 ? (
                                                            playbook.trigger_severities.map(
                                                                (s) => (
                                                                    <Badge
                                                                        key={s}
                                                                        className={`text-xs ${severityColors[s] ?? ''}`}
                                                                    >
                                                                        {s}
                                                                    </Badge>
                                                                ),
                                                            )
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                Any
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 pt-1">
                                                    {playbook.auto_attach && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-status-success-bg text-xs text-status-success"
                                                        >
                                                            Auto-attach
                                                        </Badge>
                                                    )}
                                                    {playbook.requires_approval && (
                                                        <Badge
                                                            variant="outline"
                                                            className="bg-status-warning-bg text-xs text-status-warning"
                                                        >
                                                            Requires Approval
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* SLA Targets */}
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                SLA Targets
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-3">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm">
                                                        Acknowledge
                                                    </span>
                                                    <span className="font-mono text-sm font-medium">
                                                        {playbook.sla_acknowledge_minutes
                                                            ? `${playbook.sla_acknowledge_minutes} min`
                                                            : '-'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm">
                                                        Response
                                                    </span>
                                                    <span className="font-mono text-sm font-medium">
                                                        {playbook.sla_response_minutes
                                                            ? `${playbook.sla_response_minutes} min`
                                                            : '-'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between">
                                                    <span className="text-sm">
                                                        Resolution
                                                    </span>
                                                    <span className="font-mono text-sm font-medium">
                                                        {playbook.sla_resolution_minutes
                                                            ? `${playbook.sla_resolution_minutes} min`
                                                            : '-'}
                                                    </span>
                                                </div>
                                                {playbook.escalation_after_minutes && (
                                                    <div className="flex items-center justify-between border-t pt-2">
                                                        <span className="text-sm text-muted-foreground">
                                                            Escalate after
                                                        </span>
                                                        <span className="font-mono text-sm font-medium text-status-critical">
                                                            {
                                                                playbook.escalation_after_minutes
                                                            }{' '}
                                                            min
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Required Evidence */}
                                    <Card>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                                Required Evidence
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            {playbook.required_evidence.length >
                                            0 ? (
                                                <div className="flex flex-wrap gap-1.5">
                                                    {playbook.required_evidence.map(
                                                        (type) => (
                                                            <Badge
                                                                key={type}
                                                                variant="outline"
                                                                className="text-xs"
                                                            >
                                                                {type.replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    No evidence requirements
                                                    specified.
                                                </p>
                                            )}
                                            {playbook.created_by && (
                                                <p className="mt-4 text-xs text-muted-foreground">
                                                    Created by{' '}
                                                    {playbook.created_by.name}
                                                    {playbook.created_at &&
                                                        ` on ${formatDateTime(playbook.created_at)}`}
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>

                                {/* Steps */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Layers className="h-4 w-4" />
                                            Steps ({playbook.steps.length})
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {playbook.steps.length === 0 ? (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No steps defined.
                                            </p>
                                        ) : (
                                            <div className="space-y-3">
                                                {playbook.steps.map((step) => (
                                                    <div
                                                        key={step.id}
                                                        className="flex items-start gap-3 rounded-lg border p-3"
                                                    >
                                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                                                            {step.order}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium">
                                                                    {step.title}
                                                                </span>
                                                                <Badge
                                                                    className={`text-xs ${stepTypeColors[step.type] ?? 'bg-muted text-foreground'}`}
                                                                >
                                                                    {stepTypes[
                                                                        step
                                                                            .type
                                                                    ] ??
                                                                        step.type}
                                                                </Badge>
                                                                {step.is_required && (
                                                                    <span
                                                                        className="flex items-center gap-0.5 text-xs text-status-warning"
                                                                        title="Required"
                                                                    >
                                                                        <Star className="h-3 w-3" />
                                                                        Required
                                                                    </span>
                                                                )}
                                                                {step.is_blocking && (
                                                                    <span
                                                                        className="flex items-center gap-0.5 text-xs text-status-critical"
                                                                        title="Blocking"
                                                                    >
                                                                        <Lock className="h-3 w-3" />
                                                                        Blocking
                                                                    </span>
                                                                )}
                                                                {step.time_limit_minutes && (
                                                                    <span className="flex items-center gap-0.5 text-xs text-muted-foreground">
                                                                        <Clock className="h-3 w-3" />
                                                                        {
                                                                            step.time_limit_minutes
                                                                        }
                                                                        m
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {step.instructions && (
                                                                <p className="mt-1 text-sm text-muted-foreground">
                                                                    {
                                                                        step.instructions
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Run History */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Run History
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {recentRuns.length === 0 ? (
                                            <p className="py-4 text-center text-sm text-muted-foreground">
                                                No runs yet. This playbook has
                                                not been executed.
                                            </p>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>
                                                                Alert
                                                            </TableHead>
                                                            <TableHead>
                                                                Status
                                                            </TableHead>
                                                            <TableHead>
                                                                Progress
                                                            </TableHead>
                                                            <TableHead>
                                                                Started
                                                            </TableHead>
                                                            <TableHead>
                                                                Completed
                                                            </TableHead>
                                                            <TableHead>
                                                                Duration
                                                            </TableHead>
                                                            <TableHead>
                                                                Started By
                                                            </TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {recentRuns.map(
                                                            (run) => (
                                                                <TableRow
                                                                    key={run.id}
                                                                >
                                                                    <TableCell>
                                                                        {run.alert ? (
                                                                            <Link
                                                                                href={`/control-room/alerts/${run.alert_id}`}
                                                                                className="flex items-center gap-1 text-sm hover:underline"
                                                                            >
                                                                                <span className="font-medium">
                                                                                    #
                                                                                    {
                                                                                        run.alert_id
                                                                                    }
                                                                                </span>
                                                                                <Badge
                                                                                    className={`text-[10px] ${severityColors[run.alert.severity] ?? ''}`}
                                                                                >
                                                                                    {
                                                                                        run
                                                                                            .alert
                                                                                            .severity
                                                                                    }
                                                                                </Badge>
                                                                                <ExternalLink className="h-3 w-3 text-muted-foreground" />
                                                                            </Link>
                                                                        ) : (
                                                                            <span className="text-sm text-muted-foreground">
                                                                                #
                                                                                {
                                                                                    run.alert_id
                                                                                }
                                                                            </span>
                                                                        )}
                                                                    </TableCell>
                                                                    <TableCell>
                                                                        <Badge
                                                                            variant="outline"
                                                                            className={`text-xs ${runStatusColors[run.status] ?? ''}`}
                                                                        >
                                                                            {run.status.replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            )}
                                                                        </Badge>
                                                                    </TableCell>
                                                                    <TableCell>
                                                                        <div className="flex items-center gap-2">
                                                                            <div className="h-2 w-20 overflow-hidden rounded-full bg-muted">
                                                                                <div
                                                                                    className="h-full rounded-full bg-primary transition-all"
                                                                                    style={{
                                                                                        width: `${run.progress}%`,
                                                                                    }}
                                                                                />
                                                                            </div>
                                                                            <span className="text-xs text-muted-foreground">
                                                                                {
                                                                                    run.completed_steps
                                                                                }

                                                                                /
                                                                                {
                                                                                    run.total_steps
                                                                                }
                                                                            </span>
                                                                        </div>
                                                                    </TableCell>
                                                                    <TableCell className="text-sm">
                                                                        {formatDateTime(
                                                                            run.started_at,
                                                                        )}
                                                                    </TableCell>
                                                                    <TableCell className="text-sm">
                                                                        {formatDateTime(
                                                                            run.completed_at,
                                                                        )}
                                                                    </TableCell>
                                                                    <TableCell className="font-mono text-sm">
                                                                        {formatDuration(
                                                                            run.started_at,
                                                                            run.completed_at,
                                                                        )}
                                                                    </TableCell>
                                                                    <TableCell className="text-sm">
                                                                        {run
                                                                            .started_by
                                                                            ?.name ??
                                                                            '-'}
                                                                    </TableCell>
                                                                </TableRow>
                                                            ),
                                                        )}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        }
                    </CommandCentrePage>
                </PageShell>
            </div>

            {/* Guided playbook editor — basics → steps → automation → review.
                Mounted only while open so every run starts fresh. */}
            {wizardOpen ? (
                <PlaybookWizard
                    open
                    onClose={() => setWizardOpen(false)}
                    categories={categories}
                    stepTypes={stepTypes}
                    playbook={{
                        id: playbook.id,
                        name: playbook.name,
                        description: playbook.description ?? '',
                        category: playbook.category,
                        auto_attach: playbook.auto_attach,
                        requires_approval: playbook.requires_approval,
                        sla_acknowledge_minutes:
                            playbook.sla_acknowledge_minutes?.toString() ?? '',
                        sla_response_minutes:
                            playbook.sla_response_minutes?.toString() ?? '',
                        sla_resolution_minutes:
                            playbook.sla_resolution_minutes?.toString() ?? '',
                        required_evidence: playbook.required_evidence ?? [],
                        steps: playbook.steps.map((s) => ({
                            id: s.id,
                            title: s.title,
                            type: s.type,
                            instructions: s.instructions ?? '',
                            is_required: s.is_required,
                            is_blocking: s.is_blocking,
                            time_limit_minutes:
                                s.time_limit_minutes?.toString() ?? '',
                        })),
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
