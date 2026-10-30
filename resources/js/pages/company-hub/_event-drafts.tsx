import { bookingCutoffLabel } from '@/components/hr/calendar/event-booking-cutoff';
import {
    EventWizardDialog,
    type CalendarEventInitial,
} from '@/components/hr/calendar/event-wizard-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowLeft, LockKeyhole, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { EventOptions } from './_events';
import { HubSelect } from './_select';

export type EventDraft = {
    new_event?: boolean;
    publish_at?: string | null;
    schedule_error?: string | null;
    id: number;
    version: number;
    state:
        | 'draft'
        | 'review'
        | 'changes_requested'
        | 'published'
        | 'scheduled'
        | 'schedule_blocked';
    archived_at: string | null;
    owner: string;
    feedback: string | null;
    updated_at: string;
    published_at: string | null;
    initial: CalendarEventInitial;
};
type Workspace = {
    actor_id?: number;
    can_schedule?: boolean;
    can_publish: boolean;
    drafts: EventDraft[];
};
const labels = {
    draft: 'Private draft',
    review: 'Awaiting review',
    changes_requested: 'Changes requested',
    published: 'Published',
    scheduled: 'Scheduled',
    schedule_blocked: 'Publication needs attention',
};

export function EventDraftWorkspace() {
    const open = true;
    const pending = useRef(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [options, setOptions] = useState<EventOptions | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(
        () =>
            Number(
                new URLSearchParams(window.location.search).get('event_draft'),
            ) || null,
    );
    const [editing, setEditing] = useState<EventDraft | null>(null);
    const [filter, setFilter] = useState('active');
    const [query, setQuery] = useState('');
    const [refresh, setRefresh] = useState(0);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [feedback, setFeedback] = useState('');
    const [returning, setReturning] = useState(false);
    useEffect(() => {
        if (!open) return;
        let current = true;
        setLoading(true);
        setError('');
        void Promise.all([
            hubRequest<Workspace>('/company-hub/event-drafts', 'GET'),
            hubRequest<EventOptions>('/company-hub/events/options', 'GET'),
        ])
            .then(([next, choices]) => {
                if (!current) return;
                if (next.actor_id !== choices.actor_id)
                    throw new Error(
                        'Your account has changed. Refresh drafts to continue.',
                    );
                setWorkspace(next);
                setOptions(choices);
            })
            .catch((e) => {
                if (current) setError((e as Error).message);
            })
            .finally(() => {
                if (current) setLoading(false);
            });
        return () => {
            current = false;
        };
    }, [open, refresh]);
    const selected = workspace?.drafts.find((draft) => draft.id === selectedId);
    const drafts = (workspace?.drafts ?? []).filter(
        (draft) =>
            (filter === 'archived'
                ? !!draft.archived_at
                : !draft.archived_at &&
                  (filter === 'active'
                      ? draft.state !== 'published'
                      : draft.state === filter)) &&
            `${draft.initial.title} ${draft.owner}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const transition = async (action: string) => {
        if (!selected || busy || loading || pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await hubRequest<{ draft: EventDraft }>(
                `/company-hub/event-drafts/${selected.id}/transition`,
                'POST',
                {
                    action,
                    ...(workspace?.actor_id
                        ? { actor_id: workspace.actor_id }
                        : {}),
                    version: selected.version,
                    feedback: action === 'return' ? feedback : undefined,
                },
            );
            if (!alive.current) return;
            if (
                result.draft.id !== selected.id ||
                result.draft.version <= selected.version
            )
                throw new Error(
                    'The saved version could not be confirmed. Refresh drafts before continuing.',
                );
            setWorkspace((current) =>
                current
                    ? {
                          ...current,
                          drafts: current.drafts.map((draft) =>
                              draft.id === result.draft.id
                                  ? result.draft
                                  : draft,
                          ),
                      }
                    : current,
            );
            setReturning(false);
            setFeedback('');
            setMessage(
                action === 'approve'
                    ? result.draft.state === 'scheduled'
                        ? 'Event scheduled for publication.'
                        : 'Event published. Staff can now see the approved version.'
                    : action === 'cancel_schedule'
                      ? 'Publication cancelled. The private draft can be edited again.'
                      : action === 'return'
                        ? 'Feedback returned to the author.'
                        : action === 'withdraw'
                          ? 'Review withdrawn. You can edit this draft again.'
                          : action === 'archive'
                            ? 'Private draft archived. The published event is unchanged.'
                            : 'Private draft restored.',
            );
            if (action === 'approve')
                router.reload({ only: ['events'], preserveScroll: true });
        } catch (e) {
            if (!alive.current) return;
            setError((e as Error).message);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    return (
        <>
            {!editing && (
                <section
                    className="space-y-6"
                    aria-label="Event drafts workspace"
                >
                    <header>
                        <Button
                            variant="ghost"
                            className="mb-3 -ml-3"
                            disabled={busy}
                            onClick={() =>
                                router.visit('/company-hub?view=events')
                            }
                        >
                            <ArrowLeft className="size-4" />
                            Back to events
                        </Button>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Event drafts
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Prepare, review and publish company events. Private
                            changes stay here until publication.
                        </p>
                    </header>
                    <div className="flex items-center gap-3">
                        <Input
                            aria-label="Search event drafts"
                            placeholder="Search title or author…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <div className="w-48 shrink-0">
                            <HubSelect
                                label="Event draft status"
                                value={filter}
                                onChange={(value) => {
                                    setFilter(value);
                                    setSelectedId(null);
                                    setReturning(false);
                                    setMessage('');
                                }}
                                options={[
                                    { value: 'active', label: 'In progress' },
                                    {
                                        value: 'review',
                                        label: 'Awaiting review',
                                    },
                                    { value: 'scheduled', label: 'Scheduled' },
                                    {
                                        value: 'schedule_blocked',
                                        label: 'Needs attention',
                                    },
                                    {
                                        value: 'published',
                                        label: 'Published history',
                                    },
                                    {
                                        value: 'archived',
                                        label: 'Archived drafts',
                                    },
                                ]}
                            />
                        </div>
                        <Button
                            variant="outline"
                            disabled={loading || busy}
                            onClick={() => setRefresh((n) => n + 1)}
                        >
                            <RefreshCw className="size-4" />
                            Refresh drafts
                        </Button>
                    </div>
                    {loading && (
                        <p role="status" className="text-sm">
                            Loading current drafts…
                        </p>
                    )}
                    {error && (
                        <p
                            role="alert"
                            className="rounded-lg border border-destructive/30 p-3 text-sm text-destructive"
                        >
                            {error} Use Refresh drafts to check the current
                            version.
                        </p>
                    )}
                    {message && (
                        <p
                            role="status"
                            className="rounded-lg border bg-muted/40 p-3 text-sm"
                        >
                            {message}
                        </p>
                    )}
                    <div className="grid min-h-[420px] grid-cols-[300px_minmax(0,1fr)] gap-6">
                        <div
                            className="space-y-2 border-r pr-5"
                            aria-label="Saved event drafts"
                        >
                            {!loading && workspace && drafts.length === 0 && (
                                <p className="py-8 text-sm text-muted-foreground">
                                    No drafts in this view. Use Create event to
                                    prepare a new event, or edit an existing
                                    event to prepare changes.
                                </p>
                            )}
                            {drafts.map((draft) => (
                                // eslint-disable-next-line no-restricted-syntax -- A selectable draft card with title, status and author.
                                <button
                                    type="button"
                                    key={draft.id}
                                    disabled={busy || loading}
                                    onClick={() => {
                                        setSelectedId(draft.id);
                                        setFeedback('');
                                        setReturning(false);
                                        setError('');
                                        setMessage('');
                                    }}
                                    aria-pressed={selectedId === draft.id}
                                    className={`w-full rounded-xl border p-4 text-left transition-colors ${selectedId === draft.id ? 'border-primary bg-primary/5' : 'border-transparent hover:bg-muted'}`}
                                >
                                    <span className="block text-xs font-medium text-muted-foreground">
                                        {draft.archived_at
                                            ? 'Archived draft'
                                            : labels[draft.state]}{' '}
                                        · v{draft.version}
                                    </span>
                                    <span className="mt-1 block font-semibold">
                                        {draft.initial.title}
                                    </span>
                                    <span className="mt-2 block text-xs text-muted-foreground">
                                        {draft.owner} ·{' '}
                                        {formatDateTime(draft.updated_at)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        {selected && options ? (
                            <div className="min-w-0 space-y-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                                            <LockKeyhole className="size-3" />
                                            {selected.archived_at
                                                ? 'Archived draft'
                                                : labels[selected.state]}{' '}
                                            · Version {selected.version}
                                        </p>
                                        <h2 className="text-2xl font-semibold">
                                            {selected.initial.title}
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Prepared by {selected.owner}
                                            {(selected.new_event ??
                                            !selected.initial.id)
                                                ? ' · New event'
                                                : ' · Changes to an existing event'}
                                        </p>
                                    </div>
                                </div>
                                {selected.publish_at && (
                                    <p className="text-sm">
                                        Publication:{' '}
                                        {formatDateTime(selected.publish_at)}
                                    </p>
                                )}
                                {selected.schedule_error && (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {selected.schedule_error}
                                    </p>
                                )}
                                {selected.feedback && (
                                    <div className="rounded-xl border bg-muted/50 p-4">
                                        <h3 className="text-sm font-semibold">
                                            Reviewer feedback
                                        </h3>
                                        <p className="mt-1 text-sm whitespace-pre-wrap">
                                            {selected.feedback}
                                        </p>
                                    </div>
                                )}
                                <EventDraftPreview
                                    draft={selected}
                                    options={options}
                                />
                                <div className="flex flex-wrap gap-2 border-t pt-4">
                                    {!selected.archived_at &&
                                        [
                                            'scheduled',
                                            'schedule_blocked',
                                        ].includes(selected.state) && (
                                            <Button
                                                variant="outline"
                                                disabled={busy || loading}
                                                onClick={() =>
                                                    void transition(
                                                        'cancel_schedule',
                                                    )
                                                }
                                            >
                                                Cancel publication & edit draft
                                            </Button>
                                        )}
                                    {!selected.archived_at &&
                                        ['draft', 'changes_requested'].includes(
                                            selected.state,
                                        ) && (
                                            <Button
                                                disabled={busy || loading}
                                                onClick={() =>
                                                    setEditing(selected)
                                                }
                                            >
                                                Edit draft
                                            </Button>
                                        )}
                                    {!selected.archived_at &&
                                        selected.state === 'review' && (
                                            <>
                                                {workspace?.can_publish && (
                                                    <>
                                                        <Button
                                                            disabled={
                                                                busy || loading
                                                            }
                                                            onClick={() =>
                                                                void transition(
                                                                    'approve',
                                                                )
                                                            }
                                                        >
                                                            {selected.publish_at
                                                                ? 'Approve & schedule'
                                                                : 'Approve & publish'}
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            disabled={
                                                                busy || loading
                                                            }
                                                            onClick={() =>
                                                                setReturning(
                                                                    true,
                                                                )
                                                            }
                                                        >
                                                            Return with feedback
                                                        </Button>
                                                    </>
                                                )}
                                                <Button
                                                    variant="outline"
                                                    disabled={busy || loading}
                                                    onClick={() =>
                                                        void transition(
                                                            'withdraw',
                                                        )
                                                    }
                                                >
                                                    Withdraw review
                                                </Button>
                                            </>
                                        )}
                                    {![
                                        'published',
                                        'scheduled',
                                        'schedule_blocked',
                                    ].includes(selected.state) && (
                                        <Button
                                            variant="outline"
                                            disabled={busy || loading}
                                            onClick={() =>
                                                void transition(
                                                    selected.archived_at
                                                        ? 'restore'
                                                        : 'archive',
                                                )
                                            }
                                        >
                                            {selected.archived_at
                                                ? 'Restore draft'
                                                : 'Archive draft'}
                                        </Button>
                                    )}
                                    {selected.state === 'published' && (
                                        <p className="text-sm text-muted-foreground">
                                            Published{' '}
                                            {selected.published_at
                                                ? formatDateTime(
                                                      selected.published_at,
                                                  )
                                                : ''}
                                            . To prepare further changes, open
                                            the event on the board and choose
                                            Edit event details.
                                        </p>
                                    )}
                                </div>
                                {returning && (
                                    <div className="space-y-3 rounded-xl border p-4">
                                        <Label htmlFor="event-review-feedback">
                                            What should the author change?
                                        </Label>
                                        <Textarea
                                            id="event-review-feedback"
                                            maxLength={2000}
                                            value={feedback}
                                            onChange={(e) =>
                                                setFeedback(e.target.value)
                                            }
                                        />
                                        <div className="flex gap-2">
                                            <Button
                                                disabled={
                                                    busy ||
                                                    loading ||
                                                    !feedback.trim()
                                                }
                                                onClick={() =>
                                                    void transition('return')
                                                }
                                            >
                                                Send feedback
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                disabled={busy}
                                                onClick={() =>
                                                    setReturning(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-center justify-center text-sm text-muted-foreground">
                                Select a draft to review its details and files.
                            </div>
                        )}
                    </div>
                </section>
            )}
            {editing && options && (
                <EventWizardDialog
                    open
                    presentation="page"
                    {...options}
                    initial={editing.initial}
                    publishing={{
                        draft_id: editing.id,
                        version: editing.version,
                        can_publish: !!workspace?.can_publish,
                        can_schedule: !!workspace?.can_schedule,
                        cutoff_supported: !!options.cutoff_supported,
                        actor_id: workspace?.actor_id,
                        publish_at: editing.publish_at,
                    }}
                    onClose={() => {
                        setEditing(null);
                        setRefresh((n) => n + 1);
                    }}
                    onSaved={() => {
                        setRefresh((n) => n + 1);
                        router.reload({
                            only: ['events'],
                            preserveScroll: true,
                        });
                    }}
                />
            )}
        </>
    );
}

function EventDraftPreview({
    draft,
    options,
}: {
    draft: EventDraft;
    options: EventOptions;
}) {
    const event = draft.initial;
    const content = event.hub_content;
    const cover = event.attachments?.find(
        (file) => `file:${file.id}` === content?.cover_ref,
    );
    const findName = (
        items: { id: number; name: string }[],
        id: number | null | undefined,
    ) => items.find((item) => item.id === id)?.name ?? 'None';
    const audience =
        event.audience_type === 'people'
            ? (event.audience_user_ids ?? [])
                  .map(
                      (id) =>
                          options.staff.find(
                              (person) => Number(person.value) === id,
                          )?.label ?? 'Unavailable colleague',
                  )
                  .join(', ')
            : event.audience_type === 'site'
              ? findName(options.sites, event.site_id)
              : event.audience_type === 'department'
                ? findName(options.departments, event.department_id)
                : event.audience_type === 'team'
                  ? event.audience_team
                  : 'Everyone';
    return (
        <div className="space-y-4">
            {cover && (
                <img
                    src={cover.url}
                    alt={content?.cover_alt || 'Event cover'}
                    className="h-48 w-full rounded-xl object-cover"
                />
            )}
            {event.description && (
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {event.description}
                </p>
            )}
            <dl className="grid grid-cols-2 gap-4 rounded-xl border bg-muted/30 p-4 text-sm">
                {[
                    [
                        'When',
                        `${formatDateTime(event.starts_at)} – ${formatDateTime(event.ends_at)}${event.is_all_day ? ' · All day' : ''}`,
                    ],
                    ['Where', event.location || 'Location to be confirmed'],
                    ['Audience', audience],
                    ['Site', findName(options.sites, event.site_id)],
                    ['Category', event.event_type],
                    [
                        'Host',
                        options.staff.find(
                            (person) =>
                                Number(person.value) === content?.host_id,
                        )?.label ?? draft.owner,
                    ],
                    [
                        'Repeat',
                        event.rrule
                            ? `${event.rrule} · ${event.recurrence_timezone || 'UTC'}${event.recurrence_until ? ` · until ${formatDateTime(event.recurrence_until)}` : ''}`
                            : 'One date',
                    ],
                    [
                        'Changes apply to',
                        event.scope === 'this' || event.recurrence_parent_id
                            ? 'This date only'
                            : event.scope === 'following'
                              ? 'This and following dates'
                              : 'Whole event / series',
                    ],
                    [
                        'Registration',
                        event.registration_enabled === undefined
                            ? 'Existing series settings'
                            : event.registration_enabled
                              ? event.registration_capacity
                                  ? `${event.registration_capacity} places per date`
                                  : 'Open · unlimited places'
                              : 'Closed',
                    ],
                    [
                        'Reminders',
                        event.reminders?.length
                            ? event.reminders
                                  .map(
                                      (reminder) =>
                                          `${reminder.offset_minutes} minutes before · ${reminder.channel === 'email' ? 'Email' : 'In-app'}`,
                                  )
                                  .join(', ')
                            : 'No reminders',
                    ],
                    ...(options.cutoff_supported &&
                    event.registration_enabled &&
                    event.scope !== 'this' &&
                    !event.recurrence_parent_id
                        ? [
                              [
                                  'Registration closes',
                                  bookingCutoffLabel(
                                      event.registration_closes_before_minutes?.toString() ??
                                          '',
                                  ),
                              ],
                          ]
                        : []),
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="mt-1 font-medium break-words">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
            {[
                ['Agenda', content?.agenda],
                ['Recap', content?.recap],
                [
                    'Related story',
                    options.storyOptions?.find(
                        (story) => story.id === content?.story_id,
                    )?.title,
                ],
            ].map(([label, value]) =>
                value ? (
                    <div key={label}>
                        <h3 className="text-sm font-semibold">{label}</h3>
                        <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                            {value}
                        </p>
                    </div>
                ) : null,
            )}
            {!!content?.gallery_refs?.length && (
                <div
                    className="grid grid-cols-3 gap-2"
                    aria-label="Draft recap gallery"
                >
                    {event.attachments
                        ?.filter((file) =>
                            content.gallery_refs.includes(`file:${file.id}`),
                        )
                        .map((file) => (
                            <img
                                key={file.id}
                                src={file.url}
                                alt={file.name}
                                className="h-24 w-full rounded-lg object-cover"
                            />
                        ))}
                </div>
            )}
            {!!event.attachments?.length && (
                <div>
                    <h3 className="mb-2 text-sm font-semibold">Files</h3>
                    <div className="flex flex-wrap gap-2">
                        {event.attachments.map((file) => (
                            <a
                                key={file.id}
                                href={file.url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-lg border px-3 py-2 text-sm underline underline-offset-4"
                            >
                                {file.name}
                                {file.id < 0 ? ' · Private draft file' : ''}
                            </a>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
