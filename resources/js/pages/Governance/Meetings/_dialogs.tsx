import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    WORKER_TIMEZONE,
    formatDateTimeLong,
    formatDurationMinutes,
    toDatetimeLocal,
} from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Briefcase,
    CalendarClock,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Landmark,
    Loader2,
    Lock,
    MapPin,
    ShieldCheck,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Shared meeting vocabulary                                          */
/* ------------------------------------------------------------------ */

export const MEETING_TYPE_LABELS: Record<string, string> = {
    full_board: 'Full Board',
    audit_risk: 'Audit & Risk',
    people: 'People Committee',
    finance: 'Finance Committee',
    special_general: 'Special General',
    executive_session: 'Executive Session',
};

export const meetingTypeLabel = (type: string | null | undefined) =>
    type ? (MEETING_TYPE_LABELS[type] ?? humanise(type)) : '—';

export const MEETING_STATUS_OPTIONS: { value: string; label: string }[] = [
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'agenda_draft', label: 'Agenda draft' },
    { value: 'agenda_final', label: 'Agenda final' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'minutes_draft', label: 'Minutes draft' },
    { value: 'minutes_review', label: 'Minutes review' },
    { value: 'minutes_approved', label: 'Minutes approved' },
    { value: 'minutes_signed', label: 'Minutes signed' },
    { value: 'archived', label: 'Archived' },
];

export function humanise(value: string): string {
    const text = value.replace(/[_-]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function meetingStatusLabel(status: string | null | undefined): string {
    if (!status) return '—';
    return (
        MEETING_STATUS_OPTIONS.find((option) => option.value === status)
            ?.label ?? humanise(status)
    );
}

/** Meeting lifecycle → the shared status token pairs. */
export function meetingStatusVariant(
    status: string | null | undefined,
): StatusVariant {
    switch (status) {
        case 'scheduled':
        case 'in_progress':
            return 'info';
        case 'agenda_draft':
        case 'minutes_draft':
        case 'minutes_review':
            return 'warning';
        case 'agenda_final':
        case 'minutes_approved':
        case 'minutes_signed':
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'critical';
        default:
            return 'neutral';
    }
}

/**
 * NZ wall time from a datetime-local input → the UTC instant the server
 * stores. The app runs in UTC, so a naive "2026-09-20T09:00" would otherwise
 * be saved as 9am UTC and read back as 9pm in Auckland.
 */
export function nzLocalToUtcIso(local: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(local);
    if (!match) return local;
    const [, y, mo, d, h, mi] = match.map(Number);
    const wall = Date.UTC(y, mo - 1, d, h, mi);
    const offsetAt = (instant: number) => {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', {
                timeZone: WORKER_TIMEZONE,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            })
                .formatToParts(new Date(instant))
                .map((part) => [part.type, part.value]),
        );
        const asUtc = Date.UTC(
            Number(parts.year),
            Number(parts.month) - 1,
            Number(parts.day),
            Number(parts.hour) % 24,
            Number(parts.minute),
        );
        return asUtc - instant;
    };
    // Two passes settle the offset across a daylight-saving boundary.
    let instant = wall - offsetAt(wall);
    instant = wall - offsetAt(instant);
    return new Date(instant).toISOString();
}

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface MeetingFormOptions {
    board_members: Array<{ id: number; name: string; is_active: boolean }>;
    committees: Array<{
        id: number;
        name: string;
        committee_type: string | null;
    }>;
    can_schedule_executive: boolean;
}

/** The fields the retired Edit page could change. */
export interface EditableMeeting {
    id: number;
    title: string;
    meeting_type: string;
    board_committee_id?: number | null;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    virtual_link: string | null;
    notes: string | null;
    status: string;
    quorum_required?: number | null;
    chair_id?: number | null;
    secretary_id?: number | null;
    chair?: { id: number } | null;
    secretary?: { id: number } | null;
}

