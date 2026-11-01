/**
 * The safety review's pop-ups (board M8, approved by Stephan 1 Nov 2026), all on the pop-up kit: round lens, title, a
 * short phrase, ✕, the record it came from, a grey footer with Cancel first and the main button named for what it
 * does. Choose / change the owner, add an action (or a revised action), record that WorkSafe was told, and close.
 * Every command is the existing server route; the server checks everything again.
 */
import { useForm } from '@inertiajs/react';
import { CheckCircle2, ListChecks, Search, ShieldAlert, UserRoundCheck } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';

import type { EventCorrectiveAction, EventDetail } from '@/components/health-safety/event-detail-dialog';
import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { PopupCallout, PopupField, PopupForm } from '@/components/popup';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Segmented, TilePicker } from '@/components/wizard/primitives';
import { formatDate, timeOffsetsInZone } from '@/lib/datetime';
import { cn } from '@/lib/utils';

export type OwnerCandidate = { id: number; name: string; detail?: string | null };

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

/** A short list of people to choose from, searchable, "You" first (board M8). */
export function PeopleChooser({
    label,
    people,
    value,
    onChange,
    viewerId,
    hint,
    error,
}: {
    label: string;
    people: OwnerCandidate[];
    value: string;
    onChange: (value: string) => void;
    viewerId?: number | null;
    hint?: string;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const groupId = useId();
    const ordered = useMemo(
        () => [...people].sort((a, b) => (a.id === viewerId ? -1 : b.id === viewerId ? 1 : a.name.localeCompare(b.name))),
        [people, viewerId],
    );
    const shown = ordered.filter((person) =>
        `${person.name} ${person.detail ?? ''}`.toLowerCase().includes(query.trim().toLowerCase()),
    );
    return (
        <PopupField label={label} required hint={hint} error={error}>
            <div className="grid gap-2">
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search people who can own it"
                        aria-label="Search people who can own it"
                        className="pl-9"
                    />
                </div>
                <div role="radiogroup" aria-label={label} id={groupId} className="grid max-h-64 gap-1 overflow-y-auto scrollbar-pretty">
                    {shown.length ? (
                        shown.map((person) => {
                            const picked = value === String(person.id);
                            const you = person.id === viewerId;
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- a person card in a radio list (custom layout), not a form button.
                                <button
                                    key={person.id}
                                    type="button"
                                    role="radio"
                                    aria-checked={picked}
                                    onClick={() => onChange(String(person.id))}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        picked ? 'border-primary bg-accent' : 'border-border hover:bg-muted/60',
                                    )}
                                >
                                    <span
                                        className="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold text-muted-foreground"
                                        aria-hidden="true"
                                    >
                                        {initials(person.name)}
                                    </span>
                                    <span className="grid min-w-0">
                                        <b className="truncate text-sm font-semibold">{you ? `You — ${person.name}` : person.name}</b>
                                        {person.detail ? <span className="text-caption truncate">{person.detail}</span> : null}
                                    </span>
                                </button>
                            );
                        })
                    ) : (
                        <p className="text-caption px-1 py-2">No one matches this search.</p>
                    )}
                </div>
            </div>
        </PopupField>
    );
}

