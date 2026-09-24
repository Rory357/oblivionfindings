import {
    BellPlus,
    CalendarClock,
    CalendarDays,
    CalendarPlus,
    Check,
    CheckCheck,
    ClipboardCheck,
    Clock3,
    FileText,
    History,
    KeyRound,
    Lock,
    RefreshCw,
    Route,
    ShieldCheck,
    Upload,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import type { PlannedAppointment } from './appointment-wizard';
import type { BookingDecision } from './booking-decision-wizard';
import type {
    BookingRow,
    CustodyRow,
    UnavailableRow,
    VehicleCalendarItem,
    VehicleCalendarSummary,
} from './calendar-types';
import type { ObligationReminder, VehicleReminder } from './types';

/**
 * The vehicle calendar's right-click actions, as data: the approved design's
 * menus (docs/fleet-assets-audit/previews/PKG-02B/v13/calendar-actions.ts)
 * over the real records. An item the person may use but that the current
 * state blocks is shown disabled with its reason, never silently left out.
 * The calendar, the Bookings & custody cards and the tests share it.
 */

export type LinkedSource = {
    type: 'service_schedule' | 'compliance_record';
    id: number;
};

export type CalendarIntent =
    | { type: 'open-entry' }
    | { type: 'retry-summary' }
    | { type: 'request-booking'; startLocal: string }
    | { type: 'schedule-service'; startLocal: string }
    | { type: 'add-reminder'; dueLocal: string }
    | { type: 'mark-unavailable'; startLocal: string }
    | { type: 'view-day' }
    | { type: 'view-trips' }
    | { type: 'edit-reminder'; reminder: VehicleReminder }
    | { type: 'edit-document'; setId: number }
    | { type: 'snooze-reminder'; reminder: VehicleReminder }
    | { type: 'complete-reminder'; reminder: VehicleReminder }
    | { type: 'open-documents' }
    | { type: 'open-work'; workOrderId: number; release?: boolean }
    | { type: 'reminder-activity'; reminder: VehicleReminder }
    | { type: 'custody-record'; row: CustodyRow }
    | { type: 'edit-booking'; row: BookingRow }
    | { type: 'change-block'; row: UnavailableRow }
    | { type: 'decide'; row: CustodyRow; decision: BookingDecision }
    | { type: 'custody-evidence'; row: CustodyRow }
    | {
          type: 'manage-appointment';
          workOrderId: number;
          appointment?: PlannedAppointment;
          startLocal?: string;
      }
    | { type: 'upload-work-evidence'; workOrderId: number; label: string }
    | { type: 'release-requirements' }
    | {
          type: 'plan-linked';
          startLocal: string;
          presetType: string;
          source: LinkedSource;
      }
    | { type: 'add-follow-up'; source: string }
    | {
          type: 'obligation';
          reminder: ObligationReminder;
          action: 'acknowledge' | 'retry';
      }
    | { type: 'obligation-history'; reminder: ObligationReminder };

export type CalendarAction = {
    key: string;
    label: string;
    icon: LucideIcon;
    intent: CalendarIntent;
    disabled?: boolean;
    destructive?: boolean;
    /** Why the item is disabled, shown under its label. */
    reason?: string;
};

export type CalendarEntry = VehicleCalendarItem & { _start: Date };

export type EntryActionContext = {
    summary: VehicleCalendarSummary | null;
    reminders: VehicleReminder[];
    obligations: ObligationReminder[];
    /** The booking or unavailable period behind the entry, when known. */
    row?: CustodyRow | null;
    vehicleId: number;
    /** 9 am on the entry's day, or the next whole hour: where planning starts. */
    planStart: (day: Date) => string;
};

const PAST_REASON = 'Past time · choose a future slot to schedule';
const RETRY: CalendarAction = {
    key: 'retry-summary',
    label: 'Retry loading actions',
    icon: RefreshCw,
    intent: { type: 'retry-summary' },
    reason: 'Scheduling actions couldn’t load.',
};

/** The first item of every entry menu: open its record where it lives. */
export function openEntryLabel(kind: VehicleCalendarItem['kind']): string {
    if (kind === 'restriction') return 'View restriction reason';
    if (kind === 'appointment' || kind === 'estimate') return 'Open work order';
    return 'Open source record';
}

/** Right-click on a blank date or time (and the New entry menu). */
export function creationActions(
    startLocal: string,
    nowLocal: string,
    summary: VehicleCalendarSummary | null,
): CalendarAction[] {
    if (!summary) return [RETRY];
    const can = summary.can;
    const past = startLocal < nowLocal;
    const reason = past ? PAST_REASON : undefined;
    const item = (
        key: string,
        label: string,
        icon: LucideIcon,
        intent: CalendarIntent,
    ): CalendarAction => ({ key, label, icon, intent, disabled: past, reason });
    return [
        ...(can.request
            ? [
                  item('request', 'Request vehicle booking', CalendarPlus, {
                      type: 'request-booking',
                      startLocal,
                  }),
              ]
            : []),
        ...(can.schedule_service
            ? [
                  item('service', 'Schedule service or inspection', Wrench, {
                      type: 'schedule-service',
                      startLocal,
                  }),
              ]
            : []),
        ...(can.add_reminder
            ? [
                  item('reminder', 'Add reminder', BellPlus, {
                      type: 'add-reminder',
                      dueLocal: startLocal,
                  }),
              ]
            : []),
        ...(can.mark_unavailable
            ? [
                  item('unavailable', 'Mark vehicle unavailable', Lock, {
                      type: 'mark-unavailable',
                      startLocal,
                  }),
              ]
            : []),
    ];
}

/** Always available below the creation items. */
export function dayNavigationActions(
    day: string,
    today: string,
    tripsVisible: boolean,
): CalendarAction[] {
    return [
        {
            key: 'view-day',
            label: 'View this day / availability',
            icon: CalendarDays,
            intent: { type: 'view-day' },
        },
        ...(tripsVisible && day <= today
            ? [
                  {
                      key: 'view-trips',
                      label: 'View trips on this date',
                      icon: Route,
                      intent: { type: 'view-trips' } as CalendarIntent,
                  },
              ]
            : []),
    ];
}

/** Booking and unavailable-period actions, shared with the Bookings & custody cards. */
export function custodyActions(
    row: CustodyRow,
    summary: VehicleCalendarSummary,
): CalendarAction[] {
    if (row.kind === 'unavailable') {
        // An appointment's hold is changed through its appointment, so the
        // two never drift apart.
        if (row.work_order_id) {
            return summary.can.schedule_service
                ? [
                      {
                          key: 'manage-appointment',
                          label: 'Reschedule / manage appointment',
                          icon: CalendarClock,
                          intent: {
                              type: 'manage-appointment',
                              workOrderId: row.work_order_id,
                          },
                      },
                  ]
                : [];
        }
        return [
            ...(row.can.edit
                ? [
                      {
                          key: 'change-block',
                          label: 'Change unavailable period',
                          icon: CalendarClock,
                          intent: {
                              type: 'change-block',
                              row,
                          } as CalendarIntent,
                      },
                  ]
                : []),
            ...(row.can.upload
                ? [
                      {
                          key: 'evidence',
                          label: 'Upload evidence',
                          icon: Upload,
                          intent: {
                              type: 'custody-evidence',
                              row,
                          } as CalendarIntent,
                      },
                  ]
                : []),
            ...(row.can.cancel
                ? [
                      {
                          key: 'cancel',
                          label: 'Cancel unavailable period',
                          icon: X,
                          destructive: true,
                          intent: {
                              type: 'decide',
                              row,
                              decision: 'cancel',
                          } as CalendarIntent,
                      },
                  ]
                : []),
        ];
    }
    const blocked = summary.use_problem ?? '';
    const decide = (
        key: string,
        label: string,
        icon: LucideIcon,
        decision: BookingDecision,
        extra: Partial<CalendarAction> = {},
    ): CalendarAction => ({
        key,
        label,
        icon,
        intent: { type: 'decide', row, decision },
        ...extra,
    });
    return [
        ...(row.can.edit
            ? [
                  {
                      key: 'edit',
                      label: 'Edit booking',
                      icon: CalendarClock,
                      intent: { type: 'edit-booking', row } as CalendarIntent,
                  },
              ]
            : []),
        ...(row.can.approve
            ? [
                  decide('approve', 'Review & approve', Check, 'approve', {
                      disabled: !!blocked,
                      reason: blocked || undefined,
                  }),
              ]
            : []),
        ...(row.can.checkout
            ? [
                  decide('checkout', 'Check out vehicle', KeyRound, 'out', {
                      disabled: !!blocked,
                      reason: blocked || undefined,
                  }),
              ]
            : []),
        ...(row.can.return
            ? [decide('return', 'Record vehicle return', KeyRound, 'return')]
            : []),
        ...(row.can.upload
            ? [
                  {
                      key: 'evidence',
                      label: 'Upload evidence',
                      icon: Upload,
                      intent: {
                          type: 'custody-evidence',
                          row,
                      } as CalendarIntent,
                  },
              ]
            : []),
        ...(row.can.decline
            ? [
                  decide('decline', 'Decline request', X, 'decline', {
                      destructive: true,
                  }),
              ]
            : []),
        ...(row.can.cancel && row.status !== 'checked_out'
            ? [
                  decide('cancel', 'Cancel booking', X, 'cancel', {
                      destructive: true,
                  }),
              ]
            : []),
    ];
}

/** The obligation reminder behind a due date: acknowledge, retry and its history. */
export function obligationActions(
    key: string,
    obligations: ObligationReminder[],
): CalendarAction[] {
    const reminder = obligations.find((item) => item.key === key);
    if (!reminder) return [];
    return [
        ...(reminder.can.acknowledge
            ? [
                  {
                      key: 'acknowledge',
                      label: 'Acknowledge reminder',
                      icon: Check,
                      intent: {
                          type: 'obligation',
                          reminder,
                          action: 'acknowledge',
                      } as CalendarIntent,
                  },
              ]
            : []),
        ...(reminder.can.retry
            ? [
                  {
                      key: 'retry',
                      label: 'Retry delivery',
                      icon: BellPlus,
                      intent: {
                          type: 'obligation',
                          reminder,
                          action: 'retry',
                      } as CalendarIntent,
                  },
              ]
            : []),
        {
            key: 'activity',
            label: 'View reminder activity',
            icon: History,
            intent: { type: 'obligation-history', reminder },
        },
    ];
}

/** Right-click on an entry, after its "Open …" item. */
export function entryActions(
    entry: CalendarEntry,
    ctx: EntryActionContext,
): CalendarAction[] {
    const { summary } = ctx;
    const can = summary?.can;

    if (entry.kind === 'reminder') {
        const reminder = ctx.reminders.find(
            (item) => item.id === entry.recordId,
        );
        if (!reminder) return summary ? [] : [RETRY];
        const open = ['scheduled', 'acknowledged'].includes(reminder.state);
        const renewal =
            reminder.source.type === 'document_set' && reminder.source.id;
        return [
            ...(!can
                ? [RETRY]
                : can.add_reminder && open
                  ? [
                        // A document owns its renewal reminder: rescheduling
                        // it means editing the document's renewal plan.
                        renewal
                            ? {
                                  key: 'edit',
                                  label: 'Edit / reschedule reminder',
                                  icon: CalendarClock,
                                  intent: {
                                      type: 'edit-document',
                                      setId: reminder.source.id as number,
                                  } as CalendarIntent,
                              }
                            : {
                                  key: 'edit',
                                  label: 'Edit / reschedule reminder',
                                  icon: CalendarClock,
                                  intent: {
                                      type: 'edit-reminder',
                                      reminder,
                                  } as CalendarIntent,
                              },
                        {
                            key: 'snooze',
                            label: 'Snooze reminder',
                            icon: Clock3,
                            intent: {
                                type: 'snooze-reminder',
                                reminder,
                            } as CalendarIntent,
                        },
                        {
                            key: 'complete',
                            label: 'Complete follow-up',
                            icon: CheckCheck,
                            intent: {
                                type: 'complete-reminder',
                                reminder,
                            } as CalendarIntent,
                        },
                    ]
                  : []),
            ...(reminder.source.type === 'document_set'
                ? [
                      {
                          key: 'document',
                          label: 'Open linked document',
                          icon: FileText,
                          intent: { type: 'open-documents' } as CalendarIntent,
                      },
                  ]
                : []),
            ...(reminder.source.type === 'work_order' && reminder.source.id
                ? [
                      {
                          key: 'work',
                          label: 'Open linked Maintenance',
                          icon: Wrench,
                          intent: {
                              type: 'open-work',
                              workOrderId: reminder.source.id,
                          } as CalendarIntent,
                      },
                  ]
                : []),
            {
                key: 'activity',
                label: 'View reminder activity',
                icon: History,
                intent: { type: 'reminder-activity', reminder },
            },
        ];
    }

    if (entry.kind === 'booking' || entry.kind === 'unavailable') {
        if (!summary) return [RETRY];
        // An appointment's hold is known from the entry even before its row loads.
        if (
            entry.kind === 'unavailable' &&
            entry.meta?.held_by_appointment &&
            entry.workOrderId
        ) {
            return [
                ...(summary.can.schedule_service
                    ? [
                          {
                              key: 'manage-appointment',
                              label: 'Reschedule / manage appointment',
                              icon: CalendarClock,
                              intent: {
                                  type: 'manage-appointment',
                                  workOrderId: entry.workOrderId,
                              } as CalendarIntent,
                          },
                      ]
                    : []),
                {
                    key: 'work',
                    label: 'Open work order',
                    icon: Wrench,
                    intent: {
                        type: 'open-work',
                        workOrderId: entry.workOrderId,
                    },
                },
            ];
        }
        return ctx.row ? custodyActions(ctx.row, summary) : [];
    }

    if (
        (entry.kind === 'appointment' || entry.kind === 'estimate') &&
        entry.workOrderId
    ) {
        if (!can) return [RETRY];
        if (!can.schedule_service) return [];
        const workOrderId = entry.workOrderId;
        const openWork = summary?.open_work.some(
            (work) => work.id === workOrderId,
        );
        // A planned appointment is managed; an estimate manages the plan
        // already on its work, or gets its first one.
        const planned =
            entry.kind === 'appointment' && entry.start && entry.meta?.open
                ? {
                      start: entry.start,
                      end: entry.end ?? null,
                      provider: entry.meta.provider ?? undefined,
                      unavailable: entry.meta.unavailable,
                  }
                : entry.kind === 'estimate' && entry.meta?.appointment
                  ? {
                        start: entry.meta.appointment.start,
                        end: entry.meta.appointment.end ?? null,
                        provider: entry.meta.appointment.provider ?? undefined,
                        unavailable: entry.meta.appointment.unavailable,
                    }
                  : undefined;
        return [
            ...(openWork && (planned || entry.kind === 'estimate')
                ? [
                      {
                          key: 'manage-appointment',
                          label: 'Reschedule / manage appointment',
                          icon: CalendarClock,
                          intent: {
                              type: 'manage-appointment',
                              workOrderId,
                              appointment: planned as
                                  | PlannedAppointment
                                  | undefined,
                              startLocal: planned
                                  ? undefined
                                  : ctx.planStart(entry._start),
                          } as CalendarIntent,
                      },
                  ]
                : []),
            {
                key: 'evidence',
                label: 'Upload work evidence',
                icon: Upload,
                intent: {
                    type: 'upload-work-evidence',
                    workOrderId,
                    label: entry.ref ?? `Work #${workOrderId}`,
                },
            },
        ];
    }

    if (entry.kind === 'restriction') {
        const active = entry.status === 'overdue';
        const workStatus =
            entry.meta?.work_status ?? summary?.restriction?.work_status;
        const reviewer = !!can?.review_release;
        return [
            ...(entry.workOrderId
                ? [
                      {
                          key: 'work',
                          label: 'Open linked Maintenance',
                          icon: Wrench,
                          intent: {
                              type: 'open-work',
                              workOrderId: entry.workOrderId,
                          } as CalendarIntent,
                      },
                  ]
                : []),
            {
                key: 'requirements',
                label: 'View release requirements',
                icon: ClipboardCheck,
                intent: { type: 'release-requirements' },
            },
            ...(active &&
            entry.workOrderId &&
            (can?.schedule_service || reviewer)
                ? [
                      {
                          key: 'release',
                          label: 'Review authorised release',
                          icon: ShieldCheck,
                          intent: {
                              type: 'open-work',
                              workOrderId: entry.workOrderId,
                              release: true,
                          } as CalendarIntent,
                          disabled: !reviewer || workStatus !== 'completed',
                          reason: !reviewer
                              ? 'An authorised release reviewer for this Site records the release.'
                              : workStatus !== 'completed'
                                ? 'Complete the repair and record a passing retest first.'
                                : undefined,
                      },
                  ]
                : []),
        ];
    }

    if (
        entry.kind === 'schedule' ||
        entry.kind === 'compliance' ||
        entry.kind === 'check'
    ) {
        if (!can) return [RETRY];
        const key =
            entry.kind === 'schedule'
                ? `service_schedule:${entry.recordId}`
                : entry.kind === 'compliance'
                  ? `compliance_record:${entry.recordId}`
                  : `vehicle_check:${ctx.vehicleId}`;
        const source: LinkedSource | null =
            entry.kind === 'schedule' && entry.recordId
                ? { type: 'service_schedule', id: entry.recordId }
                : entry.kind === 'compliance' && entry.recordId
                  ? { type: 'compliance_record', id: entry.recordId }
                  : null;
        // Open work already reported from this due date is planned on, not duplicated.
        const linkedWork = source
            ? summary?.open_work.find(
                  (work) =>
                      work.source?.type === source.type &&
                      work.source.id === source.id,
              )
            : undefined;
        return [
            ...(can.schedule_service && source
                ? [
                      linkedWork
                          ? {
                                key: 'plan',
                                label: 'Plan linked appointment',
                                icon: Wrench,
                                intent: {
                                    type: 'manage-appointment',
                                    workOrderId: linkedWork.id,
                                    startLocal: ctx.planStart(entry._start),
                                } as CalendarIntent,
                            }
                          : {
                                key: 'plan',
                                label: 'Plan linked appointment',
                                icon: Wrench,
                                intent: {
                                    type: 'plan-linked',
                                    startLocal: ctx.planStart(entry._start),
                                    presetType: entry.title.replace(
                                        / due · reminder$/,
                                        '',
                                    ),
                                    source,
                                } as CalendarIntent,
                            },
                  ]
                : []),
            ...(can.add_reminder
                ? [
                      {
                          key: 'follow-up',
                          label: 'Add follow-up reminder',
                          icon: BellPlus,
                          intent: {
                              type: 'add-follow-up',
                              // Checks have no reminder source of their own.
                              source: entry.kind === 'check' ? 'vehicle' : key,
                          } as CalendarIntent,
                      },
                  ]
                : []),
            ...obligationActions(key, ctx.obligations),
        ];
    }

    return [];
}
