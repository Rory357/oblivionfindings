/**
 * The safety review's pop-ups (board M8, approved by Stephan 1 Nov 2026), all on the pop-up kit: round lens, title, a
 * short phrase, ✕, the record it came from, a grey footer with Cancel first and the main button named for what it
 * does. Choose / change the owner, add an action (or a revised action), record that WorkSafe was told, and close.
 * Every command is the existing server route; the server checks everything again.
 */
import { useForm } from '@inertiajs/react';
import { CheckCircle2, FileDown, ListChecks, Search, ShieldAlert, UserRoundCheck } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';

import type { EventCorrectiveAction, EventDetail } from '@/components/health-safety/event-detail-dialog';
import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { collapsePendingWorksafeRequirements } from '@/components/health-safety/review-progress';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { PopupCallout, PopupField, PopupForm } from '@/components/popup';
import { Checkbox } from '@/components/ui/checkbox';
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
    const inlineRequirementLabel = (label: string): string => {
        const trimmed = label.replace(/\.$/, '');
        if (/^(WorkSafe|H&S|Control Room|Site\b)/.test(trimmed)) return trimmed;
        return trimmed ? trimmed[0].toLowerCase() + trimmed.slice(1) : trimmed;
    };
    const line = (items: typeof requirements) =>
        `${items.filter((r) => r.complete).length} of ${items.length} done (${collapsePendingWorksafeRequirements(items)
            .map((r) => inlineRequirementLabel(r.label))
            .join(', ')})`;

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

/* ------------------------------------------------------------------ */
/*  M12: ask to close anyway; record the WorkSafe decision             */
/*  M11: decide a close-anyway request                                  */
/* ------------------------------------------------------------------ */

const CATEGORY_BY_SCOPE: Record<string, string> = {
    hs_acceptance: 'handover_record',
    hs_investigation: 'investigation_record',
    recommendation_dispositions: 'recommendation_decision',
    corrective_actions: 'corrective_action_monitoring',
};

/** The end of a New Zealand day as an instant ("Close it by" / "Request expires": until the end of that day). */
export function nzEndOfDay(date: string): string {
    const local = `${date}T23:59`;
    const offset = timeOffsetsInZone(local)[0] ?? '';
    return `${local}:59${offset}`;
}

function nzDatePlus(days: number): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Pacific/Auckland' }).format(new Date(Date.now() + days * 86400000));
}

/**
 * "Ask to close anyway" (board M12, 720): one request covers one open item, so it is offered only when exactly one
 * "close anyway" item is left and nothing that can never be skipped is open. The asker can't approve it.
 */
export function AskCloseAnywayDialog({
    open,
    onClose,
    onDone,
    d,
}: {
    open: boolean;
    onClose: () => void;
    onDone: () => void;
    d: EventDetail;
}) {
    const item = d.close_readiness.exceptional_blockers[0] ?? null;
    const [closeBy, setCloseBy] = useState(nzDatePlus(14));
    const [expires, setExpires] = useState(nzDatePlus(21));
    const form = useForm<{
        category: string;
        reason: string;
        evidence_reference: string;
        scope: string[];
        review_at: string;
        expires_at: string;
    }>({
        category: item ? (CATEGORY_BY_SCOPE[item.key] ?? '') : '',
        reason: '',
        evidence_reference: '',
        scope: item ? [item.key] : [],
        review_at: nzEndOfDay(nzDatePlus(14)),
        expires_at: nzEndOfDay(nzDatePlus(21)),
    });
    useEffect(() => {
        if (closeBy) form.setData('review_at', nzEndOfDay(closeBy));
        if (expires) form.setData('expires_at', nzEndOfDay(expires));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [closeBy, expires]);
    const latest = nzDatePlus(29);
    const datesOk = !!closeBy && !!expires && closeBy <= expires && expires <= latest;
    const ready = !!item && form.data.reason.trim().length >= 20 && form.data.evidence_reference.trim().length >= 5 && datesOk;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ShieldAlert}
            title="Ask to close anyway"
            description="For one open item that can’t be finished, or doesn’t need to be"
            context={{
                title: [d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · '),
                note: d.handover.owner ? `Owned by ${d.handover.owner.name}.` : 'No owner yet.',
            }}
            submitLabel="Send request"
            processingLabel="Sending…"
            processing={form.processing}
            submitDisabled={!ready}
            onSubmit={() =>
                form.post(`/health-safety/events/${d.id}/closure-exceptions`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
                    },
                })
            }
            dirty={form.isDirty}
            draftName="this request"
            footerStart={<span className="text-caption">Someone else who approves “close anyway” requests decides, never you.</span>}
            testId="ask-close-anyway-dialog"
        >
            <PopupField
                label="The open item this covers"
                hint="WorkSafe steps, record checks and a revised action after a failed check can never be skipped. None is open here."
                error={form.errors.scope}
            >
                <p className="rounded-lg border border-border px-3 py-2 text-sm">
                    <b className="font-semibold">{item?.label ?? 'Nothing left that “close anyway” can cover.'}</b>
                    <span className="text-caption block">It’s the only item left that “close anyway” can cover</span>
                </p>
            </PopupField>
            <PopupField label="Why" htmlFor="ask-close-why" required hint="At least 20 characters." error={form.errors.reason}>
                <Textarea id="ask-close-why" rows={3} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </PopupField>
            <PopupField label="Evidence" htmlFor="ask-close-evidence" required error={form.errors.evidence_reference}>
                <Input
                    id="ask-close-evidence"
                    value={form.data.evidence_reference}
                    placeholder="For example, the check record or a photo"
                    onChange={(event) => form.setData('evidence_reference', event.target.value)}
                />
            </PopupField>
            <div className="grid gap-3 sm:grid-cols-2">
                <PopupField label="Close it by" htmlFor="ask-close-by" required hint="On or before the expiry date" error={form.errors.review_at}>
                    <DatePicker
                        id="ask-close-by"
                        label="Close it by"
                        value={closeBy}
                        onChange={setCloseBy}
                        invalid={!!closeBy && !!expires && closeBy > expires}
                    />
                </PopupField>
                <PopupField label="Request expires" htmlFor="ask-close-expires" required hint="At most 29 days ahead" error={form.errors.expires_at}>
                    <DatePicker id="ask-close-expires" label="Request expires" value={expires} onChange={setExpires} invalid={!!expires && expires > latest} />
                </PopupField>
            </div>
        </PopupForm>
    );
}

