import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    ArrowUpRight,
    Bell,
    CalendarDays,
    Check,
    ClipboardCheck,
    Clock3,
    Gauge,
    ShieldAlert,
    ShieldCheck,
    Wrench,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { defaultBookingStart } from './booking-wizard';
import { PlanAppointmentDialog, SectionHeading } from './studio-kit';
import type { VehicleWorkspace } from './types';
import {
    blockingReasons,
    checkOverdue,
    complianceIssue,
    formatKm,
    headerStatus,
    reasonDestination,
    todayInAuckland,
    type WorkspaceLocation,
} from './workspace-model';

/** Overview › Readiness, built to the approved design's overview studio. */
export function ReadinessPanel({
    workspace,
    onNavigate,
    onSetUpSchedule,
    onStartCheck,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onSetUpSchedule: () => void;
    /** Start a vehicle check; omitted when the person can't inspect. */
    onStartCheck?: () => void;
    onChanged: () => void;
}) {
    const today = todayInAuckland();
    const status = headerStatus(workspace, today);
    const { vehicle, can, work, checks, odometer } = workspace;
    const [planning, setPlanning] = useState(false);
    const nextService =
        workspace.schedules.find((schedule) => schedule.is_active) ?? null;
    const nextReminder =
        workspace.reminders.find((reminder) =>
            ['scheduled', 'acknowledged'].includes(reminder.state),
        ) ?? null;
    const owner = vehicle.responsible?.name ?? 'No responsible person recorded';
    const restricted = status.state === 'restricted';
    const ready = status.state === 'ready';
    // The review button goes to where the first open issue is resolved.
    const firstIssue = blockingReasons(workspace)[0];
    const reviewTarget: WorkspaceLocation = firstIssue
        ? reasonDestination(firstIssue)
        : workspace.schedules.some((schedule) => schedule.overdue)
          ? { tab: 'service', view: 'schedules' }
          : checkOverdue(workspace, today)
            ? { tab: 'checks', view: 'recent' }
            : { tab: 'service', view: 'evidence' };
    // 9 am on the due date when that's still ahead (Auckland wall time).
    const dueStart = nextService?.next_due_at
        ? `${nextService.next_due_at.slice(0, 10)}T09:00`
        : null;
    const planStart =
        dueStart && dueStart > defaultBookingStart() ? dueStart : undefined;

    return (
        <div className="studio-overview">
            <section className="studio-readiness">
                <div className="readiness-primary">
                    <div
                        className={`status-medallion ${restricted ? 'restricted' : 'clear'}`}
                    >
                        {restricted ? (
                            <ShieldAlert className="size-[30px]" />
                        ) : (
                            <ShieldCheck className="size-[30px]" />
                        )}
                    </div>
                    <div>
                        <span className="studio-eyebrow">
                            VEHICLE READINESS
                        </span>
                        <h2>
                            {restricted
                                ? vehicle.status !== 'active'
                                    ? 'Not in service'
                                    : 'Restricted for use'
                                : ready
                                  ? 'Ready for next journey'
                                  : 'Readiness needs review'}
                        </h2>
                        <p>
                            {restricted
                                ? vehicle.status !== 'active'
                                    ? 'The vehicle record is not active, so it cannot be booked or checked out.'
                                    : 'Maintenance hold · independent release required'
                                : ready
                                  ? 'Current evidence recorded. Review the driver and journey at checkout.'
                                  : 'Review missing, stale or overdue evidence before confirming use.'}
                        </p>
                    </div>
                    <StatusBadge variant={restricted ? 'critical' : 'info'}>
                        {restricted ? 'Action required' : status.label}
                    </StatusBadge>
                </div>
                <div className="readiness-action">
                    <div>
                        <small>Next action</small>
                        <strong>
                            {restricted
                                ? 'Review repair & release'
                                : ready
                                  ? 'Confirm the next journey'
                                  : 'Resolve outstanding evidence'}
                        </strong>
                        <span>{owner}</span>
                    </div>
                    {restricted ? (
                        work.can_view ? (
                            <Button
                                onClick={() =>
                                    onNavigate({
                                        tab: 'maintenance',
                                        view: 'open',
                                    })
                                }
                            >
                                Review release
                                <ArrowRight className="size-4" />
                            </Button>
                        ) : null
                    ) : ready ? (
                        can.book ? (
                            <Button
                                onClick={() => onNavigate({ tab: 'calendar' })}
                            >
                                Book this vehicle
                                <ArrowRight className="size-4" />
                            </Button>
                        ) : null
                    ) : (
                        <Button onClick={() => onNavigate(reviewTarget)}>
                            Review evidence
                            <ArrowRight className="size-4" />
                        </Button>
                    )}
                </div>
                <div className="readiness-facts">
                    {[
                        {
                            icon: Wrench,
                            label: 'Open work',
                            value: work.can_view
                                ? String(work.open_count ?? 0)
                                : 'No access',
                            go: () =>
                                onNavigate({
                                    tab: 'maintenance',
                                    view: 'open',
                                }),
                        },
                        {
                            icon: ClipboardCheck,
                            label: 'Next check',
                            value: checks.next_due_at
                                ? formatDateOnly(checks.next_due_at)
                                : 'Not scheduled',
                            go: () =>
                                onNavigate({ tab: 'checks', view: 'recent' }),
                        },
                        {
                            icon: Gauge,
                            label: 'Latest mileage',
                            value:
                                odometer.current_km !== null
                                    ? formatKm(odometer.current_km)
                                    : 'Unknown',
                            go: () =>
                                onNavigate({ tab: 'service', view: 'mileage' }),
                        },
                    ].map((fact) => (
                        // eslint-disable-next-line no-restricted-syntax -- The design's readiness fact tile, which opens where the figure lives.
                        <button
                            key={fact.label}
                            type="button"
                            onClick={fact.go}
                        >
                            <fact.icon className="size-[18px]" aria-hidden />
                            <span>
                                <small>{fact.label}</small>
                                <strong>{fact.value}</strong>
                            </span>
                            <ArrowUpRight className="size-[14px]" aria-hidden />
                        </button>
                    ))}
                </div>
            </section>
            <section className="studio-next">
                <SectionHeading eyebrow="LOOKING AHEAD" title="Next service">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'schedules' })
                        }
                    >
                        All <ArrowRight className="size-[14px]" />
                    </Button>
                </SectionHeading>
                {nextService ? (
                    <>
                        <div className="service-date">
                            <CalendarDays className="size-[25px]" aria-hidden />
                            <div>
                                <strong>
                                    {nextService.next_due_at
                                        ? formatDateOnly(
                                              nextService.next_due_at,
                                          )
                                        : 'Distance based'}
                                </strong>
                                <span>{nextService.name}</span>
                            </div>
                        </div>
                        <div className="service-distance">
                            <span>
                                {nextService.next_due_km !== null
                                    ? formatKm(nextService.next_due_km)
                                    : 'Date trigger only'}
                            </span>
                            <StatusBadge
                                variant={
                                    nextService.overdue ? 'critical' : 'warning'
                                }
                            >
                                {nextService.overdue ? 'Overdue' : 'Upcoming'}
                            </StatusBadge>
                        </div>
                        <p className="text-caption text-muted-foreground">
                            {nextService.owner?.name ?? 'No owner recorded'}
                        </p>
                        {can.schedule_service && (
                            <Button
                                className="w-full"
                                variant="outline"
                                onClick={() => setPlanning(true)}
                            >
                                Plan service
                            </Button>
                        )}
                    </>
                ) : (
                    <>
                        <p className="studio-empty-line">
                            No service schedule recorded.
                        </p>
                        {can.manage_schedules && (
                            <Button onClick={onSetUpSchedule}>
                                Set up service schedule
                            </Button>
                        )}
                    </>
                )}
            </section>
            <section className="studio-health">
                <SectionHeading title="Compliance at a glance">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'evidence' })
                        }
                    >
                        View evidence <ArrowRight className="size-[14px]" />
                    </Button>
                </SectionHeading>
                <div className="health-grid">
                    {workspace.compliance.map((record) => {
                        const issue = complianceIssue(
                            record,
                            workspace.readiness.reasons,
                        );
                        const current = record.current;
                        const failed =
                            current?.applicability === 'applicable' &&
                            current.outcome === 'failed';
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- The design's compliance cell, which opens the evidence view.
                            <button
                                className="health-cell"
                                key={record.kind}
                                type="button"
                                onClick={() =>
                                    onNavigate({
                                        tab: 'service',
                                        view: 'evidence',
                                    })
                                }
                            >
                                <span
                                    className={
                                        !issue && !failed
                                            ? 'health-icon success'
                                            : 'health-icon warning'
                                    }
                                >
                                    {failed ? (
                                        <X className="size-[17px]" />
                                    ) : !issue ? (
                                        <Check className="size-[17px]" />
                                    ) : (
                                        <Clock3 className="size-[17px]" />
                                    )}
                                </span>
                                <div>
                                    <strong>{record.label}</strong>
                                    <small>
                                        {current?.applicability ===
                                        'not_applicable'
                                            ? 'Not applicable'
                                            : current?.expires_on
                                              ? formatDateOnly(
                                                    current.expires_on,
                                                )
                                              : current?.ruc_end_km
                                                ? formatKm(current.ruc_end_km)
                                                : 'Needs evidence'}
                                    </small>
                                </div>
                                <ArrowUpRight
                                    className="size-[14px]"
                                    aria-hidden
                                />
                            </button>
                        );
                    })}
                </div>
            </section>
            <section className="studio-activity">
                <SectionHeading title="What happens next">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onNavigate({ tab: 'calendar' })}
                    >
                        Calendar <ArrowRight className="size-[14px]" />
                    </Button>
                </SectionHeading>
                <div className="next-event-row">
                    <span className="timeline-node">
                        <ClipboardCheck className="size-4" />
                    </span>
                    <div>
                        <strong>Vehicle condition check</strong>
                        <small>
                            {checks.next_due_at
                                ? `${formatDateOnly(checks.next_due_at)} · ${owner}`
                                : 'No check date set'}
                        </small>
                    </div>
                    {onStartCheck && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onStartCheck}
                        >
                            Start check
                        </Button>
                    )}
                </div>
                <div className="next-event-row">
                    <span className="timeline-node">
                        <Bell className="size-4" />
                    </span>
                    <div>
                        <strong>
                            {nextReminder
                                ? nextReminder.title
                                : 'Reminder follow-up'}
                        </strong>
                        <small>
                            {nextReminder?.due_at
                                ? `${formatDateTime(nextReminder.due_at)} · ${nextReminder.owner?.name ?? 'No owner'}`
                                : 'Service and compliance owners'}
                        </small>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'reminders' })
                        }
                    >
                        Review
                    </Button>
                </div>
            </section>
            {planning && nextService && (
                <PlanAppointmentDialog
                    vehicle={vehicle}
                    presetType={nextService.name}
                    startLocal={planStart}
                    onClose={() => setPlanning(false)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}
