import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock3,
    HeartHandshake,
    History,
    Repeat2,
    UserRoundCog,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import DraftResumePrompt from '@/components/draft-resume-prompt';
import DraftSavedIndicator from '@/components/draft-saved-indicator';
import { SecondPersonConfirmationDialog } from '@/components/emar/second-person-confirmation-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingState } from '@/components/ui/loading-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { formatDateTime } from '@/lib/datetime';
import type { SharedData } from '@/types';
import {
    createMedicationMutationReplayState,
    prepareMedicationMutationReplayState,
} from '@/lib/emar-offline';
import {
    dismissRejectedOfflineSubmission,
    readServerSyncOutcome,
    submitOffline,
} from '@/lib/offline-queue';
import { FollowupStatus } from './followup-list';
import { nzFollowupInstants } from './time';
import { followupSourceUrl, type MedicationFollowup } from './types';

type Form = {
    outcome: string;
    reason: string;
    observations: string;
    again_at: string;
    again_offset: string;
    owner_id: string;
    escalation_needed: boolean;
    told: string;
    escalation_action: string;
    reason_category: string;
    capacity: string;
    next_action: string;
    offered_alternative: boolean;
    alternative_details: string;
    gp_told: boolean;
    gp_response: string;
    family_told: boolean;
    family_details: string;
};
const EMPTY: Form = {
    outcome: '',
    reason: '',
    observations: '',
    again_at: '',
    again_offset: '',
    owner_id: '',
    escalation_needed: false,
    told: '',
    escalation_action: '',
    reason_category: '',
    capacity: '',
    next_action: '',
    offered_alternative: false,
    alternative_details: '',
    gp_told: false,
    gp_response: '',
    family_told: false,
    family_details: '',
};
type Mode = 'action' | 'history' | 'reassign';
type Props = {
    id: number | null;
    mode?: Mode;
    onClose: () => void;
    onSaved?: () => void;
    onReoffer?: (row: MedicationFollowup, administrationId: number) => void;
};

export function MedicationFollowupDialog(props: Props) {
    const { auth } = usePage<SharedData>().props;
    const actorId = auth.user?.id ?? null;
    const mayRead = auth.can?.medications?.view !== false;
    const loadKey = `${actorId}:${props.id}`;
    const [row, setRow] = useState<MedicationFollowup | null>(null);
    const [loadedKey, setLoadedKey] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setRow(null);
        setLoadedKey(null);
        setError(null);
        if (!props.id || actorId === null || !mayRead) {
            setError('This follow-up is no longer available to you.');
            return () => controller.abort();
        }
        axios
            .get<MedicationFollowup>(`/medication-followups/${props.id}`, {
                signal: controller.signal,
            })
            .then((result) => {
                if (!controller.signal.aborted) {
                    if (result.data.id !== props.id)
                        throw new Error('Follow-up identity did not match.');
                    setRow(result.data);
                    setLoadedKey(loadKey);
                }
            })
            .catch((e) => {
                if (!controller.signal.aborted)
                    setError(
                        e.response?.status === 404
                            ? 'We can’t show this record. It may be outside your medication access.'
                            : 'Couldn’t load this follow-up. Try again.',
                    );
            });
        return () => controller.abort();
    }, [props.id, attempt, actorId, mayRead, loadKey]);
    if (!props.id) return null;
    if (!row || loadedKey !== loadKey || !mayRead)
        return (
            <Dialog open onOpenChange={(open) => !open && props.onClose()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Medication follow-up</DialogTitle>
                        <DialogDescription>
                            Loading the permitted record and its history.
                        </DialogDescription>
                    </DialogHeader>
                    {error ? (
                        <ErrorState
                            message={error}
                            onRetry={() => setAttempt((n) => n + 1)}
                        />
                    ) : (
                        <LoadingState message="Loading follow-up…" />
                    )}
                </DialogContent>
            </Dialog>
        );
    const nominationId = row.context?.nomination_id;
    if (
        row.type === 'confirm' &&
        (props.mode ?? 'action') === 'action' &&
        row.owner?.id === auth.user?.id &&
        typeof nominationId === 'number' &&
        Number.isSafeInteger(nominationId) &&
        nominationId > 0
    ) {
        return (
            <SecondPersonConfirmationDialog
                open
                nominationId={nominationId}
                onOpenChange={(open) => !open && props.onClose()}
                onAnswered={() => {
                    // Source answer is committed. Parent refreshes the shared
                    // list/meters/dose and reloads the canonical row. The
                    // source fetch displays its retained terminal result.
                    props.onSaved?.();
                    setAttempt((n) => n + 1);
                }}
            />
        );
    }
    return (
        <FollowupBody
            key={row.id}
            {...props}
            row={row}
            refresh={() => setAttempt((n) => n + 1)}
            onUpdated={(updated) =>
                setRow((current) =>
                    current ? { ...current, ...updated } : updated,
                )
            }
        />
    );
}

