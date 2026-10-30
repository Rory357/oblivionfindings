import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { HubSelect } from '@/pages/company-hub/_select';
import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    LockKeyhole,
    Paperclip,
    Save,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { PeopleMultiPicker, type PersonOption } from '../people-picker';
import { EventBookingCutoff, bookingCutoffLabel } from './event-booking-cutoff';
import { EventDateField } from './event-date-field';
import { EventHubFields, type EventHubContentInput } from './event-hub-fields';
import type { EventLibraryPhoto } from './event-image-library';
import { eventTimeInput, eventTimeInstant } from './event-time';
import type {
    CalendarEventInitial,
    EventAttachment,
    EventCategoryOption,
} from './event-wizard-dialog';

export type EventEditorData = {
    title: string;
    description: string;
    event_type: string;
    starts_at: string;
    ends_at: string;
    is_all_day: boolean;
    rrule: string;
    recurrence_until: string;
    recurrence_timezone: 'UTC' | 'Pacific/Auckland';
    audience_type: 'org' | 'site' | 'department' | 'team' | 'people';
    audience_team: string;
    audience_user_ids: number[];
    reminders: { offset_minutes: number; channel: string }[];
    location: string;
    department_id: string;
    site_id: string;
    hub_content: EventHubContentInput;
    registration_enabled: boolean;
    registration_capacity: string;
    registration_closes_before_minutes: string;
};
export type EventPublicationMode = 'draft' | 'review' | 'publish' | 'schedule';
type Props = {
    data: EventEditorData;
    set: <K extends keyof EventEditorData>(
        key: K,
        value: EventEditorData[K],
    ) => void;
    errors: Record<string, string>;
    busy: boolean;
    dirty: boolean;
    submitted: boolean;
    savedMode: EventPublicationMode;
    canSubmit: boolean;
    canPublish: boolean;
    canSchedule: boolean;
    cutoffSupported?: boolean;
    initialPublishAt?: string | null;
    initial?: CalendarEventInitial | null;
    sites: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    teams: string[];
    categories: EventCategoryOption[];
    staff: PersonOption[];
    stories: { id: number; title: string }[];
    emailAvailable: boolean;
    attachments: EventAttachment[];
    files: File[];
    libraryPhotos?: EventLibraryPhoto[];
    libraryActorId?: number;
    retainedDraftFiles?: number;
    onLibraryPhotosChange?: (photos: EventLibraryPhoto[]) => void;
    addFiles: (files: File[]) => void;
    removeFile: (index: number) => void;
    removeAttachment: (file: EventAttachment) => void;
    inherited: (file: EventAttachment) => boolean;
    onClose: () => void;
    onSave: (mode: EventPublicationMode, publishAt?: string) => void;
};
function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <Label className="grid gap-2 text-sm font-medium">
            {label}
            {children}
        </Label>
    );
}
const repeats = [
    ['', 'One event'],
    ['FREQ=DAILY', 'Daily'],
    ['FREQ=WEEKLY', 'Weekly'],
    ['FREQ=WEEKLY;INTERVAL=2', 'Every two weeks'],
    ['FREQ=MONTHLY', 'Monthly'],
    ['FREQ=MONTHLY;INTERVAL=3', 'Every three months'],
];

