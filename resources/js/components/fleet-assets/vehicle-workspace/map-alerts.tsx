import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';
import {
    ArrowUpRight,
    Bell,
    Loader2,
    Radio,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type {
    AlertDetail,
    AlertFilter,
    AlertItem,
    VehicleAlerts,
} from './alerts-types';
import {
    AlertActionWizard,
    AlertDetailDialog,
    AlertFollowUpWizard,
    DeliveryFailureDialog,
    LinkWorkWizard,
    ResponsePlanWizard,
    ReviewSourceEvent,
    type Lifecycle,
} from './map-alerts-dialogs';
import {
    ackText,
    ALERT_FILTERS,
    duplicatesText,
    minutesBetween,
    planHeading,
    planParagraph,
    recordedEventOption,
    routeSource,
    statusLabel,
    statusVariant,
} from './map-alerts-model';
import './map-alerts.css';
import { useNow, useSessionValue, useWorkspaceJson } from './map-insights-data';
import { whenLabel } from './map-insights-kit';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import { openWorkOrder } from './studio-kit';
import './studio.css';
import type { VehicleWorkspace } from './types';
import { StudioNotice } from './wizard-kit';
import type { WorkspaceLocation } from './workspace-model';

type Pending =
    | { type: 'lifecycle'; action: Lifecycle; detail: AlertDetail }
    | { type: 'create_work'; detail: AlertDetail }
    | { type: 'link_work'; detail: AlertDetail }
    | { type: 'follow_up'; detail: AlertDetail }
    | { type: 'review_source'; detail: AlertDetail };

const FILTER_VALUES: readonly AlertFilter[] = ['open', 'all', 'resolved'];

/**
 * Map › Alerts & Control Room, built to the approved PKG-02B v13 design
 * (TelemetryStudio, alerts): the route from a vehicle signal to its Control
 * Room response, the vehicle's response queue, recorded events a manager can
 * send, and the draft response plan. Each response is the canonical Control
 * Room alert; actions go through Control Room's lifecycle, Maintenance
 * through the PKG-01 report path, and follow-ups through vehicle reminders.
 */
export function VehicleAlertsPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const vehicle = workspace.vehicle;
    const [filter, setFilter] = useSessionValue<AlertFilter>(
        `vehicle-alerts.${vehicle.id}.filter`,
        'open',
        FILTER_VALUES,
    );
    const { data, load, reload, refreshing } = useWorkspaceJson<VehicleAlerts>(
        `/fleet-assets/vehicles/${vehicle.id}/alerts?status=${filter}`,
    );
    const now = useNow();
    const [selected, setSelected] = useState<AlertItem | null>(null);
    const [pending, setPending] = useState<Pending | null>(null);
    const [planOpen, setPlanOpen] = useState(false);
    const [eventKey, setEventKey] = useState('');
    const [confirmSend, setConfirmSend] = useState(false);
    const [notice, setNotice] = useState('');
    const route = useVehicleRecordCommand(isJsonObject);
    const work = useVehicleRecordCommand(isJsonObject);

    if (!data) {
        if (load === 'unavailable')
            return (
                <EmptyState
                    icon={ShieldAlert}
                    title="Vehicle alerts aren’t available to you"
                    description="This vehicle is outside the sites you can see."
                />
            );
        if (load === 'error')
            return (
                <ErrorState
                    title="Vehicle alerts couldn’t be loaded"
                    message="Try again. Nothing was changed."
                    onRetry={reload}
                />
            );
        return <LoadingState message="Loading vehicle alerts…" />;
    }

    const events = data.recorded_events;
    const chosen =
        events.find((event) => event.source_key === eventKey) ?? null;
    const options = events.map((event) =>
        recordedEventOption(event, whenLabel),
    );
    const chosenOption =
        options.find((option) => option.value === eventKey) ?? null;
    // A refusal that needs fresh records (a changed response or access) is
    // settled by loading them, so the next action isn't silently ignored.
    const reloadView = () => {
        if (route.requiresReload) route.reset();
        if (work.requiresReload) work.reset();
        reload();
    };
    const refresh = () => {
        reloadView();
        onChanged();
    };
    const send = async () => {
        if (!chosen) return;
        if (route.requiresReload) {
            setConfirmSend(false);
            reloadView();
            setNotice(
                'Loaded the latest responses. Send the event again if it still needs a response.',
            );
            return;
        }
        const result = await route.submit(
            `/fleet-assets/vehicles/${vehicle.id}/alerts/route`,
            chosen.kind === 'overspeed'
                ? {
                      kind: 'overspeed',
                      trip_id: chosen.trip_id,
                      event_key: chosen.event_key,
                  }
                : { kind: 'telemetry', event_id: chosen.event_id },
        );
        if (result) {
            setConfirmSend(false);
            setNotice(
                typeof result.message === 'string'
                    ? result.message
                    : 'Sent to Control Room.',
            );
            setEventKey('');
            if (filter !== 'open') setFilter('open');
            reload();
        }
    };
    const createWork = async () => {
        if (pending?.type !== 'create_work') return;
        if (work.requiresReload) {
            setPending(null);
            refresh();
            return;
        }
        const detail = pending.detail;
        const result = await work.submit(
            `/fleet-assets/vehicles/${vehicle.id}/alerts/${detail.id}/maintenance`,
            {
                mode: 'create',
                title: `${detail.kind} assessment`,
                description: detail.evidence,
                expected_version: detail.version,
            },
        );
        if (result) {
            setPending(null);
            setNotice(
                typeof result.message === 'string'
                    ? result.message
                    : 'Maintenance assessment created.',
            );
            refresh();
        }
    };
    const workError =
        work.errors.asset_id ??
        work.errors.mode ??
        work.errors.source_id ??
        (work.message || '');
    const plan = data.plan;

    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">SAFETY & RESPONSE</span>
                    <h2 className="text-section-title">
                        Vehicle alerts → Control Room
                    </h2>
                    <p className="muted">
                        One source event, one response record, linked follow-up.
                    </p>
                </div>
                {data.can.plan && (
                    <Button
                        variant="outline"
                        disabled={!workspace.people.length}
                        onClick={() => setPlanOpen(true)}
                    >
                        Response plan
                    </Button>
                )}
            </div>
            <div className="alert-clock">
                <p>
                    <strong>Response clock · {formatTime(now)}</strong> ·
                    Acknowledgement targets come from Control Room’s response
                    settings; overdue responses escalate under those settings
                    {plan?.backup
                        ? `, and the draft plan names ${plan.backup.name} as backup`
                        : ''}
                    .
                </p>
                <Button
                    variant="outline"
                    disabled={refreshing}
                    onClick={reloadView}
                >
                    {refreshing && <Loader2 className="size-4 animate-spin" />}
                    Refresh
                </Button>
            </div>
            <div
                className="alert-route"
                aria-label="From vehicle signal to follow-up"
            >
                <span>
                    <Radio className="size-5" aria-hidden />
                    {routeSource(data.tracker)}
                </span>
                <b aria-hidden>→</b>
                <span>
                    <ShieldAlert className="size-5" aria-hidden />
                    Correlate & route
                </span>
                <b aria-hidden>→</b>
                <span>
                    <Bell className="size-5" aria-hidden />
                    Control Room owner
                </span>
                <b aria-hidden>→</b>
                <span>
                    <Wrench className="size-5" aria-hidden />
                    Assessment & follow-up
                </span>
            </div>
            {notice && (
                <StudioNotice title={notice}>
                    {data.can.view
                        ? 'The queue below shows the response once Control Room receives it.'
                        : undefined}
                </StudioNotice>
            )}
            <div className="telemetry-two">
                <section className="studio-card">
                    <div className="activity-title">
                        <h3>
                            Response queue{' '}
                            <StatusBadge variant="neutral">
                                {data.counts?.all ?? 0}
                            </StatusBadge>
                        </h3>
                        <select
                            aria-label="Alert status filter"
                            value={filter}
                            onChange={(change) =>
                                setFilter(change.target.value as AlertFilter)
                            }
                        >
                            {ALERT_FILTERS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {!data.can.view ? (
                        <div className="telemetry-empty">
                            <ShieldAlert className="size-8" aria-hidden />
                            <h3>Alerts need Control Room access</h3>
                            <p>
                                Ask for Control Room or asset alert access to
                                see this vehicle’s responses.
                            </p>
                        </div>
                    ) : (
                        data.items.map((item) => {
                            const age = minutesBetween(item.observed_at, now);
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- The design's response row opens the response.
                                <button
                                    type="button"
                                    className="alert-record"
                                    key={String(item.id)}
                                    onClick={() => setSelected(item)}
                                >
                                    <span className="event-symbol">
                                        <ShieldAlert
                                            className="size-[21px]"
                                            aria-hidden
                                        />
                                    </span>
                                    <div>
                                        <strong>{item.kind}</strong>
                                        <small>
                                            {item.reference} ·{' '}
                                            {item.owner ?? 'Unassigned'} ·{' '}
                                            {whenLabel(item.observed_at)}
                                        </small>
                                        <small>
                                            {duplicatesText(item.duplicates)}
                                        </small>
                                        <small>
                                            {ackText(item, now)}
                                            {age !== null
                                                ? ` · age ${age} min`
                                                : ''}
                                        </small>
                                    </div>
                                    <StatusBadge
                                        variant={statusVariant(item.status)}
                                    >
                                        {statusLabel(item)}
                                    </StatusBadge>
                                    <ArrowUpRight
                                        className="size-[15px]"
                                        aria-hidden
                                    />
                                </button>
                            );
                        })
                    )}
                    {data.can.view && !data.items.length && (
                        <div className="telemetry-empty">
                            <ShieldAlert className="size-8" aria-hidden />
                            <h3>
                                No{' '}
                                {filter === 'resolved'
                                    ? 'resolved'
                                    : 'matching'}{' '}
                                alerts
                            </h3>
                            <p>
                                Responses appear here when Control Room receives
                                a signal from this vehicle or a recorded event
                                is sent.
                            </p>
                        </div>
                    )}
                </section>
                <section className="studio-card alert-preview">
                    <StatusBadge variant="info">Recorded events</StatusBadge>
                    <h3>Send a recorded event</h3>
                    {data.can.route ? (
                        <>
                            <div className="field">
                                <label htmlFor="alert-recorded-event">
                                    Recorded vehicle event
                                </label>
                                <VehicleSearchSelect
                                    id="alert-recorded-event"
                                    label="Recorded vehicle event"
                                    value={eventKey}
                                    options={options}
                                    onChange={(value) => {
                                        setEventKey(value);
                                        setNotice('');
                                        route.reset();
                                    }}
                                />
                                {chosenOption && (
                                    <small className="muted">
                                        {chosenOption.description}
                                    </small>
                                )}
                                {!options.length && (
                                    <small className="muted">
                                        No overspeed, power or voltage events
                                        were recorded in the last 7 days.
                                    </small>
                                )}
                            </div>
                            <Button
                                disabled={!chosen}
                                onClick={() => setConfirmSend(true)}
                            >
                                Send to Control Room
                            </Button>
                        </>
                    ) : (
                        <p className="muted">
                            Sending a recorded event needs fleet management
                            access, and the vehicle’s trips must be at one of
                            your sites.
                        </p>
                    )}
                    <p className="muted">
                        Sending the same recorded event again adds it to its
                        existing response. Towing, SOS, geofence and
                        tracker-offline signals reach Control Room
                        automatically. No emergency call or device command is
                        made.
                    </p>
                </section>
            </div>
            <section className="studio-card routing-details">
                <div>
                    <span className="studio-eyebrow">DRAFT RESPONSE PLAN</span>
                    <h3>{planHeading(plan)}</h3>
                    <p>{planParagraph(plan)}</p>
                </div>
                <StatusBadge variant="warning">
                    {plan ? 'Activation pending' : 'Not drafted'}
                </StatusBadge>
                <p>
                    Geofence alerts require an active assignment and schedule.
                    Towing, power loss and motion during a restriction need
                    contextual review. Harsh driving normally creates a coaching
                    task unless an approved escalation rule applies.
                </p>
            </section>
            {selected?.type === 'response' && (
                <AlertDetailDialog
                    workspace={workspace}
                    alertId={Number(selected.id)}
                    onClose={() => setSelected(null)}
                    actions={{
                        onLifecycle: (action, detail) => {
                            setSelected(null);
                            setPending({ type: 'lifecycle', action, detail });
                        },
                        onCreateWork: (detail) => {
                            setSelected(null);
                            work.reset();
                            setPending({ type: 'create_work', detail });
                        },
                        onLinkWork: (detail) => {
                            setSelected(null);
                            setPending({ type: 'link_work', detail });
                        },
                        onFollowUp: (detail) => {
                            setSelected(null);
                            setPending({ type: 'follow_up', detail });
                        },
                        onReviewSource: (detail) => {
                            setSelected(null);
                            setPending({ type: 'review_source', detail });
                        },
                        onOpenTrip: (date) => {
                            setSelected(null);
                            onNavigate(
                                date
                                    ? { tab: 'trips', date }
                                    : { tab: 'trips' },
                            );
                        },
                        onOpenWork: (workOrderId) =>
                            openWorkOrder(workOrderId, vehicle.id, {
                                tab: 'map',
                                view: 'alerts',
                            }),
                    }}
                />
            )}
            {selected?.type === 'delivery' && (
                <DeliveryFailureDialog
                    workspace={workspace}
                    item={selected}
                    canRetry={data.can.retry}
                    onClose={() => setSelected(null)}
                    onSaved={reload}
                />
            )}
            {pending?.type === 'lifecycle' && (
                <AlertActionWizard
                    workspace={workspace}
                    detail={pending.detail}
                    action={pending.action}
                    onClose={() => setPending(null)}
                    onSaved={reload}
                />
            )}
            {pending?.type === 'link_work' && (
                <LinkWorkWizard
                    workspace={workspace}
                    detail={pending.detail}
                    onClose={() => setPending(null)}
                    onSaved={refresh}
                />
            )}
            {pending?.type === 'follow_up' && (
                <AlertFollowUpWizard
                    workspace={workspace}
                    detail={pending.detail}
                    onClose={() => setPending(null)}
                    onSaved={refresh}
                />
            )}
            {pending?.type === 'review_source' &&
                pending.detail.review_source && (
                    <ReviewSourceEvent
                        workspace={workspace}
                        tripId={pending.detail.review_source.trip_id}
                        eventKey={pending.detail.review_source.event_key}
                        onClose={() => setPending(null)}
                        onSaved={reload}
                    />
                )}
            <ConfirmDialog
                open={pending?.type === 'create_work'}
                onClose={() => setPending(null)}
                onConfirm={createWork}
                processing={work.processing}
                variant="default"
                title="Create a Maintenance assessment?"
                description={
                    pending?.type === 'create_work' ? (
                        <>
                            Creates “{pending.detail.kind} assessment” for{' '}
                            {vehicle.name} with {pending.detail.reference} as
                            its source. The site’s approved Coordinator assesses
                            it. Resolving the response never releases the
                            vehicle or closes the work.
                            {workError ? ` ${workError}` : ''}
                        </>
                    ) : (
                        ''
                    )
                }
                confirmText={work.uncertain ? 'Retry' : 'Create assessment'}
            />
            <ConfirmDialog
                open={confirmSend && !!chosen}
                onClose={() => setConfirmSend(false)}
                onConfirm={send}
                processing={route.processing}
                variant="default"
                title="Send this recorded event to Control Room?"
                description={
                    <>
                        {chosenOption ? `${chosenOption.label}. ` : ''}
                        Control Room opens one response for this recorded event;
                        sending it again adds a duplicate report to that
                        response. No emergency call or device command is made.
                        {route.errors.event_key ||
                        route.errors.event_id ||
                        route.message
                            ? ` ${route.errors.event_key ?? route.errors.event_id ?? route.message}`
                            : ''}
                    </>
                }
                confirmText={
                    route.uncertain ? 'Retry sending' : 'Send to Control Room'
                }
            />
            {planOpen && (
                <ResponsePlanWizard
                    workspace={workspace}
                    plan={plan}
                    onClose={() => setPlanOpen(false)}
                    onSaved={reload}
                />
            )}
        </div>
    );
}
