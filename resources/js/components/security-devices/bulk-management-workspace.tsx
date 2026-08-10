import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    ExternalLink,
    Layers3,
    LockKeyhole,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type ChangeOption = {
    id: number;
    reference: string;
    title: string;
    workflowState: string;
    maintenanceEndsAt: string | null;
};

type BulkActionAvailability = {
    available: boolean;
    state: string;
    reason: string;
};

export type BulkManagementDevice = {
    id: number;
    name: string;
    uid: string;
    category: string;
    subcategory: string | null;
    provider: string | null;
    status: string;
    health: string;
    siteId: number | null;
    siteName: string;
    changeOptions: ChangeOption[];
    actions: Record<string, BulkActionAvailability>;
};

type BulkParameter = {
    name: string;
    label: string;
    type: 'integer' | 'string' | 'date_time';
    min: number | null;
    max: number | null;
    options: string[];
    optionLabels?: Record<string, string>;
};

export type BulkManagementAction = {
    key: string;
    label: string;
    risk: 'low' | 'medium' | 'high' | 'critical';
    level: string;
    sensitivity: string;
    impact: string;
    expectedResult: string;
    requiresStepUp: boolean;
    requiresMfa: boolean;
    requiresFreshObservation: boolean;
    requiresApproval: boolean;
    requiresChange: boolean;
    expiresAfterSeconds: number;
    confirmationMode: string;
    eligibleCount: number;
    declaredCount: number;
    parameters: BulkParameter[];
};

export type BulkManagementWorkspaceData = {
    workspace: string;
    actions: BulkManagementAction[];
    devices: BulkManagementDevice[];
    candidateCount: number;
    totalVisibleCount: number;
    truncated: boolean;
    targetLimit: number;
    canObserve: boolean;
    canRequest: boolean;
    stepUpCurrent: boolean;
    recentBatches: Array<{
        id: number;
        uuid: string;
        label: string;
        risk: BulkManagementAction['risk'];
        status: string;
        requestedBy: string | null;
        requestedAt: string | null;
        summary: {
            selected: number;
            included: number;
            excluded: number;
            sites: number;
            awaitingApproval: number;
            ready: number;
            queuedOrRunning: number;
            terminal: number;
            reconciled: number;
            failedOrBlocked: number;
        };
        href: string;
    }>;
};

const elevatedSensitivities = new Set([
    'personal_location',
    'privileged_remote',
    'destructive_endpoint',
    'security_control',
    'cctv_media',
    'availability_control',
    'broad_availability',
    'healthcare_technical',
    'facilities_control',
]);

function humanise(value: string): string {
    return value.replaceAll('_', ' ').replaceAll('.', ' ');
}

function initialParameters(
    action: BulkManagementAction | undefined,
): Record<string, string> {
    if (!action) return {};

    return Object.fromEntries(
        action.parameters.map((parameter) => [
            parameter.name,
            parameter.options[0] ??
                (parameter.min === null ? '' : String(parameter.min)),
        ]),
    );
}

function initialChanges(
    action: BulkManagementAction | undefined,
    devices: BulkManagementDevice[],
): Record<number, string> {
    if (!action?.requiresChange) return {};

    return Object.fromEntries(
        devices
            .filter((device) => device.actions[action.key]?.available)
            .filter((device) => device.changeOptions[0])
            .map((device) => [device.id, String(device.changeOptions[0].id)]),
    );
}

function riskTone(risk: BulkManagementAction['risk']): string {
    if (risk === 'critical') return 'border-destructive/40 text-destructive';
    if (risk === 'high') return 'border-status-warning/40 text-status-warning';

    return 'border-border text-muted-foreground';
}

