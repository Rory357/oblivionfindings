import { ConfirmDialog } from '@/components/confirm-dialog';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { governanceStatus, meetingTypeLabel } from '@/lib/governance-labels';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    Briefcase,
    CalendarCheck,
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
/*  Meeting vocabulary                                                 */
/* ------------------------------------------------------------------ */

/** Meeting types the wizard offers, in order (mirrors MeetingDetailsRules::MEETING_TYPES). */
export const MEETING_TYPES = [
    'full_board',
    'audit_risk',
    'people',
    'finance',
    'special_general',
    'executive_session',
] as const;

/** A committee meeting's type is its committee's own type. */
export const COMMITTEE_MEETING_TYPES: readonly string[] = ['audit_risk', 'people', 'finance'];

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
    board_members: Array<{
        id: number;
        name: string;
        is_active: boolean;
        /** Counted towards a meeting's quorum today. */
        counts_for_quorum?: boolean;
    }>;
    committees: Array<{
        id: number;
        name: string;
        committee_type: string | null;
        /** Board members with an active seat on the committee. */
        member_ids?: number[];
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
        label: 'When and where',
        blurb: 'Date, length and venue',
        icon: CalendarClock,
    },
    {
        key: 'people',
        label: 'Chair and quorum',
        blurb: 'Who runs it, who must attend',
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
    status: 'schedule',
    chair_id: 'people',
    secretary_id: 'people',
    quorum_required: 'people',
};

const TYPE_META: Record<string, { icon: LucideIcon; description: string }> = {
    full_board: {
        icon: Landmark,
        description: 'A meeting of the whole board.',
    },
    audit_risk: {
        icon: ShieldCheck,
        description: 'The committee that looks after audit, risk and assurance.',
    },
    people: {
        icon: Users,
        description: 'The committee for staff, culture and pay.',
    },
    finance: {
        icon: Wallet,
        description: 'The committee that oversees money and spending.',
    },
    special_general: {
        icon: Briefcase,
        description: 'A special meeting outside the usual cycle.',
    },
    executive_session: {
        icon: Lock,
        description: 'A private session only some people can see.',
    },
};

const NONE = '__none';

/** Stages an edit keeps as "going ahead" (anything else is set by its own step). */
const GOING_AHEAD_STAGES = ['scheduled', 'agenda_draft', 'agenda_final'];

function isValidUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

/** How many members count towards the quorum for this meeting, if known. */
export function quorumBase(
    data: Pick<MeetingForm, 'meeting_type' | 'board_committee_id' | 'chair_id' | 'secretary_id'>,
    options: MeetingFormOptions,
): number | null {
    const members = options.board_members;
    if (members.length === 0 || members.some((member) => member.counts_for_quorum === undefined)) {
        return null;
    }
    const counting = new Set(members.filter((member) => member.counts_for_quorum).map((member) => member.id));
    if (!data.board_committee_id) return counting.size;

    const committee = options.committees.find((c) => String(c.id) === data.board_committee_id);
    if (!committee?.member_ids) return null;
    const invited = new Set<number>([
        ...committee.member_ids,
        ...[data.chair_id, data.secretary_id].filter(Boolean).map(Number),
    ]);
    return [...invited].filter((id) => counting.has(id)).length;
}

/** "At least 3 of the 6 members must be present." */
export function quorumSentence(percent: number, base: number | null): string | null {
    if (base === null || !Number.isFinite(percent) || percent <= 0) return null;
    const required = Math.ceil(base * (percent / 100));
    return `With ${base} ${base === 1 ? 'member' : 'members'} today, at least ${required} must be present for decisions to be valid.`;
}