/**
 * "Record the WorkSafe decision" (board M12, 480): the law's meaning word for word in its hint; "Yes" leads straight
 * to "Record that WorkSafe was told". Also changes a recorded decision while the review is open (with a reason).
 */
export function WorksafeDecisionDialog({
    open,
    onClose,
    onDone,
    d,
}: {
    open: boolean;
    onClose: () => void;
    onDone: (notifiable: boolean) => void;
    d: EventDetail;
}) {
    const revising = d.worksafe.decision_signed;
    const [chosen, setChosen] = useState<'' | 'yes' | 'no'>(revising ? (d.worksafe.notifiable ? 'yes' : 'no') : '');
    const form = useForm<{ notifiable: boolean; reason: string; source: string }>({
        notifiable: d.worksafe.notifiable ?? false,
        reason: '',
        source: 'manual',
    });
    const ready = chosen !== '' && form.data.reason.trim().length >= 10;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={ShieldAlert}
            title={revising ? 'Change the WorkSafe decision' : 'Record the WorkSafe decision'}
            description="Decide whether this is a notifiable event"
            context={{ title: [d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · ') }}
            submitLabel={revising ? 'Save decision' : 'Record decision'}
            processingLabel="Recording…"
            processing={form.processing}
            submitDisabled={!ready}
            onSubmit={() =>
                form.post(`/health-safety/events/${d.id}/worksafe/decision`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone(form.data.notifiable);
                    },
                })
            }
            dirty={form.isDirty || chosen !== ''}
            draftName="this decision"
            testId="worksafe-decision-dialog"
        >
            <p className="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
                <b className="font-semibold text-foreground">Notifiable event:</b> a death, or a serious injury, illness or incident that
                the Health and Safety at Work Act 2015 (sections 23–25) says we must tell WorkSafe about straight away.
            </p>
            <TilePicker
                value={chosen}
                onChange={(value) => {
                    setChosen(value as 'yes' | 'no');
                    form.setData('notifiable', value === 'yes');
                }}
                cols={2}
                options={[
                    { key: 'yes', label: 'Yes, it’s a notifiable event', description: 'Next: record that WorkSafe was told' },
                    { key: 'no', label: 'No, it isn’t notifiable', description: 'Say why below' },
                ]}
            />
            <PopupField
                label={revising ? 'Why are you changing it?' : 'Why did you decide this?'}
                htmlFor="worksafe-decision-why"
                required
                hint="At least 10 characters. For example: “Admitted to hospital for immediate treatment, so this is a notifiable injury.”"
                error={form.errors.reason}
            >
                <Textarea id="worksafe-decision-why" rows={3} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </PopupField>
        </PopupForm>
    );
}

/** "Decide: close anyway?" (board M11, 720). Approving records a decision and closes nothing. */
/** What the Decide pop-up reads from a review: the page's detail, or the Overview worklist's slim copy of it (M11). */
export type DecideReview = Pick<EventDetail, 'id' | 'title' | 'reference_number' | 'site'> & {
    handover: { owner: EventDetail['handover']['owner'] };
    close_readiness: { requirements: EventDetail['close_readiness']['requirements'] };
};