type MeetingForm = {
    meeting_type: string;
    board_committee_id: string;
    title: string;
    notes: string;
    scheduled_at: string;
    duration_minutes: string;
    location: string;
    virtual_link: string;
    chair_id: string;
    secretary_id: string;
    quorum_required: string;
    status?: string;
};

type StepKey = 'type' | 'details' | 'schedule' | 'people' | 'review';

export const MEETING_STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'type',
        label: 'Meeting type',
        blurb: 'Board, committee or session',
        icon: Landmark,
    },
    {
        key: 'details',
        label: 'Purpose',
        blurb: 'Title and notes',
        icon: FileText,
    },
    {
        key: 'schedule',
        label: 'When & where',
        blurb: 'Date, duration and venue',
        icon: CalendarClock,
    },
    {
        key: 'people',
        label: 'Chair & quorum',
        blurb: 'Officers and quorum rule',
        icon: Users,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StepKey> = {
    meeting_type: 'type',
    board_committee_id: 'type',
    title: 'details',
    notes: 'details',
    scheduled_at: 'schedule',
    duration_minutes: 'schedule',
    location: 'schedule',
    virtual_link: 'schedule',
    chair_id: 'people',
    secretary_id: 'people',
    quorum_required: 'people',
    status: 'people',
};

const TYPE_META: Record<string, { icon: LucideIcon; description: string }> = {
    full_board: {
        icon: Landmark,
        description: 'Scheduled meeting of the whole board.',
    },
    audit_risk: {
        icon: ShieldCheck,
        description: 'Audit, risk and assurance committee.',
    },
    people: {
        icon: Users,
        description: 'People, culture and remuneration committee.',
    },
    finance: {
        icon: Wallet,
        description: 'Finance committee oversight and approvals.',
    },
    special_general: {
        icon: Briefcase,
        description: 'Special or general meeting outside the cycle.',
    },
    executive_session: {
        icon: Lock,
        description: 'Confidential session with restricted access.',
    },
};

const NONE = '__none';

function isValidUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

function validateStep(
    step: StepKey,
    data: MeetingForm,
    isEdit: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'type' && !data.meeting_type) {
        errors.meeting_type = 'Choose the type of meeting.';
    }
    if (step === 'details' && !data.title.trim()) {
        errors.title = 'Give the meeting a title.';
    }
    if (step === 'schedule') {
        if (!data.scheduled_at) {
            errors.scheduled_at = 'Choose the date and start time.';
        } else if (
            !isEdit &&
            new Date(nzLocalToUtcIso(data.scheduled_at)).getTime() <= Date.now()
        ) {
            errors.scheduled_at = 'New meetings must be scheduled in the future.';
        }
        const duration = Number(data.duration_minutes);
        if (
            !Number.isInteger(duration) ||
            duration < 30 ||
            duration > 480
        ) {
            errors.duration_minutes =
                'Duration must be between 30 and 480 minutes.';
        }
        if (data.virtual_link.trim() && !isValidUrl(data.virtual_link.trim())) {
            errors.virtual_link = 'Enter a full link, e.g. https://…';
        }
    }
    if (step === 'people') {
        const quorum = Number(data.quorum_required);
        if (!Number.isInteger(quorum) || quorum < 25 || quorum > 100) {
            errors.quorum_required = 'Quorum must be between 25% and 100%.';
        }
    }
    return errors;
}

function completeness(data: MeetingForm): number {
    const fields = [
        data.meeting_type,
        data.title.trim(),
        data.scheduled_at,
        data.duration_minutes,
        data.location.trim() || data.virtual_link.trim(),
        data.chair_id,
        data.secretary_id,
        data.notes.trim(),
    ];
    return Math.round(
        (fields.filter(Boolean).length / fields.length) * 100,
    );
}

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export interface MeetingWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    options: MeetingFormOptions;
    /** Edit mode — the same wizard, prefilled, PUTs to the meeting. */
    meeting?: EditableMeeting | null;
    /** Create seed from a calendar slot ("YYYY-MM-DDTHH:mm", NZ wall time). */
    initialScheduledAt?: string | null;
}

