import { readFiles } from './evidence-field';
import type { Action, Field, Store, VehicleReminder } from './operations';
type Patch = (fn: (data: Store) => Store, message: string) => void;
type Open = (action: Action) => void;
const field = (
    key: string,
    label: string,
    type: Field['type'] = 'text',
    required = true,
): Field => ({ key, label, type, required });
const endTime = (start: string) => {
    const d = new Date(start);
    d.setHours(d.getHours() + 2);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
export function calendarAppointmentAction(
    data: Store,
    start: string,
    makeWork: (
        id: string,
        title: string,
        source: string,
    ) => Store['works'][number],
    conflict: (start: string, end: string, work: string) => string,
    open: Open,
    patch: Patch,
) {
    const available = data.works.filter(
        (w) =>
            ![
                'Completed',
                'Completed · awaiting release',
                'Cancelled',
            ].includes(w.status),
    );
    open({
        title: 'Schedule service or inspection',
        verb: 'Save appointment',
        values: {
            work: 'new',
            type: 'Routine service',
            start,
            end: endTime(start),
            provider: '',
            owner: data.profile.owner,
            unavailable: 'true',
            confirmed: '',
            notes: '',
            files: '[]',
        },
        change: (key, value, current) => {
            const w =
                key === 'work'
                    ? available.find((x) => x.id === value)
                    : undefined;
            return w
                ? {
                      ...current,
                      [key]: value,
                      type: w.title,
                      provider: w.provider,
                      owner: w.owner,
                  }
                : { ...current, [key]: value };
        },
        sections: [
            {
                title: 'Maintenance work',
                description:
                    'Create a work order or reuse an open record. An existing record keeps its source, evidence and history.',
                fields: [
                    {
                        ...field('work', 'Maintenance record', 'record'),
                        records: [
                            {
                                id: 'new',
                                name: 'Create new work order',
                                detail: 'Owned by Maintenance · linked to VH-014',
                            },
                            ...available.map((w) => ({
                                id: w.id,
                                name: w.id + ' · ' + w.title,
                                detail: w.status,
                            })),
                        ],
                    },
                    {
                        ...field(
                            'type',
                            'Service or inspection type',
                            'catalog',
                        ),
                        options: [
                            'Routine service',
                            'Safety inspection',
                            'WoF inspection',
                            'Tyre inspection',
                            'Repair assessment',
                        ],
                        hint: 'Used as the title for new work; an existing work order keeps its title.',
                    },
                    field('provider', 'Service provider', 'provider'),
                    field('owner', 'Responsible person or role', 'person'),
                ],
            },
            {
                title: 'Appointment & vehicle use',
                description:
                    'Pacific/Auckland. A calendar appointment is an internal plan until provider confirmation is recorded.',
                fields: [
                    field('start', 'Appointment start', 'datetime'),
                    field('end', 'Appointment end', 'datetime'),
                    field(
                        'unavailable',
                        'Vehicle unavailable during this appointment',
                        'check',
                        false,
                    ),
                    field(
                        'confirmed',
                        'Provider confirmation reference',
                        'text',
                        false,
                    ),
                    field(
                        'notes',
                        'Purpose / reason for scheduling',
                        'textarea',
                    ),
                    field('files', 'Appointment evidence', 'files', false),
                ],
            },
        ],
        validationSection: () => 1,
        validate: (v) =>
            v.end <= v.start
                ? 'End must follow start.'
                : v.start < '2026-09-22T09:30'
                  ? 'Choose a future appointment time.'
                  : v.work !== 'new' && !available.some((w) => w.id === v.work)
                    ? 'Choose an open Maintenance record.'
                    : v.unavailable === 'true'
                      ? conflict(v.start, v.end, v.work)
                      : '',
        save: (v) => {
            const existing = available.find((w) => w.id === v.work);
            const id =
                existing?.id ||
                'WO-' +
                    (Math.max(
                        268,
                        ...data.works.map(
                            (w) => Number(w.id.replace(/\D/g, '')) || 0,
                        ),
                    ) +
                        1);
            const work = {
                ...(existing ||
                    makeWork(id, v.type, 'Vehicle calendar · VH-014')),
                provider: v.provider,
                owner: v.owner,
                target: v.start.slice(0, 10),
                start: v.start,
                end: v.end,
                unavailable: v.unavailable === 'true',
                confirmed: v.confirmed,
                status: v.confirmed ? 'Provider confirmed' : 'Internal plan',
                notes: [existing?.notes, v.notes].filter(Boolean).join('\n'),
                history: [
                    ...(existing?.history || []),
                    `22 Sep · Alex Morgan · Calendar appointment ${existing ? 'rescheduled' : 'created'}: ${v.notes}`,
                ],
            };
            patch(
                (d) => ({
                    ...d,
                    works: existing
                        ? d.works.map((w) => (w.id === id ? work : w))
                        : [work, ...d.works],
                    documents: [
                        ...d.documents,
                        ...readFiles(v.files).map((f) => ({
                            ...f,
                            owner: id,
                            category: 'Service appointment',
                        })),
                    ],
                }),
                'Appointment saved in Maintenance and the vehicle calendar.',
            );
        },
    });
}
export function snoozeReminderAction(
    r: VehicleReminder,
    open: Open,
    patch: Patch,
) {
    const base = r.at > '2026-09-22T09:30' ? r.at : '2026-09-22T09:30';
    open({
        title: 'Snooze vehicle reminder',
        verb: 'Snooze reminder',
        values: { at: endTime(base), reason: '' },
        sections: [
            {
                title: 'New reminder time',
                description:
                    r.title +
                    ' · The linked service, compliance due date and vehicle restriction stay unchanged.',
                fields: [
                    field('at', 'Remind me at', 'datetime'),
                    field('reason', 'Reason for snoozing', 'textarea'),
                ],
            },
        ],
        validate: (v) =>
            v.at <= '2026-09-22T09:30' || v.at <= r.at
                ? 'Choose a time after the current reminder and the current preview time.'
                : '',
        save: (v) =>
            patch(
                (d) => ({
                    ...d,
                    followups: d.followups.map((x) =>
                        x.id === r.id
                            ? {
                                  ...x,
                                  at: v.at,
                                  repeatAnchor: x.repeatAnchor || x.at,
                                  status: 'Scheduled',
                                  history: [
                                      ...x.history,
                                      `22 Sep · Alex Morgan · Snoozed from ${x.at} to ${v.at}: ${v.reason}`,
                                  ],
                              }
                            : x,
                    ),
                }),
                'Reminder moved on the calendar; the linked obligation is unchanged.',
            ),
    });
}