export function DecideCloseAnywayDialog({
    open,
    onClose,
    d,
    exception,
    viewerName,
}: {
    open: boolean;
    onClose: () => void;
    d: DecideReview;
    exception: EventDetail['closure_exceptions'][number];
    viewerName?: string | null;
}) {
    const [choice, setChoice] = useState<'' | 'approved' | 'rejected'>('');
    const form = useForm<{ reason: string; decision: string }>({ reason: '', decision: 'approved' });
    const asker = exception.requester?.name ?? 'Someone';
    const covered = d.close_readiness.requirements.filter((r) => exception.scope.includes(r.key));
    const hard = d.close_readiness.requirements.filter((r) => r.classification === 'hard');
    const site = d.site?.name ?? 'the site';
    const ready = choice !== '' && form.data.reason.trim().length >= 10;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={ShieldAlert}
            title="Decide: close anyway?"
            description={`${asker} asked to close this safety review with one item still open`}
            context={{
                title: [d.title ?? d.reference_number, d.reference_number].filter(Boolean).join(' · '),
                note: `Asked ${exception.requested_at ? formatDate(exception.requested_at) : ''} by ${asker}${d.handover.owner ? '' : ' · no owner'}`,
            }}
            submitLabel={choice === 'rejected' ? 'Decline' : 'Approve'}
            processingLabel="Saving…"
            processing={form.processing}
            submitDisabled={!ready}
            onSubmit={() => {
                form.transform((data) => ({ ...data, decision: choice }));
                form.post(`/health-safety/events/${d.id}/closure-exceptions/${exception.id}/decision`, {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (!(page.props as { flash?: { error?: string } }).flash?.error) onClose();
                    },
                });
            }}
            dirty={form.isDirty || choice !== ''}
            draftName="this decision"
            footerStart={viewerName ? <span className="text-caption">Recorded as {viewerName}</span> : undefined}
            testId="decide-close-anyway-dialog"
        >
            <dl className="grid gap-2 text-sm">
                <div>
                    <dt className="font-semibold">The request covers</dt>
                    <dd className="text-muted-foreground">{covered.map((r) => r.label).join(' ') || exception.scope.join(', ')}</dd>
                </div>
                <div>
                    <dt className="font-semibold">Can’t be skipped {hard.every((r) => r.complete) ? '— all done' : '— not all done'}</dt>
                    <dd className="text-muted-foreground">
                        {collapsePendingWorksafeRequirements(hard)
                            .map((r) => r.label)
                            .join(' · ')}
                    </dd>
                </div>
                <div>
                    <dt className="font-semibold">Why {asker} asked</dt>
                    <dd className="whitespace-pre-line text-muted-foreground">{exception.reason}</dd>
                </div>
                <div>
                    <dt className="font-semibold">Evidence</dt>
                    <dd className="text-muted-foreground">{exception.evidence_reference}</dd>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                        <dt className="font-semibold">Close it by</dt>
                        <dd className="text-muted-foreground">
                            {exception.review_at ? formatDate(exception.review_at) : '—'} · it can be closed until the end of this day
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold">Request expires</dt>
                        <dd className="text-muted-foreground">
                            {exception.expires_at ? formatDate(exception.expires_at) : '—'} · if nobody decides by then
                        </dd>
                    </div>
                </div>
            </dl>
            <TilePicker
                value={choice}
                onChange={(value) => setChoice(value as 'approved' | 'rejected')}
                cols={2}
                options={[
                    {
                        key: 'approved',
                        label: 'Approve',
                        description: `Then the owner, or anyone who can close safety reviews at ${site}, can close it${exception.review_at ? `, until ${formatDate(exception.review_at)}` : ''}`,
                    },
                    { key: 'rejected', label: 'Decline — keep it open', description: `${asker} sees your reason on the record` },
                ]}
            />
            <PopupField label="Your reason" htmlFor="decide-close-why" required hint="At least 10 characters." error={form.errors.reason}>
                <Textarea id="decide-close-why" rows={3} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
            </PopupField>
        </PopupForm>
    );
}

/**
 * "Download PDF" (closed) / "Print summary" (open) — board M5c, decision 25: photos and video stills are a choice at
 * download, ticked by default for people who manage health and safety. The download itself is logged by the server.
 */
export function DownloadPdfDialog({
    open,
    onClose,
    d,
}: {
    open: boolean;
    onClose: () => void;
    d: EventDetail;
}) {
    const final = d.status === 'closed';
    const [photos, setPhotos] = useState(d.can.manage);
    const href = `/health-safety/events/${d.id}/pdf${photos ? '?photos=1' : ''}`;
    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={FileDown}
            title={final ? 'Download PDF' : 'Print summary'}
            description={final ? 'The record as it stood when it closed' : 'A summary of a review still in progress — not its final record'}
            context={{ title: [d.title ?? d.reference_number, d.site?.name, d.reference_number].filter(Boolean).join(' · ') }}
            submitLabel={final ? 'Download PDF' : 'Download summary'}
            onSubmit={() => {
                window.location.assign(href);
                onClose();
            }}
            testId="download-pdf-dialog"
        >
            <label className="flex items-start gap-2.5 text-sm">
                <Checkbox checked={photos} onCheckedChange={(value) => setPhotos(value === true)} aria-label="Include photos and video stills" />
                <span>
                    <b className="font-semibold">Include photos and video stills</b>
                    <span className="text-caption block">Photos of a person are health information. Videos are listed with their length — a PDF can’t play them.</span>
                </span>
            </label>
            <PopupCallout tone="info">
                Every page says “Confidential — contains health information”, your name and the time. The download is kept in
                the review’s history.
            </PopupCallout>
        </PopupForm>
    );
}
