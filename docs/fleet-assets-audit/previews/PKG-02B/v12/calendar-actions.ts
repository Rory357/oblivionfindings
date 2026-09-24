import {
    BellPlus,
    CalendarClock,
    CalendarPlus,
    Check,
    CheckCheck,
    ClipboardCheck,
    Clock3,
    FileText,
    History,
    KeyRound,
    Lock,
    ShieldCheck,
    Upload,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import type { VehicleModel } from './operations';

export type CalendarAction = {
    label: string;
    icon: LucideIcon;
    run: () => void;
    disabled?: boolean;
    destructive?: boolean;
    reason?: string;
};

export function calendarActions(
    m: VehicleModel,
    openWork: (id: string) => void,
    nav: (view: string, sub?: string) => void,
) {
    const createAt = (start: string): CalendarAction[] => {
        const past = start < '2026-09-22T09:30';
        return [
            ...(m.canRequest
                ? [
                      {
                          label: 'Request vehicle booking',
                          icon: CalendarPlus,
                          run: () => m.booking(start),
                          disabled: past,
                      },
                  ]
                : []),
            ...(m.canManage
                ? [
                      {
                          label: 'Schedule service or inspection',
                          icon: Wrench,
                          run: () => m.calendarAppointment(start),
                          disabled: past,
                      },
                      {
                          label: 'Add reminder',
                          icon: BellPlus,
                          run: () =>
                              m.followup(undefined, 'Vehicle profile', start),
                          disabled: past,
                      },
                      {
                          label: 'Mark vehicle unavailable',
                          icon: Lock,
                          run: () => m.booking(start, undefined, true),
                          disabled: past,
                      },
                  ]
                : []),
        ];
    };
    const forEntry = (id: string): CalendarAction[] => {
        const r = m.data.followups.find((x) => x.id === id);
        if (r)
            return [
                ...(m.canManage && !['Completed', 'Paused'].includes(r.status)
                    ? [
                          {
                              label: 'Edit / reschedule reminder',
                              icon: CalendarClock,
                              run: () => m.followup(id),
                          },
                          {
                              label: 'Snooze reminder',
                              icon: Clock3,
                              run: () => m.followupAction(id, 'snooze'),
                          },
                          {
                              label: 'Complete follow-up',
                              icon: CheckCheck,
                              run: () => m.followupAction(id, 'complete'),
                          },
                      ]
                    : []),
                ...(m.data.documents.some((f) => f.id === r.source)
                    ? [
                          {
                              label: 'Open linked document',
                              icon: FileText,
                              run: () => nav('overview', 'documents'),
                          },
                      ]
                    : []),
                ...(m.data.works.some((w) => w.id === r.source)
                    ? [
                          {
                              label: 'Open linked Maintenance',
                              icon: Wrench,
                              run: () => openWork(r.source),
                          },
                      ]
                    : []),
                {
                    label: 'View reminder activity',
                    icon: History,
                    run: () => m.followupAction(id, 'detail'),
                },
            ];
        const b = m.data.bookings.find((x) => x.id === id);
        if (b) {
            if (
                !m.canManage ||
                ['Returned', 'Cancelled', 'Declined'].includes(b.status)
            )
                return [];
            return [
                ...(b.status !== 'Checked out'
                    ? [
                          {
                              label: b.block
                                  ? 'Change unavailable period'
                                  : 'Edit booking',
                              icon: CalendarClock,
                              run: () => m.booking(b.start, id, b.block),
                          },
                      ]
                    : []),
                ...(b.status === 'Pending approval' && !b.block
                    ? [
                          {
                              label: 'Review & approve',
                              icon: Check,
                              run: () => m.bookingTransition(id, 'approve'),
                              disabled: m.hold,
                              reason: m.hold
                                  ? 'Vehicle restriction must be resolved'
                                  : undefined,
                          },
                          {
                              label: 'Decline request',
                              icon: X,
                              run: () => m.bookingTransition(id, 'decline'),
                              destructive: true,
                          },
                      ]
                    : []),
                ...(b.status === 'Confirmed' && !b.block
                    ? [
                          {
                              label: 'Check out vehicle',
                              icon: KeyRound,
                              run: () => m.bookingTransition(id, 'out'),
                              disabled: m.hold,
                              reason: m.hold
                                  ? 'Vehicle restriction must be resolved'
                                  : undefined,
                          },
                      ]
                    : []),
                ...(b.status === 'Checked out'
                    ? [
                          {
                              label: 'Record vehicle return',
                              icon: KeyRound,
                              run: () => m.bookingTransition(id, 'return'),
                          },
                      ]
                    : [
                          {
                              label: b.block
                                  ? 'Cancel unavailable period'
                                  : 'Cancel booking',
                              icon: X,
                              run: () => m.bookingTransition(id, 'cancel'),
                              destructive: true,
                          },
                      ]),
            ];
        }
        const workId = id.startsWith('estimate-') ? id.slice(9) : id;
        const w = m.data.works.find((x) => x.id === workId);
        if (w)
            return m.canManage
                ? [
                      ...(![
                          'Completed',
                          'Completed · awaiting release',
                          'Cancelled',
                      ].includes(w.status)
                          ? [
                                {
                                    label: 'Reschedule / manage appointment',
                                    icon: CalendarClock,
                                    run: () => m.plan(w.id),
                                },
                            ]
                          : []),
                      {
                          label: 'Upload work evidence',
                          icon: Upload,
                          run: () => m.setUploadFor(w.id),
                      },
                  ]
                : [];
        if (id === 'restriction')
            return [
                {
                    label: 'Open linked Maintenance',
                    icon: Wrench,
                    run: () => openWork('WO-0264'),
                },
                {
                    label: 'View release requirements',
                    icon: ClipboardCheck,
                    run: () =>
                        m.detail('Vehicle release requirements', [
                            [
                                'Original issue',
                                'Retain the failed check and its linked Maintenance record.',
                            ],
                            [
                                'Repair and retest',
                                'Record completed repair evidence and a passed retest.',
                            ],
                            [
                                'Readiness',
                                'Resolve overdue service, checks and compliance evidence.',
                            ],
                            [
                                'Authorisation',
                                'An independent authorised reviewer records the release decision. Completing work alone does not release the vehicle.',
                            ],
                        ]),
                },
                ...(m.canManage
                    ? [
                          {
                              label: 'Review authorised release',
                              icon: ShieldCheck,
                              run: () => m.release(),
                          },
                      ]
                    : []),
            ];
        const schedule = m.data.schedules.find((x) => x.id === id);
        const compliance = m.data.compliance.find((x) => x.id === id);
        if (schedule || compliance || id === 'check-due')
            return [
                ...(m.canManage
                    ? [
                          ...(schedule || compliance
                              ? [
                                    {
                                        label: 'Plan linked appointment',
                                        icon: Wrench,
                                        run: () => m.plan(id, !!schedule),
                                    },
                                ]
                              : []),
                          {
                              label: 'Add follow-up reminder',
                              icon: BellPlus,
                              run: () => m.followup(undefined, id),
                          },
                          {
                              label: 'Acknowledge reminder',
                              icon: Check,
                              run: () => m.reminder(id, 'acknowledge'),
                          },
                      ]
                    : []),
                {
                    label: 'View reminder activity',
                    icon: History,
                    run: () => m.reminder(id, 'detail'),
                },
            ];
        return [];
    };
    return { createAt, forEntry };
}