/** Choose the first owner, or change the owner (decision 3, Q2). POST /health-safety/events/{id}/owner. */
export function ReviewOwnerDialog({
    open,
    onClose,
    eventId,
    reference,
    title,
    siteName,
    currentOwner,
    candidates,
    viewerId,
}: {
    open: boolean;
    onClose: () => void;
    eventId: number;
    reference: string;
    title: string;
    siteName: string | null;
    currentOwner: { id: number; name: string } | null;
    candidates: OwnerCandidate[];
    viewerId?: number | null;
}) {
    const first = currentOwner === null;
    const form = useForm<{ owner_user_id: string; reason: string }>({ owner_user_id: '', reason: '' });
    useEffect(() => {
        if (!open) form.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
    const people = candidates.filter((person) => person.id !== currentOwner?.id);
    const site = siteName ?? 'this site';
    const reasonShort = !first && form.data.reason.trim().length < 10;
    const self = viewerId != null && form.data.owner_user_id === String(viewerId);

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={UserRoundCheck}
            title={first ? 'Choose the owner' : 'Change the owner'}
            description={
                first
                    ? 'Whoever you choose owns the safety review and its next steps'
                    : 'The new owner takes over the safety review and its next steps'
            }
            context={{
                title: [title, siteName, reference].filter(Boolean).join(' · '),
                note: first ? 'No owner yet. It can’t close without one.' : `Owned by ${currentOwner?.name}.`,
            }}
            submitLabel={first ? (self ? 'Take it on' : 'Choose owner') : 'Change owner'}
            processingLabel="Saving…"
            processing={form.processing}
            submitDisabled={!form.data.owner_user_id || reasonShort}
            onSubmit={() => form.post(`/health-safety/events/${eventId}/owner`, { preserveScroll: true, onSuccess: () => onClose() })}
            dirty={form.isDirty}
            draftName="this owner change"
            testId="review-owner-dialog"
        >
            <PeopleChooser
                label={first ? 'Who will own it?' : 'New owner'}
                people={people}
                value={form.data.owner_user_id}
                onChange={(value) => form.setData('owner_user_id', value)}
                viewerId={viewerId}
                hint={
                    people.length
                        ? `Only people who manage health and safety at ${site} are listed.`
                        : `No one else who manages health and safety at ${site} can own it.`
                }
                error={form.errors.owner_user_id}
            />
            <PopupField
                label={first ? 'Note for the history (optional)' : 'Why the change?'}
                htmlFor="review-owner-reason"
                required={!first}
                hint={first ? undefined : `Needed. Kept in the history.${currentOwner ? ` ${currentOwner.name} is told.` : ''}`}
                error={form.errors.reason}
            >
                <Textarea
                    id="review-owner-reason"
                    rows={3}
                    value={form.data.reason}
                    placeholder={first ? 'For example, what you’ll look at first' : 'For example: “Jordan is on leave until 16 Nov.”'}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </PopupField>
        </PopupForm>
    );
}

const ACTION_KINDS = [
    { key: 'corrective', label: 'Fix the cause', description: 'Remove what caused it' },
    { key: 'preventive', label: 'Stop it happening', description: 'A check or a control' },
    { key: 'improvement', label: 'Improvement', description: 'Better than before' },
];

const PRIORITIES = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Urgent' },
] as const;

/** Add an action, or a revised action for one whose check said it didn't work (Q11). */
export function AddActionDialog({
    open,
    onClose,
    onDone,
    d,
    replaces,
}: {
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    d: EventDetail;
    replaces?: EventCorrectiveAction | null;
}) {
    const kindId = useId();
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        action_type: string;
        assigned_to_user_id: string;
        due_date: string;
        replaces_action_id: number | null;
    }>({
        title: '',
        description: '',
        priority: 'medium',
        action_type: 'corrective',
        assigned_to_user_id: '',
        due_date: '',
        replaces_action_id: replaces?.id ?? null,
    });
    const site = d.site?.name ?? 'this site';
    const ready = !!form.data.title.trim() && !!form.data.assigned_to_user_id && !!form.data.due_date;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ListChecks}
            title={replaces ? 'Add a revised action' : 'Add action'}
            description={replaces ? 'A new fix for the one whose check said it didn’t work' : 'A job to fix the cause so it doesn’t happen again'}
            context={{
                title: `For: ${[d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · ')}`,
                note: replaces
                    ? `Replaces ${replaces.reference_number} “${replaces.title}”. This review can’t close until the revised action is done and checked.`
                    : undefined,
            }}
            callout={replaces?.check_notes ? { tone: 'warning', content: <>Check result: {replaces.check_notes}</> } : undefined}
            submitLabel="Add action"
            processingLabel="Adding…"
            processing={form.processing}
            submitDisabled={!ready}
            onSubmit={() =>
                form.post(`/health-safety/events/${d.id}/corrective-actions`, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
                    },
                })
            }
            dirty={form.isDirty}
            draftName="this action"
            testId="add-action-dialog"
        >
            <PopupField label="What needs to happen?" htmlFor="add-action-title" required error={form.errors.title}>
                <Input
                    id="add-action-title"
                    value={form.data.title}
                    placeholder="For example, dry the bathroom floor after every shower"
                    onChange={(event) => form.setData('title', event.target.value)}
                />
            </PopupField>
            <div className="grid gap-1.5">
                <span id={kindId} className="text-sm font-semibold">
                    What kind of action?
                </span>
                <TilePicker
                    value={form.data.action_type}
                    onChange={(value) => form.setData('action_type', value)}
                    options={ACTION_KINDS}
                    labelledBy={kindId}
                />
            </div>
            <PopupField
                label="Owner"
                htmlFor="add-action-owner"
                required
                hint={`Only people who manage health and safety at ${site}`}
                error={form.errors.assigned_to_user_id}
            >
                <HazardStaffPicker
                    id="add-action-owner"
                    value={form.data.assigned_to_user_id}
                    onChange={(value) => form.setData('assigned_to_user_id', value)}
                    options={d.assignable_staff.map((person) => ({ value: String(person.id), label: person.name }))}
                    placeholder="Choose the owner"
                    searchLabel="Search people"
                    listLabel="People who can own this action"
                />
            </PopupField>
            <div className="grid gap-3 sm:grid-cols-2">
                <PopupField label="Priority" error={form.errors.priority}>
                    <Segmented
                        value={form.data.priority as (typeof PRIORITIES)[number]['value']}
                        onChange={(value) => form.setData('priority', value)}
                        options={[...PRIORITIES]}
                    />
                </PopupField>
                <PopupField label="Due date" htmlFor="add-action-due" required error={form.errors.due_date}>
                    <DatePicker
                        id="add-action-due"
                        label="Due date"
                        value={form.data.due_date}
                        onChange={(value) => form.setData('due_date', value)}
                        invalid={!!form.errors.due_date}
                    />
                </PopupField>
            </div>
        </PopupForm>
    );
}