function FollowupBody({
    row,
    mode: initialMode = 'action',
    onClose,
    onSaved,
    onReoffer,
    refresh,
    onUpdated,
}: Props & {
    row: MedicationFollowup;
    refresh: () => void;
    onUpdated: (row: MedicationFollowup) => void;
}) {
    const actorId = (
        usePage().props.auth as { user?: { id: number } } | undefined
    )?.user?.id;
    const [mode, setMode] = useState<Mode>(initialMode);
    const [form, setForm] = useState<Form>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [step, setStep] = useState(0);
    const [sending, setSending] = useState(false);
    const [emergencyEnded, setEmergencyEnded] = useState(false);
    const [saved, setSaved] = useState<'sent' | 'queued' | null>(null);
    const [discard, setDiscard] = useState(false);
    const [dirty, setDirty] = useState(false);
    const queue = useOfflineQueueState();
    const mutationUrl = `/medication-followups/${row.id}/transition`;
    const pending = queue.pendingSubmissions.find(
        (item) =>
            item.action === 'medication_followup' && item.url === mutationUrl,
    );
    const rejected = queue.rejectedSubmissions.find(
        (item) =>
            item.action === 'medication_followup' && item.url === mutationUrl,
    );
    const [recoveredQueueId, setRecoveredQueueId] = useState<string | null>(
        null,
    );
    const [recovery, setRecovery] = useState<{
        data: Form;
        savedAt: number;
        changed: boolean;
    } | null>(null);
    const replay = useRef(createMedicationMutationReplayState());
    const bodyRef = useRef<HTMLDivElement>(null);
    const autosave = useFormAutosave(
        form,
        { revision: row.revision, replay: replay.current },
        {
            key: `medication-followup-draft:${actorId ?? 'unknown'}:${row.id}`,
            enabled: !!actorId && dirty && !recovery && !saved,
        },
    );
    useEffect(() => {
        if (!actorId) return;
        const draft = autosave.load();
        if (draft) {
            const restored = Object.fromEntries(
                Object.entries(EMPTY).map(([key, initial]) => [
                    key,
                    typeof draft.data?.[key as keyof Form] === typeof initial
                        ? draft.data[key as keyof Form]
                        : initial,
                ]),
            ) as Form;
            const priorReplay = draft.meta?.replay as
                | { uuid?: string; fingerprint?: string | null }
                | undefined;
            if (priorReplay?.uuid && /^[a-f0-9-]{36}$/i.test(priorReplay.uuid))
                replay.current = {
                    uuid: priorReplay.uuid,
                    fingerprint: priorReplay.fingerprint ?? null,
                };
            setRecovery({
                data: restored,
                savedAt: draft.savedAt,
                changed: draft.meta?.revision !== row.revision,
            });
        }
    }, [actorId, autosave.load, row.revision]);
    const set = (values: Partial<Form>) => {
        setForm((current) => ({ ...current, ...values }));
        setDirty(true);
        setErrors({});
    };
    const requestClose = () => {
        if (sending) return;
        if (dirty && !saved) setDiscard(true);
        else onClose();
    };
    const fullRefusal =
        row.type === 'reoffer' &&
        !['taken', 'couldnt_check'].includes(form.outcome) &&
        (row.refusal_assessment_required || form.outcome === 'refused_again');
    const steps = [
        {
            key: 'outcome',
            label: 'What happened',
            blurb: 'The outcome',
            icon: HeartHandshake,
        },
        ...(fullRefusal
            ? [
                  {
                      key: 'assessment',
                      label: 'Why and who was told',
                      blurb: 'The fuller assessment',
                      icon: ClipboardList,
                  },
              ]
            : []),
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check and save',
            icon: Check,
        },
    ];
    const againOptions = nzFollowupInstants(form.again_at);
    const againAt =
        againOptions.length === 1 ? againOptions[0].value : form.again_offset;
    const textField = (key: keyof Form, label: string, multiline = true) => (
        <div className="space-y-1.5">
            <Label htmlFor={`fu-${key}`}>{label}</Label>
            {multiline ? (
                <Textarea
                    id={`fu-${key}`}
                    value={String(form[key])}
                    onChange={(e) => set({ [key]: e.target.value })}
                    aria-invalid={!!errors[key]}
                    rows={2}
                />
            ) : (
                <Input
                    id={`fu-${key}`}
                    value={String(form[key])}
                    onChange={(e) => set({ [key]: e.target.value })}
                    aria-invalid={!!errors[key]}
                />
            )}
            <InputError message={errors[key]} />
        </div>
    );
    const focusError = () =>
        requestAnimationFrame(() =>
            bodyRef.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus(),
        );
    const showErrors = (next: Record<string, string>) => {
        const global = Object.entries(next).filter(
            ([key]) => key !== 'save' && !(key in EMPTY),
        );
        setErrors({
            ...next,
            ...(!next.save && global.length
                ? { save: global.map(([, value]) => value).join(' ') }
                : {}),
        });
        if (row.type === 'reoffer' && mode === 'action') {
            const assessmentKeys = [
                'reason_category',
                'capacity',
                'next_action',
                'alternative_details',
                'gp_response',
                'family_details',
            ];
            setStep(
                Object.keys(next).some((key) => assessmentKeys.includes(key))
                    ? 1
                    : 0,
            );
        }
        focusError();
    };
    const validate = () => {
        const next: Record<string, string> = {};
        if (mode === 'reassign') {
            if (!form.owner_id)
                next.owner_id = 'Choose someone rostered at this house.';
            if (!form.reason.trim())
                next.reason = 'Say why you’re handing it over.';
        } else if (form.outcome === 'couldnt_check') {
            if (!form.reason.trim())
                next.reason = 'Say why you couldn’t check.';
            if (!againAt) next.again_at = 'Choose a valid NZ date and time.';
        } else if (!form.outcome) next.outcome = 'Choose what happened.';
        if (
            mode === 'action' &&
            row.type === 'effect' &&
            (form.outcome === 'not_effective' || form.escalation_needed)
        ) {
            if (!form.told.trim()) next.told = 'Say who was told.';
            if (!form.escalation_action.trim())
                next.escalation_action = 'Say what was done.';
        }
        if (
            mode === 'action' &&
            row.type === 'reoffer' &&
            form.outcome !== 'taken' &&
            form.outcome !== 'couldnt_check'
        ) {
            if (!form.reason.trim())
                next.reason = 'Record why or what happened.';
            if (fullRefusal) {
                for (const key of [
                    'reason_category',
                    'capacity',
                    'next_action',
                ] as const)
                    if (!form[key].trim()) next[key] = 'Record this detail.';
                for (const [toggle, detail] of [
                    ['offered_alternative', 'alternative_details'],
                    ['gp_told', 'gp_response'],
                    ['family_told', 'family_details'],
                ] as const)
                    if (form[toggle] && !form[detail].trim())
                        next[detail] = 'Record this detail.';
            }
        }
        if (Object.keys(next).length) showErrors(next);
        else setErrors({});
        return !Object.keys(next).length;
    };
    const save = async () => {
        if (!validate() || sending) return;
        if (form.outcome === 'taken' && (!onReoffer || !row.reoffer_target)) {
            setErrors({
                save: 'Open this refusal from Meds today to record the re-offer for this exact dose.',
            });
            return;
        }
        const action =
            mode === 'reassign'
                ? 'reassign'
                : form.outcome === 'couldnt_check'
                  ? 'couldnt_check'
                  : row.type === 'effect'
                    ? row.completed_at
                        ? 'amend_effect'
                        : 'effect'
                    : row.type === 'reoffer'
                      ? 'refusal'
                      : row.lead
                        ? 'signoff'
                        : 'complete';
        const material = {
            ...form,
            action,
            revision: row.revision,
            again_at: againAt,
            owner_id: form.owner_id ? Number(form.owner_id) : undefined,
        };
        replay.current = prepareMedicationMutationReplayState(
            replay.current,
            material,
        );
        setSending(true);
        setEmergencyEnded(false);
        setErrors({});
        try {
            const result = await submitOffline({
                action: 'medication_followup',
                url: `/medication-followups/${row.id}/transition`,
                payload: {
                    ...material,
                    request_uuid: replay.current.uuid,
                    client_request_uuid: replay.current.uuid,
                },
                queuedMessage:
                    'Saved on this device — the follow-up stays open until the server accepts it.',
            });
            if (result.status === 'queued') {
                if (recoveredQueueId)
                    await dismissRejectedOfflineSubmission(recoveredQueueId);
                setSaved('queued');
                autosave.clear();
                onSaved?.();
                return;
            }
            if (result.status !== 'sent') {
                setErrors({ save: result.message });
                return;
            }
            if (readServerSyncOutcome(result.data).kind !== 'confirmed') {
                setErrors({
                    save: 'The save could not be confirmed. Your entries are retained; check the follow-up before retrying.',
                });
                return;
            }
            const response = result.data as {
                next_action?: string;
                reoffer_of_id?: number;
            };
            if (recoveredQueueId)
                await dismissRejectedOfflineSubmission(recoveredQueueId);
            onUpdated(result.data as MedicationFollowup);
            if (
                response.next_action === 'record_reoffer' &&
                response.reoffer_of_id &&
                onReoffer
            ) {
                autosave.clear();
                setDirty(false);
                onReoffer(row, response.reoffer_of_id);
                onClose();
                return;
            }
            setSaved('sent');
            autosave.clear();
            onSaved?.();
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const server = error.response?.data as
                    | {
                          code?: string;
                          errors?: Record<string, string[]>;
                          message?: string;
                      }
                    | undefined;
                if (server?.code === 'emergency_access_ended') {
                    setEmergencyEnded(true);
                    showErrors({
                        save: 'Emergency access ended. Your entries are retained. Start access again or ask someone on shift to complete this follow-up.',
                    });
                    return;
                }
                showErrors(
                    server?.errors
                        ? Object.fromEntries(
                              Object.entries(server.errors).map(
                                  ([key, values]) => [key, values[0]],
                              ),
                          )
                        : {
                              save:
                                  error.response?.status === 409
                                      ? 'This follow-up changed. Keep your entries and reload the latest record before saving.'
                                      : (server?.message ??
                                        'Couldn’t save. Your entries are still here.'),
                          },
                );
                focusError();
            } else {
                setErrors({
                    save: 'Couldn’t save. Your entries are still here.',
                });
            }
        } finally {
            setSending(false);
        }
    };
    const assessment = (
        <div className="flex flex-col gap-5">
            <p className="text-subtle">
                {row.refusal_count ?? 0} refusals within{' '}
                {row.refusal_days ?? 'the configured period'} days. The full
                assessment is needed for a second refusal or the configured
                escalation threshold.
            </p>
            <div className="space-y-1.5">
                <Label htmlFor="fu-reason_category">
                    Why do you think they refused?
                </Label>
                <Select
                    value={form.reason_category}
                    onValueChange={(v) => set({ reason_category: v })}
                >
                    <SelectTrigger
                        id="fu-reason_category"
                        aria-invalid={!!errors.reason_category}
                    >
                        <SelectValue placeholder="Choose a reason" />
                    </SelectTrigger>
                    <SelectContent>
                        {[
                            ['personal_choice', 'Their choice'],
                            ['side_effects', 'Side effects'],
                            ['difficulty_swallowing', 'Hard to swallow'],
                            ['nausea', 'Feeling sick'],
                            ['pain', 'In pain'],
                            ['cognitive', 'Confused or unsure'],
                            ['behavioural', 'Upset or distressed'],
                            ['sleeping', 'Asleep or drowsy'],
                            ['other', 'Other'],
                        ].map(([key, label]) => (
                            <SelectItem key={key} value={key}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.reason_category} />
            </div>
            <div>
                <Label id="fu-capacity-label">
                    Did they understand the choice?
                </Label>
                <TilePicker
                    frontline
                    labelledBy="fu-capacity-label"
                    value={form.capacity}
                    invalid={!!errors.capacity}
                    onChange={(v) => set({ capacity: v })}
                    options={[
                        {
                            key: 'has_capacity',
                            label: 'Understood the choice',
                            icon: CheckCircle2,
                        },
                        {
                            key: 'lacks_capacity',
                            label: 'Didn’t seem to understand',
                            icon: X,
                        },
                        {
                            key: 'fluctuating',
                            label: 'It varies',
                            icon: Repeat2,
                        },
                        {
                            key: 'not_assessed',
                            label: 'Not sure',
                            icon: Clock3,
                        },
                    ]}
                />
                <InputError message={errors.capacity} />
            </div>
            {(
                [
                    [
                        'offered_alternative',
                        'alternative_details',
                        'Something else was offered',
                    ],
                    ['gp_told', 'gp_response', 'The GP or prescriber was told'],
                    [
                        'family_told',
                        'family_details',
                        'Whānau or family were told',
                    ],
                ] as const
            ).map(([toggle, detail, label]) => (
                <div key={toggle} className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <Label htmlFor={`fu-${toggle}`}>{label}</Label>
                        <Switch
                            id={`fu-${toggle}`}
                            checked={form[toggle]}
                            onCheckedChange={(v) => set({ [toggle]: v })}
                        />
                    </div>
                    {form[toggle] &&
                        textField(
                            detail,
                            'Who, what happened, and what was agreed',
                        )}
                </div>
            ))}
            {textField('next_action', 'What happens next?')}
        </div>
    );
    const actionBody =
        mode === 'reassign' ? (
            <div className="flex flex-col gap-5">
                <Label id="fu-owner-label">
                    Choose someone rostered at this house
                </Label>
                <Command aria-labelledby="fu-owner-label">
                    <CommandInput placeholder="Find a worker" />
                    <CommandList>
                        <CommandEmpty>
                            Nobody eligible is rostered here now.
                        </CommandEmpty>
                        {row.candidates?.map((person) => (
                            <CommandItem
                                key={person.id}
                                value={person.name}
                                onSelect={() =>
                                    set({ owner_id: String(person.id) })
                                }
                            >
                                <Check
                                    className={
                                        form.owner_id === String(person.id)
                                            ? 'size-4'
                                            : 'invisible size-4'
                                    }
                                />
                                {person.name}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
                <InputError message={errors.owner_id} />
                {textField('reason', 'Why are you handing this over?')}
            </div>
        ) : row.source_owned ? (
            <div className="space-y-3">
                <p>
                    Complete this work in its source record so the required
                    evidence and checks are recorded.
                </p>
                {followupSourceUrl(row) ? (
                    <Button asChild className="frontline-tap">
                        <Link href={followupSourceUrl(row)!}>
                            Open source record
                        </Link>
                    </Button>
                ) : (
                    <p className="text-subtle">
                        Open the source request from Meds today or the person’s
                        medication record.
                    </p>
                )}
            </div>
        ) : (
            <div className="flex flex-col gap-5">
                {row.type === 'effect' || row.type === 'reoffer' ? (
                    <div>
                        <Label id="fu-outcome-label">
                            {row.type === 'effect'
                                ? 'Did it help?'
                                : 'What happened?'}
                        </Label>
                        <TilePicker
                            frontline
                            labelledBy="fu-outcome-label"
                            value={form.outcome}
                            invalid={!!errors.outcome}
                            onChange={(v) =>
                                set({
                                    outcome: v,
                                    escalation_needed:
                                        v === 'not_effective' ||
                                        form.escalation_needed,
                                })
                            }
                            options={
                                row.type === 'effect'
                                    ? [
                                          {
                                              key: 'effective',
                                              label: 'Helped',
                                              icon: CheckCircle2,
                                          },
                                          {
                                              key: 'partially_effective',
                                              label: 'Helped a little',
                                              icon: Check,
                                          },
                                          {
                                              key: 'not_effective',
                                              label: 'Didn’t help',
                                              icon: X,
                                          },
                                          {
                                              key: 'couldnt_check',
                                              label: 'Couldn’t check',
                                              icon: Clock3,
                                          },
                                      ]
                                    : [
                                          {
                                              key: 'taken',
                                              label: 'They took it',
                                              description:
                                                  'Record the exact dose next',
                                              icon: CheckCircle2,
                                              disabled: row.reoffer_target
                                                  ? null
                                                  : 'This source has no scheduled re-offer target. Record any new dose from Meds today.',
                                          },
                                          {
                                              key: 'refused_again',
                                              label: 'Refused again',
                                              icon: Repeat2,
                                          },
                                          {
                                              key: 'not_needed',
                                              label: 'Not needed now',
                                              icon: X,
                                          },
                                          {
                                              key: 'couldnt_check',
                                              label: 'Couldn’t offer it',
                                              icon: Clock3,
                                          },
                                      ]
                            }
                        />
                        <InputError message={errors.outcome} />
                    </div>
                ) : (
                    textField(
                        'outcome',
                        row.lead
                            ? 'What was reviewed and decided?'
                            : 'What happened?',
                    )
                )}
                {form.outcome === 'couldnt_check' ? (
                    <>
                        {textField('reason', 'Why couldn’t you check?')}
                        <DateTimeField
                            id="fu-again"
                            label={
                                row.type === 'reoffer'
                                    ? 'Try again at'
                                    : 'Check again at'
                            }
                            value={form.again_at}
                            onChange={(value) =>
                                set({ again_at: value, again_offset: '' })
                            }
                            error={errors.again_at}
                            hint={`No later than your shift end: ${formatDateTime(row.shift_end, 'No current shift')}. The follow-up stays open.`}
                        />
                        {againOptions.length === 2 && (
                            <div className="space-y-1.5">
                                <Label htmlFor="fu-offset">
                                    This time occurs twice — choose which one
                                </Label>
                                <Select
                                    value={form.again_offset}
                                    onValueChange={(value) =>
                                        set({ again_offset: value })
                                    }
                                >
                                    <SelectTrigger id="fu-offset">
                                        <SelectValue placeholder="Choose the occurrence" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {againOptions.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </>
                ) : row.type === 'effect' ? (
                    <>
                        {textField('observations', 'What did you notice?')}
                        <div className="flex items-center justify-between gap-3">
                            <Label htmlFor="fu-escalation">
                                Someone else needs to know
                            </Label>
                            <Switch
                                id="fu-escalation"
                                checked={
                                    form.escalation_needed ||
                                    form.outcome === 'not_effective'
                                }
                                disabled={form.outcome === 'not_effective'}
                                onCheckedChange={(v) =>
                                    set({ escalation_needed: v })
                                }
                            />
                        </div>
                        {(form.escalation_needed ||
                            form.outcome === 'not_effective') && (
                            <>
                                {textField('told', 'Who was told?', false)}
                                {textField(
                                    'escalation_action',
                                    'What was done?',
                                )}
                            </>
                        )}
                    </>
                ) : row.type === 'reoffer' && form.outcome !== 'taken' ? (
                    textField(
                        'reason',
                        form.outcome === 'not_needed'
                            ? 'Why isn’t it needed now?'
                            : 'What happened or what did they say?',
                    )
                ) : null}
            </div>
        );
    const history = (
        <div className="flex flex-col gap-5">
            <p>Original owner: {row.original_owner?.name ?? 'Not recorded'}</p>
            <ol className="space-y-3">
                {row.history?.map((event) => (
                    <li
                        key={event.id}
                        className="border-l-2 border-border pl-3"
                    >
                        <p className="font-medium">
                            {event.action.replaceAll('_', ' ')}
                        </p>
                        <p className="text-caption">
                            {formatDateTime(event.at)} · {event.by ?? 'System'}
                        </p>
                        {Object.entries(event.data)
                            .filter(
                                ([key]) =>
                                    ![
                                        'request_uuid',
                                        'revision',
                                        'action',
                                        'owner_id',
                                    ].includes(key),
                            )
                            .map(([key, value]) =>
                                typeof value === 'string' && value ? (
                                    <p
                                        key={key}
                                        className="text-subtle break-words"
                                    >
                                        {key.replaceAll('_', ' ')}: {value}
                                    </p>
                                ) : null,
                            )}
                    </li>
                ))}
            </ol>
        </div>
    );
    const recoveryPrompt = recovery && (
        <DraftResumePrompt
            savedAt={recovery.savedAt}
            description={
                recovery.changed
                    ? 'This follow-up changed since this draft. Resume your entries, then review them against the current record before saving.'
                    : 'We found unfinished entries for this follow-up on this device.'
            }
            onResume={() => {
                setForm(recovery.data);
                setDirty(true);
                setRecovery(null);
            }}
            onDiscard={() => {
                autosave.clear();
                setRecovery(null);
            }}
        />
    );
    const queueNotice = pending ? (
        <p role="status" className="text-subtle">
            Saved on this device. This follow-up stays open until the server
            accepts it. Check the connection banner for any retry that needs
            attention.
        </p>
    ) : rejected && !recoveredQueueId ? (
        <DraftResumePrompt
            savedAt={Date.parse(rejected.createdAt)}
            title="Review a save the server could not accept"
            description={
                rejected.lastError ??
                'These entries are retained on this device. Review them against the current record before saving again.'
            }
            onResume={() => {
                const retained = Object.fromEntries(
                    Object.entries(EMPTY).map(([key, initial]) => [
                        key,
                        typeof rejected.payload[key] === typeof initial
                            ? rejected.payload[key]
                            : initial,
                    ]),
                ) as Form;
                if (typeof rejected.payload.owner_id === 'number')
                    retained.owner_id = String(rejected.payload.owner_id);
                if (typeof rejected.payload.again_at === 'string') {
                    retained.again_at = rejected.payload.again_at.slice(0, 16);
                    retained.again_offset = rejected.payload.again_at;
                }
                setForm(retained);
                setDirty(true);
                setMode(
                    rejected.payload.action === 'reassign'
                        ? 'reassign'
                        : 'action',
                );
                replay.current = createMedicationMutationReplayState();
                setRecoveredQueueId(rejected.id);
            }}
            onDiscard={() => {
                void dismissRejectedOfflineSubmission(rejected.id);
            }}
        />
    ) : null;
    const reloadButton = errors.save?.includes('reload') && (
        <Button
            type="button"
            variant="outline"
            className="frontline-tap"
            onClick={() => {
                autosave.flush();
                refresh();
            }}
        >
            Reload latest record
        </Button>
    );
    const emergencyAccessButton = emergencyEnded && (
        <Button variant="outline" asChild className="frontline-tap">
            <Link
                href={`/emar/emergency-access?request_client=${row.client.id}`}
                target="_blank"
                rel="noopener noreferrer"
            >
                Start emergency access in another tab
            </Link>
        </Button>
    );
    const sharedHeader = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <FollowupStatus row={row} />
                <span className="text-caption">
                    Due {formatDateTime(row.due_at, 'Time not set')}
                </span>
            </div>
            <p className="text-subtle">
                {row.client.name} · {row.medication?.name ?? row.site.name} ·
                Owner:{' '}
                {row.owner?.name ?? (row.lead ? 'House lead' : 'Unassigned')}
            </p>
            {row.why && <p className="text-subtle">{row.why}</p>}
        </div>
    );
    const canSave =
        mode === 'reassign'
            ? row.can_reassign
            : row.can_complete && !row.source_owned;
    const saveButton = (
        <Button
            type="button"
            className="frontline-tap"
            disabled={
                sending ||
                !canSave ||
                !!recovery ||
                !!pending ||
                (!!rejected && !recoveredQueueId)
            }
            onClick={save}
        >
            {sending ? 'Saving…' : 'Save follow-up'}
        </Button>
    );
    const review = (
        <div className="flex flex-col gap-5">
            <ReviewCard
                icon={HeartHandshake}
                title="What happened"
                onEdit={() => setStep(0)}
            >
                <ReviewRow
                    label="Outcome"
                    value={form.outcome.replaceAll('_', ' ')}
                />
                <ReviewRow label="Reason" value={form.reason} />
                <ReviewRow
                    label="Check again"
                    value={formatDateTime(againAt)}
                />
            </ReviewCard>
            {fullRefusal && (
                <ReviewCard
                    icon={ClipboardList}
                    title="Assessment"
                    onEdit={() => setStep(1)}
                >
                    <ReviewRow
                        label="Reason"
                        value={form.reason_category.replaceAll('_', ' ')}
                    />
                    <ReviewRow
                        label="Understood the choice"
                        value={form.capacity.replaceAll('_', ' ')}
                    />
                    <ReviewRow
                        label="What happens next"
                        value={form.next_action}
                    />
                    <ReviewRow
                        label="Something else offered"
                        value={
                            form.offered_alternative
                                ? form.alternative_details
                                : 'No'
                        }
                    />
                    <ReviewRow
                        label="GP or prescriber told"
                        value={form.gp_told ? form.gp_response : 'No'}
                    />
                    <ReviewRow
                        label="Whānau or family told"
                        value={form.family_told ? form.family_details : 'No'}
                    />
                </ReviewCard>
            )}
            <p className="text-subtle">
                {form.outcome === 'couldnt_check'
                    ? 'The follow-up stays open at the new time. It carries over if it is still outstanding at shift change.'
                    : form.outcome === 'taken'
                      ? 'The dose recorder opens next. This follow-up stays open until the dose is saved.'
                      : 'The follow-up closes. Its original record and history remain.'}
            </p>
        </div>
    );
    const discardDialog = (
        <ConfirmDialog
            open={discard}
            onClose={() => setDiscard(false)}
            title="Discard this follow-up draft?"
            description="The medication follow-up stays open. These unsent entries will be removed."
            confirmText="Discard draft"
            onConfirm={() => {
                autosave.clear();
                onClose();
            }}
        />
    );
    if (row.type === 'reoffer' && mode === 'action' && !row.completed_at)
        return (
            <>
                <WizardShell
                    open
                    onClose={requestClose}
                    title={`Follow up a refusal — ${row.client.name}`}
                    description="What happened, then review and save."
                    railIcon={HeartHandshake}
                    railTitle="Follow up a refusal"
                    railSub={row.medication?.name ?? row.client.name}
                    steps={steps}
                    stepIndex={Math.min(step, steps.length - 1)}
                    onStepClick={setStep}
                    pct={Math.round(((step + 1) / steps.length) * 100)}
                    railExtra={sharedHeader}
                    footerStart={
                        <Button
                            type="button"
                            className="frontline-tap"
                            variant="outline"
                            disabled={sending}
                            onClick={() =>
                                step ? setStep(step - 1) : requestClose()
                            }
                        >
                            {step ? (
                                <>
                                    <ChevronLeft className="size-4" />
                                    Back
                                </>
                            ) : (
                                'Cancel'
                            )}
                        </Button>
                    }
                    footerEnd={
                        step >= steps.length - 1 ? (
                            saveButton
                        ) : (
                            <Button
                                type="button"
                                className="frontline-tap"
                                disabled={!!recovery}
                                onClick={() => {
                                    if (step === 0 && !form.outcome) {
                                        setErrors({
                                            outcome: 'Choose what happened.',
                                        });
                                        focusError();
                                        return;
                                    }
                                    setStep((s) => s + 1);
                                }}
                            >
                                Continue
                                <ChevronRight className="size-4" />
                            </Button>
                        )
                    }
                    success={
                        saved ? (
                            <WizardSuccessPane
                                title={
                                    saved === 'queued'
                                        ? 'Saved on this device'
                                        : form.outcome === 'couldnt_check'
                                          ? 'Saved — try again later'
                                          : 'Follow-up saved'
                                }
                                blurb={
                                    saved === 'queued'
                                        ? 'It will send when you reconnect. The follow-up stays open until accepted by the server.'
                                        : 'The original record and accountable history are retained.'
                                }
                                actions={
                                    <Button
                                        className="frontline-tap"
                                        onClick={onClose}
                                    >
                                        Done
                                    </Button>
                                }
                            />
                        ) : undefined
                    }
                >
                    <WizardStepPane>
                        <div ref={bodyRef} className="flex flex-col gap-5">
                            {recoveryPrompt}
                            {queueNotice}
                            {step >= steps.length - 1
                                ? review
                                : fullRefusal && step === 1
                                  ? assessment
                                  : actionBody}
                            <InputError message={errors.save} />
                            {reloadButton}
                            {emergencyAccessButton}
                            <DraftSavedIndicator savedAt={autosave.savedAt} />
                        </div>
                    </WizardStepPane>
                </WizardShell>
                {discardDialog}
            </>
        );
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && requestClose()}>
                <DialogContent
                    className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader className="border-b border-border bg-muted/30 p-5">
                        <DialogTitle>
                            {mode === 'reassign'
                                ? 'Reassign follow-up'
                                : row.label}
                        </DialogTitle>
                        <DialogDescription>
                            {row.client.name} ·{' '}
                            {row.medication?.name ?? row.site.name}
                        </DialogDescription>
                    </DialogHeader>
                    <div
                        ref={bodyRef}
                        className="scrollbar-pretty flex flex-col gap-5 overflow-y-auto p-5"
                    >
                        {sharedHeader}
                        {recoveryPrompt}
                        {queueNotice}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                className="frontline-tap"
                                variant={
                                    mode === 'history' ? 'default' : 'outline'
                                }
                                onClick={() => setMode('history')}
                            >
                                <History className="size-4" />
                                Details and history
                            </Button>
                            {row.can_complete &&
                                (!row.completed_at ||
                                    row.type === 'effect') && (
                                    <Button
                                        type="button"
                                        className="frontline-tap"
                                        variant={
                                            mode === 'action'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setMode('action')}
                                    >
                                        Next action
                                    </Button>
                                )}
                            {row.can_reassign && (
                                <Button
                                    type="button"
                                    className="frontline-tap"
                                    variant={
                                        mode === 'reassign'
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() => setMode('reassign')}
                                >
                                    <UserRoundCog className="size-4" />
                                    Reassign
                                </Button>
                            )}
                        </div>
                        {saved ? (
                            <div role="status">
                                <p className="font-semibold">
                                    {saved === 'queued'
                                        ? 'Saved on this device'
                                        : 'Saved'}
                                </p>
                                <p className="text-subtle">
                                    {saved === 'queued'
                                        ? 'It sends when you reconnect. This follow-up remains open until the server accepts it.'
                                        : 'The follow-up and its source record are updated.'}
                                </p>
                            </div>
                        ) : mode === 'history' ? (
                            history
                        ) : (
                            actionBody
                        )}
                        <InputError message={errors.save} />
                        {emergencyAccessButton}
                        {errors.save?.includes('reload') && (
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                onClick={() => {
                                    autosave.flush();
                                    refresh();
                                }}
                            >
                                Reload latest record
                            </Button>
                        )}
                        <DraftSavedIndicator savedAt={autosave.savedAt} />
                    </div>
                    <DialogFooter className="border-t border-border bg-muted/30 p-5">
                        <Button
                            type="button"
                            className="frontline-tap"
                            variant="outline"
                            disabled={sending}
                            onClick={requestClose}
                        >
                            {saved ? 'Done' : 'Close'}
                        </Button>
                        {!saved && mode !== 'history' && canSave && saveButton}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {discardDialog}
        </>
    );
}
