import { ConfirmDialog } from '@/components/confirm-dialog';
import { csrfHeaders } from '@/components/fleet-assets/vehicle-workspace/record-command';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    fleetAlertNextAction,
    type FleetAlertAction,
} from '@/lib/fleet-alert-workflow';
import { router } from '@inertiajs/react';
import {
    Bell,
    CheckCircle2,
    FileText,
    Loader2,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type FleetAlert = {
    can_open_control_room: boolean;
    can_respond: boolean;
    id: number;
    reference: string;
    source: string;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    updated_at: string | null;
    notes: string | null;
    site: { id: number; name: string } | null;
    asset: {
        id: number;
        name: string;
        asset_tag: string;
        category: string;
        href: string | null;
    } | null;
    assigned_to: { id: number; name: string } | null;
};
export const STATUS_LABELS: Record<string, string> = {
    unresolved: 'Unresolved',
    open: 'Open',
    ack: 'Acknowledged',
    triaging: 'In triage',
    confirmed: 'Confirmed',
    resolved: 'Resolved',
    closed: 'Closed',
    dismissed: 'Dismissed',
    all: 'All alerts',
};
export const ACTION_LABELS: Record<FleetAlertAction, string> = {
    acknowledge: 'Acknowledge',
    triage: 'Start triage',
    resolve: 'Resolve with notes',
};
export const alertTitle = (value: string) =>
    value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
export const alertTone = (severity: string) =>
    severity === 'critical'
        ? ('critical' as const)
        : severity === 'high'
          ? ('warning' as const)
          : ('neutral' as const);

const sections = [
    {
        key: 'details',
        label: 'Alert details',
        blurb: 'Identity and current response',
        icon: Bell,
    },
    {
        key: 'source',
        label: 'Source & response',
        blurb: 'Original source and permitted next action',
        icon: FileText,
    },
];
export function AlertDetails({
    alert,
    canManage,
    canControlRoom,
    onClose,
    onRespond,
    onSource,
}: {
    alert: FleetAlert;
    canManage: boolean;
    canControlRoom: boolean;
    onClose: () => void;
    onRespond: () => void;
    onSource: () => void;
}) {
    const [section, setSection] = useState(0);
    const next = fleetAlertNextAction(alert.status);
    return (
        <WizardShell
            open
            maxWidth="min(92vw, 900px)"
            onClose={onClose}
            title={`${alert.reference} · ${alertTitle(alert.alert_type)}`}
            description={alert.asset?.name ?? 'Resource not available'}
            railIcon={Bell}
            railTitle={alert.reference}
            railSub="Fleet alert"
            steps={sections}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={sections[section].label}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                canManage && next ? (
                    <Button onClick={onRespond}>{ACTION_LABELS[next]}</Button>
                ) : undefined
            }
        >
            <WizardStepPane key={section}>
                {section === 0 ? (
                    <ReviewCard title="Alert details" icon={Bell}>
                        <ReviewRow
                            label="Canonical reference"
                            value={alert.reference}
                        />
                        <ReviewRow
                            label="Status"
                            value={
                                STATUS_LABELS[alert.status] ??
                                alertTitle(alert.status)
                            }
                        />
                        <ReviewRow
                            label="Severity"
                            value={alertTitle(alert.severity)}
                        />
                        <ReviewRow
                            label="Triggered"
                            value={
                                alert.triggered_at
                                    ? formatDateTime(alert.triggered_at)
                                    : 'Time not recorded'
                            }
                        />
                        <ReviewRow
                            label="Owner"
                            value={alert.assigned_to?.name ?? 'Unassigned'}
                        />
                        <ReviewRow
                            label="Resolution notes"
                            value={alert.notes ?? 'Not recorded'}
                        />
                    </ReviewCard>
                ) : (
                    <div className="space-y-5">
                        <ReviewCard title="Original source" icon={FileText}>
                            <ReviewRow
                                label="Source"
                                value={alertTitle(alert.source)}
                            />
                            <ReviewRow
                                label="Resource"
                                value={
                                    alert.asset
                                        ? `${alert.asset.name} · ${alert.asset.asset_tag}`
                                        : 'Source record unavailable'
                                }
                            />
                            <ReviewRow
                                label="Site"
                                value={alert.site?.name ?? 'Not recorded'}
                            />
                            <ReviewRow
                                label="Record updated"
                                value={
                                    alert.updated_at
                                        ? formatDateTime(alert.updated_at)
                                        : 'Freshness not recorded'
                                }
                            />
                        </ReviewCard>
                        <p className="text-subtle">
                            Control Room owns the response. Resolving this alert
                            does not renew compliance evidence or release a
                            vehicle restriction.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {alert.asset?.href && (
                                <Button variant="outline" onClick={onSource}>
                                    Open source record
                                </Button>
                            )}
                            {canControlRoom && alert.can_open_control_room && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.visit(
                                            `/control-room/alerts?alert=${alert.id}`,
                                        )
                                    }
                                >
                                    View in Control Room
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

const steps = [
    ...sections.slice(0, 1),
    {
        key: 'response',
        label: 'Response',
        blurb: 'Record your action and notes',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the response',
        icon: ShieldCheck,
    },
];
export function AlertResponseDialog({
    alerts: initialAlerts,
    action: initialAction,
    onClose,
    onSaved,
    onRefresh,
}: {
    alerts: FleetAlert[];
    action: FleetAlertAction;
    onClose: () => void;
    onSaved: () => void;
    onRefresh: () => void;
}) {
    const [alerts, setAlerts] = useState(initialAlerts);
    const [action, setAction] = useState(initialAction);
    const [step, setStep] = useState(0),
        [notes, setNotes] = useState(''),
        [error, setError] = useState(''),
        [message, setMessage] = useState(''),
        [busy, setBusy] = useState(false),
        [conflict, setConflict] = useState(false),
        [saved, setSaved] = useState(false),
        [discard, setDiscard] = useState(false);
    const field = useRef<HTMLTextAreaElement>(null);
    const pending = useRef(false);
    useEffect(() => {
        if (error && step === 1) field.current?.focus();
    }, [error, step]);
    const validate = () => {
        const invalid = action === 'resolve' && !notes.trim();
        setError(invalid ? 'Add resolution notes before continuing.' : '');
        if (invalid) setStep(1);
        return !invalid;
    };
    const close = () => {
        if (busy) return;
        if (notes.trim() && !saved) setDiscard(true);
        else onClose();
    };
    const reviewLatest = async () => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        try {
            const latest = await Promise.all(
                alerts.map(async (alert) => {
                    const response = await fetch(
                        `/fleet-assets/alerts/${alert.id}/snapshot`,
                        {
                            credentials: 'same-origin',
                            cache: 'no-store',
                            headers: { Accept: 'application/json' },
                        },
                    );
                    if (!response.ok) throw new Error('unavailable');
                    return (await response.json()) as FleetAlert;
                }),
            );
            setAlerts(latest);
            onRefresh();
            const next = fleetAlertNextAction(latest[0].status);
            const available =
                next &&
                latest.every(
                    (alert) =>
                        alert.can_respond &&
                        fleetAlertNextAction(alert.status) === next,
                );
            setConflict(!available);
            if (available) setAction(next);
            setStep(0);
            setMessage(
                available
                    ? 'Latest state loaded. Your notes are retained. Review the available action before continuing.'
                    : 'The selected alerts no longer share an available response. Your notes are retained for review.',
            );
        } catch {
            setMessage(
                'The latest alert could not be loaded. Your notes are retained. Check your access or connection and try again.',
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    const submit = async () => {
        if (!validate() || pending.current || conflict) return;
        pending.current = true;
        setBusy(true);
        setMessage('');
        try {
            const response = await fetch(
                alerts.length > 1
                    ? '/fleet-assets/alerts/bulk-action'
                    : `/fleet-assets/alerts/${alerts[0].id}/${action}`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...csrfHeaders(),
                    },
                    body: JSON.stringify({
                        ...(alerts.length > 1
                            ? {
                                  action,
                                  ids: alerts.map((alert) => alert.id),
                                  expected_statuses: Object.fromEntries(
                                      alerts.map((alert) => [
                                          alert.id,
                                          alert.status,
                                      ]),
                                  ),
                              }
                            : {}),
                        expected_status: alerts[0].status,
                        notes: notes.trim() || null,
                        resolution_notes:
                            action === 'resolve' ? notes.trim() : null,
                    }),
                },
            );
            const result = await response.json().catch(() => null);
            if (!response.ok) {
                setConflict([403, 404, 409, 419].includes(response.status));
                setMessage(
                    result?.message ??
                        'The response could not be saved. Your notes are retained.',
                );
                return;
            }
            setSaved(true);
            onSaved();
        } catch {
            setMessage(
                'The result could not be confirmed. Your notes are retained. Refresh the queue before trying again.',
            );
            setConflict(true);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    return (
        <>
            <WizardShell
                open
                maxWidth="min(92vw, 900px)"
                onClose={close}
                title={ACTION_LABELS[action]}
                description="Respond through the canonical Control Room workflow."
                railIcon={Bell}
                railTitle="Alert response"
                railSub={
                    alerts.length === 1
                        ? alerts[0].reference
                        : `${alerts.length} selected alerts`
                }
                steps={steps}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!busy) setStep(next);
                }}
                pct={action === 'resolve' ? (notes.trim() ? 100 : 50) : 100}
                footerStart={
                    <Button variant="outline" disabled={busy} onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    conflict ? (
                        <Button
                            disabled={busy}
                            onClick={() => void reviewLatest()}
                        >
                            Review latest
                        </Button>
                    ) : (
                        <>
                            <Button
                                variant="outline"
                                disabled={busy || step === 0}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                            <Button
                                disabled={busy}
                                onClick={() => {
                                    if (step === 2) void submit();
                                    else if (step === 0 || validate())
                                        setStep(step + 1);
                                }}
                            >
                                {busy && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {step === 2
                                    ? ACTION_LABELS[action]
                                    : 'Continue'}
                            </Button>
                        </>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Response recorded"
                            blurb="The canonical alert response has been updated. Vehicle evidence and release decisions remain with their owners."
                            actions={
                                <Button onClick={onClose}>
                                    Return to alerts
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={step}>
                    {message && (
                        <p
                            role="alert"
                            className="mb-5 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-status-warning"
                        >
                            {message}
                        </p>
                    )}
                    {step === 0 ? (
                        <ReviewCard title="Selected alerts" icon={Bell}>
                            {alerts.map((alert) => (
                                <ReviewRow
                                    key={alert.id}
                                    label={alert.reference}
                                    value={`${alertTitle(alert.alert_type)} · ${STATUS_LABELS[alert.status] ?? alert.status}`}
                                />
                            ))}
                        </ReviewCard>
                    ) : step === 1 ? (
                        <div className="space-y-3">
                            <label
                                htmlFor="fleet-response-notes"
                                className="font-semibold"
                            >
                                {action === 'resolve'
                                    ? 'Resolution notes'
                                    : 'Response notes (optional)'}
                            </label>
                            <Textarea
                                id="fleet-response-notes"
                                ref={field}
                                value={notes}
                                maxLength={2000}
                                disabled={busy || conflict}
                                onChange={(event) => {
                                    setNotes(event.target.value);
                                    setError('');
                                }}
                                aria-invalid={!!error}
                                aria-describedby={
                                    error ? 'fleet-response-error' : undefined
                                }
                                rows={7}
                            />
                            {error && (
                                <p
                                    id="fleet-response-error"
                                    className="text-status-critical"
                                >
                                    {error}
                                </p>
                            )}
                            <p className="text-caption">
                                Record what was checked and the next action.
                                Resolving an alert does not release a
                                restriction.
                            </p>
                        </div>
                    ) : (
                        <ReviewCard
                            title="Review response"
                            icon={CheckCircle2}
                            onEdit={() => setStep(1)}
                        >
                            <ReviewRow
                                label="Action"
                                value={ACTION_LABELS[action]}
                            />
                            <ReviewRow
                                label="Alerts"
                                value={alerts
                                    .map((alert) => alert.reference)
                                    .join(', ')}
                            />
                            <ReviewRow
                                label="Notes"
                                value={notes.trim() || 'No additional notes'}
                            />
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard response draft?"
                description="Your unsent response notes will be removed."
                confirmText="Discard draft"
                cancelText="Continue editing"
            />
        </>
    );
}
