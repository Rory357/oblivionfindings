import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronDown,
    Pencil,
    SlidersHorizontal,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AppreciationIcon } from './appreciation-icon';
import {
    RECOGNITION_HEADINGS,
    RecognitionPreview,
} from './recognition-preview';
import {
    recognitionReceipt,
    RecognitionSubmissionError,
    type RecognitionPayload,
    type RecognitionReceipt,
} from './recognition-receipt';
import {
    RecognitionRecipients,
    type RecognitionTeam,
} from './recognition-recipients';
import {
    RecognitionImagePreview,
    RecognitionSocialFields,
} from './recognition-social-fields';

export interface RecognitionPerson {
    id: number;
    name: string;
    role?: string | null;
    site?: string | null;
    team?: string | null;
    email?: string | null;
}
export type RecognitionDefaults = {
    recipients?: string[];
    department_id?: number;
    category?: string;
    impact?: string;
    message?: string;
    openStep?: number;
};
type RecognitionForm = RecognitionPayload;
const STEPS = ['People', 'Your message', 'Review'];
const seed = (defaults?: RecognitionDefaults): RecognitionForm => ({
    to_user_ids: defaults?.recipients ?? [],
    to_department_id: defaults?.department_id ?? null,
    category: defaults?.category ?? '',
    impact: defaults?.impact ?? 'thank_you',
    message: defaults?.message ?? '',
    mention_ids: [],
    images: [],
});

