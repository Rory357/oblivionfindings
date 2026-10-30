import { ConfirmDialog } from '@/components/confirm-dialog';
import { startOfWeek } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    AlertTriangle,
    CalendarRange,
    Check,
    CheckCircle2,
    ChevronDown,
    ExternalLink,
    ListChecks,
    Loader2,
    NotebookPen,
    PenLine,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { NoteCommandFeedback, noteRecoveryUrl } from './note-command-ui';
import {
    type Catalogue,
    type ShiftNote,
    clientName,
    fmtClock,
    NOTE_TYPES,
    noteCalendarDate,
    TYPE_META,
    typeMeta,
    ymd,
} from './shared';
import {
    type NoteValues,
    normalizedNoteValues,
    normalizeNoteText,
    useNoteCommand,
} from './use-note-command';

export type WizardInitial = {
    client_id?: number | null;
    shift_id?: number | null;
};
const STEPS = [
    {
        key: 'type',
        label: 'Note type',
        blurb: 'Choose the purpose',
        icon: ListChecks,
    },
    {
        key: 'shift',
        label: 'Person & shift',
        blurb: 'File it with the right support',
        icon: CalendarRange,
    },
    {
        key: 'details',
        label: 'Details & privacy',
        blurb: 'Write and review access',
        icon: PenLine,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: CheckCircle2,
    },
];
function NotePicker({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: number | null;
    options: { id: number; label: string }[];
    onChange: (id: number) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={label}
                    className="h-auto min-h-11 w-full justify-between text-left whitespace-normal"
                >
                    {options.find((option) => option.id === value)?.label ??
                        (value
                            ? 'Selected record unavailable'
                            : `Choose ${label.toLowerCase()}`)}
                    <ChevronDown className="ml-2 size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-[min(80vw,560px)] p-0">
                <Command>
                    <CommandInput
                        aria-label={`Search ${label.toLowerCase()}`}
                        placeholder={`Search ${label.toLowerCase()}…`}
                    />
                    <CommandList>
                        <CommandEmpty>
                            No matching records in the available choices.
                        </CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.id}
                                    value={`${option.id} ${option.label}`}
                                    onSelect={() => {
                                        onChange(option.id);
                                        setOpen(false);
                                    }}
                                    className="min-h-11 whitespace-normal"
                                >
                                    <Check
                                        className={
                                            value === option.id
                                                ? 'size-4'
                                                : 'size-4 opacity-0'
                                        }
                                    />
                                    {option.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
export function NoteWizard({
    open,
    onOpenChange,
    initial,
    catalogue,
    onCreated,
    actorId,
    canSave,
    timeZone,
    editNote = null,
    weekStart,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initial: WizardInitial | null;
    catalogue: Catalogue;
    onCreated: (week: Date) => void;
    actorId: number;
    canSave: boolean;
    timeZone: string;
    editNote?: ShiftNote | null;
    weekStart: string;
}) {
    const [step, setStep] = useState(0);
    const [clientId, setClientId] = useState<number | null>(
        editNote?.client?.id ?? initial?.client_id ?? null,
    );
    const [shiftId, setShiftId] = useState<number | null>(
        editNote?.shift?.id ?? initial?.shift_id ?? null,
    );
    const [values, setValues] = useState<NoteValues>({
        type: editNote?.type ?? 'shift_note',
        body: editNote?.body ?? '',
        is_flagged: editNote?.is_flagged ?? false,
        flagged_reason: editNote?.flagged_reason ?? null,
        is_private: editNote?.is_private ?? false,
    });
    const [initialDraft] = useState(
        JSON.stringify({ clientId, shiftId, values }),
    );
    const [confirmClose, setConfirmClose] = useState(false);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const command = useNoteCommand(
        `${actorId}:${editNote?.id ?? 'new'}:${editNote?.client?.id ?? ''}:${editNote?.shift?.id ?? ''}`,
    );
    const done = command.outcome?.status === 'confirmed';
    const locked =
        command.pending ||
        command.outcome?.status === 'unknown' ||
        done ||
        !canSave;
    const errors = useMemo(
        () => ({
            ...(command.outcome?.status === 'rejected'
                ? command.outcome.errors
                : {}),
            ...localErrors,
        }),
        [command.outcome, localErrors],
    );
    const dirty =
        JSON.stringify({ clientId, shiftId, values }) !== initialDraft;
    const shift = catalogue.shifts.find((item) => item.id === shiftId);
    const client =
        editNote?.client ??
        catalogue.clients.find((item) => item.id === clientId);
    const date = noteCalendarDate(
        editNote?.shift?.starts_at ?? editNote?.created_at ?? shift?.starts_at,
        timeZone,
    );
    const targetWeek = Number.isFinite(date.getTime())
        ? startOfWeek(date)
        : new Date(`${weekStart}T12:00:00`);
    const recoveryUrl = noteRecoveryUrl(
        ymd(targetWeek),
        clientId,
        editNote?.user?.id ?? actorId,
    );
    const clientShifts = catalogue.shifts.filter(
        (item) => item.client_id === clientId,
    );
    const labelShift = (item: Catalogue['shifts'][number]) =>
        `${item.starts_at && Number.isFinite(noteCalendarDate(item.starts_at, timeZone).getTime()) ? formatDateOnly(ymd(noteCalendarDate(item.starts_at, timeZone))) : 'Date unavailable'} · ${fmtClock(item.starts_at, timeZone)}–${fmtClock(item.ends_at, timeZone)} · ${item.staff?.name ?? 'Unassigned'} · ${item.label}`;
    const shiftLabel = editNote?.shift
        ? `${formatDateOnly(ymd(date))} · ${fmtClock(editNote.shift.starts_at, timeZone)}–${fmtClock(editNote.shift.ends_at, timeZone)}`
        : shift
          ? labelShift(shift)
          : 'No shift selected';
    const set = <K extends keyof NoteValues>(key: K, value: NoteValues[K]) => {
        if (!locked) {
            setValues((current) => ({ ...current, [key]: value }));
            setLocalErrors({});
        }
    };
    const close = () => {
        if (command.busy.current) return;
        if (!done && (dirty || command.outcome?.status === 'unknown'))
            setConfirmClose(true);
        else onOpenChange(false);
    };
    useEffect(() => {
        if (!dirty || done) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty, done]);
    useEffect(() => {
        const serverErrors =
            command.outcome?.status === 'rejected'
                ? command.outcome.errors
                : undefined;
        if (!serverErrors) return;
        setStep(serverErrors.type ? 0 : serverErrors.shift_id ? 1 : 2);
    }, [command.outcome]);
    useEffect(() => {
        if (!Object.keys(errors).length) return;
        document
            .querySelector<HTMLElement>(
                '#shift-note-writer [aria-invalid="true"]',
            )
            ?.focus();
    }, [step, errors]);
    const validate = () => {
        const next: Record<string, string> = {};
        if (!NOTE_TYPES.includes(values.type as (typeof NOTE_TYPES)[number]))
            next.type = 'Choose a note type.';
        if (
            !clientId ||
            !shiftId ||
            (!editNote && (!shift || shift.client_id !== clientId))
        )
            next.shift_id = 'Choose an available shift for this person.';
        const body = normalizeNoteText(values.body);
        if (!body) next.body = 'Write the note before saving.';
        else if (Array.from(body).length > 5000)
            next.body = 'Keep the note to 5,000 characters or fewer.';
        if (
            values.is_flagged &&
            Array.from(normalizeNoteText(values.flagged_reason ?? '')).length >
                500
        )
            next.flagged_reason = 'Keep the reason to 500 characters or fewer.';
        setLocalErrors(next);
        if (Object.keys(next).length) {
            setStep(next.type ? 0 : next.shift_id ? 1 : 2);
            return false;
        }
        return true;
    };
    const save = () => {
        if (
            command.busy.current ||
            locked ||
            !validate() ||
            !clientId ||
            !shiftId ||
            (editNote && !editNote.can_edit)
        )
            return;
        void command.submit(
            editNote ? 'put' : 'post',
            `/operations/shift-notes${editNote ? `/${editNote.id}` : ''}`,
            { ...values, ...(editNote ? {} : { shift_id: shiftId }) },
            {
                action: editNote ? 'update' : 'create',
                actorId,
                noteId: editNote?.id,
                clientId,
                shiftId,
                values: normalizedNoteValues(values),
                priorReview: editNote
                    ? {
                          at: editNote.reviewed_at,
                          by: editNote.reviewer?.id ?? null,
                      }
                    : undefined,
            },
        );
    };
    const next = () => {
        if (
            step === 0 &&
            !NOTE_TYPES.includes(values.type as (typeof NOTE_TYPES)[number])
        ) {
            setLocalErrors({ type: 'Choose a note type.' });
            return;
        }
        if (
            step === 1 &&
            (!clientId ||
                !shiftId ||
                (!editNote && (!shift || shift.client_id !== clientId)))
        ) {
            setLocalErrors({
                shift_id: 'Choose an available shift for this person.',
            });
            return;
        }
        if (step === 2 && !validate()) return;
        setStep((current) => Math.min(3, current + 1));
    };
    const title = editNote ? 'Edit shift note' : 'Add shift note';
    return (
        <>
            <WizardShell
                open={open}
                onClose={close}
                title={title}
                description="Record support for the correct person and shift, review access and confirm the save."
                railIcon={NotebookPen}
                railTitle={title}
                railSub={editNote ? `Note #${editNote.id}` : 'Document support'}
                steps={STEPS.map((item) => ({
                    ...item,
                    disabled: command.pending,
                }))}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!command.busy.current) setStep(index);
                }}
                pct={
                    (clientId ? 20 : 0) +
                    (shiftId ? 20 : 0) +
                    (values.type ? 20 : 0) +
                    (normalizeNoteText(values.body) ? 40 : 0)
                }
                frontline
                footerStart={
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={command.pending}
                    >
                        Close
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setStep((current) => current - 1)
                                }
                                disabled={command.pending}
                            >
                                Back
                            </Button>
                        )}
                        {step < 3 ? (
                            <Button onClick={next} disabled={command.pending}>
                                Continue
                            </Button>
                        ) : (
                            <Button onClick={save} disabled={locked}>
                                {command.pending && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {command.pending
                                    ? 'Saving…'
                                    : editNote
                                      ? 'Save changes'
                                      : 'Save note'}
                            </Button>
                        )}
                    </>
                }
                success={
                    done && command.outcome?.status === 'confirmed' ? (
                        <WizardSuccessPane
                            title={
                                editNote ? 'Changes saved' : 'Shift note saved'
                            }
                            blurb={`Note #${command.outcome.receipt.note_id} is saved for ${clientName(client)}. ${command.outcome.receipt.is_private ? 'Its private setting is retained.' : 'Access follows the person’s record permissions.'}`}
                            actions={
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => onOpenChange(false)}
                                    >
                                        Close
                                    </Button>
                                    <Button
                                        onClick={() => {
                                            onOpenChange(false);
                                            onCreated(targetWeek);
                                        }}
                                    >
                                        View notes for this week
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <div id="shift-note-writer">
                    {!canSave && !done && (
                        <div role="alert" className="mb-4">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                This action is no longer available under the
                                current record or permissions. Your draft is
                                kept here. Close and check the current record
                                before starting again.
                            </InfoCard>
                        </div>
                    )}
                    <NoteCommandFeedback
                        outcome={command.outcome}
                        recoveryUrl={recoveryUrl}
                        noteId={editNote?.id}
                    />
                    <fieldset disabled={locked} className="min-w-0 space-y-5">
                        <WizardStepPane>
                            {step === 0 && (
                                <>
                                    <StepHead
                                        icon={ListChecks}
                                        title="What kind of note?"
                                        blurb="Choose the purpose of this record."
                                    />
                                    <Field error={errors.type}>
                                        <div
                                            role="radiogroup"
                                            aria-label="Note type"
                                            className="grid gap-3 sm:grid-cols-2"
                                        >
                                            {NOTE_TYPES.map((type) => {
                                                const meta = TYPE_META[type];
                                                const Icon = meta.icon;
                                                return (
                                                    <Button
                                                        key={type}
                                                        variant="outline"
                                                        role="radio"
                                                        data-note-type={type}
                                                        tabIndex={
                                                            values.type ===
                                                                type ||
                                                            (!NOTE_TYPES.includes(
                                                                values.type as (typeof NOTE_TYPES)[number],
                                                            ) &&
                                                                type ===
                                                                    NOTE_TYPES[0])
                                                                ? 0
                                                                : -1
                                                        }
                                                        onKeyDown={(event) => {
                                                            const key =
                                                                event.key;
                                                            if (
                                                                ![
                                                                    'ArrowRight',
                                                                    'ArrowDown',
                                                                    'ArrowLeft',
                                                                    'ArrowUp',
                                                                    'Home',
                                                                    'End',
                                                                ].includes(key)
                                                            )
                                                                return;
                                                            event.preventDefault();
                                                            const index =
                                                                NOTE_TYPES.indexOf(
                                                                    type,
                                                                );
                                                            const nextIndex =
                                                                key === 'Home'
                                                                    ? 0
                                                                    : key ===
                                                                        'End'
                                                                      ? NOTE_TYPES.length -
                                                                        1
                                                                      : (index +
                                                                            (key ===
                                                                                'ArrowRight' ||
                                                                            key ===
                                                                                'ArrowDown'
                                                                                ? 1
                                                                                : -1) +
                                                                            NOTE_TYPES.length) %
                                                                        NOTE_TYPES.length;
                                                            const nextType =
                                                                NOTE_TYPES[
                                                                    nextIndex
                                                                ];
                                                            set(
                                                                'type',
                                                                nextType,
                                                            );
                                                            event.currentTarget.parentElement
                                                                ?.querySelector<HTMLElement>(
                                                                    `[data-note-type="${nextType}"]`,
                                                                )
                                                                ?.focus();
                                                        }}
                                                        aria-checked={
                                                            values.type === type
                                                        }
                                                        onClick={() =>
                                                            set('type', type)
                                                        }
                                                        className={`h-auto min-h-20 justify-start gap-3 text-left whitespace-normal ${values.type === type ? 'border-primary bg-accent' : ''}`}
                                                    >
                                                        <Icon className="size-5 shrink-0" />
                                                        <span>
                                                            <span className="block font-semibold">
                                                                {meta.label}
                                                            </span>
                                                            <span className="block text-xs font-normal text-muted-foreground">
                                                                {meta.desc}
                                                            </span>
                                                        </span>
                                                    </Button>
                                                );
                                            })}
                                        </div>
                                    </Field>
                                    {values.type === 'incident' && (
                                        <InfoCard
                                            icon={AlertTriangle}
                                            tone="warn"
                                        >
                                            This note does not report an
                                            incident or send an emergency alert.
                                            Use Report incident when required.
                                        </InfoCard>
                                    )}
                                    {values.type === 'handover' && (
                                        <InfoCard icon={NotebookPen}>
                                            This is a note category. Use
                                            Handovers to send a handover for
                                            acknowledgement.
                                        </InfoCard>
                                    )}
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <StepHead
                                        icon={CalendarRange}
                                        title="Person and shift"
                                        blurb={`Dates and times use ${timeZone}.`}
                                    />
                                    {editNote ? (
                                        <ReviewCard
                                            icon={CalendarRange}
                                            title="Filed against"
                                        >
                                            <ReviewRow
                                                label="Person"
                                                value={clientName(client)}
                                            />
                                            <ReviewRow
                                                label="Shift"
                                                value={shiftLabel}
                                            />
                                            <p className="mt-3 text-sm text-muted-foreground">
                                                Editing keeps this note with its
                                                original person and shift.
                                            </p>
                                        </ReviewCard>
                                    ) : (
                                        <div className="space-y-5">
                                            <Field
                                                label="Person"
                                                htmlFor="note-person"
                                                required
                                            >
                                                <NotePicker
                                                    id="note-person"
                                                    label="Person"
                                                    value={clientId}
                                                    options={catalogue.clients.map(
                                                        (item) => ({
                                                            id: item.id,
                                                            label: clientName(
                                                                item,
                                                            ),
                                                        }),
                                                    )}
                                                    onChange={(id) => {
                                                        setClientId(id);
                                                        setShiftId(null);
                                                        setLocalErrors({});
                                                    }}
                                                />
                                            </Field>
                                            <Field
                                                label="Shift"
                                                htmlFor="note-shift"
                                                required
                                                error={errors.shift_id}
                                            >
                                                <NotePicker
                                                    id="note-shift"
                                                    label="Shift"
                                                    value={shiftId}
                                                    options={clientShifts.map(
                                                        (item) => ({
                                                            id: item.id,
                                                            label: labelShift(
                                                                item,
                                                            ),
                                                        }),
                                                    )}
                                                    onChange={(id) => {
                                                        setShiftId(id);
                                                        setLocalErrors({});
                                                    }}
                                                />
                                            </Field>
                                            {clientId &&
                                            !clientShifts.length ? (
                                                <p className="text-sm text-muted-foreground">
                                                    No available shifts for this
                                                    person in the supplied
                                                    choices. Check the roster or
                                                    ask a coordinator.
                                                </p>
                                            ) : null}
                                            {catalogue.shift_results
                                                ?.truncated && (
                                                <InfoCard
                                                    icon={AlertTriangle}
                                                    tone="warn"
                                                >
                                                    Showing{' '}
                                                    {
                                                        catalogue.shift_results
                                                            .shown
                                                    }{' '}
                                                    of{' '}
                                                    {
                                                        catalogue.shift_results
                                                            .total
                                                    }{' '}
                                                    permitted shifts. A missing
                                                    choice does not mean the
                                                    shift does not exist. Ask a
                                                    coordinator to locate it.
                                                </InfoCard>
                                            )}
                                        </div>
                                    )}
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <StepHead
                                        icon={PenLine}
                                        title="Details and access"
                                        blurb="Describe the support provided and any follow-up clearly."
                                    />
                                    <div className="space-y-5">
                                        <Field
                                            label="Note"
                                            htmlFor="note-body"
                                            errorId="note-body-error"
                                            required
                                            error={errors.body}
                                        >
                                            <Textarea
                                                id="note-body"
                                                value={values.body}
                                                onChange={(event) =>
                                                    set(
                                                        'body',
                                                        event.target.value,
                                                    )
                                                }
                                                rows={8}
                                                aria-invalid={!!errors.body}
                                                aria-describedby={
                                                    errors.body
                                                        ? 'note-body-error'
                                                        : undefined
                                                }
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                {Array.from(
                                                    values.body,
                                                ).length.toLocaleString()}{' '}
                                                / 5,000 characters
                                            </span>
                                        </Field>
                                        <div className="flex items-center justify-between gap-4">
                                            <label
                                                htmlFor="note-flag"
                                                className="text-sm font-medium"
                                            >
                                                Flag for review
                                            </label>
                                            <Switch
                                                id="note-flag"
                                                checked={values.is_flagged}
                                                onCheckedChange={(checked) =>
                                                    set('is_flagged', checked)
                                                }
                                            />
                                        </div>
                                        {values.is_flagged && (
                                            <Field
                                                label="Reason for flag"
                                                htmlFor="note-reason"
                                                hint="Optional context for the reviewer."
                                                error={errors.flagged_reason}
                                            >
                                                <Input
                                                    id="note-reason"
                                                    value={
                                                        values.flagged_reason ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        set(
                                                            'flagged_reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.flagged_reason
                                                    }
                                                />
                                            </Field>
                                        )}
                                        <div className="flex items-center justify-between gap-4">
                                            <label
                                                htmlFor="note-private"
                                                className="text-sm font-medium"
                                            >
                                                Private note
                                            </label>
                                            <Switch
                                                id="note-private"
                                                checked={values.is_private}
                                                onCheckedChange={(checked) =>
                                                    set('is_private', checked)
                                                }
                                            />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Private notes have restricted
                                            visibility under the person’s record
                                            permissions. They may still be
                                            accessible to authorised managers.
                                        </p>
                                        {editNote && (
                                            <p className="text-sm text-muted-foreground">
                                                Changes record your name and the
                                                edit time.
                                            </p>
                                        )}
                                    </div>
                                </>
                            )}
                            {step === 3 && (
                                <>
                                    <StepHead
                                        icon={CheckCircle2}
                                        title="Check your note"
                                        blurb="Confirm the person, shift, content and access before saving."
                                    />
                                    <div className="space-y-4">
                                        <ReviewCard
                                            icon={CalendarRange}
                                            title="Person and shift"
                                            onEdit={() => setStep(1)}
                                        >
                                            <ReviewRow
                                                label="Person"
                                                value={clientName(client)}
                                            />
                                            <ReviewRow
                                                label="Shift"
                                                value={shiftLabel}
                                            />
                                        </ReviewCard>
                                        <ReviewCard
                                            icon={NotebookPen}
                                            title={typeMeta(values.type).label}
                                            onEdit={() => setStep(2)}
                                        >
                                            <p className="break-words whitespace-pre-wrap">
                                                {values.body ||
                                                    'No note entered'}
                                            </p>
                                            <ReviewRow
                                                label="Flagged"
                                                value={
                                                    values.is_flagged
                                                        ? 'Yes'
                                                        : 'No'
                                                }
                                            />
                                            {values.is_flagged && (
                                                <ReviewRow
                                                    label="Reason"
                                                    value={
                                                        values.flagged_reason
                                                    }
                                                />
                                            )}
                                            <ReviewRow
                                                label="Private"
                                                value={
                                                    values.is_private
                                                        ? 'Yes'
                                                        : 'No'
                                                }
                                            />
                                        </ReviewCard>
                                    </div>
                                </>
                            )}
                        </WizardStepPane>
                    </fieldset>
                </div>
            </WizardShell>
            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={() => {
                    if (!command.busy.current) onOpenChange(false);
                }}
                title={
                    command.outcome?.status === 'unknown'
                        ? 'Close an unconfirmed save?'
                        : 'Discard this draft?'
                }
                description={
                    command.outcome?.status === 'unknown' ? (
                        <>
                            The note may already have saved. Closing removes the
                            draft held in this form and does not undo a save.{' '}
                            <a
                                className="underline"
                                href={recoveryUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Check the current records first{' '}
                                <ExternalLink className="inline size-3" />
                            </a>
                            .
                        </>
                    ) : (
                        'Your changes in this form will be discarded. Keep editing to review and save them.'
                    )
                }
                confirmText={
                    command.outcome?.status === 'unknown'
                        ? 'Close form'
                        : 'Discard draft'
                }
                cancelText="Keep editing"
                processing={command.pending}
                frontline
            />
        </>
    );
}