function validateStep(
    step: StepKey,
    data: MeetingForm,
    isEdit: boolean,
    options: MeetingFormOptions,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'type') {
        if (!data.meeting_type) {
            errors.meeting_type = 'Choose the type of meeting.';
        } else if (COMMITTEE_MEETING_TYPES.includes(data.meeting_type) && !data.board_committee_id) {
            const label = meetingTypeLabel(data.meeting_type).toLowerCase();
            errors.board_committee_id = options.committees.some(
                (committee) => committee.committee_type === data.meeting_type,
            )
                ? `Choose which ${label} this meeting is for.`
                : `There's no ${label} set up yet, so this meeting can't be scheduled for it. Ask an administrator to add the committee, or choose another type of meeting.`;
        }
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
        if (!Number.isInteger(duration) || duration < 30 || duration > 480) {
            errors.duration_minutes = 'A meeting must be between 30 minutes and 8 hours (480 minutes) long.';
        }
        if (data.virtual_link.trim() && !isValidUrl(data.virtual_link.trim())) {
            errors.virtual_link = 'Enter a full link, e.g. https://…';
        }
    }
    if (step === 'people') {
        const quorum = Number(data.quorum_required);
        if (!Number.isInteger(quorum) || quorum < 25 || quorum > 100) {
            errors.quorum_required = 'The quorum must be between 25% and 100% of members.';
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
    return Math.round((fields.filter(Boolean).length / fields.length) * 100);
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
    // Saving keeps a meeting's current stage; an edit only ever cancels it.
    const goingAheadStatus =
        meeting && GOING_AHEAD_STAGES.includes(meeting.status)
            ? meeting.status
            : 'scheduled';

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
        ...(meeting
            ? {
                  status:
                      meeting.status === 'cancelled' ? 'cancelled' : goingAheadStatus,
              }
            : {}),
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);

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
        const errors = validateStep(step.key, data, isEdit, options);
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

    const isCommitteeType = COMMITTEE_MEETING_TYPES.includes(data.meeting_type);
    const committeesForType = options.committees.filter(
        (committee) => committee.committee_type === data.meeting_type,
    );

    /** The committee follows the type: its own committee, or none for the whole board. */
    const chooseType = (type: string) => {
        let committee = data.board_committee_id;
        if (COMMITTEE_MEETING_TYPES.includes(type)) {
            const matching = options.committees.filter((c) => c.committee_type === type);
            const keeps = matching.some((c) => String(c.id) === committee);
            committee = keeps ? committee : matching.length === 1 ? String(matching[0].id) : '';
        } else if (type !== 'executive_session') {
            committee = '';
        }
        setData('meeting_type', type);
        setData('board_committee_id', committee);
    };

    const save = () => {
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

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of MEETING_STEPS) {
            Object.assign(all, validateStep(s.key, data, isEdit, options));
        }
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'type') ?? 'type');
            return;
        }
        setClientErrors({});

        // Cancelling is consequential: it can't be edited afterwards.
        if (meeting && data.status === 'cancelled' && meeting.status !== 'cancelled') {
            setConfirmCancel(true);
            return;
        }
        save();
    };

    const typeKeys = MEETING_TYPES.filter(
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
                label: member.is_active ? member.name : `${member.name} (not active)`,
            })),
    ];
    const memberName = (id: string) =>
        options.board_members.find((member) => String(member.id) === id)?.name ?? null;
    const committeeName =
        options.committees.find((committee) => String(committee.id) === data.board_committee_id)
            ?.name ?? null;
    const scheduledLabel = data.scheduled_at
        ? formatDateTimeLong(nzLocalToUtcIso(data.scheduled_at))
        : null;
    const quorumPreview = quorumSentence(Number(data.quorum_required), quorumBase(data, options));
    const typeLabel = meetingTypeLabel(data.meeting_type);
    const isReview = step.key === 'review';

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? (data.status === 'cancelled' ? 'Meeting cancelled' : 'Meeting updated') : 'Meeting scheduled'}
            blurb={
                isEdit
                    ? `${data.title || 'The meeting'} has been saved. Its agenda, attendance and resolutions stay in the meeting workspace.`
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
                description={
                    isEdit
                        ? "Change this meeting's details."
                        : 'Schedule a board or committee meeting in a few steps.'
                }
                railIcon={CalendarDays}
                railTitle={isEdit ? 'Edit meeting' : 'New meeting'}
                railSub={isEdit ? (meeting?.title ?? 'Meeting') : 'Board and committee meetings'}
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
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button type="button" variant="outline" onClick={requestClose}>
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button type="button" onClick={submit} disabled={processing}>
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
                                    blurb="The type decides who is invited and who counts towards the quorum."
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
                                    onChange={chooseType}
                                    options={typeKeys.map((key) => ({
                                        key,
                                        label: meetingTypeLabel(key),
                                        description: TYPE_META[key]?.description,
                                        icon: TYPE_META[key]?.icon ?? Landmark,
                                    }))}
                                />
                            </Field>
                            {data.meeting_type === 'executive_session' ? (
                                <InfoCard icon={Lock} tone="warn">
                                    Board-only sessions are private. Only the chair, the
                                    secretary, members with board-only access and members of
                                    the committee you choose can see them.
                                </InfoCard>
                            ) : null}
                            {isCommitteeType ? (
                                committeesForType.length > 0 ? (
                                    <Field
                                        label="Which committee?"
                                        required
                                        span
                                        error={err('board_committee_id')}
                                    >
                                        <SelectInput
                                            value={data.board_committee_id || NONE}
                                            onChange={(value) =>
                                                setData('board_committee_id', value === NONE ? '' : value)
                                            }
                                            placeholder={`Choose the ${typeLabel.toLowerCase()}`}
                                            ariaLabel="Committee"
                                            options={[
                                                { value: NONE, label: `Choose the ${typeLabel.toLowerCase()}` },
                                                ...committeesForType.map((committee) => ({
                                                    value: String(committee.id),
                                                    label: committee.name,
                                                })),
                                            ]}
                                        />
                                    </Field>
                                ) : (
                                    <div className="sm:col-span-2">
                                        <InfoCard icon={AlertTriangle} tone="warn">
                                            {`There's no ${typeLabel.toLowerCase()} set up yet, so this meeting can't be scheduled for it. Ask an administrator to add the committee, or choose another type of meeting.`}
                                        </InfoCard>
                                        {err('board_committee_id') ? (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {err('board_committee_id')}
                                            </p>
                                        ) : null}
                                    </div>
                                )
                            ) : null}
                            {data.meeting_type === 'executive_session' && options.committees.length > 0 ? (
                                <Field
                                    label="Committee"
                                    hint="optional — its members can see the session"
                                    span
                                    error={err('board_committee_id')}
                                >
                                    <SelectInput
                                        value={data.board_committee_id || NONE}
                                        onChange={(value) =>
                                            setData('board_committee_id', value === NONE ? '' : value)
                                        }
                                        placeholder="No committee"
                                        ariaLabel="Committee"
                                        options={[
                                            { value: NONE, label: 'No committee' },
                                            ...options.committees.map((committee) => ({
                                                value: String(committee.id),
                                                label: committee.name,
                                            })),
                                        ]}
                                    />
                                </Field>
                            ) : null}
                            {!isCommitteeType && data.meeting_type !== 'executive_session' && err('board_committee_id') ? (
                                <p className="text-xs text-status-critical sm:col-span-2">
                                    {err('board_committee_id')}
                                </p>
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
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="e.g. Monthly board meeting – October 2026"
                                />
                            </Field>
                            <Field label="Notes" hint="optional" error={err('notes')}>
                                <Textarea
                                    id="meeting-notes"
                                    rows={6}
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="What the meeting is for, what to read beforehand, how to send apologies…"
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
                            <Field label="Date and start time" required error={err('scheduled_at')}>
                                <Input
                                    id="meeting-scheduled-at"
                                    type="datetime-local"
                                    value={data.scheduled_at}
                                    onChange={(e) => setData('scheduled_at', e.target.value)}
                                />
                            </Field>
                            <Field label="Length (minutes)" required error={err('duration_minutes')}>
                                <Input
                                    id="meeting-duration"
                                    type="number"
                                    min={30}
                                    max={480}
                                    step={15}
                                    value={data.duration_minutes}
                                    onChange={(e) => setData('duration_minutes', e.target.value)}
                                />
                            </Field>
                            <Field label="Location" hint="optional" span error={err('location')}>
                                <Input
                                    id="meeting-location"
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                    placeholder="e.g. Board room, 12 Queen Street, Auckland"
                                />
                            </Field>
                            <Field label="Video link" hint="optional" span error={err('virtual_link')}>
                                <Input
                                    id="meeting-virtual-link"
                                    type="url"
                                    value={data.virtual_link}
                                    onChange={(e) => setData('virtual_link', e.target.value)}
                                    placeholder="https://teams.microsoft.com/…"
                                />
                            </Field>
                            {isEdit ? (
                                <Field
                                    label="Is the meeting going ahead?"
                                    span
                                    error={err('status')}
                                >
                                    <TilePicker
                                        value={data.status === 'cancelled' ? 'cancelled' : 'going_ahead'}
                                        onChange={(value) =>
                                            setData('status', value === 'cancelled' ? 'cancelled' : goingAheadStatus)
                                        }
                                        options={[
                                            {
                                                key: 'going_ahead',
                                                label: governanceStatus('meeting_status', 'scheduled').label,
                                                description: 'The meeting goes ahead as planned.',
                                                icon: CalendarCheck,
                                            },
                                            {
                                                key: 'cancelled',
                                                label: governanceStatus('meeting_status', 'cancelled').label,
                                                description: "It won't go ahead. It stays on record as cancelled.",
                                                icon: Ban,
                                            },
                                        ]}
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'people' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Users}
                                    title="Chair, secretary and quorum"
                                    blurb="The chair and secretary run the meeting. The quorum is the share of members who must be present for decisions to be valid."
                                />
                            </div>
                            <Field label="Chair" error={err('chair_id')}>
                                <SelectInput
                                    value={data.chair_id || NONE}
                                    onChange={(value) => setData('chair_id', value === NONE ? '' : value)}
                                    placeholder="Not assigned"
                                    ariaLabel="Chair"
                                    options={memberOptions}
                                />
                            </Field>
                            <Field label="Secretary" error={err('secretary_id')}>
                                <SelectInput
                                    value={data.secretary_id || NONE}
                                    onChange={(value) => setData('secretary_id', value === NONE ? '' : value)}
                                    placeholder="Not assigned"
                                    ariaLabel="Secretary"
                                    options={memberOptions}
                                />
                            </Field>
                            <Field
                                label="Quorum (% of members)"
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
                                    onChange={(e) => setData('quorum_required', e.target.value)}
                                />
                            </Field>
                            {quorumPreview ? (
                                <p className="text-subtle self-end pb-2" data-test="meeting-quorum-preview">
                                    {quorumPreview}
                                </p>
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
                                    Some details need attention — the steps with problems are
                                    highlighted.
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard icon={Landmark} title="Meeting" onEdit={() => goTo('type')}>
                                    <ReviewRow label="Type" value={typeLabel} />
                                    <ReviewRow
                                        label="Committee"
                                        value={committeeName ?? (isCommitteeType ? null : 'None — the whole board')}
                                    />
                                    <ReviewRow label="Title" value={data.title} />
                                </ReviewCard>
                                <ReviewCard icon={MapPin} title="When and where" onEdit={() => goTo('schedule')}>
                                    <ReviewRow label="Starts" value={scheduledLabel} />
                                    <ReviewRow
                                        label="Length"
                                        value={
                                            data.duration_minutes
                                                ? formatDurationMinutes(Number(data.duration_minutes))
                                                : null
                                        }
                                    />
                                    <ReviewRow label="Location" value={data.location || null} />
                                    <ReviewRow label="Video link" value={data.virtual_link || null} />
                                    {isEdit ? (
                                        <ReviewRow
                                            label="Going ahead"
                                            value={data.status === 'cancelled' ? 'No — cancelled' : 'Yes'}
                                        />
                                    ) : null}
                                </ReviewCard>
                                <ReviewCard icon={Users} title="Chair and quorum" onEdit={() => goTo('people')}>
                                    <ReviewRow label="Chair" value={memberName(data.chair_id)} />
                                    <ReviewRow label="Secretary" value={memberName(data.secretary_id)} />
                                    <ReviewRow
                                        label="Quorum"
                                        value={data.quorum_required ? `${data.quorum_required}% of members` : null}
                                    />
                                </ReviewCard>
                                <ReviewCard icon={FileText} title="Notes" onEdit={() => goTo('details')}>
                                    <p className="text-subtle whitespace-pre-wrap">
                                        {data.notes.trim() || 'No notes added.'}
                                    </p>
                                </ReviewCard>
                            </div>
                            {quorumPreview ? <p className="text-subtle">{quorumPreview}</p> : null}
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

            <ConfirmDialog
                open={confirmCancel}
                onClose={() => setConfirmCancel(false)}
                onConfirm={save}
                title="Cancel this meeting?"
                description={`Members will see “${data.title || 'this meeting'}” as cancelled, and it can't be edited or reopened afterwards.`}
                confirmText="Cancel meeting"
            />
        </>
    );
}