export function BulkManagementWorkspace({
    data,
}: {
    data: BulkManagementWorkspaceData;
}) {
    const firstAction =
        data.actions.find((action) => action.eligibleCount > 0) ??
        data.actions[0];
    const [actionKey, setActionKey] = useState(firstAction?.key ?? '');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [parameters, setParameters] = useState<Record<string, string>>(
        initialParameters(firstAction),
    );
    const [changeIds, setChangeIds] = useState<Record<number, string>>(
        initialChanges(firstAction, data.devices),
    );
    const [reason, setReason] = useState('');
    const [impactAcknowledged, setImpactAcknowledged] = useState(false);
    const [confirmationText, setConfirmationText] = useState('');
    const [reviewOpen, setReviewOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [requestError, setRequestError] = useState<string | null>(null);
    const handoffApplied = useRef(false);

    const action = data.actions.find(
        (candidate) => candidate.key === actionKey,
    );
    const declaredDevices = useMemo(
        () =>
            action
                ? data.devices.filter((device) => device.actions[action.key])
                : [],
        [action, data.devices],
    );
    const selectedDevices = useMemo(
        () =>
            declaredDevices.filter((device) => selectedIds.includes(device.id)),
        [declaredDevices, selectedIds],
    );
    const includedDevices = useMemo(
        () =>
            action
                ? selectedDevices.filter(
                      (device) => device.actions[action.key]?.available,
                  )
                : [],
        [action, selectedDevices],
    );
    const excludedDevices = useMemo(
        () =>
            action
                ? selectedDevices.filter(
                      (device) => !device.actions[action.key]?.available,
                  )
                : [],
        [action, selectedDevices],
    );
    const selectedSiteNames = Array.from(
        new Set(includedDevices.map((device) => device.siteName)),
    );
    const elevated = Boolean(
        action &&
        (['high', 'critical'].includes(action.risk) ||
            action.requiresStepUp ||
            action.requiresMfa ||
            elevatedSensitivities.has(action.sensitivity) ||
            selectedSiteNames.length > 1),
    );
    const confirmationPhrase = `BULK ${selectedDevices.length} DEVICES`;
    const missingChanges = Boolean(
        action?.requiresChange &&
        includedDevices.some((device) => !changeIds[device.id]),
    );

    useEffect(() => {
        if (handoffApplied.current || typeof window === 'undefined') return;
        handoffApplied.current = true;
        const query = new URLSearchParams(window.location.search);
        const requestedAction = query.get('bulk_action');
        const nextAction = data.actions.find(
            (candidate) => candidate.key === requestedAction,
        );
        if (!nextAction) return;
        const requestedIds = new Set(
            (query.get('bulk_device_ids') ?? '')
                .split(',')
                .map(Number)
                .filter((id) => Number.isInteger(id) && id > 0),
        );
        const selected = data.devices.filter((device) =>
            requestedIds.has(device.id),
        );
        const nextParameters = initialParameters(nextAction);
        nextAction.parameters.forEach((parameter) => {
            const value = query.get(`bulk_${parameter.name}`);
            if (
                value &&
                (parameter.options.length === 0 ||
                    parameter.options.includes(value))
            ) {
                nextParameters[parameter.name] = value;
            }
        });
        setActionKey(nextAction.key);
        setSelectedIds(selected.map((device) => device.id));
        setParameters(nextParameters);
        setChangeIds(initialChanges(nextAction, selected));
    }, [data.actions, data.devices]);

    const chooseAction = (nextKey: string) => {
        const nextAction = data.actions.find(
            (candidate) => candidate.key === nextKey,
        );
        setActionKey(nextKey);
        setSelectedIds([]);
        setParameters(initialParameters(nextAction));
        setChangeIds(initialChanges(nextAction, data.devices));
        setReason('');
        setImpactAcknowledged(false);
        setConfirmationText('');
        setRequestError(null);
    };

    const toggleDevice = (deviceId: number, checked: boolean) => {
        setSelectedIds((current) =>
            checked
                ? Array.from(new Set([...current, deviceId]))
                : current.filter((id) => id !== deviceId),
        );
    };

    const selectEligible = () => {
        if (!action) return;
        setSelectedIds(
            declaredDevices
                .filter((device) => device.actions[action.key]?.available)
                .map((device) => device.id),
        );
    };

    const requestBatch = () => {
        if (!action) return;
        const suffix =
            typeof crypto !== 'undefined' && crypto.randomUUID
                ? crypto.randomUUID()
                : `${Date.now()}_${Math.random().toString(16).slice(2)}`;
        const normalizedParameters = Object.fromEntries(
            action.parameters.map((parameter) => [
                parameter.name,
                parameter.type === 'integer'
                    ? Number(parameters[parameter.name])
                    : parameters[parameter.name],
            ]),
        );
        const selectedChangeIds = action.requiresChange
            ? Object.fromEntries(
                  includedDevices
                      .filter((device) => changeIds[device.id])
                      .map((device) => [
                          device.id,
                          Number(changeIds[device.id]),
                      ]),
              )
            : {};

        setSubmitting(true);
        setRequestError(null);
        router.post(
            '/security-devices/command-batches',
            {
                workspace: data.workspace,
                device_ids: selectedDevices.map((device) => device.id),
                capability: action.key,
                parameters: normalizedParameters,
                reason,
                idempotency_key: `bulk_${data.workspace}_${suffix}`,
                it_change_ids: selectedChangeIds,
                impact_acknowledged: impactAcknowledged,
                confirmation_text: elevated ? confirmationText : null,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setRequestError(
                        Object.values(errors)[0] ??
                            'The governed bulk request could not be created.',
                    ),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    if (!data.canObserve && !data.canRequest) {
        return (
            <Card>
                <CardContent className="flex min-h-40 items-center gap-3 p-6">
                    <LockKeyhole className="h-5 w-5 text-muted-foreground" />
                    <div>
                        <p className="font-semibold">
                            Management is restricted
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Your role can view Device inventory but cannot
                            inspect governed management actions.
                        </p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <section
            aria-labelledby="bulk-management-heading"
            className="space-y-4"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1">
                            <CardTitle>
                                <h2
                                    id="bulk-management-heading"
                                    className="flex items-center gap-2"
                                >
                                    <Layers3 className="h-5 w-5 text-primary" />
                                    Governed Device management
                                </h2>
                            </CardTitle>
                            <p className="max-w-3xl text-sm text-muted-foreground">
                                Choose one provider-declared action, review
                                every Device and Site, then create one
                                independently governed child request per
                                included target.
                            </p>
                        </div>
                        <Badge variant="outline">
                            {data.totalVisibleCount} visible Devices
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent className="grid gap-4 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,2.2fr)]">
                    <div className="space-y-3 rounded-xl border bg-muted/20 p-4">
                        <label
                            htmlFor="bulk-management-action"
                            className="text-sm font-semibold"
                        >
                            Management action
                        </label>
                        <select
                            id="bulk-management-action"
                            value={actionKey}
                            onChange={(event) =>
                                chooseAction(event.target.value)
                            }
                            className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            {data.actions.map((candidate) => (
                                <option
                                    key={candidate.key}
                                    value={candidate.key}
                                >
                                    {candidate.label} ·{' '}
                                    {candidate.eligibleCount} ready
                                </option>
                            ))}
                        </select>
                        {action ? (
                            <div className="space-y-3 text-sm">
                                <div className="flex flex-wrap gap-2">
                                    <Badge
                                        variant="outline"
                                        className={riskTone(action.risk)}
                                    >
                                        {humanise(action.risk)} risk
                                    </Badge>
                                    <Badge variant="outline">
                                        {humanise(action.level)} level
                                    </Badge>
                                    {action.requiresApproval ? (
                                        <Badge variant="outline">
                                            Independent approval
                                        </Badge>
                                    ) : null}
                                </div>
                                <div>
                                    <p className="font-semibold">
                                        Expected result
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        {action.expectedResult}
                                    </p>
                                </div>
                                <div>
                                    <p className="font-semibold">
                                        Possible impact
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        {action.impact}
                                    </p>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {Math.ceil(action.expiresAfterSeconds / 60)}
                                    -minute request window · fresh-state
                                    reconciliation required
                                </p>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No provider has declared a governed management
                                action for the visible Devices in this
                                workspace.
                            </p>
                        )}
                    </div>

                    <div className="min-w-0 space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p className="font-semibold">Choose targets</p>
                                <p className="text-xs text-muted-foreground">
                                    Two to {data.targetLimit} Devices.
                                    Unavailable selected targets remain explicit
                                    exclusions.
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={selectEligible}
                                    disabled={
                                        !action || action.eligibleCount === 0
                                    }
                                >
                                    Select all ready
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSelectedIds([])}
                                    disabled={selectedIds.length === 0}
                                >
                                    Clear
                                </Button>
                            </div>
                        </div>
                        {data.truncated ? (
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm">
                                Showing the first {data.candidateCount} of{' '}
                                {data.totalVisibleCount} visible Devices. Narrow
                                the workspace inventory before managing a
                                different set.
                            </div>
                        ) : null}
                        <div className="max-h-[34rem] overflow-auto rounded-xl border">
                            <table className="w-full min-w-[48rem] text-left text-sm">
                                <thead className="sticky top-0 z-10 bg-muted/95 text-xs text-muted-foreground backdrop-blur">
                                    <tr>
                                        <th className="w-12 px-3 py-3">
                                            Select
                                        </th>
                                        <th className="px-3 py-3">Device</th>
                                        <th className="px-3 py-3">Site</th>
                                        <th className="px-3 py-3">Provider</th>
                                        <th className="px-3 py-3">Readiness</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {declaredDevices.map((device) => {
                                        const availability = action
                                            ? device.actions[action.key]
                                            : undefined;
                                        const selected = selectedIds.includes(
                                            device.id,
                                        );

                                        return (
                                            <tr
                                                key={device.id}
                                                className={
                                                    selected
                                                        ? 'bg-primary/5'
                                                        : undefined
                                                }
                                            >
                                                <td className="px-3 py-3 align-top">
                                                    <input
                                                        aria-label={`Select ${device.name}`}
                                                        type="checkbox"
                                                        className="h-4 w-4"
                                                        checked={selected}
                                                        onChange={(event) =>
                                                            toggleDevice(
                                                                device.id,
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    <Link
                                                        href={`/security-devices/devices/${device.id}?section=management`}
                                                        className="frontline-focus font-semibold text-primary hover:underline"
                                                    >
                                                        {device.name}
                                                    </Link>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {device.uid} ·{' '}
                                                        {humanise(
                                                            device.category,
                                                        )}
                                                    </p>
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    {device.siteName}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    {device.provider ??
                                                        'Native'}
                                                </td>
                                                <td className="px-3 py-3 align-top">
                                                    <div className="flex items-start gap-2">
                                                        {availability?.available ? (
                                                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                                                        ) : (
                                                            <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                        )}
                                                        <div>
                                                            <p className="font-medium">
                                                                {availability?.available
                                                                    ? 'Ready for review'
                                                                    : 'Will be excluded'}
                                                            </p>
                                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                                {
                                                                    availability?.reason
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {declaredDevices.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                No visible Device declares this action through
                                an approved provider contract.
                            </div>
                        ) : null}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="flex flex-wrap items-center justify-between gap-4 p-4">
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                        <span>
                            <strong>{selectedDevices.length}</strong> selected
                        </span>
                        <span className="text-status-success">
                            <strong>{includedDevices.length}</strong> ready
                        </span>
                        <span className="text-muted-foreground">
                            <strong>{excludedDevices.length}</strong> exclusions
                        </span>
                        <span>
                            <strong>{selectedSiteNames.length}</strong> Sites
                        </span>
                    </div>
                    <Button
                        type="button"
                        onClick={() => setReviewOpen(true)}
                        disabled={
                            !data.canRequest ||
                            !action ||
                            selectedDevices.length < 2 ||
                            includedDevices.length === 0
                        }
                    >
                        <ClipboardCheck className="mr-2 h-4 w-4" />
                        Review selected targets
                    </Button>
                </CardContent>
            </Card>

            {data.recentBatches.length > 0 ? (
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle>
                            <h2 className="flex items-center gap-2">
                                <Clock3 className="h-5 w-5 text-primary" />
                                Recent governed activity
                            </h2>
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Return to current approvals, execution progress,
                            reconciliations, exclusions and downloadable result
                            ledgers.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[52rem] text-left text-sm">
                                <thead className="bg-muted/70 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-3">Action</th>
                                        <th className="px-3 py-3">Requested</th>
                                        <th className="px-3 py-3">Scope</th>
                                        <th className="px-3 py-3">Progress</th>
                                        <th className="px-3 py-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {data.recentBatches.map((batch) => (
                                        <tr key={batch.id}>
                                            <td className="px-3 py-3 align-top">
                                                <Link
                                                    href={batch.href}
                                                    className="frontline-focus font-semibold text-primary hover:underline"
                                                >
                                                    {batch.label}
                                                </Link>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {batch.uuid}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3 align-top">
                                                <p>
                                                    {batch.requestedBy ??
                                                        'System'}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        batch.requestedAt,
                                                    )}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3 align-top">
                                                {batch.summary.included}{' '}
                                                included ·{' '}
                                                {batch.summary.excluded}{' '}
                                                excluded · {batch.summary.sites}{' '}
                                                Sites
                                            </td>
                                            <td className="px-3 py-3 align-top">
                                                {batch.summary.reconciled}{' '}
                                                reconciled ·{' '}
                                                {batch.summary.failedOrBlocked}{' '}
                                                failed or blocked
                                            </td>
                                            <td className="px-3 py-3 align-top">
                                                <div className="flex flex-wrap gap-2">
                                                    <Badge variant="outline">
                                                        {humanise(batch.status)}
                                                    </Badge>
                                                    <Badge
                                                        variant="outline"
                                                        className={riskTone(
                                                            batch.risk,
                                                        )}
                                                    >
                                                        {humanise(batch.risk)}{' '}
                                                        risk
                                                    </Badge>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            <Dialog open={reviewOpen} onOpenChange={setReviewOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                    <DialogHeader>
                        <DialogTitle>Review governed bulk action</DialogTitle>
                        <DialogDescription>
                            Confirm scope, Sites, exclusions, prerequisites and
                            expected state. Submission creates independent child
                            requests; it does not report blanket success.
                        </DialogDescription>
                    </DialogHeader>

                    {action ? (
                        <div className="space-y-5">
                            <div className="grid gap-3 sm:grid-cols-4">
                                {[
                                    ['Selected', selectedDevices.length],
                                    ['Included', includedDevices.length],
                                    ['Excluded', excludedDevices.length],
                                    ['Sites', selectedSiteNames.length],
                                ].map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="rounded-lg border bg-muted/20 p-3"
                                    >
                                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            {label}
                                        </p>
                                        <p className="mt-1 text-2xl font-bold">
                                            {value}
                                        </p>
                                    </div>
                                ))}
                            </div>

                            <div
                                className={`rounded-xl border p-4 ${
                                    action.risk === 'critical'
                                        ? 'border-destructive/40 bg-destructive/5'
                                        : elevated
                                          ? 'border-status-warning/30 bg-status-warning-bg'
                                          : 'bg-muted/20'
                                }`}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <ShieldCheck className="h-5 w-5" />
                                    <p className="font-semibold">
                                        {action.label}
                                    </p>
                                    <Badge
                                        variant="outline"
                                        className={riskTone(action.risk)}
                                    >
                                        {humanise(action.risk)} risk
                                    </Badge>
                                </div>
                                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt className="font-semibold">
                                            Possible impact
                                        </dt>
                                        <dd className="mt-1 text-muted-foreground">
                                            {action.impact}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold">
                                            Expected state
                                        </dt>
                                        <dd className="mt-1 text-muted-foreground">
                                            {action.expectedResult}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <div className="grid gap-4 lg:grid-cols-2">
                                <div className="rounded-xl border p-4">
                                    <p className="font-semibold">
                                        Included targets
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Each target receives its own signed
                                        request, approval, execution attempt and
                                        fresh-state reconciliation.
                                    </p>
                                    <div className="mt-3 max-h-64 space-y-2 overflow-y-auto pr-1">
                                        {includedDevices.map((device) => (
                                            <div
                                                key={device.id}
                                                className="rounded-lg border bg-muted/20 p-3 text-sm"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="font-semibold">
                                                            {device.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {device.siteName} ·{' '}
                                                            {device.provider ??
                                                                'Native'}
                                                        </p>
                                                    </div>
                                                    <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                                                </div>
                                                {action.requiresChange ? (
                                                    <div className="mt-3 space-y-1.5">
                                                        <label
                                                            htmlFor={`bulk-change-${device.id}`}
                                                            className="text-xs font-semibold"
                                                        >
                                                            Approved IT Change
                                                        </label>
                                                        <select
                                                            id={`bulk-change-${device.id}`}
                                                            value={
                                                                changeIds[
                                                                    device.id
                                                                ] ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                setChangeIds(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [device.id]:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    }),
                                                                )
                                                            }
                                                            className="h-9 w-full rounded-md border border-input bg-background px-2 text-xs"
                                                        >
                                                            <option value="">
                                                                Choose a current
                                                                linked Change
                                                            </option>
                                                            {device.changeOptions.map(
                                                                (change) => (
                                                                    <option
                                                                        key={
                                                                            change.id
                                                                        }
                                                                        value={
                                                                            change.id
                                                                        }
                                                                    >
                                                                        {
                                                                            change.reference
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            change.title
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </div>
                                                ) : null}
                                                <p className="mt-3 text-xs text-muted-foreground">
                                                    <span className="font-semibold text-foreground">
                                                        Expected for this
                                                        Device:{' '}
                                                    </span>
                                                    {action.expectedResult}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="rounded-xl border p-4">
                                    <p className="font-semibold">
                                        Explicit exclusions
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Exclusions remain immutable evidence and
                                        are never converted into successful
                                        child commands.
                                    </p>
                                    <div className="mt-3 max-h-64 space-y-2 overflow-y-auto pr-1">
                                        {excludedDevices.length > 0 ? (
                                            excludedDevices.map((device) => (
                                                <div
                                                    key={device.id}
                                                    className="rounded-lg border bg-muted/20 p-3 text-sm"
                                                >
                                                    <div className="flex items-start gap-2">
                                                        <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                        <div>
                                                            <p className="font-semibold">
                                                                {device.name}
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    device
                                                                        .actions[
                                                                        action
                                                                            .key
                                                                    ]?.reason
                                                                }
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                                No current exclusions.
                                                Eligibility is rechecked per
                                                Device when submitted and again
                                                before dispatch.
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {action.parameters.map((parameter) => (
                                <div
                                    key={parameter.name}
                                    className="space-y-1.5"
                                >
                                    <label
                                        htmlFor={`bulk-parameter-${parameter.name}`}
                                        className="text-sm font-semibold"
                                    >
                                        {parameter.label}
                                    </label>
                                    {parameter.options.length > 0 ? (
                                        <select
                                            id={`bulk-parameter-${parameter.name}`}
                                            value={
                                                parameters[parameter.name] ?? ''
                                            }
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
                                                <option
                                                    key={option}
                                                    value={option}
                                                >
                                                    {parameter.optionLabels?.[
                                                        option
                                                    ] ?? humanise(option)}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <Input
                                            id={`bulk-parameter-${parameter.name}`}
                                            type={
                                                parameter.type === 'integer'
                                                    ? 'number'
                                                    : parameter.type ===
                                                        'date_time'
                                                      ? 'datetime-local'
                                                      : 'text'
                                            }
                                            min={parameter.min ?? undefined}
                                            max={parameter.max ?? undefined}
                                            value={
                                                parameters[parameter.name] ?? ''
                                            }
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

                            <div className="space-y-1.5">
                                <label
                                    htmlFor="bulk-command-reason"
                                    className="text-sm font-semibold"
                                >
                                    Operational reason
                                </label>
                                <textarea
                                    id="bulk-command-reason"
                                    rows={4}
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    placeholder="Why is this exact group being changed now, who owns the outcome, and what should be verified?"
                                />
                            </div>

                            {elevated ? (
                                <div className="space-y-4 rounded-xl border border-status-warning/30 bg-status-warning-bg p-4">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" />
                                        <div>
                                            <p className="font-semibold">
                                                Elevated bulk confirmation
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Required for high-risk,
                                                sensitive, or multi-Site scope.
                                                The confirmation is checked by
                                                the server and is not stored.
                                            </p>
                                        </div>
                                    </div>
                                    {!data.stepUpCurrent ? (
                                        <Button asChild variant="outline">
                                            <Link
                                                href={`/security-devices/command-batches/confirm-identity?workspace=${data.workspace}`}
                                            >
                                                Confirm identity
                                                <ExternalLink className="ml-2 h-4 w-4" />
                                            </Link>
                                        </Button>
                                    ) : (
                                        <div className="flex items-center gap-2 text-sm text-status-success">
                                            <CheckCircle2 className="h-4 w-4" />
                                            Identity confirmation is current
                                        </div>
                                    )}
                                    <label
                                        htmlFor="bulk-command-impact-acknowledged"
                                        className="flex items-start gap-3 text-sm font-medium"
                                    >
                                        <input
                                            id="bulk-command-impact-acknowledged"
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
                                            I understand the combined impact and
                                            have checked the included Devices,
                                            Sites, exclusions and expected
                                            result.
                                        </span>
                                    </label>
                                    <div className="space-y-1.5">
                                        <label
                                            htmlFor="bulk-command-confirmation"
                                            className="text-sm font-semibold"
                                        >
                                            Type{' '}
                                            <span className="font-mono">
                                                {confirmationPhrase}
                                            </span>{' '}
                                            to confirm
                                        </label>
                                        <Input
                                            id="bulk-command-confirmation"
                                            value={confirmationText}
                                            onChange={(event) =>
                                                setConfirmationText(
                                                    event.target.value,
                                                )
                                            }
                                            autoComplete="off"
                                        />
                                    </div>
                                </div>
                            ) : null}

                            <div className="rounded-xl border bg-muted/20 p-4 text-sm">
                                <p className="font-semibold">
                                    Partial-result rule
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Every included Device progresses
                                    independently. A rejected, blocked, failed,
                                    uncertain, mismatched or excluded target
                                    never changes another target to successful.
                                    Use the downloadable result ledger for
                                    handover and closure evidence.
                                </p>
                            </div>

                            {requestError ? (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {requestError}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setReviewOpen(false)}
                        >
                            Back to targets
                        </Button>
                        <Button
                            type="button"
                            onClick={requestBatch}
                            disabled={
                                submitting ||
                                !action ||
                                selectedDevices.length < 2 ||
                                includedDevices.length === 0 ||
                                reason.trim().length < 10 ||
                                missingChanges ||
                                (elevated &&
                                    (!data.stepUpCurrent ||
                                        !impactAcknowledged ||
                                        confirmationText !==
                                            confirmationPhrase))
                            }
                        >
                            {submitting
                                ? 'Creating governed requests…'
                                : `Create ${includedDevices.length} child request${includedDevices.length === 1 ? '' : 's'}`}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