export function MeetingWizardDialog(props: MeetingWizardDialogProps) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <MeetingWizardBody {...props} /> : null;
}

function MeetingWizardBody({
    isOpen,
    onClose,
    options,
    meeting = null,
    initialScheduledAt = null,
}: MeetingWizardDialogProps) {
    const isEdit = Boolean(meeting);
    const allowExecutive =
        options.can_schedule_executive ||
        meeting?.meeting_type === 'executive_session';

    const form = useForm<MeetingForm>({
        meeting_type: meeting?.meeting_type ?? 'full_board',
        board_committee_id: meeting?.board_committee_id
            ? String(meeting.board_committee_id)
            : '',
        title: meeting?.title ?? '',
        notes: meeting?.notes ?? '',
        scheduled_at: meeting?.scheduled_at
            ? toDatetimeLocal(meeting.scheduled_at)
            : (initialScheduledAt ?? ''),
        duration_minutes: String(meeting?.duration_minutes ?? 120),
        location: meeting?.location ?? '',
        virtual_link: meeting?.virtual_link ?? '',
        chair_id: String(meeting?.chair_id ?? meeting?.chair?.id ?? ''),
        secretary_id: String(
            meeting?.secretary_id ?? meeting?.secretary?.id ?? '',
        ),
        quorum_required: String(meeting?.quorum_required ?? 50),
        ...(meeting ? { status: meeting.status ?? 'scheduled' } : {}),
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const step = MEETING_STEPS[stepIndex];
    const pct = useMemo(() => completeness(data), [data]);
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StepKey) => {
        const index = MEETING_STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const next = () => {
        const errors = validateStep(step.key, data, isEdit);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, MEETING_STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of MEETING_STEPS)
            Object.assign(all, validateStep(s.key, data, isEdit));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'type') ?? 'type');
            return;
        }
        setClientErrors({});

        form.transform((values) => ({
            ...values,
            scheduled_at: nzLocalToUtcIso(values.scheduled_at),
            duration_minutes: Number(values.duration_minutes),
            quorum_required: Number(values.quorum_required),
        }));

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const target = firstErrorStep(errors, FIELD_STEPS, 'review');
                if (target) goTo(target);
            },
        };

        if (meeting) {
            form.put(`/governance/meetings/${meeting.id}`, visit);
        } else {
            form.post('/governance/meetings', visit);
        }
    };

    const typeKeys = Object.keys(MEETING_TYPE_LABELS).filter(
        (key) => key !== 'executive_session' || allowExecutive,
    );
    const memberOptions = [
        { value: NONE, label: 'Not assigned' },
        ...options.board_members
            .filter(
                (member) =>
                    member.is_active ||
                    String(member.id) === data.chair_id ||
                    String(member.id) === data.secretary_id,
            )
            .map((member) => ({
                value: String(member.id),
                label: member.is_active
                    ? member.name
                    : `${member.name} (inactive)`,
            })),
    ];
    const memberName = (id: string) =>
        options.board_members.find((member) => String(member.id) === id)
            ?.name ?? null;
    const committeeName =
        options.committees.find(
            (committee) => String(committee.id) === data.board_committee_id,
        )?.name ?? null;
    const scheduledLabel = data.scheduled_at
        ? formatDateTimeLong(nzLocalToUtcIso(data.scheduled_at))
        : null;
    const isReview = step.key === 'review';

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Meeting updated' : 'Meeting scheduled'}
            blurb={
                isEdit
                    ? `${data.title || 'The meeting'} has been saved. Agenda, attendance and papers stay on the meeting workspace.`
                    : 'The meeting is scheduled. Build its agenda and board pack from the meeting workspace.'
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit meeting' : 'Schedule meeting'}
                description="A guided wizard to schedule a board or committee meeting."
                railIcon={CalendarDays}
                railTitle={isEdit ? 'Edit meeting' : 'New meeting'}
                railSub={
                    isEdit
                        ? (meeting?.title ?? 'Meeting')
                        : 'Board & committee meetings'
                }
                steps={MEETING_STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                setStepIndex((i) => Math.max(i - 1, 0))
                            }
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save changes' : 'Schedule meeting'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={step.key}>
                    {step.key === 'type' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Landmark}
                                    title="What kind of meeting?"
                                    blurb="The type sets who is invited and how its papers are shared."
                                />
                            </div>
                            <Field
                                label="Meeting type"
                                required
                                span
                                error={err('meeting_type')}
                            >
                                <TilePicker
                                    cols={3}
                                    value={data.meeting_type}
                                    onChange={(value) =>
                                        setData('meeting_type', value)
                                    }
                                    options={typeKeys.map((key) => ({
                                        key,
                                        label: MEETING_TYPE_LABELS[key],
                                        description:
                                            TYPE_META[key]?.description,
                                        icon: TYPE_META[key]?.icon ?? Landmark,
                                    }))}
                                />
                            </Field>
                            {data.meeting_type === 'executive_session' ? (
                                <InfoCard icon={Lock} tone="warn">
                                    Executive sessions are visible only to
                                    executive-authority members, the chair,
                                    the secretary and appointed committee
                                    members.
                                </InfoCard>
                            ) : null}
                            {options.committees.length > 0 ? (
                                <Field
                                    label="Committee"
                                    hint="optional"
                                    span
                                    error={err('board_committee_id')}
                                >
                                    <SelectInput
                                        value={data.board_committee_id || NONE}
                                        onChange={(value) =>
                                            setData(
                                                'board_committee_id',
                                                value === NONE ? '' : value,
                                            )
                                        }
                                        placeholder="None (full board)"
                                        ariaLabel="Committee"
                                        options={[
                                            {
                                                value: NONE,
                                                label: 'None (full board)',
                                            },
                                            ...options.committees.map(
                                                (committee) => ({
                                                    value: String(committee.id),
                                                    label: committee.name,
                                                }),
                                            ),
                                        ]}
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'details' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={FileText}
                                title="Purpose"
                                blurb="Members see the title and notes when they prepare for the meeting."
                            />
                            <Field label="Title" required error={err('title')}>
                                <Input
                                    id="meeting-title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g. Monthly board meeting – October 2026"
                                />
                            </Field>
                            <Field
                                label="Notes"
                                hint="optional"
                                error={err('notes')}
                            >
                                <Textarea
                                    id="meeting-notes"
                                    rows={6}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                    placeholder="What the meeting is for, pre-reading expectations, apologies process…"
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'schedule' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={CalendarClock}
                                    title="When and where"
                                    blurb="Times are New Zealand time. Add a room, a video link, or both."
                                />
                            </div>
                            <Field
                                label="Date & start time"
                                required
                                error={err('scheduled_at')}
                            >
                                <Input
                                    id="meeting-scheduled-at"
                                    type="datetime-local"
                                    value={data.scheduled_at}
                                    onChange={(e) =>
                                        setData('scheduled_at', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Duration (minutes)"
                                required
                                error={err('duration_minutes')}
                            >
                                <Input
                                    id="meeting-duration"
                                    type="number"
                                    min={30}
                                    max={480}
                                    step={15}
                                    value={data.duration_minutes}
                                    onChange={(e) =>
                                        setData(
                                            'duration_minutes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Location"
                                hint="optional"
                                span
                                error={err('location')}
                            >
                                <Input
                                    id="meeting-location"
                                    value={data.location}
                                    onChange={(e) =>
                                        setData('location', e.target.value)
                                    }
                                    placeholder="e.g. Board room, 12 Queen Street, Auckland"
                                />
                            </Field>
                            <Field
                                label="Video link"
                                hint="optional"
                                span
                                error={err('virtual_link')}
                            >
                                <Input
                                    id="meeting-virtual-link"
                                    type="url"
                                    value={data.virtual_link}
                                    onChange={(e) =>
                                        setData('virtual_link', e.target.value)
                                    }
                                    placeholder="https://teams.microsoft.com/…"
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'people' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Users}
                                    title="Chair, secretary and quorum"
                                    blurb="The chair and secretary can manage minutes; quorum is the share of voting members who must attend."
                                />
                            </div>
                            <Field label="Chair" error={err('chair_id')}>
                                <SelectInput
                                    value={data.chair_id || NONE}
                                    onChange={(value) =>
                                        setData(
                                            'chair_id',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                    placeholder="Not assigned"
                                    ariaLabel="Chair"
                                    options={memberOptions}
                                />
                            </Field>
                            <Field
                                label="Secretary"
                                error={err('secretary_id')}
                            >
                                <SelectInput
                                    value={data.secretary_id || NONE}
                                    onChange={(value) =>
                                        setData(
                                            'secretary_id',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                    placeholder="Not assigned"
                                    ariaLabel="Secretary"
                                    options={memberOptions}
                                />
                            </Field>
                            <Field
                                label="Quorum required (%)"
                                required
                                error={err('quorum_required')}
                            >
                                <Input
                                    id="meeting-quorum"
                                    type="number"
                                    min={25}
                                    max={100}
                                    step={5}
                                    value={data.quorum_required}
                                    onChange={(e) =>
                                        setData(
                                            'quorum_required',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {isEdit ? (
                                <Field label="Status" error={err('status')}>
                                    <SelectInput
                                        value={data.status ?? 'scheduled'}
                                        onChange={(value) =>
                                            setData('status', value)
                                        }
                                        placeholder="Status"
                                        ariaLabel="Status"
                                        options={MEETING_STATUS_OPTIONS}
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review the meeting"
                                blurb={
                                    isEdit
                                        ? 'Changes apply to the meeting workspace straight away.'
                                        : 'After scheduling, build the agenda and board pack from the meeting workspace.'
                                }
                            />
                            {Object.keys(form.errors).length > 0 ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    Some details need attention — the steps
                                    with problems are highlighted.
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Landmark}
                                    title="Meeting"
                                    onEdit={() => goTo('type')}
                                >
                                    <ReviewRow
                                        label="Type"
                                        value={meetingTypeLabel(
                                            data.meeting_type,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Committee"
                                        value={committeeName ?? 'Full board'}
                                    />
                                    <ReviewRow label="Title" value={data.title} />
                                </ReviewCard>
                                <ReviewCard
                                    icon={MapPin}
                                    title="When & where"
                                    onEdit={() => goTo('schedule')}
                                >
                                    <ReviewRow
                                        label="Starts"
                                        value={scheduledLabel}
                                    />
                                    <ReviewRow
                                        label="Duration"
                                        value={
                                            data.duration_minutes
                                                ? formatDurationMinutes(
                                                      Number(
                                                          data.duration_minutes,
                                                      ),
                                                  )
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Location"
                                        value={data.location || null}
                                    />
                                    <ReviewRow
                                        label="Video link"
                                        value={data.virtual_link || null}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Users}
                                    title="Chair & quorum"
                                    onEdit={() => goTo('people')}
                                >
                                    <ReviewRow
                                        label="Chair"
                                        value={memberName(data.chair_id)}
                                    />
                                    <ReviewRow
                                        label="Secretary"
                                        value={memberName(data.secretary_id)}
                                    />
                                    <ReviewRow
                                        label="Quorum"
                                        value={
                                            data.quorum_required
                                                ? `${data.quorum_required}%`
                                                : null
                                        }
                                    />
                                    {isEdit ? (
                                        <ReviewRow
                                            label="Status"
                                            value={meetingStatusLabel(
                                                data.status,
                                            )}
                                        />
                                    ) : null}
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Notes"
                                    onEdit={() => goTo('details')}
                                >
                                    <p className="text-[13px] whitespace-pre-wrap text-muted-foreground">
                                        {data.notes.trim() || 'No notes added.'}
                                    </p>
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description="Any changes to this meeting will be lost."
            />
        </>
    );
}
