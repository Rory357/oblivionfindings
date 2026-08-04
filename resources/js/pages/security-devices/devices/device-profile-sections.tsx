import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { formatDate, formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Cable,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Cpu,
    Download,
    FileClock,
    HardDrive,
    MapPin,
    RadioTower,
    Settings2,
    ShieldCheck,
    TicketCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { DeviceProfile, DeviceProfileSectionKey } from './device-profile';

function stateBadgeVariant(
    state: string | null,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (
        ['critical', 'failed', 'offline', 'rejected', 'mismatch'].includes(
            state ?? '',
        )
    ) {
        return 'destructive';
    }
    if (['healthy', 'active', 'fresh', 'aligned'].includes(state ?? '')) {
        return 'default';
    }
    if (
        [
            'warning',
            'degraded',
            'stale',
            'update_available',
            'drifted',
            'uncertain',
            'awaiting_approval',
            'awaiting_step_up',
            'awaiting_change',
            'blocked',
        ].includes(state ?? '')
    ) {
        return 'outline';
    }
    return 'secondary';
}

type ManagementAction = DeviceProfile['management']['actions'][number];
type CommandHistory = DeviceProfile['management']['history'][number];

const managementGroups: Array<{
    key: ManagementAction['group'];
    label: string;
    description: string;
}> = [
    {
        key: 'diagnostics',
        label: 'Diagnostics',
        description:
            'Read-safe checks that help confirm reachability and path health.',
    },
    {
        key: 'standard_management',
        label: 'Standard management',
        description:
            'Bounded operational changes with an explicit expected result.',
    },
    {
        key: 'high_risk_control',
        label: 'High-risk control',
        description:
            'Physical, privacy, access, safety, or destructive actions with extra safeguards.',
    },
];

export function DeviceManagementSection({
    profile,
    deviceId,
}: {
    profile: DeviceProfile;
    deviceId: number;
}) {
    const management = profile.management;
    const [selectedAction, setSelectedAction] =
        useState<ManagementAction | null>(null);
    const [parameters, setParameters] = useState<Record<string, string>>({});
    const [reason, setReason] = useState('');
    const [impactAcknowledged, setImpactAcknowledged] = useState(false);
    const [confirmationText, setConfirmationText] = useState('');
    const [itChangeId, setItChangeId] = useState('');
    const [useBreakGlass, setUseBreakGlass] = useState(false);
    const [breakGlassReason, setBreakGlassReason] = useState('');
    const [breakGlassReviewerId, setBreakGlassReviewerId] = useState('');
    const [requestError, setRequestError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [decisionCommand, setDecisionCommand] =
        useState<CommandHistory | null>(null);
    const [decision, setDecision] = useState<'approved' | 'rejected'>(
        'approved',
    );
    const [decisionComment, setDecisionComment] = useState('');
    const [breakGlassReviewCommand, setBreakGlassReviewCommand] =
        useState<CommandHistory | null>(null);
    const [breakGlassReviewOutcome, setBreakGlassReviewOutcome] = useState(
        'confirmed_appropriate',
    );
    const [breakGlassReviewSummary, setBreakGlassReviewSummary] = useState('');
    const deepLinkHandled = useRef(false);

    const openAction = useCallback(
        (
            action: ManagementAction,
            parameterOverrides: Record<string, string> = {},
        ) => {
            setSelectedAction(action);
            setReason('');
            setImpactAcknowledged(false);
            setConfirmationText('');
            setUseBreakGlass(false);
            setBreakGlassReason('');
            setBreakGlassReviewerId(
                management.breakGlassReviewers[0]
                    ? String(management.breakGlassReviewers[0].id)
                    : '',
            );
            setItChangeId(
                action.requiresChange && management.changeOptions.length > 0
                    ? String(management.changeOptions[0].id)
                    : '',
            );
            setRequestError(null);
            setParameters(
                Object.fromEntries(
                    action.parameters.map((parameter) => [
                        parameter.name,
                        parameterOverrides[parameter.name] ??
                            parameter.options[0] ??
                            (parameter.min === null
                                ? ''
                                : String(parameter.min)),
                    ]),
                ),
            );
        },
        [management.breakGlassReviewers, management.changeOptions],
    );

    useEffect(() => {
        if (deepLinkHandled.current || typeof window === 'undefined') return;

        const query = new URLSearchParams(window.location.search);
        const requested = query.get('action');
        if (!requested) {
            deepLinkHandled.current = true;
            return;
        }

        const action = management.actions.find(
            (candidate) => candidate.key === requested,
        );
        if (action && (!action.requiresStepUp || management.stepUpCurrent)) {
            const parameterOverrides = Object.fromEntries(
                action.parameters.flatMap((parameter) => {
                    const requestedValue = query.get(
                        `command_${parameter.name}`,
                    );

                    return requestedValue &&
                        (parameter.options.length === 0 ||
                            parameter.options.includes(requestedValue))
                        ? [[parameter.name, requestedValue]]
                        : [];
                }),
            );
            openAction(action, parameterOverrides);
        }
        deepLinkHandled.current = true;
    }, [management.actions, management.stepUpCurrent, openAction]);

    const requestAction = () => {
        if (!selectedAction) return;
        const suffix =
            typeof crypto !== 'undefined' && crypto.randomUUID
                ? crypto.randomUUID()
                : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
        const normalized = Object.fromEntries(
            selectedAction.parameters.map((parameter) => [
                parameter.name,
                parameter.type === 'integer'
                    ? Number(parameters[parameter.name])
                    : parameters[parameter.name],
            ]),
        );
        setSubmitting(true);
        setRequestError(null);
        router.post(
            `/security-devices/devices/${deviceId}/commands`,
            {
                capability: selectedAction.key,
                parameters: normalized,
                reason,
                idempotency_key: `command_${deviceId}_${suffix}`,
                impact_acknowledged: impactAcknowledged,
                ...(selectedAction.confirmationMode === 'type_device_name'
                    ? { confirmation_text: confirmationText }
                    : {}),
                ...(selectedAction.requiresChange && !useBreakGlass
                    ? { it_change_id: Number(itChangeId) }
                    : {}),
                ...(useBreakGlass
                    ? {
                          break_glass: true,
                          break_glass_reason: breakGlassReason,
                          break_glass_reviewer_user_id:
                              Number(breakGlassReviewerId),
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedAction(null),
                onError: (errors) =>
                    setRequestError(
                        Object.values(errors)[0] ??
                            'The command request could not be created.',
                    ),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const submitDecision = () => {
        if (!decisionCommand) return;
        setSubmitting(true);
        router.post(
            `/security-devices/commands/${decisionCommand.id}/decision`,
            { decision, comment: decisionComment },
            {
                preserveScroll: true,
                onSuccess: () => setDecisionCommand(null),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const queueCommand = (command: CommandHistory) => {
        router.post(
            `/security-devices/commands/${command.id}/dispatch`,
            {},
            { preserveScroll: true },
        );
    };

    const submitBreakGlassReview = () => {
        if (!breakGlassReviewCommand) return;
        setSubmitting(true);
        router.post(
            `/security-devices/commands/${breakGlassReviewCommand.id}/break-glass-review`,
            {
                outcome: breakGlassReviewOutcome,
                summary: breakGlassReviewSummary,
            },
            {
                preserveScroll: true,
                onSuccess: () => setBreakGlassReviewCommand(null),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <Metric
                    label="Declared actions"
                    value={management.summary.declared}
                />
                <Metric
                    label="Requestable"
                    value={management.summary.available}
                />
                <Metric
                    label="Awaiting approval"
                    value={management.summary.awaitingApproval}
                />
                <Metric
                    label="Needs reconciliation"
                    value={management.summary.uncertain}
                />
                <Metric
                    label="Blocked safely"
                    value={management.summary.blocked}
                />
                <Metric
                    label="Break-glass reviews"
                    value={management.summary.breakGlassReviewDue}
                />
            </div>

            <Card data-testid="management-target-context">
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <ShieldCheck className="h-4 w-4" /> Management target
                    </CardTitle>
                    <CardDescription>
                        Confirm the exact Device, Site, provider, and last known
                        state before requesting or approving an action.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <CommandDetail
                            label="Device"
                            value={profile.header.identity.name}
                        />
                        <CommandDetail
                            label="Site / location"
                            value={
                                profile.header.location?.name ??
                                'No confirmed Site'
                            }
                        />
                        <CommandDetail
                            label="Provider"
                            value={profile.header.providerObservation.label}
                        />
                        <CommandDetail
                            label="Last confirmed state"
                            value={
                                profile.header.health.deviceStateLabel ??
                                profile.header.health.label
                            }
                            detail={formatDateTime(
                                profile.header.freshness.observedAt,
                                'Observation time not collected',
                            )}
                        />
                    </dl>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Settings2 className="h-4 w-4" /> Governed actions
                    </CardTitle>
                    <CardDescription>
                        Only exact capabilities declared by this Device and an
                        approved provider adapter are actionable. High-risk
                        controls require fresh identity confirmation and an
                        independent decision.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {management.actions.length === 0 ? (
                        <EmptyState
                            icon={ShieldCheck}
                            title="No management actions available"
                            description="No management action is available for your current access and Device context. Monitoring remains available."
                            variant="compact"
                        />
                    ) : (
                        <div className="space-y-6">
                            {managementGroups.map((group) => {
                                const actions = management.actions.filter(
                                    (action) => action.group === group.key,
                                );
                                if (actions.length === 0) return null;

                                return (
                                    <section
                                        key={group.key}
                                        className="space-y-3"
                                    >
                                        <div>
                                            <h3 className="text-sm font-semibold">
                                                {group.label}
                                            </h3>
                                            <p className="text-xs text-muted-foreground">
                                                {group.description}
                                            </p>
                                        </div>
                                        <div className="grid gap-3 lg:grid-cols-2">
                                            {actions.map((action) => (
                                                <div
                                                    key={action.key}
                                                    className="rounded-xl border p-4"
                                                >
                                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                                        <div>
                                                            <p className="font-semibold">
                                                                {action.label}
                                                            </p>
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {humanise(
                                                                    action.level,
                                                                )}{' '}
                                                                ·{' '}
                                                                {humanise(
                                                                    action.risk,
                                                                )}{' '}
                                                                risk ·{' '}
                                                                {
                                                                    action.expiresAfterSeconds
                                                                }
                                                                s request window
                                                            </p>
                                                        </div>
                                                        <Badge
                                                            variant={
                                                                action.available
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                        >
                                                            {action.available
                                                                ? action.requiresStepUp &&
                                                                  !management.stepUpCurrent
                                                                    ? 'Identity check required'
                                                                    : 'Requestable'
                                                                : humanise(
                                                                      action.state,
                                                                  )}
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-3 text-sm text-muted-foreground">
                                                        {action.impact}
                                                    </p>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {action.reason}
                                                    </p>
                                                    <div className="mt-3 rounded-lg border bg-muted/40 p-3 text-xs">
                                                        <p className="font-medium">
                                                            Execution ·{' '}
                                                            {action.executionMode ===
                                                            'collector_runtime'
                                                                ? 'Remote Site collector'
                                                                : action.executionMode ===
                                                                    'central_runtime'
                                                                  ? 'Main application over the Site network'
                                                                  : 'No approved execution route'}
                                                        </p>
                                                        <p className="mt-1 text-muted-foreground">
                                                            {
                                                                action.executionGuidance
                                                            }
                                                        </p>
                                                    </div>
                                                    <div className="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                                        {action.requiresStepUp && (
                                                            <span>
                                                                Identity
                                                                confirmation
                                                            </span>
                                                        )}
                                                        {action.requiresMfa && (
                                                            <span>
                                                                Configured MFA
                                                            </span>
                                                        )}
                                                        {action.requiresFreshObservation && (
                                                            <span>
                                                                Fresh
                                                                observation ·{' '}
                                                                {humanise(
                                                                    action
                                                                        .freshness
                                                                        .state,
                                                                )}
                                                            </span>
                                                        )}
                                                        {action.requiresApproval && (
                                                            <span>
                                                                Independent
                                                                approval
                                                            </span>
                                                        )}
                                                        {action.requiresChange && (
                                                            <span>
                                                                IT Change window
                                                                {' · '}
                                                                {
                                                                    management
                                                                        .changeOptions
                                                                        .length
                                                                }{' '}
                                                                current
                                                            </span>
                                                        )}
                                                        {action.allowsBreakGlass &&
                                                            management
                                                                .breakGlassReviewers
                                                                .length > 0 && (
                                                                <span>
                                                                    Governed
                                                                    break glass
                                                                </span>
                                                            )}
                                                        <span>
                                                            Fresh-state
                                                            reconciliation
                                                        </span>
                                                    </div>
                                                    <div className="mt-4">
                                                        {action.available &&
                                                        action.requiresStepUp &&
                                                        !management.stepUpCurrent ? (
                                                            <Button
                                                                asChild
                                                                variant="outline"
                                                            >
                                                                <Link
                                                                    href={`/security-devices/devices/${deviceId}/commands/confirm-identity`}
                                                                >
                                                                    Confirm
                                                                    identity
                                                                    first
                                                                </Link>
                                                            </Button>
                                                        ) : (
                                                            <Button
                                                                onClick={() =>
                                                                    openAction(
                                                                        action,
                                                                    )
                                                                }
                                                                disabled={
                                                                    !action.available
                                                                }
                                                            >
                                                                Request action
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <FileClock className="h-4 w-4" /> Command history
                    </CardTitle>
                    <CardDescription>
                        Signed requests, independent decisions, execution, and
                        fresh-state outcomes for this Device.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {!management.canObserve ? (
                        <p className="text-sm text-muted-foreground">
                            Your role can request permitted actions but cannot
                            view command history.
                        </p>
                    ) : management.history.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No governed command has been requested for this
                            Device.
                        </p>
                    ) : (
                        management.history.map((command) => (
                            <div
                                key={command.uuid}
                                className="rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-semibold">
                                            {command.label}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Requested by{' '}
                                            {command.requestedBy ?? 'System'} ·{' '}
                                            {formatDateTime(
                                                command.requestedAt,
                                            )}
                                        </p>
                                    </div>
                                    <Badge
                                        variant={stateBadgeVariant(
                                            command.status,
                                        )}
                                    >
                                        {humanise(command.status)}
                                    </Badge>
                                </div>
                                <p className="mt-3 text-sm">{command.reason}</p>
                                <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
                                    <CommandDetail
                                        label="Expected state"
                                        value={formatSafeState(
                                            command.expectedState,
                                        )}
                                    />
                                    <CommandDetail
                                        label="Approved parameters"
                                        value={formatSafeState(
                                            command.safeParameters,
                                        )}
                                    />
                                    <CommandDetail
                                        label="Request expires"
                                        value={formatDateTime(
                                            command.expiresAt,
                                        )}
                                    />
                                    <CommandDetail
                                        label="Execution route"
                                        value={
                                            command.executionRoute
                                                ? humanise(
                                                      command.executionRoute,
                                                  )
                                                : 'Not dispatched'
                                        }
                                    />
                                    {command.confirmationMode &&
                                        command.confirmationMode !== 'none' && (
                                            <CommandDetail
                                                label="Impact confirmation"
                                                value={humanise(
                                                    command.confirmationMode,
                                                )}
                                                detail={formatDateTime(
                                                    command.impactAcknowledgedAt,
                                                )}
                                            />
                                        )}
                                </dl>
                                {command.approvedBy && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Approved by {command.approvedBy}
                                    </p>
                                )}
                                {command.change && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        IT Change:{' '}
                                        <Link
                                            href={`/it/changes/${command.change.id}`}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {command.change.reference} ·{' '}
                                            {command.change.title}
                                        </Link>
                                    </p>
                                )}
                                {command.breakGlass && (
                                    <div
                                        className={`mt-3 rounded-lg border p-3 text-xs ${
                                            command.breakGlass.overdue
                                                ? 'border-destructive/50 bg-destructive/5'
                                                : 'border-status-warning/30 bg-status-warning-bg'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="destructive">
                                                Break glass
                                            </Badge>
                                            <span className="font-medium">
                                                Post-use review{' '}
                                                {command.breakGlass.reviewedAt
                                                    ? 'completed'
                                                    : command.breakGlass.overdue
                                                      ? 'overdue'
                                                      : 'required'}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-muted-foreground">
                                            Designated reviewer:{' '}
                                            {command.breakGlass.reviewer ??
                                                'Unavailable'}{' '}
                                            · due{' '}
                                            {formatDateTime(
                                                command.breakGlass.reviewDueAt,
                                            )}
                                        </p>
                                        {command.breakGlass.emergencyReason && (
                                            <p className="mt-2">
                                                Emergency declaration:{' '}
                                                {
                                                    command.breakGlass
                                                        .emergencyReason
                                                }
                                            </p>
                                        )}
                                        {command.breakGlass.reviewedAt && (
                                            <p className="mt-2 text-muted-foreground">
                                                {humanise(
                                                    command.breakGlass
                                                        .outcome ?? 'reviewed',
                                                )}{' '}
                                                by{' '}
                                                {command.breakGlass
                                                    .reviewedBy ??
                                                    'Reviewer'}{' '}
                                                ·{' '}
                                                {formatDateTime(
                                                    command.breakGlass
                                                        .reviewedAt,
                                                )}
                                            </p>
                                        )}
                                        {command.breakGlass.reviewSummary && (
                                            <p className="mt-2">
                                                Review summary:{' '}
                                                {
                                                    command.breakGlass
                                                        .reviewSummary
                                                }
                                            </p>
                                        )}
                                    </div>
                                )}
                                {command.safeFailureReason && (
                                    <div className="mt-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                        <p>{command.safeFailureReason}</p>
                                        {command.blockedReasonCode && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Governance code:{' '}
                                                {humanise(
                                                    command.blockedReasonCode,
                                                )}
                                                {command.blockedAt
                                                    ? ` · ${formatDateTime(command.blockedAt)}`
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {command.latestAttempt && (
                                    <div className="mt-3 rounded-lg bg-muted/50 p-3 text-xs">
                                        <p className="font-medium">
                                            Execution attempt{' '}
                                            {command.latestAttempt.number} ·{' '}
                                            {humanise(
                                                command.latestAttempt.status,
                                            )}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {humanise(
                                                command.latestAttempt.runtime,
                                            )}{' '}
                                            runtime
                                            {command.latestAttempt.completedAt
                                                ? ` · ${formatDateTime(command.latestAttempt.completedAt)}`
                                                : ''}
                                        </p>
                                        {Object.keys(
                                            command.latestAttempt.safeResult,
                                        ).length > 0 && (
                                            <p className="mt-1 text-muted-foreground">
                                                Safe result:{' '}
                                                {formatSafeState(
                                                    command.latestAttempt
                                                        .safeResult,
                                                )}
                                            </p>
                                        )}
                                        {command.latestAttempt
                                            .safeFailureReason && (
                                            <p className="mt-1 text-destructive">
                                                {
                                                    command.latestAttempt
                                                        .safeFailureReason
                                                }
                                            </p>
                                        )}
                                    </div>
                                )}
                                {command.latestReconciliation && (
                                    <div className="mt-3 rounded-lg border p-3 text-xs">
                                        <p className="font-medium">
                                            Fresh-state verification ·{' '}
                                            {humanise(
                                                command.latestReconciliation
                                                    .outcome,
                                            )}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            {command.latestReconciliation
                                                .safeEvidenceSummary ??
                                                formatSafeState(
                                                    command.latestReconciliation
                                                        .observedState,
                                                )}
                                        </p>
                                    </div>
                                )}
                                <div className="mt-3 rounded-lg border-l-4 border-l-primary bg-muted/30 p-3 text-sm">
                                    <span className="font-medium">
                                        Next action:
                                    </span>{' '}
                                    {command.nextAction}
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" asChild>
                                        <a href={command.evidenceExportHref}>
                                            <Download className="mr-2 h-4 w-4" />
                                            Export audit evidence
                                        </a>
                                    </Button>
                                    {command.canDecide && (
                                        <>
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    setDecision('approved');
                                                    setDecisionComment('');
                                                    setDecisionCommand(command);
                                                }}
                                            >
                                                Review
                                            </Button>
                                        </>
                                    )}
                                    {command.canDispatch && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                queueCommand(command)
                                            }
                                        >
                                            {command.dispatchPreconditionsCurrent
                                                ? 'Add to execution queue'
                                                : 'Recheck request safely'}
                                        </Button>
                                    )}
                                    {command.canReviewBreakGlass && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setBreakGlassReviewOutcome(
                                                    'confirmed_appropriate',
                                                );
                                                setBreakGlassReviewSummary('');
                                                setBreakGlassReviewCommand(
                                                    command,
                                                );
                                            }}
                                        >
                                            Complete post-use review
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={selectedAction !== null}
                onOpenChange={(open) => !open && setSelectedAction(null)}
            >
                <DialogContent className="max-h-[calc(100vh-2rem)] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{selectedAction?.label}</DialogTitle>
                        <DialogDescription>
                            This creates a signed, expiring request. Execution
                            is not reported as successful until fresh Device
                            state matches the expected result.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {selectedAction && (
                            <div className="rounded-lg border bg-muted/30 p-3">
                                <dl className="grid gap-2 text-xs sm:grid-cols-2">
                                    <CommandDetail
                                        label="Device"
                                        value={profile.header.identity.name}
                                    />
                                    <CommandDetail
                                        label="Site / location"
                                        value={
                                            profile.header.location?.name ??
                                            'No confirmed Site'
                                        }
                                    />
                                    <CommandDetail
                                        label="Current state"
                                        value={
                                            profile.header.health
                                                .deviceStateLabel ??
                                            profile.header.health.label
                                        }
                                        detail={formatDateTime(
                                            profile.header.freshness.observedAt,
                                            'Observation time not collected',
                                        )}
                                    />
                                    <CommandDetail
                                        label="Governance"
                                        value={[
                                            selectedAction.requiresStepUp
                                                ? 'Identity confirmed'
                                                : null,
                                            selectedAction.requiresMfa
                                                ? 'Configured MFA'
                                                : null,
                                            selectedAction.requiresApproval
                                                ? 'Independent approval'
                                                : null,
                                            selectedAction.requiresChange
                                                ? 'IT Change window'
                                                : null,
                                            'Fresh-state reconciliation',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    />
                                    <CommandDetail
                                        label="Request expires"
                                        value={`${Math.ceil(selectedAction.expiresAfterSeconds / 60)} minute window`}
                                    />
                                    <CommandDetail
                                        label="Execution path"
                                        value={
                                            selectedAction.executionMode ===
                                            'central_runtime'
                                                ? 'Oblivion central runtime'
                                                : selectedAction.executionMode ===
                                                    'collector_runtime'
                                                  ? 'Remote Site collector'
                                                  : 'Not available'
                                        }
                                        detail={
                                            selectedAction.executionGuidance
                                        }
                                    />
                                </dl>
                            </div>
                        )}
                        {selectedAction?.confirmationMode !== 'none' && (
                            <div
                                className={`rounded-lg border p-3 ${
                                    selectedAction?.risk === 'critical'
                                        ? 'border-destructive/40 bg-destructive/5'
                                        : 'border-status-warning/30 bg-status-warning-bg'
                                }`}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={
                                            selectedAction?.risk === 'critical'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {humanise(selectedAction?.risk ?? '')}
                                    </Badge>
                                    <span className="text-sm font-semibold">
                                        Confirm impact before requesting
                                    </span>
                                </div>
                                <p className="mt-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    What could happen
                                </p>
                                <p className="mt-1 text-sm">
                                    {selectedAction?.impact}
                                </p>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Expected safe result:{' '}
                                    {selectedAction?.expectedResult}
                                </p>
                                <label className="mt-4 flex items-start gap-3 text-sm font-medium">
                                    <input
                                        id="command-impact-acknowledged"
                                        name="impact_acknowledged"
                                        type="checkbox"
                                        className="mt-0.5 h-4 w-4"
                                        checked={impactAcknowledged}
                                        onChange={(event) =>
                                            setImpactAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        I understand this impact and have
                                        checked the exact Device, Site, and
                                        current state.
                                    </span>
                                </label>
                                {selectedAction?.confirmationMode ===
                                    'type_device_name' && (
                                    <div className="mt-4 space-y-1.5">
                                        <label
                                            htmlFor="command-confirmation-text"
                                            className="text-sm font-medium"
                                        >
                                            Type{' '}
                                            <span className="font-mono">
                                                {profile.header.identity.name}
                                            </span>{' '}
                                            to confirm
                                        </label>
                                        <Input
                                            id="command-confirmation-text"
                                            value={confirmationText}
                                            onChange={(event) =>
                                                setConfirmationText(
                                                    event.target.value,
                                                )
                                            }
                                            autoComplete="off"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Critical actions require the exact
                                            Device name. This text is checked by
                                            the server and is not stored.
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}
                        {selectedAction?.parameters.map((parameter) => (
                            <div key={parameter.name} className="space-y-1.5">
                                <label
                                    htmlFor={`command-${parameter.name}`}
                                    className="text-sm font-medium"
                                >
                                    {parameter.label}
                                </label>
                                {parameter.options.length > 0 ? (
                                    <select
                                        id={`command-${parameter.name}`}
                                        value={parameters[parameter.name] ?? ''}
                                        onChange={(event) =>
                                            setParameters((current) => ({
                                                ...current,
                                                [parameter.name]:
                                                    event.target.value,
                                            }))
                                        }
                                        className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        {parameter.options.map((option) => (
                                            <option key={option} value={option}>
                                                {parameter.optionLabels?.[
                                                    option
                                                ] ?? humanise(option)}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <Input
                                        id={`command-${parameter.name}`}
                                        type={
                                            parameter.type === 'integer'
                                                ? 'number'
                                                : parameter.type === 'date_time'
                                                  ? 'datetime-local'
                                                  : 'text'
                                        }
                                        min={parameter.min ?? undefined}
                                        max={parameter.max ?? undefined}
                                        value={parameters[parameter.name] ?? ''}
                                        onChange={(event) =>
                                            setParameters((current) => ({
                                                ...current,
                                                [parameter.name]:
                                                    event.target.value,
                                            }))
                                        }
                                    />
                                )}
                            </div>
                        ))}
                        {selectedAction?.requiresChange && !useBreakGlass && (
                            <div className="space-y-1.5">
                                <label
                                    htmlFor="command-it-change"
                                    className="text-sm font-medium"
                                >
                                    Approved IT Change
                                </label>
                                <select
                                    id="command-it-change"
                                    value={itChangeId}
                                    onChange={(event) =>
                                        setItChangeId(event.target.value)
                                    }
                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    {management.changeOptions.map((change) => (
                                        <option
                                            key={change.id}
                                            value={String(change.id)}
                                        >
                                            {change.reference} · {change.title}{' '}
                                            · window ends{' '}
                                            {formatDateTime(
                                                change.maintenanceEndsAt,
                                            )}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-xs text-muted-foreground">
                                    Only changes linked to this Device and Site,
                                    in an approved execution state and current
                                    maintenance window, are listed.
                                </p>
                            </div>
                        )}
                        {selectedAction?.allowsBreakGlass && (
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                <label className="flex items-start gap-3 text-sm font-medium">
                                    <input
                                        id="command-break-glass"
                                        name="break_glass"
                                        type="checkbox"
                                        className="mt-0.5 h-4 w-4"
                                        checked={useBreakGlass}
                                        disabled={
                                            management.breakGlassReviewers
                                                .length === 0
                                        }
                                        onChange={(event) =>
                                            setUseBreakGlass(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        Declare emergency break glass
                                        <span className="mt-1 block font-normal text-muted-foreground">
                                            Bypasses the normal pre-approval
                                            path only. Device, Site, capability,
                                            current-state, signature, expiry and
                                            reconciliation controls still apply.
                                        </span>
                                    </span>
                                </label>
                                {management.breakGlassReviewers.length ===
                                    0 && (
                                    <p className="mt-2 text-xs text-status-warning">
                                        Break glass is unavailable because no
                                        different MFA-enabled command
                                        administrator can review this Device and
                                        Site.
                                    </p>
                                )}
                                {useBreakGlass && (
                                    <div className="mt-4 space-y-4">
                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="command-break-glass-reviewer"
                                                className="text-sm font-medium"
                                            >
                                                Designated post-use reviewer
                                            </label>
                                            <select
                                                id="command-break-glass-reviewer"
                                                value={breakGlassReviewerId}
                                                onChange={(event) =>
                                                    setBreakGlassReviewerId(
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                                            >
                                                {management.breakGlassReviewers.map(
                                                    (reviewer) => (
                                                        <option
                                                            key={reviewer.id}
                                                            value={String(
                                                                reviewer.id,
                                                            )}
                                                        >
                                                            {reviewer.name}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                            <p className="text-xs text-muted-foreground">
                                                This person is notified
                                                immediately and must record a
                                                permanent post-use review.
                                            </p>
                                        </div>
                                        <div className="space-y-1.5">
                                            <label
                                                htmlFor="command-break-glass-reason"
                                                className="text-sm font-medium"
                                            >
                                                Emergency declaration
                                            </label>
                                            <textarea
                                                id="command-break-glass-reason"
                                                rows={3}
                                                value={breakGlassReason}
                                                onChange={(event) =>
                                                    setBreakGlassReason(
                                                        event.target.value,
                                                    )
                                                }
                                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                                placeholder="What immediate harm or outage requires bypassing normal approval?"
                                            />
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <label
                                htmlFor="command-reason"
                                className="text-sm font-medium"
                            >
                                Operational reason
                            </label>
                            <textarea
                                id="command-reason"
                                rows={4}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="Who needs this action, why now, and what outcome is expected?"
                            />
                        </div>
                        {requestError && (
                            <p className="text-sm text-destructive">
                                {requestError}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setSelectedAction(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            id="command-create-request"
                            onClick={requestAction}
                            disabled={
                                submitting ||
                                reason.trim().length < 10 ||
                                (selectedAction?.confirmationMode !== 'none' &&
                                    !impactAcknowledged) ||
                                (selectedAction?.confirmationMode ===
                                    'type_device_name' &&
                                    confirmationText !==
                                        profile.header.identity.name) ||
                                (selectedAction?.requiresChange &&
                                    !useBreakGlass &&
                                    !itChangeId) ||
                                (useBreakGlass &&
                                    (breakGlassReason.trim().length < 20 ||
                                        !breakGlassReviewerId))
                            }
                        >
                            {submitting ? 'Creating…' : 'Create request'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={decisionCommand !== null}
                onOpenChange={(open) => !open && setDecisionCommand(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Review command request</DialogTitle>
                        <DialogDescription>
                            You must be independent from the requester. Confirm
                            the Device, Site, reason, timing, and expected state
                            before deciding.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {decisionCommand && (
                            <div className="rounded-lg border bg-muted/30 p-3">
                                <dl className="grid gap-2 text-xs sm:grid-cols-2">
                                    <CommandDetail
                                        label="Device"
                                        value={profile.header.identity.name}
                                    />
                                    <CommandDetail
                                        label="Site / location"
                                        value={
                                            profile.header.location?.name ??
                                            'No confirmed Site'
                                        }
                                    />
                                    <CommandDetail
                                        label="Requested by"
                                        value={
                                            decisionCommand.requestedBy ??
                                            'System'
                                        }
                                    />
                                    <CommandDetail
                                        label="Request expires"
                                        value={formatDateTime(
                                            decisionCommand.expiresAt,
                                        )}
                                    />
                                    <CommandDetail
                                        label="Operational reason"
                                        value={decisionCommand.reason}
                                    />
                                    <CommandDetail
                                        label="Expected state"
                                        value={formatSafeState(
                                            decisionCommand.expectedState,
                                        )}
                                    />
                                </dl>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                variant={
                                    decision === 'approved'
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => setDecision('approved')}
                            >
                                Approve
                            </Button>
                            <Button
                                variant={
                                    decision === 'rejected'
                                        ? 'destructive'
                                        : 'outline'
                                }
                                onClick={() => setDecision('rejected')}
                            >
                                Reject
                            </Button>
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="command-decision-comment"
                                className="text-sm font-medium"
                            >
                                Decision comment
                            </label>
                            <textarea
                                id="command-decision-comment"
                                rows={4}
                                value={decisionComment}
                                onChange={(event) =>
                                    setDecisionComment(event.target.value)
                                }
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDecisionCommand(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                decision === 'rejected'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={submitDecision}
                            disabled={
                                submitting || decisionComment.trim().length < 10
                            }
                        >
                            Record decision
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={breakGlassReviewCommand !== null}
                onOpenChange={(open) =>
                    !open && setBreakGlassReviewCommand(null)
                }
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Post-use break-glass review</DialogTitle>
                        <DialogDescription>
                            Confirm whether the emergency bypass was appropriate
                            and record any required follow-up. This review is
                            permanent.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {breakGlassReviewCommand?.breakGlass && (
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                <dl className="grid gap-2 text-xs sm:grid-cols-2">
                                    <CommandDetail
                                        label="Device"
                                        value={profile.header.identity.name}
                                    />
                                    <CommandDetail
                                        label="Site / location"
                                        value={
                                            profile.header.location?.name ??
                                            'No confirmed Site'
                                        }
                                    />
                                    <CommandDetail
                                        label="Requested by"
                                        value={
                                            breakGlassReviewCommand.requestedBy ??
                                            'System'
                                        }
                                    />
                                    <CommandDetail
                                        label="Emergency declaration"
                                        value={
                                            breakGlassReviewCommand.breakGlass
                                                .emergencyReason ??
                                            'Restricted emergency narrative'
                                        }
                                    />
                                    <CommandDetail
                                        label="Execution outcome"
                                        value={humanise(
                                            breakGlassReviewCommand
                                                .latestAttempt?.status ??
                                                breakGlassReviewCommand.status,
                                        )}
                                    />
                                    <CommandDetail
                                        label="Fresh-state outcome"
                                        value={humanise(
                                            breakGlassReviewCommand
                                                .latestReconciliation
                                                ?.outcome ?? 'Not confirmed',
                                        )}
                                    />
                                </dl>
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <label
                                htmlFor="command-break-glass-review-outcome"
                                className="text-sm font-medium"
                            >
                                Review outcome
                            </label>
                            <select
                                id="command-break-glass-review-outcome"
                                value={breakGlassReviewOutcome}
                                onChange={(event) =>
                                    setBreakGlassReviewOutcome(
                                        event.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option value="confirmed_appropriate">
                                    Confirmed appropriate
                                </option>
                                <option value="follow_up_required">
                                    Follow-up required
                                </option>
                                <option value="incident_required">
                                    Incident required
                                </option>
                            </select>
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="command-break-glass-review-summary"
                                className="text-sm font-medium"
                            >
                                Permanent review summary
                            </label>
                            <textarea
                                id="command-break-glass-review-summary"
                                rows={4}
                                value={breakGlassReviewSummary}
                                onChange={(event) =>
                                    setBreakGlassReviewSummary(
                                        event.target.value,
                                    )
                                }
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="What was verified, and what follow-up is required?"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setBreakGlassReviewCommand(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitBreakGlassReview}
                            disabled={
                                submitting ||
                                breakGlassReviewSummary.trim().length < 20
                            }
                        >
                            Record permanent review
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function humanise(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    return value.replace(/[._-]+/g, ' ');
}

function formatSafeState(state: Record<string, unknown>): string {
    const entries = Object.entries(state);
    if (entries.length === 0) return 'Not recorded';

    return entries
        .map(([key, value]) => {
            const display =
                value === null || value === undefined
                    ? 'Not recorded'
                    : typeof value === 'object'
                      ? JSON.stringify(value)
                      : humanise(String(value));

            return `${humanise(key)}: ${display}`;
        })
        .join(' · ');
}

function CommandDetail({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string | null;
}) {
    return (
        <div className="rounded-md border p-2">
            <dt className="font-medium text-muted-foreground">{label}</dt>
            <dd className="mt-1">{value}</dd>
            {detail && (
                <dd className="mt-1 text-[11px] text-muted-foreground">
                    {detail}
                </dd>
            )}
        </div>
    );
}

function Metric({
    label,
    value,
    detail,
}: {
    label: string;
    value: string | number | null | undefined;
    detail?: string | null;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact metric tile is a definition-list-like atom, not a standalone card.
        <div className="min-w-0 rounded-lg border bg-card p-3">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 truncate text-sm font-semibold">
                {value ?? 'Not recorded'}
            </p>
            {detail && (
                <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
            )}
        </div>
    );
}

export function DeviceProfileHeader({
    profile,
    onOpenSection,
}: {
    profile: DeviceProfile;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const { header } = profile;
    const ActionIcon =
        header.requiredAction.state === 'none'
            ? CheckCircle2
            : header.requiredAction.state === 'critical'
              ? AlertTriangle
              : Clock3;

    return (
        <Card data-testid="device-profile-header" className="overflow-hidden">
            <CardContent className="space-y-4 p-4 md:p-5">
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div className="sm:col-span-2 xl:col-span-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Identity
                        </p>
                        <p className="mt-1 font-semibold">
                            {header.identity.type || 'Registered device'}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground">
                            {header.identity.uid}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Location
                        </p>
                        {header.location?.href ? (
                            <Link
                                href={header.location.href}
                                className="mt-1 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                            >
                                <MapPin className="h-3.5 w-3.5" />
                                {header.location.name}
                            </Link>
                        ) : (
                            <p className="mt-1 text-sm font-semibold">
                                {header.location?.name ?? 'Unassigned'}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {header.assignment
                                ? `${humanise(header.assignment.type)} assignment`
                                : 'No current assignment'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Health
                        </p>
                        <div className="mt-1 flex flex-wrap gap-1.5">
                            <Badge
                                variant={stateBadgeVariant(header.health.state)}
                            >
                                {header.health.label}
                            </Badge>
                            <Badge variant="outline">
                                {humanise(header.health.deviceState)}
                            </Badge>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Freshness
                        </p>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge
                                variant={stateBadgeVariant(
                                    header.freshness.state,
                                )}
                            >
                                {humanise(header.freshness.state)}
                            </Badge>
                            <span
                                className="text-xs text-muted-foreground"
                                title={formatDateTime(
                                    header.freshness.observedAt,
                                )}
                            >
                                {formatRelative(
                                    header.freshness.observedAt,
                                    Date.now(),
                                    'Never observed',
                                )}
                            </span>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Observation source
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            {header.providerObservation.label}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {humanise(header.providerObservation.source)}
                        </p>
                    </div>
                </div>

                <div
                    className={`flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:items-center sm:justify-between ${
                        header.requiredAction.state === 'critical'
                            ? 'border-destructive/30 bg-destructive/5'
                            : 'bg-muted/30'
                    }`}
                >
                    <div className="flex min-w-0 gap-3">
                        <ActionIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p className="text-sm font-semibold">
                                {header.requiredAction.label}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {header.requiredAction.description}
                            </p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="shrink-0"
                        onClick={() =>
                            onOpenSection(header.requiredAction.section)
                        }
                    >
                        Open details <ArrowRight className="ml-1 h-3.5 w-3.5" />
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

export function DeviceHealthSection({ profile }: { profile: DeviceProfile }) {
    const monitoring = profile.health.monitoring;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Metric
                    label="Device state"
                    value={humanise(profile.health.deviceState)}
                    detail={`Health: ${humanise(profile.health.state)}`}
                />
                <Metric
                    label="Last seen"
                    value={formatRelative(
                        profile.health.lastSeenAt,
                        Date.now(),
                        'Never observed',
                    )}
                    detail={formatDateTime(
                        profile.health.lastSeenAt,
                        'No timestamp',
                    )}
                />
                <Metric
                    label="Last signal"
                    value={formatRelative(
                        profile.health.lastSignalAt,
                        Date.now(),
                        'Not collected',
                    )}
                    detail={formatDateTime(
                        profile.health.lastSignalAt,
                        'No signal timestamp',
                    )}
                />
                <Metric
                    label="Battery"
                    value={
                        profile.health.batteryLevel === null
                            ? 'Not collected'
                            : `${profile.health.batteryLevel}%`
                    }
                    detail={
                        profile.health.batteryUpdatedAt
                            ? `Updated ${formatRelative(profile.health.batteryUpdatedAt)}`
                            : null
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Activity className="h-4 w-4" /> Native monitoring
                        summary
                    </CardTitle>
                    <CardDescription>
                        Current retained checks for this device. Missing
                        evidence is shown honestly as not collected.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {monitoring ? (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Metric
                                label="Enabled"
                                value={monitoring.enabled}
                            />
                            <Metric
                                label="Healthy"
                                value={monitoring.healthy}
                            />
                            <Metric
                                label="Needs attention"
                                value={monitoring.attention}
                            />
                            <Metric
                                label="Uncertain"
                                value={monitoring.uncertain}
                            />
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            You can see this device record, but your role cannot
                            open monitoring evidence.
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card className="border-dashed">
                <CardContent className="flex gap-3 p-4">
                    <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                    <div>
                        <p className="text-sm font-semibold">
                            Governed management boundary
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {profile.capabilities.control.reason}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

export function DeviceMonitorsSection({ profile }: { profile: DeviceProfile }) {
    if (profile.monitors.length === 0) {
        return (
            <EmptyState
                icon={RadioTower}
                title="No monitoring coverage"
                description="No native checks are assigned to this device. Add coverage from Monitoring when the device capability and your role allow it."
                variant="compact"
            />
        );
    }

    return (
        <div className="grid gap-3 lg:grid-cols-2">
            {profile.monitors.map((monitor) => (
                <Card key={monitor.id}>
                    <CardContent className="space-y-3 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate font-semibold">
                                    {monitor.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {monitor.kindLabel}
                                    {monitor.affectsAvailability
                                        ? ' · affects availability'
                                        : ''}
                                </p>
                            </div>
                            <Badge variant={stateBadgeVariant(monitor.state)}>
                                {monitor.enabled
                                    ? humanise(monitor.state)
                                    : 'disabled'}
                            </Badge>
                        </div>
                        <div className="grid grid-cols-2 gap-2 text-xs">
                            <Metric
                                label="Last observation"
                                value={formatRelative(
                                    monitor.lastObservationAt,
                                    Date.now(),
                                    'Never observed',
                                )}
                            />
                            <Metric
                                label="Monitoring profile"
                                value={monitor.profile?.name ?? 'Not assigned'}
                            />
                        </div>
                        {monitor.collector && (
                            <div className="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2 text-xs">
                                <span>{monitor.collector.name}</span>
                                <span className="text-muted-foreground">
                                    Collector{' '}
                                    {humanise(monitor.collector.status)}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function formatRate(value: number | null): string {
    if (value === null) return 'Not collected';
    if (value >= 1_000_000_000)
        return `${(value / 1_000_000_000).toFixed(1)} Gbps`;
    if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)} Mbps`;
    if (value >= 1_000) return `${(value / 1_000).toFixed(1)} Kbps`;
    return `${value} bps`;
}

export function DeviceInterfacesSensorsSection({
    profile,
}: {
    profile: DeviceProfile;
}) {
    if (profile.interfacesSensors.length === 0) {
        return (
            <EmptyState
                icon={Cable}
                title="No interface or sensor evidence"
                description="Native collection has not retained interface or sensor observations for this device."
                variant="compact"
            />
        );
    }

    return (
        <div className="grid gap-3 xl:grid-cols-2">
            {profile.interfacesSensors.map((sensor) => (
                <Card key={sensor.monitorId}>
                    <CardHeader className="pb-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <CardTitle className="text-base">
                                    {sensor.name}
                                </CardTitle>
                                <CardDescription>
                                    {humanise(sensor.kind)}
                                    {sensor.index !== null
                                        ? ` · interface ${sensor.index}`
                                        : ''}
                                </CardDescription>
                            </div>
                            <Badge variant={stateBadgeVariant(sensor.state)}>
                                {humanise(sensor.state)}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <Metric
                            label="Reading"
                            value={
                                sensor.value === null
                                    ? 'Not collected'
                                    : `${sensor.value}${sensor.unit ?? ''}`
                            }
                        />
                        <Metric
                            label="In / out"
                            value={`${formatRate(sensor.inBps)} / ${formatRate(sensor.outBps)}`}
                        />
                        <Metric
                            label="Utilisation"
                            value={`${sensor.inUtilisation ?? '—'}% / ${sensor.outUtilisation ?? '—'}%`}
                        />
                        <Metric
                            label="Link state"
                            value={
                                sensor.operationalStatus ??
                                sensor.adminStatus ??
                                'Not collected'
                            }
                        />
                        <Metric
                            label="Errors / discards"
                            value={`${sensor.errors ?? '—'} / ${sensor.discards ?? '—'}`}
                        />
                        <Metric
                            label="Observed"
                            value={formatRelative(
                                sensor.observedAt,
                                Date.now(),
                                'Never',
                            )}
                        />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export function DeviceConfigurationSection({
    profile,
    editHref,
    onEditServiceDue,
}: {
    profile: DeviceProfile;
    editHref: string;
    onEditServiceDue?: () => void;
}) {
    const { registry, configuration, firmware } = profile.configuration;

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle className="flex items-center gap-2">
                            <HardDrive className="h-4 w-4" /> Registry identity
                        </CardTitle>
                        <CardDescription>
                            Deliberately allowlisted device fields. Provider
                            payloads and credential-shaped configuration are not
                            exposed here.
                        </CardDescription>
                    </div>
                    {profile.capabilities.registry.available && (
                        <div className="flex flex-wrap justify-end gap-2">
                            {onEditServiceDue && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={onEditServiceDue}
                                >
                                    Update service date
                                </Button>
                            )}
                            <Button size="sm" variant="outline" asChild>
                                <Link href={editHref}>Edit registry</Link>
                            </Button>
                        </div>
                    )}
                </CardHeader>
                <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric
                        label="Manufacturer"
                        value={registry.manufacturer}
                    />
                    <Metric label="Model" value={registry.model} />
                    <Metric label="Serial" value={registry.serialNumber} />
                    <Metric label="Asset tag" value={registry.assetTag} />
                    <Metric label="IP address" value={registry.ipAddress} />
                    <Metric label="MAC address" value={registry.macAddress} />
                    <Metric label="IMEI" value={registry.imei} />
                    <Metric
                        label="Next service due"
                        value={formatDate(registry.nextServiceDue)}
                    />
                    <Metric
                        label="Commissioned"
                        value={formatDate(registry.commissionedAt)}
                    />
                    <Metric
                        label="Warranty expires"
                        value={formatDate(registry.warrantyExpiresAt)}
                    />
                    <Metric
                        label="Expected lifespan"
                        value={
                            registry.expectedLifespanMonths === null
                                ? null
                                : `${registry.expectedLifespanMonths} months`
                        }
                    />
                    <Metric
                        label="Purchase price"
                        value={
                            registry.purchasePrice === null
                                ? null
                                : new Intl.NumberFormat('en-NZ', {
                                      style: 'currency',
                                      currency: 'NZD',
                                  }).format(Number(registry.purchasePrice))
                        }
                    />
                    <Metric
                        label="Groups"
                        value={
                            registry.groups.length > 0
                                ? registry.groups
                                      .map((group) => group.name)
                                      .join(', ')
                                : null
                        }
                    />
                    <Metric
                        label="Registered by"
                        value={registry.createdBy?.name}
                        detail={formatDateTime(registry.createdAt)}
                    />
                </CardContent>
                {registry.notes && (
                    <CardContent className="pt-0">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Registry notes
                        </p>
                        <p className="mt-1 text-sm whitespace-pre-wrap">
                            {registry.notes}
                        </p>
                    </CardContent>
                )}
            </Card>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Settings2 className="h-4 w-4" /> Configuration
                            evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Badge variant={stateBadgeVariant(configuration.state)}>
                            {humanise(configuration.state)}
                        </Badge>
                        <Metric
                            label="Observed hash"
                            value={configuration.observedHash}
                            detail={formatDateTime(
                                configuration.observedAt,
                                'Observation time not collected',
                            )}
                        />
                        <Metric
                            label="Desired hash"
                            value={configuration.desiredHash}
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Cpu className="h-4 w-4" /> Firmware evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Badge variant={stateBadgeVariant(firmware.state)}>
                            {humanise(firmware.state)}
                        </Badge>
                        <Metric
                            label="Current version"
                            value={firmware.currentVersion}
                            detail={formatDateTime(
                                firmware.observedAt,
                                'Observation time not collected',
                            )}
                        />
                        <Metric
                            label="Desired version"
                            value={firmware.desiredVersion}
                        />
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

export function DeviceTicketsSection({ profile }: { profile: DeviceProfile }) {
    if (profile.tickets.length === 0) {
        return (
            <EmptyState
                icon={TicketCheck}
                title="No linked IT work"
                description="Tickets linked with this device as an affected device will appear here."
                variant="compact"
            />
        );
    }

    return (
        <div className="space-y-2">
            {profile.tickets.map((ticket) => (
                <Link
                    key={ticket.id}
                    href={ticket.href}
                    className="flex min-h-16 flex-col gap-2 rounded-xl border bg-card p-4 transition-colors hover:border-primary/40 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-mono text-xs font-semibold text-primary">
                                {ticket.reference}
                            </span>
                            <Badge variant={stateBadgeVariant(ticket.status)}>
                                {humanise(ticket.status)}
                            </Badge>
                            <Badge variant="outline">
                                {humanise(ticket.priority)}
                            </Badge>
                        </div>
                        <p className="mt-1 truncate text-sm font-semibold">
                            {ticket.title}
                        </p>
                        {ticket.nextAction && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Next: {ticket.nextAction}
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                        {humanise(ticket.workType)}
                        <ArrowRight className="h-4 w-4" />
                    </div>
                </Link>
            ))}
        </div>
    );
}

export function DeviceAuditSection({ profile }: { profile: DeviceProfile }) {
    if (profile.audit.length === 0) {
        return (
            <EmptyState
                icon={FileClock}
                title="No audit entries"
                description="Canonical device changes will appear here without exposing raw before-and-after payloads."
                variant="compact"
            />
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ClipboardList className="h-4 w-4" /> Device audit
                </CardTitle>
                <CardDescription>
                    Who changed the canonical device record and which fields
                    were involved. Raw values remain protected.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-2">
                {profile.audit.map((entry) => (
                    <div
                        key={entry.id}
                        className="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                {humanise(entry.action)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {entry.actor ?? 'System'}
                                {entry.fields.length > 0
                                    ? ` · ${entry.fields.map(humanise).join(', ')}`
                                    : ''}
                            </p>
                        </div>
                        <span
                            className="shrink-0 text-xs text-muted-foreground"
                            title={formatDateTime(entry.createdAt)}
                        >
                            {formatRelative(entry.createdAt)}
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