/** Hub authoring stays beneath the shared hero. The canonical controller owns every save. */
export function EventPageEditor(p: Props) {
    const { data: d, set } = p;
    const [tab, setTab] = useState('Details');
    const [schedule, setSchedule] = useState(!!p.initialPublishAt);
    const [release, setRelease] = useState(
        eventTimeInput(p.initialPublishAt || '', false),
    );
    const dirty =
        p.dirty ||
        schedule !== !!p.initialPublishAt ||
        release !== eventTimeInput(p.initialPublishAt || '', false);
    const [localError, setLocalError] = useState('');
    const [leave, setLeave] = useState<(() => void) | null>(null);
    const allowed = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const [coverUrl, setCoverUrl] = useState('');
    const single =
        p.initial?.scope === 'this' || !!p.initial?.recurrence_parent_id;
    useEffect(() => {
        if (p.submitted) {
            setLocalError('');
            setLeave(null);
        }
    }, [p.submitted]);
    useEffect(() => {
        heading.current?.focus();
    }, []);
    useEffect(() => {
        const ref = d.hub_content.cover_ref;
        const file = ref.startsWith('upload:')
            ? p.files[Number(ref.slice(7))]
            : null;
        const url = file
            ? URL.createObjectURL(file)
            : p.attachments.find((a) => 'file:' + a.id === ref)?.url ||
              p.libraryPhotos?.find((photo) => `library:${photo.id}` === ref)
                  ?.url ||
              '';
        setCoverUrl(url);
        return () => {
            if (file) URL.revokeObjectURL(url);
        };
    }, [d.hub_content.cover_ref, p.files, p.attachments, p.libraryPhotos]);
    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (p.busy || (dirty && !p.submitted)) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        const unsubscribe = router.on('before', (e) => {
            if (
                e.detail.visit.method !== 'get' ||
                allowed.current ||
                p.submitted
            )
                return;
            if (!p.busy && !dirty) return;
            e.preventDefault();
            if (p.busy) {
                setLocalError('Please wait for the current save to finish.');
                return;
            }
            const next = e.detail.visit;
            setLeave(() => () => router.visit(next.url, { ...next }));
        });
        window.addEventListener('beforeunload', warn);
        return () => {
            unsubscribe();
            window.removeEventListener('beforeunload', warn);
        };
    }, [p.busy, dirty, p.submitted]);
    const close = () => {
        if (p.busy) return;
        if (dirty && !p.submitted) setLeave(() => p.onClose);
        else p.onClose();
    };
    const save = (mode: EventPublicationMode) => {
        setLocalError('');
        let instant: string | undefined;
        if (schedule) {
            try {
                instant = eventTimeInstant(release, false);
                if (!instant || Date.parse(instant) <= Date.now())
                    throw new Error(
                        'Choose a future publication date and time.',
                    );
            } catch (error) {
                setLocalError((error as Error).message);
                return;
            }
        }
        p.onSave(mode === 'publish' && schedule ? 'schedule' : mode, instant);
    };
    const when = (value: string) => {
        try {
            return d.is_all_day
                ? formatDateOnly(value)
                : formatDateTime(eventTimeInstant(value, false));
        } catch {
            return 'Check the event time';
        }
    };
    const errors = [...new Set(Object.values(p.errors)), localError].filter(
        Boolean,
    );
    return (
        <section
            className="hub-event-editor space-y-4"
            aria-label="Event editor"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                    <Button
                        variant="outline"
                        size="icon"
                        className="shrink-0"
                        aria-label="Back to events"
                        title="Back to events"
                        disabled={p.busy}
                        onClick={close}
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <div>
                        <h1
                            ref={heading}
                            tabIndex={-1}
                            className="scroll-mt-36 text-2xl font-semibold tracking-tight outline-none"
                        >
                            {p.submitted
                                ? p.savedMode === 'publish'
                                    ? 'Event published'
                                    : p.savedMode === 'schedule'
                                      ? 'Event scheduled'
                                      : p.savedMode === 'review'
                                        ? 'Sent for review'
                                        : 'Private draft saved'
                                : p.initial
                                  ? 'Edit event'
                                  : 'Create an event'}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {p.submitted
                                ? 'Your saved event is available in Event drafts.'
                                : 'A clear invitation helps people feel welcome.'}
                        </p>
                    </div>
                </div>
                <span className="mt-2 flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                    <LockKeyhole className="size-4" />
                    {p.busy
                        ? 'Saving…'
                        : dirty && !p.submitted
                          ? 'Unsaved changes'
                          : 'Private event workspace'}
                </span>
            </div>
            {leave && (
                <div
                    role="alert"
                    className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-3 py-2"
                >
                    <p className="mr-auto text-sm">
                        Leave without these changes? Your last saved draft will
                        be kept.
                    </p>
                    <Button variant="outline" onClick={() => setLeave(null)}>
                        Keep editing
                    </Button>
                    <Button
                        variant="outline"
                        disabled={p.busy}
                        onClick={() => {
                            allowed.current = true;
                            leave();
                        }}
                    >
                        Leave without changes
                    </Button>
                </div>
            )}
            {errors.length > 0 && (
                <div
                    role="alert"
                    className="space-y-1 rounded-xl border border-destructive/30 p-4 text-sm text-destructive"
                >
                    {errors.map((e) => (
                        <p key={e}>{e}</p>
                    ))}
                </div>
            )}
            {p.submitted ? (
                <div className="flex items-start gap-4 border-t py-8">
                    <Check className="size-8 text-primary" />
                    <div>
                        <h2 className="text-xl font-semibold">{d.title}</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {p.savedMode === 'publish'
                                ? 'The published invitation is available to its audience.'
                                : 'Staff keep seeing the currently published event until these changes are published.'}
                        </p>
                        <Button className="mt-5" onClick={close}>
                            Back to event drafts
                        </Button>
                    </div>
                </div>
            ) : (
                <>
                    <div
                        className="flex gap-2 border-b pb-2"
                        aria-label="Event editing sections"
                    >
                        {['Details', 'Cover & files', 'Review invitation'].map(
                            (name) => (
                                <Button
                                    key={name}
                                    variant={
                                        tab === name ? 'secondary' : 'ghost'
                                    }
                                    aria-pressed={tab === name}
                                    disabled={p.busy}
                                    onClick={() => setTab(name)}
                                >
                                    {name}
                                </Button>
                            ),
                        )}
                    </div>
                    <fieldset disabled={p.busy} className="min-w-0">
                        {tab === 'Details' && (
                            <div className="grid grid-cols-[minmax(0,1fr)_280px] gap-10">
                                <div className="min-w-0 space-y-6">
                                    <Field label="Event title">
                                        <Input
                                            value={d.title}
                                            onChange={(e) =>
                                                set('title', e.target.value)
                                            }
                                            placeholder="A moment worth getting together for"
                                        />
                                    </Field>
                                    <Field label="What can people look forward to?">
                                        <Textarea
                                            rows={3}
                                            className="hub-event-description"
                                            value={d.description}
                                            onChange={(e) =>
                                                set(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Share what’s happening, who it’s for and anything to bring."
                                        />
                                    </Field>
                                    <Field label="Location">
                                        <Input
                                            value={d.location}
                                            onChange={(e) =>
                                                set('location', e.target.value)
                                            }
                                            placeholder="A place or meeting link"
                                        />
                                    </Field>
                                    <Label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={d.is_all_day}
                                            onChange={(e) => {
                                                set(
                                                    'is_all_day',
                                                    e.target.checked,
                                                );
                                                set(
                                                    'starts_at',
                                                    e.target.checked
                                                        ? d.starts_at.slice(
                                                              0,
                                                              10,
                                                          )
                                                        : d.starts_at +
                                                              (d.starts_at
                                                                  .length === 10
                                                                  ? 'T09:00'
                                                                  : ''),
                                                );
                                                set(
                                                    'ends_at',
                                                    e.target.checked
                                                        ? d.ends_at.slice(0, 10)
                                                        : d.ends_at +
                                                              (d.ends_at
                                                                  .length === 10
                                                                  ? 'T10:00'
                                                                  : ''),
                                                );
                                            }}
                                        />
                                        All-day event
                                    </Label>
                                    <div className="grid grid-cols-2 gap-4">
                                        <EventDateField
                                            label="Starts"
                                            dateOnly={d.is_all_day}
                                            value={d.starts_at}
                                            onChange={(value) =>
                                                set('starts_at', value)
                                            }
                                            disabled={p.busy}
                                            error={p.errors.starts_at}
                                        />
                                        <EventDateField
                                            label="Ends"
                                            dateOnly={d.is_all_day}
                                            value={d.ends_at}
                                            onChange={(value) =>
                                                set('ends_at', value)
                                            }
                                            disabled={p.busy}
                                            error={p.errors.ends_at}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        New Zealand time · Pacific/Auckland
                                    </p>
                                    {!single && (
                                        <div className="space-y-4 border-t pt-5">
                                            <Label className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        d.registration_enabled
                                                    }
                                                    onChange={(e) =>
                                                        set(
                                                            'registration_enabled',
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                Open registration when published
                                            </Label>
                                            {d.registration_enabled && (
                                                <Field label="Places per date">
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        value={
                                                            d.registration_capacity
                                                        }
                                                        onChange={(e) =>
                                                            set(
                                                                'registration_capacity',
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="No limit"
                                                    />
                                                </Field>
                                            )}
                                            {p.cutoffSupported &&
                                                d.registration_enabled && (
                                                    <EventBookingCutoff
                                                        value={
                                                            d.registration_closes_before_minutes
                                                        }
                                                        onChange={(value) =>
                                                            set(
                                                                'registration_closes_before_minutes',
                                                                value,
                                                            )
                                                        }
                                                        disabled={p.busy}
                                                    />
                                                )}
                                        </div>
                                    )}
                                    <details className="border-t pt-5">
                                        <summary className="cursor-pointer text-sm font-semibold">
                                            Audience & reminders
                                        </summary>
                                        <div className="mt-5 space-y-5">
                                            <div className="grid grid-cols-2 gap-4">
                                                {(
                                                    [
                                                        'site_id',
                                                        'department_id',
                                                    ] as const
                                                ).map((key) => (
                                                    <Field
                                                        key={key}
                                                        label={
                                                            key === 'site_id'
                                                                ? 'Site'
                                                                : 'Department'
                                                        }
                                                    >
                                                        <HubSelect
                                                            label={
                                                                key ===
                                                                'site_id'
                                                                    ? 'Site'
                                                                    : 'Department'
                                                            }
                                                            value={d[key]}
                                                            disabled={p.busy}
                                                            onChange={(value) =>
                                                                set(key, value)
                                                            }
                                                            options={[
                                                                {
                                                                    value: '',
                                                                    label: 'All',
                                                                },
                                                                ...(key ===
                                                                'site_id'
                                                                    ? p.sites
                                                                    : p.departments
                                                                ).map(
                                                                    (item) => ({
                                                                        value: String(
                                                                            item.id,
                                                                        ),
                                                                        label: item.name,
                                                                    }),
                                                                ),
                                                            ]}
                                                        />
                                                    </Field>
                                                ))}
                                            </div>
                                            <Field label="Who is invited?">
                                                <HubSelect
                                                    label="Who is invited?"
                                                    disabled={single || p.busy}
                                                    value={d.audience_type}
                                                    onChange={(value) =>
                                                        set(
                                                            'audience_type',
                                                            value as EventEditorData['audience_type'],
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: 'org',
                                                            label: 'Everyone',
                                                        },
                                                        {
                                                            value: 'site',
                                                            label: 'Selected site',
                                                        },
                                                        {
                                                            value: 'department',
                                                            label: 'Selected department',
                                                        },
                                                        {
                                                            value: 'team',
                                                            label: 'A team',
                                                        },
                                                        {
                                                            value: 'people',
                                                            label: 'Selected colleagues',
                                                        },
                                                    ]}
                                                />
                                            </Field>
                                            {d.audience_type === 'team' && (
                                                <Field label="Team">
                                                    <HubSelect
                                                        label="Team"
                                                        disabled={
                                                            single || p.busy
                                                        }
                                                        value={d.audience_team}
                                                        onChange={(value) =>
                                                            set(
                                                                'audience_team',
                                                                value,
                                                            )
                                                        }
                                                        options={[
                                                            {
                                                                value: '',
                                                                label: 'Choose a team',
                                                            },
                                                            ...p.teams.map(
                                                                (team) => ({
                                                                    value: team,
                                                                    label: team,
                                                                }),
                                                            ),
                                                        ]}
                                                    />
                                                </Field>
                                            )}
                                            {d.audience_type === 'people' && (
                                                <PeopleMultiPicker
                                                    disabled={single}
                                                    value={d.audience_user_ids.map(
                                                        String,
                                                    )}
                                                    onChange={(values) =>
                                                        set(
                                                            'audience_user_ids',
                                                            values.map(Number),
                                                        )
                                                    }
                                                    people={p.staff}
                                                />
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {single
                                                    ? 'The series audience and repeat pattern stay the same.'
                                                    : 'Only colleagues in this audience can see the invitation.'}
                                            </p>
                                            <fieldset className="space-y-3">
                                                <legend className="mb-2 text-sm font-medium">
                                                    Remind invited colleagues
                                                </legend>
                                                {[1440, 60, 15].map(
                                                    (minutes) => (
                                                        <Label
                                                            key={minutes}
                                                            className="flex items-center gap-2"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={d.reminders.some(
                                                                    (r) =>
                                                                        r.offset_minutes ===
                                                                        minutes,
                                                                )}
                                                                onChange={(e) =>
                                                                    set(
                                                                        'reminders',
                                                                        e.target
                                                                            .checked
                                                                            ? [
                                                                                  ...d.reminders,
                                                                                  {
                                                                                      offset_minutes:
                                                                                          minutes,
                                                                                      channel:
                                                                                          'notification',
                                                                                  },
                                                                              ]
                                                                            : d.reminders.filter(
                                                                                  (
                                                                                      r,
                                                                                  ) =>
                                                                                      r.offset_minutes !==
                                                                                      minutes,
                                                                              ),
                                                                    )
                                                                }
                                                            />
                                                            {minutes === 1440
                                                                ? 'One day before'
                                                                : minutes +
                                                                  ' minutes before'}
                                                        </Label>
                                                    ),
                                                )}
                                                {d.reminders.some(
                                                    (r) =>
                                                        ![
                                                            1440, 60, 15,
                                                        ].includes(
                                                            r.offset_minutes,
                                                        ),
                                                ) && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Other saved reminder
                                                        times are retained.
                                                    </p>
                                                )}
                                                <Field label="Reminder delivery">
                                                    <HubSelect
                                                        label="Reminder delivery"
                                                        value={
                                                            d.reminders[0]
                                                                ?.channel ??
                                                            'notification'
                                                        }
                                                        disabled={p.busy}
                                                        onChange={(value) =>
                                                            set(
                                                                'reminders',
                                                                d.reminders.map(
                                                                    (
                                                                        reminder,
                                                                    ) => ({
                                                                        ...reminder,
                                                                        channel:
                                                                            value,
                                                                    }),
                                                                ),
                                                            )
                                                        }
                                                        options={[
                                                            {
                                                                value: 'notification',
                                                                label: 'In-app notification',
                                                            },
                                                            ...(p.emailAvailable
                                                                ? [
                                                                      {
                                                                          value: 'email',
                                                                          label: 'Email',
                                                                      },
                                                                  ]
                                                                : []),
                                                        ]}
                                                    />
                                                </Field>
                                            </fieldset>
                                        </div>
                                    </details>
                                </div>
                                <aside className="space-y-6 border-l pl-7">
                                    <Field label="Host">
                                        <HubSelect
                                            label="Host"
                                            value={
                                                d.hub_content.host_id == null
                                                    ? ''
                                                    : String(
                                                          d.hub_content.host_id,
                                                      )
                                            }
                                            disabled={p.busy}
                                            onChange={(value) =>
                                                set('hub_content', {
                                                    ...d.hub_content,
                                                    host_id: value
                                                        ? Number(value)
                                                        : null,
                                                })
                                            }
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Use the organiser',
                                                },
                                                ...p.staff.map((person) => ({
                                                    value: String(person.value),
                                                    label: person.label,
                                                })),
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Category">
                                        <HubSelect
                                            label="Category"
                                            value={d.event_type}
                                            disabled={p.busy}
                                            onChange={(value) =>
                                                set('event_type', value)
                                            }
                                            options={p.categories.map(
                                                (category) => ({
                                                    value: category.key,
                                                    label: category.label,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    <Field label="Repeats">
                                        <HubSelect
                                            label="Repeats"
                                            value={d.rrule}
                                            disabled={single || p.busy}
                                            onChange={(value) => {
                                                set('rrule', value);
                                                set(
                                                    'recurrence_timezone',
                                                    'Pacific/Auckland',
                                                );
                                            }}
                                            options={[
                                                ...(!repeats.some(
                                                    (repeat) =>
                                                        repeat[0] === d.rrule,
                                                )
                                                    ? [
                                                          {
                                                              value: d.rrule,
                                                              label: 'Saved repeat pattern',
                                                          },
                                                      ]
                                                    : []),
                                                ...repeats.map(
                                                    ([value, label]) => ({
                                                        value,
                                                        label,
                                                    }),
                                                ),
                                            ]}
                                        />
                                    </Field>
                                    {d.rrule && (
                                        <EventDateField
                                            label="Repeat until"
                                            dateOnly
                                            clearable
                                            disabled={single || p.busy}
                                            value={d.recurrence_until}
                                            onChange={(value) =>
                                                set('recurrence_until', value)
                                            }
                                            error={p.errors.recurrence_until}
                                        />
                                    )}
                                    {p.initial?.scope && (
                                        <div className="border-t pt-5 text-sm">
                                            <p className="font-medium">
                                                Applying changes to
                                            </p>
                                            <p className="mt-2 text-muted-foreground">
                                                {single
                                                    ? 'This date only'
                                                    : p.initial.scope ===
                                                        'following'
                                                      ? 'This and following dates'
                                                      : 'The event or whole series'}
                                            </p>
                                            <p className="mt-3 text-xs text-muted-foreground">
                                                Registrations remain linked to
                                                their event dates.
                                            </p>
                                        </div>
                                    )}
                                </aside>
                            </div>
                        )}
                        {tab === 'Cover & files' && (
                            <div className="max-w-4xl space-y-6">
                                {coverUrl && (
                                    <img
                                        src={coverUrl}
                                        alt={d.hub_content.cover_alt}
                                        className="h-64 w-full rounded-xl object-cover"
                                    />
                                )}
                                <FileDropzone
                                    onFiles={p.addFiles}
                                    hint="PDF, Word, Excel, PowerPoint or photos. Up to 10 files, 10 MB each. New files stay private until publication."
                                />
                                <ul className="divide-y">
                                    {p.attachments.map((file) => (
                                        <li
                                            key={file.id}
                                            className="flex items-center gap-3 py-3 text-sm"
                                        >
                                            <Paperclip className="size-4" />
                                            <a
                                                className="min-w-0 flex-1 break-words hover:underline"
                                                href={file.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {file.name}
                                            </a>
                                            {p.inherited(file) ? (
                                                <span className="text-xs text-muted-foreground">
                                                    Shared by the series
                                                </span>
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={
                                                        'Remove ' + file.name
                                                    }
                                                    onClick={() =>
                                                        p.removeAttachment(file)
                                                    }
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {p.files.map((file, i) => (
                                    <StagedFileCard
                                        key={file.name + i}
                                        file={file}
                                        onRemove={() => p.removeFile(i)}
                                    />
                                ))}
                                <EventHubFields
                                    value={d.hub_content}
                                    onChange={(value) =>
                                        set('hub_content', value)
                                    }
                                    files={p.files}
                                    attachments={p.attachments}
                                    libraryPhotos={p.libraryPhotos}
                                    libraryActorId={p.libraryActorId}
                                    retainedDraftFiles={p.retainedDraftFiles}
                                    onLibraryPhotosChange={
                                        p.onLibraryPhotosChange
                                    }
                                    staff={p.staff}
                                    stories={p.stories}
                                    draftMode
                                    mediaOnly
                                />
                            </div>
                        )}
                        {tab === 'Review invitation' && (
                            <div className="grid grid-cols-[minmax(0,1fr)_280px] gap-10">
                                <article className="hub-event-invitation-preview min-w-0 space-y-5">
                                    <p className="text-xs font-medium text-primary">
                                        Staff invitation preview
                                    </p>
                                    {coverUrl && (
                                        <img
                                            className="h-64 w-full rounded-xl object-cover"
                                            src={coverUrl}
                                            alt={d.hub_content.cover_alt}
                                        />
                                    )}
                                    <h2 className="text-2xl font-semibold">
                                        {d.title || 'Your event title'}
                                    </h2>
                                    <p className="text-sm leading-7 whitespace-pre-wrap">
                                        {d.description}
                                    </p>
                                    {d.hub_content.agenda && (
                                        <div className="border-t pt-5">
                                            <h3 className="font-semibold">
                                                What’s on the agenda
                                            </h3>
                                            <p className="mt-3 text-sm leading-7 whitespace-pre-wrap">
                                                {d.hub_content.agenda}
                                            </p>
                                        </div>
                                    )}
                                    {(p.attachments.length > 0 ||
                                        p.files.length > 0) && (
                                        <div className="space-y-2 border-t pt-4">
                                            <h3 className="text-sm font-semibold">
                                                Files for this event
                                            </h3>
                                            <ul className="space-y-2 text-sm">
                                                {p.attachments.map((file) => (
                                                    <li key={file.id}>
                                                        <a
                                                            className="inline-flex items-center gap-2 text-primary hover:underline"
                                                            href={file.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            <Paperclip className="size-4" />
                                                            {file.name}
                                                        </a>
                                                    </li>
                                                ))}
                                                {p.files.map((file, index) => (
                                                    <li
                                                        key={`${file.name}-${index}`}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Paperclip className="size-4" />
                                                        {file.name}
                                                        <span className="text-xs text-muted-foreground">
                                                            Ready to upload
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                    <div className="flex items-center gap-3 border-t pt-4">
                                        <Button disabled>
                                            {d.registration_enabled
                                                ? 'Register for this event'
                                                : 'Registration closed'}
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            Preview only · no registration is
                                            made here
                                        </span>
                                    </div>
                                    <Button
                                        variant="outline"
                                        onClick={() => setTab('Details')}
                                    >
                                        Edit invitation
                                    </Button>
                                </article>
                                <aside className="space-y-5 border-l pl-7">
                                    <h3 className="font-semibold">
                                        Invitation details
                                    </h3>
                                    <dl className="space-y-4 text-sm">
                                        {[
                                            [
                                                'When',
                                                when(d.starts_at) +
                                                    ' – ' +
                                                    when(d.ends_at),
                                            ],
                                            [
                                                'Where',
                                                d.location ||
                                                    'No location added',
                                            ],
                                            [
                                                'Host',
                                                p.staff.find(
                                                    (person) =>
                                                        person.value ===
                                                        String(
                                                            d.hub_content
                                                                .host_id,
                                                        ),
                                                )?.label || 'The organiser',
                                            ],
                                            [
                                                'Places',
                                                d.registration_enabled
                                                    ? d.registration_capacity ||
                                                      'No limit'
                                                    : 'Registration is closed',
                                            ],
                                            ...(p.cutoffSupported &&
                                            d.registration_enabled &&
                                            !single
                                                ? [
                                                      [
                                                          'Registration closes',
                                                          bookingCutoffLabel(
                                                              d.registration_closes_before_minutes,
                                                          ),
                                                      ],
                                                  ]
                                                : []),
                                        ].map(([label, value]) => (
                                            <div key={label}>
                                                <dt className="text-xs text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="mt-1 break-words">
                                                    {value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                    {p.canSchedule && (
                                        <div className="space-y-4 border-t pt-5">
                                            <Field label="Publication">
                                                <HubSelect
                                                    label="Publication"
                                                    value={
                                                        schedule
                                                            ? 'later'
                                                            : 'now'
                                                    }
                                                    disabled={p.busy}
                                                    onChange={(value) =>
                                                        setSchedule(
                                                            value === 'later',
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: 'now',
                                                            label: 'Publish now',
                                                        },
                                                        {
                                                            value: 'later',
                                                            label: 'Schedule for later',
                                                        },
                                                    ]}
                                                />
                                            </Field>
                                            {schedule && (
                                                <>
                                                    <EventDateField
                                                        label="Publish date and time"
                                                        value={release}
                                                        onChange={setRelease}
                                                        disabled={p.busy}
                                                    />
                                                    <p className="text-xs text-muted-foreground">
                                                        New Zealand time ·
                                                        Pacific/Auckland
                                                    </p>
                                                </>
                                            )}
                                        </div>
                                    )}
                                </aside>
                            </div>
                        )}
                    </fieldset>
                    <div className="sticky bottom-0 z-10 flex items-center justify-between gap-4 border-t bg-background/95 py-4 backdrop-blur">
                        <Button
                            variant="ghost"
                            disabled={p.busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        <div className="flex items-center gap-3">
                            <Button
                                variant="outline"
                                disabled={p.busy || !p.canSubmit}
                                onClick={() => save('draft')}
                            >
                                <Save className="size-4" />
                                Save draft
                            </Button>
                            {tab === 'Review invitation' ? (
                                <>
                                    {p.canPublish && (
                                        <Button
                                            variant="outline"
                                            disabled={p.busy || !p.canSubmit}
                                            onClick={() => save('review')}
                                        >
                                            Send for review
                                        </Button>
                                    )}
                                    <Button
                                        disabled={p.busy || !p.canSubmit}
                                        onClick={() =>
                                            save(
                                                p.canPublish
                                                    ? 'publish'
                                                    : 'review',
                                            )
                                        }
                                    >
                                        {p.busy
                                            ? 'Saving…'
                                            : p.canPublish
                                              ? schedule
                                                  ? 'Schedule event'
                                                  : 'Publish event'
                                              : 'Send for review'}
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    disabled={p.busy}
                                    onClick={() => setTab('Review invitation')}
                                >
                                    Review invitation
                                    <ArrowRight className="size-4" />
                                </Button>
                            )}
                        </div>
                    </div>
                </>
            )}
        </section>
    );
}