/** One canonical recognition command, shared by the Hub and HR entry points. */
export function RecognitionWizard({
    open,
    onClose,
    onSuccess,
    employees,
    teams,
    kudosCategories,
    kudosImpacts,
    defaults,
    endpoint = '/hr/feed/kudos',
    submission,
}: {
    open: boolean;
    onClose: () => void;
    onSuccess?: () => void;
    employees: RecognitionPerson[];
    teams?: RecognitionTeam[];
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    defaults?: RecognitionDefaults;
    endpoint?: string;
    submission?: {
        actorId: number;
        send: (payload: RecognitionPayload) => Promise<unknown>;
        onView: (url: string) => void;
        mentionOptions?: string;
    };
}) {
    const form = useForm<RecognitionForm>(seed(defaults));
    const [step, setStep] = useState(0),
        [done, setDone] = useState(false),
        [details, setDetails] = useState(false),
        [leave, setLeave] = useState(false);
    const [mode, setMode] = useState<'people' | 'team'>(
        defaults?.department_id ? 'team' : 'people',
    );
    const initial = useRef(seed(defaults)),
        sending = useRef(false);
    const messageRef = useRef<HTMLTextAreaElement>(null),
        bodyRef = useRef<HTMLDivElement>(null),
        keepRef = useRef<HTMLButtonElement>(null);
    const { setData, clearErrors } = form;
    const [receipt, setReceipt] = useState<RecognitionReceipt | null>(null);
    const [sharing, setSharing] = useState(false);
    const [submissionError, setSubmissionError] = useState('');
    const [unconfirmed, setUnconfirmed] = useState(false);
    const opening = useRef<string | null>(null);
    const lastAttempt = useRef<RecognitionPayload | null>(null);
    const busy = form.processing || sharing;

    useEffect(() => {
        const generation = crypto.randomUUID();
        opening.current = generation;
        sending.current = false;
        setSharing(false);
        setReceipt(null);
        setSubmissionError('');
        setUnconfirmed(false);
        lastAttempt.current = null;
        if (!open) return;
        initial.current = seed(defaults);
        setData(initial.current);
        clearErrors();
        setMode(defaults?.department_id ? 'team' : 'people');
        setStep(Math.max(0, Math.min(2, defaults?.openStep ?? 0)));
        setDone(false);
        setDetails(false);
        setLeave(false);
        return () => {
            opening.current = null;
        };
        // Defaults initialise an opening; refreshed props must not erase a draft.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, submission?.actorId]);
    const dirty =
        !done && JSON.stringify(form.data) !== JSON.stringify(initial.current);
    const departure = useSettingsLeaveConfirmation(
        !!submission && open && !done && (dirty || busy || unconfirmed),
        'Leave this shout-out?',
        unconfirmed
            ? 'This shout-out may already have been shared. Leaving removes this local copy only. Check the board before sharing it again.'
            : 'Your unsent message will be discarded. Cancel to keep writing.',
        () => {
            opening.current = null;
            onClose();
        },
        busy,
    );
    useEffect(() => {
        if (submission || !open || (!dirty && !busy && !unconfirmed)) return;
        const protect = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', protect);
        return () => window.removeEventListener('beforeunload', protect);
    }, [open, dirty, busy, unconfirmed, submission]);
    useEffect(() => {
        if (leave) keepRef.current?.focus();
    }, [leave]);
    useEffect(() => {
        bodyRef.current?.scrollTo?.({ top: 0 });
        if (open && step === 1) messageRef.current?.focus();
    }, [step, open]);

    const selectedTeam = teams?.find(
        (team) => team.id === form.data.to_department_id,
    );
    const selectedPeople = employees.filter((person) =>
        form.data.to_user_ids.includes(String(person.id)),
    );
    const recipientNames =
        mode === 'team'
            ? (selectedTeam?.name ?? '')
            : selectedPeople.map((person) => person.name).join(', ');
    const recipientsValid =
        mode === 'team'
            ? !!selectedTeam
            : form.data.to_user_ids.length > 0 &&
              form.data.to_user_ids.every((id) =>
                  employees.some((person) => String(person.id) === id),
              );
    const messageValid =
        form.data.message.trim().length >= 10 &&
        form.data.message.length <= 2000;
    const categoryValid =
        !form.data.category ||
        Object.hasOwn(kudosCategories, form.data.category);
    const valid = recipientsValid && messageValid && categoryValid;
    const category = kudosCategories[form.data.category];
    const recipientError =
        form.errors.to_department_id ||
        form.errors.to_user_ids ||
        form.errors['to_user_ids.0'];
    const knownErrors = new Set([
        'to_department_id',
        'to_user_ids',
        'to_user_ids.0',
        'category',
        'message',
        'impact',
    ]);
    const otherError = Object.entries(form.errors)
        .filter(([key]) => !knownErrors.has(key))
        .map(([, value]) => value)
        .join(' ');
    const close = () => {
        if (sending.current || busy) return;
        if (dirty || unconfirmed) setLeave(true);
        else onClose();
    };
    const startAgain = () => {
        const next = seed();
        initial.current = next;
        setData(next);
        clearErrors();
        setStep(0);
        setMode('people');
        setDone(false);
        setDetails(false);
        setLeave(false);
        setReceipt(null);
        setSubmissionError('');
        setUnconfirmed(false);
        lastAttempt.current = null;
    };
    const submit = (confirmAgain = false) => {
        if (!valid || sending.current || busy || (unconfirmed && !confirmAgain))
            return;
        sending.current = true;
        if (submission) {
            const generation = opening.current;
            const payload: RecognitionPayload =
                confirmAgain && lastAttempt.current
                    ? lastAttempt.current
                    : {
                          ...form.data,
                          to_user_ids: [...form.data.to_user_ids],
                          mention_ids: [...(form.data.mention_ids ?? [])],
                          images: [...(form.data.images ?? [])],
                          client_request_id: crypto.randomUUID(),
                      };
            lastAttempt.current = payload;
            setSharing(true);
            setSubmissionError('');
            void submission
                .send(payload)
                .then((value) => {
                    if (opening.current !== generation) return;
                    const saved = recognitionReceipt(
                        value,
                        submission.actorId,
                        payload,
                    );
                    setReceipt(saved);
                    setUnconfirmed(false);
                    setDone(true);
                    onSuccess?.();
                })
                .catch((cause: unknown) => {
                    if (opening.current !== generation) return;
                    const rejected =
                        cause instanceof RecognitionSubmissionError &&
                        cause.rejected;
                    setUnconfirmed(!rejected);
                    setSubmissionError(
                        rejected && cause instanceof Error
                            ? cause.message
                            : 'We could not confirm whether your shout-out was shared. Your message is still here. Check the board before sharing it again.',
                    );
                })
                .finally(() => {
                    if (opening.current !== generation) return;
                    sending.current = false;
                    setSharing(false);
                });
            return;
        }
        form.post(endpoint, {
            preserveScroll: true,
            onSuccess: () => {
                setDone(true);
                onSuccess?.();
            },
            onError: (errors) => {
                if (
                    errors.to_department_id ||
                    errors.to_user_ids ||
                    errors['to_user_ids.0']
                )
                    setStep(0);
                else if (errors.category || errors.message || errors.impact) {
                    setStep(1);
                    if (errors.category || errors.impact) setDetails(true);
                }
            },
            onFinish: () => {
                sending.current = false;
            },
        });
    };
    const card = (
        <div>
            <RecognitionPreview
                names={recipientNames}
                team={mode === 'team'}
                message={form.data.message}
                category={category}
                impact={form.data.impact}
                sent={done}
            />
            <RecognitionImagePreview files={form.data.images ?? []} />
            {!!form.data.mention_ids?.length && (
                <p className="mt-3 text-xs text-muted-foreground">
                    {form.data.mention_ids.length}{' '}
                    {form.data.mention_ids.length === 1
                        ? 'colleague mentioned'
                        : 'colleagues mentioned'}
                </p>
            )}
        </div>
    );

    return (
        <>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!value) close();
                }}
            >
                <DialogContent
                    className="flex max-h-[90vh] flex-col gap-0 overflow-hidden rounded-2xl p-0"
                    style={{
                        width:
                            step === 0 && !done
                                ? 'min(94vw, 720px)'
                                : 'min(94vw, 980px)',
                        maxWidth: 'none',
                    }}
                    showCloseButton={false}
                    onPointerDownOutside={(event) => event.preventDefault()}
                >
                    <header className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-7 py-6">
                        <div>
                            <DialogTitle className="flex items-center gap-3 text-xl">
                                <AppreciationIcon className="size-6 shrink-0 text-primary" />
                                {done
                                    ? 'Your thank-you is on its way'
                                    : 'Give a shout-out'}
                            </DialogTitle>
                            <DialogDescription className="mt-2">
                                {done
                                    ? 'A little appreciation makes a difference.'
                                    : 'A few thoughtful words can make someone’s day.'}
                            </DialogDescription>
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Close shout-out"
                            onClick={close}
                            disabled={busy}
                        >
                            <X className="size-4" />
                        </Button>
                    </header>
                    {!done && (
                        <nav
                            aria-label="Shout-out steps"
                            className="flex shrink-0 gap-5 border-b border-border bg-muted/30 px-7 py-3"
                        >
                            {STEPS.map((label, index) => (
                                <Button
                                    key={label}
                                    variant="ghost"
                                    size="sm"
                                    className={cn(
                                        'gap-2 px-0 hover:bg-transparent',
                                        index === step
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                    aria-current={
                                        index === step ? 'step' : undefined
                                    }
                                    disabled={
                                        busy || unconfirmed || index > step
                                    }
                                    onClick={() => setStep(index)}
                                >
                                    <span
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-full border text-xs',
                                            index === step
                                                ? 'border-primary-fill bg-primary-fill text-primary-fill-foreground'
                                                : 'border-border',
                                        )}
                                    >
                                        {index < step ? (
                                            <Check className="size-3" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>
                                    {label}
                                </Button>
                            ))}
                        </nav>
                    )}
                    <div
                        ref={bodyRef}
                        className="min-h-0 overflow-y-auto overscroll-contain p-7"
                    >
                        {submissionError && (
                            <section
                                role="alert"
                                className="mb-5 rounded-xl border border-border bg-muted/40 p-4 text-sm leading-relaxed"
                            >
                                <p>{submissionError}</p>
                                {unconfirmed && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => submit(true)}
                                        >
                                            Try confirming again
                                        </Button>
                                        <Button asChild variant="outline">
                                            <a
                                                href="/company-hub?view=shoutouts"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Review shout-outs in a new tab
                                            </a>
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}
                        <fieldset
                            disabled={busy || unconfirmed}
                            className="min-w-0"
                        >
                            {leave && (
                                <section
                                    role="alert"
                                    className="mb-6 rounded-xl border border-border bg-muted/40 p-4"
                                >
                                    <h2 className="font-semibold">
                                        Leave this shout-out?
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {unconfirmed
                                            ? 'This shout-out may already have been shared. Discarding only removes this local copy; it does not remove a posted shout-out.'
                                            : 'Your message has not been shared. Keep writing, or discard this draft.'}
                                    </p>
                                    <div className="mt-3 flex gap-2">
                                        <Button
                                            ref={keepRef}
                                            onClick={() => setLeave(false)}
                                        >
                                            Keep writing
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                startAgain();
                                                onClose();
                                            }}
                                        >
                                            Discard shout-out
                                        </Button>
                                    </div>
                                </section>
                            )}
                            {done ? (
                                <div className="mx-auto max-w-md space-y-5">
                                    <p
                                        role="status"
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Check className="size-5 text-primary" />
                                        Shout-out shared with colleagues.
                                    </p>
                                    {card}
                                    {receipt &&
                                        submission &&
                                        receipt.records.some(
                                            (record) => record.reader_url,
                                        ) && (
                                            <div className="grid gap-2">
                                                {receipt.records
                                                    .filter(
                                                        (record) =>
                                                            record.reader_url,
                                                    )
                                                    .map((record) => (
                                                        <Button
                                                            key={
                                                                record.kudos_id
                                                            }
                                                            onClick={() =>
                                                                submission.onView(
                                                                    record.reader_url!,
                                                                )
                                                            }
                                                        >
                                                            {receipt.records
                                                                .length === 1
                                                                ? 'View shout-out'
                                                                : `View shout-out for ${employees.find((person) => person.id === record.to_user_id)?.name ?? 'colleague'}`}
                                                            <ArrowRight className="size-4" />
                                                        </Button>
                                                    ))}
                                            </div>
                                        )}
                                </div>
                            ) : step === 0 ? (
                                <RecognitionRecipients
                                    employees={employees}
                                    teams={teams}
                                    mode={mode}
                                    people={form.data.to_user_ids}
                                    teamId={form.data.to_department_id}
                                    changeMode={(next) => {
                                        setMode(next);
                                        setData((current) => ({
                                            ...current,
                                            to_user_ids: [],
                                            to_department_id: null,
                                            mention_ids: [],
                                        }));
                                        clearErrors(
                                            'to_user_ids',
                                            'to_department_id',
                                        );
                                    }}
                                    changePeople={(ids) =>
                                        setData((current) => ({
                                            ...current,
                                            to_user_ids: ids,
                                            mention_ids: [],
                                        }))
                                    }
                                    changeTeam={(id) =>
                                        setData((current) => ({
                                            ...current,
                                            to_department_id: id,
                                            mention_ids: [],
                                        }))
                                    }
                                    error={recipientError}
                                />
                            ) : (
                                <div className="grid items-start gap-7 md:grid-cols-[minmax(0,1fr)_290px]">
                                    {step === 1 ? (
                                        <div className="min-w-0 space-y-5">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                                        Your thank-you is for
                                                    </p>
                                                    <p className="mt-1 text-sm font-semibold break-words">
                                                        {recipientNames ||
                                                            'Choose recipients'}
                                                    </p>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setStep(0)}
                                                >
                                                    Change
                                                </Button>
                                            </div>
                                            <div>
                                                <Label htmlFor="recognition-message">
                                                    What would you like to thank
                                                    them for?
                                                </Label>
                                                <Textarea
                                                    id="recognition-message"
                                                    ref={messageRef}
                                                    value={form.data.message}
                                                    onChange={(event) =>
                                                        setData(
                                                            'message',
                                                            event.target.value,
                                                        )
                                                    }
                                                    rows={7}
                                                    maxLength={2000}
                                                    className="mt-2 min-h-44 rounded-xl text-sm leading-7"
                                                    placeholder="Thank you for…"
                                                    aria-invalid={
                                                        !!form.errors.message
                                                    }
                                                    aria-describedby="recognition-message-help"
                                                />
                                                <div className="mt-1 flex items-center justify-between gap-2">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="px-0 text-primary"
                                                        onClick={() => {
                                                            if (
                                                                !form.data.message.trim()
                                                            )
                                                                setData(
                                                                    'message',
                                                                    'Thank you for ',
                                                                );
                                                            messageRef.current?.focus();
                                                        }}
                                                    >
                                                        <Pencil className="size-3" />
                                                        Help me get started
                                                    </Button>
                                                    <span className="text-xs text-muted-foreground">
                                                        {
                                                            form.data.message
                                                                .length
                                                        }{' '}
                                                        / 2,000
                                                    </span>
                                                </div>
                                                <p
                                                    id="recognition-message-help"
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    Mention the moment and the
                                                    difference it made. At least
                                                    10 characters.
                                                </p>
                                                {form.errors.message && (
                                                    <p
                                                        role="alert"
                                                        className="mt-2 text-sm text-destructive"
                                                    >
                                                        {form.errors.message}
                                                    </p>
                                                )}
                                            </div>
                                            <RecognitionSocialFields
                                                key={
                                                    submission?.actorId ?? 'hr'
                                                }
                                                files={form.data.images ?? []}
                                                mentionIds={
                                                    form.data.mention_ids ?? []
                                                }
                                                onFiles={(images) =>
                                                    setData('images', images)
                                                }
                                                onMentions={(mention_ids) =>
                                                    setData(
                                                        'mention_ids',
                                                        mention_ids,
                                                    )
                                                }
                                                insertMention={(person) =>
                                                    setData((current) => ({
                                                        ...current,
                                                        message:
                                                            `${current.message.trimEnd()} @${person.name} `.slice(
                                                                0,
                                                                2000,
                                                            ),
                                                    }))
                                                }
                                                mentionOptions={
                                                    submission?.mentionOptions
                                                }
                                                actorId={submission?.actorId}
                                            />
                                            <details
                                                open={details}
                                                onToggle={(event) =>
                                                    setDetails(
                                                        event.currentTarget
                                                            .open,
                                                    )
                                                }
                                                className="border-y border-border py-4"
                                            >
                                                <summary className="flex cursor-pointer list-none items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                                    <SlidersHorizontal className="size-4 shrink-0 text-primary" />
                                                    <span className="flex-1">
                                                        <span className="block text-sm font-semibold">
                                                            Add a personal touch
                                                        </span>
                                                        <span className="mt-1 block text-xs text-muted-foreground">
                                                            Choose a value or
                                                            change the card
                                                            heading · optional
                                                        </span>
                                                    </span>
                                                    <ChevronDown
                                                        className={cn(
                                                            'size-4 text-muted-foreground',
                                                            details &&
                                                                'rotate-180',
                                                        )}
                                                    />
                                                </summary>
                                                <div className="mt-5 space-y-5">
                                                    <div>
                                                        <div className="mb-3 flex items-center justify-between gap-2">
                                                            <span
                                                                id="recognition-value-label"
                                                                className="text-sm font-medium"
                                                            >
                                                                A value worth
                                                                celebrating
                                                            </span>
                                                            {form.data
                                                                .category && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setData(
                                                                            'category',
                                                                            '',
                                                                        )
                                                                    }
                                                                >
                                                                    Remove value
                                                                </Button>
                                                            )}
                                                        </div>
                                                        <div
                                                            role="group"
                                                            aria-labelledby="recognition-value-label"
                                                            className="flex flex-wrap gap-2"
                                                        >
                                                            {Object.entries(
                                                                kudosCategories,
                                                            ).map(
                                                                ([
                                                                    key,
                                                                    label,
                                                                ]) => (
                                                                    <Button
                                                                        key={
                                                                            key
                                                                        }
                                                                        variant={
                                                                            form
                                                                                .data
                                                                                .category ===
                                                                            key
                                                                                ? 'secondary'
                                                                                : 'outline'
                                                                        }
                                                                        size="sm"
                                                                        className="h-auto min-h-9 rounded-full whitespace-normal"
                                                                        aria-pressed={
                                                                            form
                                                                                .data
                                                                                .category ===
                                                                            key
                                                                        }
                                                                        onClick={() =>
                                                                            setData(
                                                                                'category',
                                                                                form
                                                                                    .data
                                                                                    .category ===
                                                                                    key
                                                                                    ? ''
                                                                                    : key,
                                                                            )
                                                                        }
                                                                    >
                                                                        {label}
                                                                    </Button>
                                                                ),
                                                            )}
                                                        </div>
                                                        {form.errors
                                                            .category && (
                                                            <p
                                                                role="alert"
                                                                className="mt-2 text-sm text-destructive"
                                                            >
                                                                {
                                                                    form.errors
                                                                        .category
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <Label htmlFor="recognition-heading">
                                                            Card heading
                                                        </Label>
                                                        <Select
                                                            value={
                                                                form.data.impact
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                setData(
                                                                    'impact',
                                                                    value,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                id="recognition-heading"
                                                                className="mt-2 w-full"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(
                                                                    {
                                                                        ...kudosImpacts,
                                                                        ...RECOGNITION_HEADINGS,
                                                                    },
                                                                ).map(
                                                                    ([
                                                                        key,
                                                                        label,
                                                                    ]) => (
                                                                        <SelectItem
                                                                            key={
                                                                                key
                                                                            }
                                                                            value={
                                                                                key
                                                                            }
                                                                        >
                                                                            {
                                                                                label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        {form.errors.impact && (
                                                            <p
                                                                role="alert"
                                                                className="mt-2 text-sm text-destructive"
                                                            >
                                                                {
                                                                    form.errors
                                                                        .impact
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            </details>
                                            <p className="flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                                                <Users className="mt-0.5 size-4 shrink-0" />
                                                Shared with colleagues in the
                                                Hub after you review it.
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="space-y-6">
                                            <div>
                                                <h2 className="text-lg font-semibold">
                                                    Ready to make their day?
                                                </h2>
                                                <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                                    Check the names and read
                                                    your card before sharing it
                                                    with colleagues.
                                                </p>
                                            </div>
                                            <div className="border-y border-border py-5">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="text-xs text-muted-foreground">
                                                            To
                                                        </p>
                                                        <p className="mt-1 font-semibold break-words">
                                                            {recipientNames ||
                                                                'Recipients unavailable'}
                                                        </p>
                                                        {mode === 'team' && (
                                                            <p className="mt-2 text-xs text-muted-foreground">
                                                                Whole team ·{' '}
                                                                {selectedTeam?.member_count ??
                                                                    0}{' '}
                                                                current
                                                                colleagues
                                                            </p>
                                                        )}
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setStep(0)
                                                        }
                                                    >
                                                        Edit people
                                                    </Button>
                                                </div>
                                                <div className="mt-5 flex items-center justify-between gap-3">
                                                    <span className="text-sm text-muted-foreground">
                                                        Your message and card
                                                        details
                                                    </span>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setStep(1)
                                                        }
                                                    >
                                                        Edit message
                                                    </Button>
                                                </div>
                                            </div>
                                            {!recipientsValid && (
                                                <p
                                                    role="alert"
                                                    className="text-sm text-destructive"
                                                >
                                                    A recipient is no longer
                                                    available. Please review the
                                                    people selected.
                                                </p>
                                            )}
                                            <p className="text-xs leading-5 text-muted-foreground">
                                                {mode === 'team'
                                                    ? 'One shared shout-out for the whole team.'
                                                    : selectedPeople.length > 1
                                                      ? 'Each selected person receives their own shout-out.'
                                                      : 'Your shout-out will appear in the Company Hub.'}
                                            </p>
                                        </div>
                                    )}
                                    <aside className="min-w-0">
                                        <p className="mb-4 flex items-center justify-between text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                            Your card
                                            <span className="size-1.5 rounded-full bg-primary" />
                                        </p>
                                        {card}
                                    </aside>
                                </div>
                            )}
                            {otherError && (
                                <p
                                    role="alert"
                                    className="mt-4 text-sm text-destructive"
                                >
                                    {otherError}
                                </p>
                            )}
                        </fieldset>
                    </div>
                    <footer className="flex shrink-0 items-center justify-between gap-4 border-t border-border px-7 py-4">
                        {done ? (
                            <>
                                <Button variant="outline" onClick={startAgain}>
                                    Recognise someone else
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        ) : (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={busy || unconfirmed}
                                    onClick={
                                        step === 0
                                            ? close
                                            : () => setStep(step - 1)
                                    }
                                >
                                    {step === 0 ? (
                                        'Cancel'
                                    ) : (
                                        <>
                                            <ArrowLeft className="size-4" />
                                            Back
                                        </>
                                    )}
                                </Button>
                                <div className="flex items-center gap-4">
                                    <span className="text-xs text-muted-foreground">
                                        {step === 2
                                            ? 'Shared with colleagues'
                                            : 'Review before sharing'}
                                    </span>
                                    <Button
                                        disabled={
                                            busy ||
                                            unconfirmed ||
                                            (step === 0
                                                ? !recipientsValid
                                                : step === 1
                                                  ? !messageValid ||
                                                    !categoryValid
                                                  : !valid)
                                        }
                                        onClick={
                                            step === 2
                                                ? () => submit()
                                                : () => setStep(step + 1)
                                        }
                                    >
                                        {busy
                                            ? 'Sharing…'
                                            : step === 2
                                              ? 'Share shout-out'
                                              : step === 1
                                                ? 'Review shout-out'
                                                : 'Continue'}
                                        {!busy && (
                                            <ArrowRight className="size-4" />
                                        )}
                                    </Button>
                                </div>
                            </>
                        )}
                    </footer>
                </DialogContent>
            </Dialog>
            {departure.confirmation}
        </>
    );
}
export default RecognitionWizard;