const WORKSAFE_HOW = [
    { value: 'phone', label: 'Phone' },
    { value: 'online', label: 'Online form' },
    { value: 'email', label: 'Email' },
    { value: 'in_person', label: 'In person' },
] as const;

/** "2026-10-31" + "09:15" in New Zealand time → an instant with its offset (never read as UTC). */
export function nzInstant(date: string, time: string): string {
    const local = `${date}T${time}`;
    const offset = timeOffsetsInZone(local)[0] ?? '';
    return offset ? `${local}:00${offset}` : `${local}:00`;
}

function todayNz(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date());
}

/** Record that WorkSafe was told (board M8). POST /health-safety/events/{id}/worksafe/notify. */
export function WorksafeToldDialog({
    open,
    onClose,
    onDone,
    d,
    viewerName,
}: {
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    d: EventDetail;
    viewerName?: string | null;
}) {
    const [date, setDate] = useState(todayNz());
    const [time, setTime] = useState('');
    const form = useForm<{ notified_at: string; method: string; reference: string; site_preserved: boolean | null }>({
        notified_at: '',
        method: '',
        reference: d.worksafe.reference ?? '',
        site_preserved: null,
    });
    useEffect(() => {
        if (date && time) form.setData('notified_at', nzInstant(date, time));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [date, time]);
    const ready = !!date && !!time && !!form.data.method && form.data.site_preserved !== null;
    const decided = d.worksafe.decided_by
        ? `decided notifiable by ${d.worksafe.decided_by.name}${d.worksafe.decided_at ? `, ${formatDate(d.worksafe.decided_at)}` : ''}`
        : 'decided notifiable';

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={ShieldAlert}
            title="Record that WorkSafe was told"
            description="When and how you told WorkSafe"
            context={{ title: [d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · '), note: decided }}
            submitLabel="Record that WorkSafe was told"
            processingLabel="Recording…"
            processing={form.processing}
            submitDisabled={!ready}
            onSubmit={() =>
                form.post(`/health-safety/events/${d.id}/worksafe/notify`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
                    },
                })
            }
            dirty={form.isDirty || !!time}
            draftName="this WorkSafe record"
            footerStart={viewerName ? <span className="text-caption">Recorded as {viewerName}</span> : undefined}
            testId="worksafe-told-dialog"
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <PopupField label="Date told" htmlFor="worksafe-told-date" required error={form.errors.notified_at}>
                    <DatePicker id="worksafe-told-date" label="Date told" value={date} onChange={setDate} />
                </PopupField>
                <PopupField label="Time told" htmlFor="worksafe-told-time" required>
                    <TimePicker id="worksafe-told-time" label="Time told" value={time} onChange={setTime} />
                </PopupField>
            </div>
            <PopupField label="How" required error={form.errors.method}>
                <Segmented
                    value={form.data.method as (typeof WORKSAFE_HOW)[number]['value']}
                    onChange={(value) => form.setData('method', value)}
                    options={[...WORKSAFE_HOW]}
                />
            </PopupField>
            <PopupField label="WorkSafe reference (optional)" htmlFor="worksafe-told-ref" error={form.errors.reference}>
                <Input
                    id="worksafe-told-ref"
                    value={form.data.reference}
                    placeholder="For example, the number they gave you"
                    onChange={(event) => form.setData('reference', event.target.value)}
                />
            </PopupField>
            <PopupField label="Is the site being kept as it was until WorkSafe releases it?" required error={form.errors.site_preserved}>
                <TilePicker
                    value={form.data.site_preserved === null ? '' : form.data.site_preserved ? 'yes' : 'no'}
                    onChange={(value) => form.setData('site_preserved', value === 'yes')}
                    cols={2}
                    options={[
                        { key: 'yes', label: 'Yes', description: 'Record when WorkSafe releases it, later' },
                        {
                            key: 'no',
                            label: 'No',
                            description: 'WorkSafe said it needn’t be kept, or an exception applied. Next, add the reference',
                        },
                    ]}
                />
            </PopupField>
        </PopupForm>
    );
}

/** Close the safety review (board M8): only when nothing blocks it, or an approved "close anyway" covers what's left. */
export function CloseReviewDialog({
    open,
    onClose,
    onDone,
    d,
    exceptionId,
    incidentCloser,
}: {
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    d: EventDetail;
    /** An approved, current "close anyway" that covers what's left. */
    exceptionId?: number | null;
    /** Who closes the incident afterwards ("Rāwiri Ngata, or another manager at Kauri House"). */
    incidentCloser?: string | null;
}) {
    const form = useForm<{ closure_summary: string; exception_id: string }>({
        closure_summary: '',
        exception_id: exceptionId ? String(exceptionId) : '',
    });
    const requirements = d.close_readiness.requirements;
    const hard = requirements.filter((r) => r.classification === 'hard');
    const soft = requirements.filter((r) => r.classification === 'exceptional');
    const line = (items: typeof requirements) =>
        `${items.filter((r) => r.complete).length} of ${items.length} done (${items.map((r) => r.label.replace(/\.$/, '').toLowerCase()).join(', ')})`;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={CheckCircle2}
            title="Close this safety review"
            description="Everything’s done — say what we learned"
            context={{ title: [d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · ') }}
            submitLabel="Close safety review"
            processingLabel="Closing…"
            processing={form.processing}
            submitDisabled={!form.data.closure_summary.trim()}
            onSubmit={() =>
                form.post(`/health-safety/events/${d.id}/close`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
                    },
                })
            }
            dirty={form.isDirty}
            draftName="what we learned"
            testId="close-review-dialog"
        >
            <ul className="grid gap-1.5 text-sm">
                {hard.length ? (
                    <li>
                        <b className="font-semibold">Can’t be skipped:</b> {line(hard)}
                    </li>
                ) : null}
                {soft.length ? (
                    <li>
                        <b className="font-semibold">“Close anyway” can cover:</b> {line(soft)}
                        {exceptionId ? ' — an approved “close anyway” covers what’s left' : ''}
                    </li>
                ) : null}
            </ul>
            <PopupField
                label="What did we learn?"
                htmlFor="close-review-learned"
                required
                hint="Kept with the safety review and shown in its history."
                error={form.errors.closure_summary}
            >
                <Textarea
                    id="close-review-learned"
                    rows={4}
                    value={form.data.closure_summary}
                    onChange={(event) => form.setData('closure_summary', event.target.value)}
                />
            </PopupField>
            <PopupCallout tone="warning">
                This closes the safety review, and it can’t be reopened.
                {incidentCloser ? ` The incident stays open until ${incidentCloser} closes it after the manager review.` : ''}
            </PopupCallout>
        </PopupForm>
    );
}
