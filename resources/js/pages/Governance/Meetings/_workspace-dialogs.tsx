/**
 * The meeting workspace's simple dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 * replying to the invitation, recording attendance, adding an agenda item,
 * writing, signing and correcting the minutes. Every dialog has a
 * description, shows server problems inline and only closes when the change
 * was really saved — `back()->with('error')` still fires Inertia's onSuccess.
 */
import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, TilePicker } from '@/components/wizard/primitives';
import {
    agendaItemTypeLabel,
    attendanceStatusLabel,
    governanceStatus,
} from '@/lib/governance-labels';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    FileText,
    HelpCircle,
    Info,
    ListChecks,
    Loader2,
    Lock,
    MessageSquare,
    PenLine,
    Plus,
    RotateCcw,
    ShieldCheck,
    Users,
    Vote,
    XCircle,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import {
    buildRsvpPayload,
    DEFAULT_MINUTES_HEADINGS,
    RSVP_CHOICES,
    rsvpChoiceFor,
    type RsvpFormValues,
} from './_workspace';

const STANDARD_WIDTH = { maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' };
const LONG_WIDTH = { maxWidth: 'min(92vw, 900px)', width: 'min(92vw, 900px)' };
const CONFIRM_WIDTH = { maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' };

/** The flash error a "successful" Inertia visit carried, if any. */
export function flashErrorText(page: unknown): string | null {
    if (!pageHasFlashError(page)) return null;
    const flash = (page as { props?: { flash?: { error?: unknown } } }).props?.flash;
    return String(flash?.error ?? '');
}

/** The first validation message from an Inertia error bag. */
function firstError(errors: Record<string, string | undefined> | undefined): string | null {
    const first = Object.values(errors ?? {}).find(Boolean);
    return first ? String(first) : null;
}

function ServerError({ message }: { message: string | null }) {
    if (!message) return null;
    return (
        <InfoCard icon={AlertTriangle} tone="crit">
            <span role="alert">{message}</span>
        </InfoCard>
    );
}

/* -------------------------------------------------------------------------- */
/*  Reply to the invitation                                                    */
/* -------------------------------------------------------------------------- */

export interface ExistingRsvp {
    response: string;
    decline_reason: string | null;
    dietary_requirements: boolean;
    dietary_notes: string | null;
}

export interface RsvpDialogProps {
    isOpen: boolean;
    onClose: () => void;
    /** Called once the reply was really recorded (before closing). */
    onSaved?: () => void;
    meetingId: number;
    meetingTitle: string;
    /** "7 October 2026, 5:00 pm" */
    meetingWhen?: string | null;
    existing?: ExistingRsvp | null;
}

const RSVP_ICONS = {
    accepted: CheckCircle2,
    declined: XCircle,
    tentative: HelpCircle,
} as const;

export function RsvpDialog(props: RsvpDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={STANDARD_WIDTH}>
                {props.isOpen ? <RsvpBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function RsvpBody({ onClose, onSaved, meetingId, meetingTitle, meetingWhen, existing = null }: RsvpDialogProps) {
    const [serverError, setServerError] = useState<string | null>(null);
    const form = useForm<RsvpFormValues>({
        response: rsvpChoiceFor(existing?.response ?? 'accepted'),
        decline_reason: existing?.decline_reason ?? '',
        dietary_requirements: Boolean(existing?.dietary_requirements),
        dietary_notes: existing?.dietary_notes ?? '',
    });
    const errors = form.errors as Partial<Record<string, string>>;
    const isApology = form.data.response === 'declined';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setServerError(null);
        form.transform((values) => buildRsvpPayload(values));
        form.post(`/governance/meetings/${meetingId}/rsvp`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const error = flashErrorText(page);
                if (error !== null) {
                    setServerError(error);
                    return;
                }
                onSaved?.();
                onClose();
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <MessageSquare className="h-4 w-4 text-primary" />
                    {existing ? 'Change your reply' : 'Reply to the meeting invitation'}
                </DialogTitle>
                <DialogDescription>
                    {`Let the secretary know whether you'll be at ${meetingTitle}${meetingWhen ? ` on ${meetingWhen}` : ''}.`}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4">
                <Field label="Will you be there?" required error={errors.response}>
                    <div data-test="rsvp-response-tiles">
                        <TilePicker
                            cols={3}
                            value={form.data.response}
                            onChange={(value) => form.setData('response', rsvpChoiceFor(value))}
                            options={RSVP_CHOICES.map((choice) => ({
                                key: choice.key,
                                label: choice.label,
                                description: choice.description,
                                icon: RSVP_ICONS[choice.key],
                            }))}
                        />
                    </div>
                </Field>

                {isApology ? (
                    <Field
                        label="Reason for your apology"
                        hint="optional — only the chair and secretary see it"
                        error={errors.decline_reason}
                    >
                        <Textarea
                            id={`rsvp-decline-reason-${meetingId}`}
                            rows={3}
                            maxLength={500}
                            value={form.data.decline_reason}
                            onChange={(e) => form.setData('decline_reason', e.target.value)}
                            placeholder="e.g. I'm overseas that week."
                            data-test="rsvp-decline-reason"
                        />
                    </Field>
                ) : (
                    <div className="grid gap-3">
                        <Label className="flex items-start gap-2.5 font-normal">
                            <Checkbox
                                checked={form.data.dietary_requirements}
                                onCheckedChange={(checked) => {
                                    form.setData('dietary_requirements', checked === true);
                                    if (checked !== true) form.setData('dietary_notes', '');
                                }}
                                className="mt-0.5"
                            />
                            <span>
                                <span className="font-medium">I have dietary or access needs</span>
                                <span className="text-caption block">
                                    Only the chair and secretary see what you write.
                                </span>
                            </span>
                        </Label>
                        {form.data.dietary_requirements ? (
                            <Field label="Dietary or access needs" error={errors.dietary_notes}>
                                <Textarea
                                    id={`rsvp-dietary-notes-${meetingId}`}
                                    rows={2}
                                    maxLength={255}
                                    value={form.data.dietary_notes}
                                    onChange={(e) => form.setData('dietary_notes', e.target.value)}
                                    placeholder="e.g. Vegetarian, step-free access."
                                    data-test="rsvp-dietary-notes"
                                />
                            </Field>
                        ) : null}
                    </div>
                )}

                <ServerError message={serverError} />
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing} dusk="save-rsvp" data-test="save-rsvp">
                    {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    {existing ? 'Update reply' : 'Send reply'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/* -------------------------------------------------------------------------- */
/*  Record attendance                                                          */
/* -------------------------------------------------------------------------- */

export interface AttendanceMember {
    id: number;
    user: { id: number; name: string };
}

export interface AttendanceDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetingId: number;
    members: AttendanceMember[];
    attendances: Array<{ board_member_id: number; status: string; apology_reason?: string | null }>;
    rsvps: Array<{ board_member_id: number; response: string }>;
}

const ATTENDANCE_OPTIONS = ['unrecorded', 'present', 'late', 'apology', 'no_show'] as const;

export function AttendanceDialog(props: AttendanceDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={STANDARD_WIDTH}>
                {props.isOpen ? <AttendanceBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function AttendanceBody({ onClose, meetingId, members, attendances, rsvps }: AttendanceDialogProps) {
    const [records, setRecords] = useState<Record<number, { status: string; apology_reason: string }>>(() =>
        Object.fromEntries(
            members.map((member) => {
                const existing = attendances.find((a) => a.board_member_id === member.id);
                return [
                    member.id,
                    { status: existing?.status ?? 'unrecorded', apology_reason: existing?.apology_reason ?? '' },
                ];
            }),
        ),
    );
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);

    const update = (memberId: number, patch: Partial<{ status: string; apology_reason: string }>) =>
        setRecords((current) => ({ ...current, [memberId]: { ...current[memberId], ...patch } }));

    const save = () => {
        setSubmitting(true);
        setServerError(null);
        router.post(
            `/governance/meetings/${meetingId}/attendance`,
            {
                attendance: Object.entries(records).map(([id, record]) => ({
                    board_member_id: Number(id),
                    status: record.status,
                    apology_reason: record.status === 'apology' ? record.apology_reason.trim() || null : null,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const error = flashErrorText(page);
                    if (error !== null) {
                        setServerError(error);
                        return;
                    }
                    onClose();
                },
                onError: (errors) =>
                    setServerError(firstError(errors) ?? "Attendance couldn't be saved. Please try again."),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Users className="h-4 w-4 text-primary" />
                    Record attendance
                </DialogTitle>
                <DialogDescription>
                    Choose who was at the meeting. Members who arrived late still count towards the quorum.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-2">
                {members.length === 0 ? (
                    <InfoCard icon={Info}>There are no current board members to record.</InfoCard>
                ) : null}
                {members.map((member) => {
                    const record = records[member.id] ?? { status: 'unrecorded', apology_reason: '' };
                    const reply = rsvps.find((rsvp) => rsvp.board_member_id === member.id);
                    const replyChip = reply ? governanceStatus('rsvp_response', reply.response) : null;
                    return (
                        <div
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-border p-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-foreground">{member.user.name}</p>
                                <p className="mt-0.5">
                                    {replyChip ? (
                                        <StatusBadge size="sm" variant={replyChip.variant}>
                                            {`Replied: ${replyChip.label}`}
                                        </StatusBadge>
                                    ) : (
                                        <span className="text-caption">No reply to the invitation</span>
                                    )}
                                </p>
                            </div>
                            <Select value={record.status} onValueChange={(value) => update(member.id, { status: value })}>
                                <SelectTrigger className="w-52" aria-label={`Attendance for ${member.user.name}`}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {ATTENDANCE_OPTIONS.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {attendanceStatusLabel(option)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {record.status === 'apology' ? (
                                <Input
                                    className="w-full sm:w-56"
                                    aria-label={`Apology reason for ${member.user.name}`}
                                    placeholder="Reason (optional)"
                                    value={record.apology_reason}
                                    onChange={(e) => update(member.id, { apology_reason: e.target.value })}
                                />
                            ) : null}
                        </div>
                    );
                })}
                <ServerError message={serverError} />
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="button" onClick={save} disabled={submitting} dusk="save-attendance">
                    {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    Save attendance
                </Button>
            </DialogFooter>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/*  Add an agenda item                                                         */
/* -------------------------------------------------------------------------- */

const AGENDA_TYPES = [
    { key: 'standard', icon: MessageSquare, description: 'The board talks it through.' },
    { key: 'decision', icon: Vote, description: 'The board decides, usually with a resolution.' },
    { key: 'consent', icon: ListChecks, description: 'Routine matters agreed in one go.' },
    { key: 'for_info', icon: Info, description: 'Shared with the board. No decision needed.' },
] as const;

const NO_PRESENTER = '__none';

type AgendaFormValues = {
    title: string;
    description: string;
    presenter_id: string;
    duration_minutes: string;
    item_type: string;
    is_confidential: boolean;
};

export interface AgendaItemDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetingId: number;
    presenters: AttendanceMember[];
}

export function AgendaItemDialog(props: AgendaItemDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={STANDARD_WIDTH}>
                {props.isOpen ? <AgendaItemBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function AgendaItemBody({ onClose, meetingId, presenters }: AgendaItemDialogProps) {
    const [serverError, setServerError] = useState<string | null>(null);
    const form = useForm<AgendaFormValues>({
        title: '',
        description: '',
        presenter_id: '',
        duration_minutes: '15',
        item_type: 'standard',
        is_confidential: false,
    });
    const errors = form.errors as Partial<Record<keyof AgendaFormValues, string>>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setServerError(null);
        form.post(`/governance/meetings/${meetingId}/agenda`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const error = flashErrorText(page);
                if (error !== null) {
                    setServerError(error);
                    return;
                }
                onClose();
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ClipboardList className="h-4 w-4 text-primary" />
                    Add an agenda item
                </DialogTitle>
                <DialogDescription>
                    Add a topic to this meeting's agenda. Members see it when they prepare.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field label="Title" required span error={errors.title}>
                    <Input
                        id="agenda-title"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. Approve the 2026/27 budget"
                    />
                </Field>
                <Field label="What kind of item is it?" required span error={errors.item_type}>
                    <TilePicker
                        value={form.data.item_type}
                        onChange={(value) => form.setData('item_type', value)}
                        options={AGENDA_TYPES.map((type) => ({
                            key: type.key,
                            label: agendaItemTypeLabel(type.key),
                            description: type.description,
                            icon: type.icon,
                        }))}
                    />
                </Field>
                <Field label="What it's about" hint="optional" span error={errors.description}>
                    <Textarea
                        id="agenda-description"
                        rows={3}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        placeholder="A sentence or two so members know what to expect."
                    />
                </Field>
                <Field label="Time needed (minutes)" required error={errors.duration_minutes}>
                    <Input
                        id="agenda-duration"
                        type="number"
                        min={5}
                        max={120}
                        value={form.data.duration_minutes}
                        onChange={(e) => form.setData('duration_minutes', e.target.value)}
                    />
                </Field>
                <Field label="Presenter" hint="optional" error={errors.presenter_id}>
                    <Select
                        value={form.data.presenter_id || NO_PRESENTER}
                        onValueChange={(value) => form.setData('presenter_id', value === NO_PRESENTER ? '' : value)}
                    >
                        <SelectTrigger id="agenda-presenter" aria-label="Presenter">
                            <SelectValue placeholder="No presenter" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_PRESENTER}>No presenter</SelectItem>
                            {presenters.map((member) => (
                                <SelectItem key={member.user.id} value={String(member.user.id)}>
                                    {member.user.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Label className="flex items-start gap-2.5 font-normal sm:col-span-2">
                    <Checkbox
                        checked={form.data.is_confidential}
                        onCheckedChange={(checked) => form.setData('is_confidential', checked === true)}
                        className="mt-0.5"
                    />
                    <span>
                        <span className="flex items-center gap-1.5 font-medium">
                            <Lock className="size-3.5" aria-hidden="true" />
                            Confidential
                        </span>
                        <span className="text-caption block">
                            Only the chair, the secretary and people with board-only access can see this item.
                        </span>
                    </span>
                </Label>
                <div className="sm:col-span-2">
                    <ServerError message={serverError} />
                </div>
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                    Add item
                </Button>
            </DialogFooter>
        </form>
    );
}

/* -------------------------------------------------------------------------- */
/*  Write the minutes                                                          */
/* -------------------------------------------------------------------------- */

type MinutesBlock = { heading: string; content: string };

export interface MinutesEditorDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetingId: number;
    minutes: { version_number: number; content_blocks: MinutesBlock[] | null } | null;
}

export function MinutesEditorDialog(props: MinutesEditorDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={LONG_WIDTH}>
                {props.isOpen ? <MinutesEditorBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function MinutesEditorBody({ onClose, meetingId, minutes }: MinutesEditorDialogProps) {
    const [blocks, setBlocks] = useState<MinutesBlock[]>(() =>
        minutes?.content_blocks && Array.isArray(minutes.content_blocks) && minutes.content_blocks.length > 0
            ? minutes.content_blocks.map((block) => ({ heading: block.heading ?? '', content: block.content ?? '' }))
            : DEFAULT_MINUTES_HEADINGS.map((heading) => ({ heading, content: '' })),
    );
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);

    const change = (index: number, field: keyof MinutesBlock, value: string) =>
        setBlocks((current) => current.map((block, i) => (i === index ? { ...block, [field]: value } : block)));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);
        setServerError(null);
        router[minutes ? 'put' : 'post'](
            `/governance/meetings/${meetingId}/minutes`,
            { content_blocks: blocks, expected_version: minutes?.version_number ?? null },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const error = flashErrorText(page);
                    if (error !== null) {
                        setServerError(error);
                        return;
                    }
                    onClose();
                },
                onError: (errors) =>
                    setServerError(firstError(errors) ?? "The minutes couldn't be saved. Please try again."),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <PenLine className="h-4 w-4 text-primary" />
                    {minutes ? `Edit the minutes (version ${minutes.version_number})` : 'Write the minutes'}
                </DialogTitle>
                <DialogDescription>
                    Write up what was discussed and decided under each heading. You can save a draft and come back to
                    it.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-4 grid gap-3">
                {blocks.map((block, index) => (
                    <div key={index} className="grid gap-2 rounded-lg border border-border p-3">
                        <div className="flex items-center gap-2">
                            <Input
                                dusk={index === 0 ? 'minutes-heading-0' : undefined}
                                aria-label={`Heading for section ${index + 1}`}
                                value={block.heading}
                                onChange={(e) => change(index, 'heading', e.target.value)}
                                placeholder="Section heading"
                                className="font-medium"
                            />
                            {blocks.length > 1 ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setBlocks((current) => current.filter((_, i) => i !== index))}
                                    aria-label={`Remove section ${index + 1}`}
                                >
                                    Remove
                                </Button>
                            ) : null}
                        </div>
                        <Textarea
                            dusk={index === 0 ? 'minutes-content-0' : undefined}
                            aria-label={`What was discussed or decided under ${block.heading || `section ${index + 1}`}`}
                            value={block.content}
                            onChange={(e) => change(index, 'content', e.target.value)}
                            placeholder="What was discussed and decided…"
                            rows={4}
                        />
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setBlocks((current) => [...current, { heading: '', content: '' }])}
                >
                    <Plus className="h-4 w-4" />
                    Add section
                </Button>
                <ServerError message={serverError} />
            </div>

            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={submitting} dusk="save-minutes">
                    {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    {minutes ? 'Save changes' : 'Save draft'}
                </Button>
            </DialogFooter>
        </form>
    );
}

/* -------------------------------------------------------------------------- */
/*  Sign the minutes                                                           */
/* -------------------------------------------------------------------------- */

export interface SignMinutesDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetingId: number;
    meetingTitle: string;
    minutes: { version_number: number; content_hash?: string | null };
}

export function SignMinutesDialog(props: SignMinutesDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent style={CONFIRM_WIDTH}>{props.isOpen ? <SignMinutesBody {...props} /> : null}</DialogContent>
        </Dialog>
    );
}

function SignMinutesBody({ onClose, meetingId, meetingTitle, minutes }: SignMinutesDialogProps) {
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);

    const sign = () => {
        setSubmitting(true);
        setServerError(null);
        router.post(
            `/governance/meetings/${meetingId}/sign-minutes`,
            { expected_version: minutes.version_number, expected_hash: minutes.content_hash ?? null },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const error = flashErrorText(page);
                    if (error !== null) {
                        setServerError(error);
                        return;
                    }
                    onClose();
                },
                onError: (errors) =>
                    setServerError(firstError(errors) ?? "The minutes couldn't be signed. Please try again."),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Sign the minutes?
                </DialogTitle>
                <DialogDescription>
                    {`By signing, you confirm on behalf of the board that version ${minutes.version_number} of the minutes of ${meetingTitle} is a true and complete record of the meeting.`}
                </DialogDescription>
            </DialogHeader>
            <div className="mt-4 grid gap-3">
                <InfoCard icon={FileText}>
                    This is the organisation's own sign-off. It isn't a legal digital signature.
                </InfoCard>
                <ServerError message={serverError} />
            </div>
            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="button" onClick={sign} disabled={submitting} dusk="confirm-sign-minutes">
                    {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    Sign minutes
                </Button>
            </DialogFooter>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/*  Start a correction                                                         */
/* -------------------------------------------------------------------------- */

export interface CorrectionDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetingId: number;
    versionNumber: number;
}

export function CorrectionDialog(props: CorrectionDialogProps) {
    return (
        <Dialog open={props.isOpen} onOpenChange={(open) => !open && props.onClose()}>
            <DialogContent style={STANDARD_WIDTH}>{props.isOpen ? <CorrectionBody {...props} /> : null}</DialogContent>
        </Dialog>
    );
}

function CorrectionBody({ onClose, meetingId, versionNumber }: CorrectionDialogProps) {
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [serverError, setServerError] = useState<string | null>(null);
    const tooShort = reason.trim().length < 5;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (tooShort) return;
        setSubmitting(true);
        setServerError(null);
        router.post(
            `/governance/meetings/${meetingId}/minutes/correction`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const error = flashErrorText(page);
                    if (error !== null) {
                        setServerError(error);
                        return;
                    }
                    onClose();
                },
                onError: (errors) =>
                    setServerError(firstError(errors) ?? "The correction couldn't be started. Please try again."),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <form onSubmit={submit} noValidate>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <RotateCcw className="h-4 w-4 text-primary" />
                    Start a correction?
                </DialogTitle>
                <DialogDescription>
                    {`Approved minutes can't be edited. A correction makes version ${versionNumber + 1} as a new draft; version ${versionNumber} stays in the version history.`}
                </DialogDescription>
            </DialogHeader>
            <div className="mt-4 grid gap-3">
                <Field label="What needs correcting?" required hint="at least 5 characters">
                    <Textarea
                        id={`minutes-correction-${meetingId}`}
                        dusk="correction-reason-input"
                        rows={3}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="e.g. An apology from Aroha Ngata was left out."
                    />
                </Field>
                <ServerError message={serverError} />
            </div>
            <DialogFooter className="mt-5">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={submitting || tooShort} dusk="confirm-create-correction">
                    {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    Start correction
                </Button>
            </DialogFooter>
        </form>
    );
}
