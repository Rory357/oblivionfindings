import type { IncidentDetail } from '@/components/incidents/incident-detail-dialog';
import { PopupField, PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import type { PendingVisit } from '@inertiajs/core';
import { router, useForm, usePage } from '@inertiajs/react';
import { ClipboardList, RadioTower } from 'lucide-react';
import { cloneElement, useCallback, useId, useRef, useState } from 'react';

/** Request a response while keeping the incident and its ownership in place. */
export function IncidentResponseRequestDialog({
    detail,
    onClose,
}: {
    detail: IncidentDetail;
    onClose: () => void;
}) {
    const urgencyId = useId();
    const notesErrorId = useId();
    const page = usePage();
    const currentActorId = (
        page.props.auth as { user?: { id?: number } } | undefined
    )?.user?.id;
    const [actorId] = useState(currentActorId);
    const [sending, setSending] = useState(false);
    // Inertia adopts submitted values as defaults after every successful redirect,
    // including a redirect whose application-level confirmation we cannot accept.
    const [unconfirmedRequest, setUnconfirmedRequest] = useState(false);
    const inFlight = useRef(false);
    const [confirmationError, setConfirmationError] = useState<string | null>(
        null,
    );
    const form = useForm({
        source_type: 'client_incident',
        source_id: detail.id,
        severity: ['low', 'medium', 'high', 'critical'].includes(
            detail.severity,
        )
            ? detail.severity
            : 'medium',
        notes: '',
    });
    const busy = form.processing || sending;
    const canRequest =
        detail.can.requestResponse === true &&
        !['draft', 'closed'].includes(detail.status) &&
        form.data.source_id === detail.id &&
        typeof actorId === 'number' &&
        Number.isSafeInteger(actorId) &&
        actorId > 0 &&
        actorId === currentActorId;
    const preserveCurrentIncident = useCallback(
        (visit: PendingVisit) =>
            visit.url.origin === window.location.origin &&
            visit.url.pathname === `/incidents/${detail.id}` &&
            visit.preserveState === true,
        [detail.id],
    );
    const guard = useSettingsLeaveConfirmation(
        form.isDirty || busy || unconfirmedRequest,
        'Discard this response request?',
        'Your unsaved request will be discarded. Cancel to keep editing.',
        undefined,
        false,
        preserveCurrentIncident,
    );

    const submit = () => {
        if (!canRequest || busy || inFlight.current) return;
        inFlight.current = true;
        setSending(true);
        setUnconfirmedRequest(true);
        setConfirmationError(null);
        try {
            form.post('/control-room/incidents/create-alert', {
                preserveScroll: true,
                onSuccess: (returned) => {
                    const props = returned.props as {
                        viewer_id?: number;
                        auth?: {
                            user?: { id?: number };
                        };
                        detail?: {
                            id?: number;
                            control_room_alert_id?: number | null;
                        };
                        flash?: {
                            error?: unknown;
                            created_alert_id?: unknown;
                        };
                    };
                    if (props.flash?.error) {
                        setConfirmationError(
                            typeof props.flash.error === 'string'
                                ? props.flash.error
                                : 'The response could not be confirmed. Your request has been kept. Check the current incident before sending again.',
                        );
                        return;
                    }
                    const alertId = props.flash?.created_alert_id;
                    const visibleAlertId = props.detail?.control_room_alert_id;
                    if (
                        typeof alertId === 'number' &&
                        Number.isSafeInteger(alertId) &&
                        alertId > 0 &&
                        props.viewer_id === actorId &&
                        props.auth?.user?.id === actorId &&
                        props.detail?.id === form.data.source_id &&
                        (visibleAlertId === null || visibleAlertId === alertId)
                    ) {
                        onClose();
                    } else {
                        setConfirmationError(
                            'The response could not be confirmed. Your request has been kept. Check the current incident before sending again.',
                        );
                    }
                },
                onFinish: () => {
                    inFlight.current = false;
                    setSending(false);
                },
            });
        } catch {
            inFlight.current = false;
            setSending(false);
            setConfirmationError(
                'The response request could not be confirmed. Your entries have been kept. Check the current incident before trying again.',
            );
        }
    };

    return (
        <>
            <PopupForm
                open
                onClose={onClose}
                size="sm"
                icon={RadioTower}
                title="Request Control Room response"
                description="Ask the Control Room to coordinate the immediate response. The incident owner and safety review stay separate."
                context={{
                    title: detail.ref ?? `INC-${detail.id}`,
                    icon: ClipboardList,
                }}
                submitLabel="Request response"
                processingLabel="Requesting response…"
                processing={busy}
                submitDisabled={!canRequest}
                onSubmit={submit}
                dirty={form.isDirty || unconfirmedRequest}
                draftName={`Response request for ${detail.ref ?? `INC-${detail.id}`}`}
                testId="incident-response-request"
            >
                <PopupField label="Response urgency" htmlFor={urgencyId}>
                    <Select
                        value={form.data.severity}
                        disabled={busy || !canRequest}
                        onValueChange={(value) =>
                            form.setData('severity', value)
                        }
                    >
                        <SelectTrigger
                            id={urgencyId}
                            aria-label="Response urgency"
                            aria-invalid={!!form.errors.severity}
                            aria-describedby={
                                form.errors.severity
                                    ? `${urgencyId}-error`
                                    : undefined
                            }
                            className="frontline-tap w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="low" className="frontline-tap">
                                Low
                            </SelectItem>
                            <SelectItem
                                value="medium"
                                className="frontline-tap"
                            >
                                Medium
                            </SelectItem>
                            <SelectItem value="high" className="frontline-tap">
                                High
                            </SelectItem>
                            <SelectItem
                                value="critical"
                                className="frontline-tap"
                            >
                                Critical
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    {form.errors.severity ? (
                        <p
                            id={`${urgencyId}-error`}
                            className="text-sm text-status-critical"
                        >
                            {form.errors.severity}
                        </p>
                    ) : null}
                </PopupField>
                <PopupField label="What help is needed?">
                    <Textarea
                        aria-label="What help is needed?"
                        value={form.data.notes}
                        maxLength={2000}
                        rows={5}
                        disabled={busy || !canRequest}
                        aria-invalid={Boolean(form.errors.notes)}
                        aria-describedby={
                            form.errors.notes ? notesErrorId : undefined
                        }
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                    />
                    {form.errors.notes ? (
                        <p
                            id={notesErrorId}
                            className="text-sm text-status-critical"
                        >
                            {form.errors.notes}
                        </p>
                    ) : null}
                </PopupField>
                <p className="text-subtle text-muted-foreground">
                    If a response already exists, this request uses that
                    response. It will not lower its urgency or create a second
                    incident.
                </p>
                {!canRequest ? (
                    <p
                        role="status"
                        className="text-subtle text-status-warning-foreground"
                    >
                        This incident is no longer available for a new response
                        request. Your entries have been kept.
                    </p>
                ) : null}
                {confirmationError ? (
                    <p
                        role="alert"
                        className="text-subtle text-status-warning-foreground"
                    >
                        {confirmationError}
                    </p>
                ) : null}
                {confirmationError || !canRequest ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        disabled={busy}
                        onClick={() =>
                            router.reload({
                                only: ['viewer_id', 'detail'],
                                preserveState: true,
                                preserveScroll: true,
                            })
                        }
                    >
                        Check current incident
                    </Button>
                ) : null}
                {form.errors.source_id || form.errors.source_type ? (
                    <p role="alert" className="text-sm text-status-critical">
                        {form.errors.source_id ?? form.errors.source_type}
                    </p>
                ) : null}
            </PopupForm>
            {cloneElement(guard.confirmation, { frontline: true })}
        </>
    );
}
