import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
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
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
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
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { applyFormRequestErrors } from '@/lib/form-request-errors';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    FileText,
    Loader2,
    Paperclip,
    Printer,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type {
    CollectionPreview,
    Dose,
    PackPreview,
    PaperEntry,
    PrnOrder,
    Site,
    Staff,
} from './types';

export function RequestErrors({ errors }: { errors: Record<string, string> }) {
    const messages = [...new Set(Object.values(errors))].filter(Boolean);
    return messages.length ? (
        <div
            role="alert"
            className="rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
        >
            {messages.map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    ) : null;
}

function Choice({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: { id: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id} className="frontline-tap w-full">
                    <SelectValue placeholder="Choose from the paper" />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.id} value={option.id}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function CheckPaper({
    checked,
    onChange,
    label,
    id,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    id: string;
}) {
    return (
        <div className="frontline-tap flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <Label htmlFor={id} className="leading-relaxed">
                {label}
            </Label>
        </div>
    );
}

export function DuplicateResolutionDialog({
    downtimeId,
    dose,
    onClose,
}: {
    downtimeId: number;
    dose: Dose;
    onClose: () => void;
}) {
    const [discard, setDiscard] = useState(false);
    const form = useForm({
        evidence: '',
        reason: '',
        accountable_confirmation: false,
    });
    const choice = dose.resolution_choices.find(
        (item) => `${item.kind}:${item.id}` === form.data.evidence,
    );
    const close = () => {
        if (form.processing) return;
        if (form.isDirty) setDiscard(true);
        else onClose();
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="frontline-dialog flex max-h-[88vh] flex-col overflow-hidden p-0"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-12">
                        <DialogTitle>
                            {dose.resolution
                                ? 'Reviewed duplicate'
                                : 'Review existing evidence'}
                        </DialogTitle>
                        <DialogDescription>
                            Check the signed paper against the existing record.
                            This links the evidence for paper collection; it
                            does not post or replace a dose.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 space-y-4 overflow-y-auto px-5">
                        <p>
                            {dose.snapshot.person} · {dose.snapshot.medicine} ·
                            listed {formatDateTime(dose.scheduled_for)}
                        </p>
                        {dose.resolution ? (
                            <>
                                <p className="whitespace-pre-wrap">
                                    {dose.resolution.reason}
                                </p>
                                <p>
                                    Reviewed by {dose.resolution.resolved_by}.
                                </p>
                            </>
                        ) : (
                            <>
                                <RequestErrors errors={form.errors} />
                                <Choice
                                    id="duplicate-evidence"
                                    label="Existing evidence you checked"
                                    value={form.data.evidence}
                                    options={dose.resolution_choices.map(
                                        (item) => ({
                                            id: `${item.kind}:${item.id}`,
                                            label: item.label,
                                        }),
                                    )}
                                    onChange={(value) => {
                                        form.setData('evidence', value);
                                        form.setData(
                                            'accountable_confirmation',
                                            false,
                                        );
                                    }}
                                />
                                {choice && (
                                    <div className="space-y-2 rounded-lg border bg-muted p-3">
                                        <p>
                                            {choice.label} · actual time{' '}
                                            {choice.actual_at
                                                ? formatDateTime(
                                                      choice.actual_at,
                                                  )
                                                : 'Not specified'}
                                        </p>
                                        {choice.href && (
                                            <Button
                                                variant="outline"
                                                className="frontline-tap"
                                                asChild
                                            >
                                                <a
                                                    href={choice.href}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Open existing evidence
                                                </a>
                                            </Button>
                                        )}
                                    </div>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="duplicate-reason">
                                        How you checked the paper against this
                                        record
                                    </Label>
                                    <Textarea
                                        id="duplicate-reason"
                                        value={form.data.reason}
                                        onChange={(event) => {
                                            form.setData(
                                                'reason',
                                                event.target.value,
                                            );
                                            form.setData(
                                                'accountable_confirmation',
                                                false,
                                            );
                                        }}
                                    />
                                </div>
                                <CheckPaper
                                    id="duplicate-accountability"
                                    checked={form.data.accountable_confirmation}
                                    onChange={(value) =>
                                        form.setData(
                                            'accountable_confirmation',
                                            value,
                                        )
                                    }
                                    label="I checked the signed paper and this existing evidence. This listed dose is accounted for by that record."
                                />
                            </>
                        )}
                    </div>
                    <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            disabled={form.processing}
                            onClick={close}
                        >
                            {dose.resolution ? 'Close' : 'Cancel'}
                        </Button>
                        {!dose.resolution && (
                            <Button
                                className="frontline-tap"
                                disabled={
                                    form.processing ||
                                    !choice ||
                                    !form.data.reason.trim() ||
                                    !form.data.accountable_confirmation
                                }
                                onClick={() => {
                                    if (!choice) return;
                                    form.transform((data) => ({
                                        kind: choice.kind,
                                        record_id: choice.id,
                                        reason: data.reason,
                                        accountable_confirmation:
                                            data.accountable_confirmation,
                                    }));
                                    form.post(
                                        `/emar/downtime/${downtimeId}/doses/${dose.id}/resolve`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: onClose,
                                        },
                                    );
                                }}
                            >
                                {form.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                Link reviewed evidence
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                frontline
                open={discard}
                title="Discard this duplicate review?"
                description="Your review has not been saved."
                confirmText="Discard review"
                variant="destructive"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
            />
        </>
    );
}

export function DeclareDowntimeDialog({
    sites,
    onClose,
}: {
    sites: Site[];
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const form = useForm({
        site_id: sites.length === 1 ? String(sites[0].id) : '',
        started_at: '',
        ended_at: '',
        description: '',
        sheets: [] as File[],
        request_uuid: crypto.randomUUID(),
    });
    const steps = [
        {
            key: 'facts',
            label: 'What went down',
            blurb: 'House and actual times',
            icon: FileText,
        },
        {
            key: 'paper',
            label: 'Paper sheets',
            blurb: 'Private photos or scans',
            icon: Paperclip,
        },
        {
            key: 'review',
            label: 'Check and save',
            blurb: 'List doses to check',
            icon: ClipboardCheck,
        },
    ];
    const close = () => {
        if (form.processing) return;
        if (form.isDirty) setDiscard(true);
        else onClose();
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Record a downtime"
                description="Record the actual interruption, then collect the facts on paper."
                railIcon={FileText}
                railTitle="Record a downtime"
                railSub="Paper facts stay accountable"
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!form.processing) setStep(index);
                }}
                pct={Math.round(
                    ([
                        form.data.site_id,
                        form.data.started_at,
                        form.data.ended_at,
                        form.data.description,
                    ].filter(Boolean).length /
                        4) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={step ? () => setStep(step - 1) : close}
                        disabled={form.processing}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        className="frontline-tap"
                        disabled={form.processing}
                        onClick={() =>
                            step < 2
                                ? setStep(step + 1)
                                : form.post('/emar/downtime', {
                                      forceFormData: true,
                                      onSuccess: onClose,
                                  })
                        }
                    >
                        {form.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {step < 2 ? 'Continue' : 'Save and list paper doses'}
                    </Button>
                }
            >
                <WizardStepPane key={steps[step].key}>
                    <div className="space-y-5">
                        <RequestErrors errors={form.errors} />
                        {step === 0 && (
                            <>
                                <Choice
                                    id="dt-house"
                                    label="House"
                                    value={form.data.site_id}
                                    options={sites.map((site) => ({
                                        id: String(site.id),
                                        label: site.name,
                                    }))}
                                    onChange={(value) =>
                                        form.setData('site_id', value)
                                    }
                                />
                                <DateTimeField
                                    compact
                                    id="dt-start"
                                    label="When it started"
                                    value={form.data.started_at}
                                    onChange={(value) =>
                                        form.setData('started_at', value)
                                    }
                                />
                                <DateTimeField
                                    compact
                                    id="dt-end"
                                    label="When it ended"
                                    value={form.data.ended_at}
                                    onChange={(value) =>
                                        form.setData('ended_at', value)
                                    }
                                />
                                <div className="space-y-2">
                                    <Label htmlFor="dt-description">
                                        What went down
                                    </Label>
                                    <Textarea
                                        id="dt-description"
                                        value={form.data.description}
                                        onChange={(event) =>
                                            form.setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Say what happened to the system or connection"
                                    />
                                </div>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <p>
                                    Keep the signed originals. These files are
                                    private; scans that could include controlled
                                    medicines need controlled-medicine access.
                                </p>
                                <FileDropzone
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    multiple
                                    title="Add photos or scans of the paper"
                                    hint="PDF, JPG or PNG · up to 10 files, 10 MB each"
                                    onFiles={(files) =>
                                        form.setData(
                                            'sheets',
                                            [
                                                ...form.data.sheets,
                                                ...files,
                                            ].slice(0, 10),
                                        )
                                    }
                                />
                                {form.data.sheets.map((file, index) => (
                                    <StagedFileCard
                                        key={index}
                                        file={file}
                                        onRemove={() =>
                                            form.setData(
                                                'sheets',
                                                form.data.sheets.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    title="Check the downtime"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="House"
                                        value={
                                            sites.find(
                                                (site) =>
                                                    String(site.id) ===
                                                    form.data.site_id,
                                            )?.name ?? 'Choose a house'
                                        }
                                    />
                                    <ReviewRow
                                        label="Started"
                                        value={
                                            form.data.started_at.replace(
                                                'T',
                                                ' · ',
                                            ) || 'Not provided'
                                        }
                                    />
                                    <ReviewRow
                                        label="Ended"
                                        value={
                                            form.data.ended_at.replace(
                                                'T',
                                                ' · ',
                                            ) || 'Not provided'
                                        }
                                    />
                                    <ReviewRow
                                        label="What happened"
                                        value={
                                            form.data.description ||
                                            'Not provided'
                                        }
                                    />
                                    <ReviewRow
                                        label="Paper sheets"
                                        value={String(form.data.sheets.length)}
                                    />
                                </ReviewCard>
                                <p className="text-subtle">
                                    Scheduled doses without an eMAR outcome are
                                    listed to check against the paper. Nothing
                                    is marked given for you.
                                </p>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this downtime draft?"
                description="These details and staged files have not been saved."
                confirmText="Discard draft"
            />
        </>
    );
}

const observationFields: Record<string, { key: string; label: string }[]> = {
    blood_glucose: [
        { key: 'blood_glucose_level', label: 'Blood sugar on paper (mmol/L)' },
    ],
    pulse: [{ key: 'pulse_bpm', label: 'Pulse on paper (bpm)' }],
    blood_pressure: [
        { key: 'blood_pressure_systolic', label: 'Systolic on paper (mmHg)' },
        { key: 'blood_pressure_diastolic', label: 'Diastolic on paper (mmHg)' },
    ],
};

export function PaperEntryDialog({
    downtimeId,
    dose,
    prnOrders,
    staff,
    onClose,
}: {
    downtimeId: number;
    dose: Dose | null;
    prnOrders: PrnOrder[];
    staff: Staff[];
    actorId: number;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [preview, setPreview] = useState<CollectionPreview | null>(null);
    const [checking, setChecking] = useState(false);
    const [clockOffset, setClockOffset] = useState('');
    const [discard, setDiscard] = useState(false);
    const form = useForm({
        client_medication_id: dose ? String(dose.client_medication_id) : '',
        downtime_dose_id: dose?.id ?? null,
        outcome: '',
        given_at: '',
        given_by: '',
        witness_id: '',
        dose_on_paper: '',
        notes: '',
        observations: {} as Record<string, string>,
        request_uuid: crypto.randomUUID(),
        preview_token: '',
        accountable_confirmation: false,
    });
    const steps = [
        {
            key: 'facts',
            label: 'Enter it',
            blurb: 'Actual facts on paper',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Check paper entry',
            blurb: 'Conflicts and confirmations',
            icon: ClipboardCheck,
        },
    ];
    const close = () => {
        if (form.processing || checking) return;
        if (form.isDirty) setDiscard(true);
        else onClose();
    };
    async function check() {
        setChecking(true);
        form.setData('accountable_confirmation', false);
        form.clearErrors();
        try {
            const response = await axios.post<CollectionPreview>(
                `/emar/downtime/${downtimeId}/paper/preview`,
                {
                    ...form.data,
                    given_at: form.data.given_at + clockOffset,
                    witness_id: form.data.witness_id || null,
                },
            );
            setPreview(response.data);
            form.setData('preview_token', response.data.preview_token);
            setStep(1);
        } catch (error) {
            applyFormRequestErrors(
                error,
                (key, message) =>
                    form.setError(key as keyof typeof form.data, message),
                'Could not check the paper entry. Your draft is kept.',
            );
        } finally {
            setChecking(false);
        }
    }
    const prn = prnOrders.find(
        (order) => String(order.id) === form.data.client_medication_id,
    );
    const knownReadings =
        dose?.snapshot.observation_keys ?? prn?.observation_keys ?? [];
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title={
                    dose
                        ? 'Enter a listed paper dose'
                        : 'Add an as-needed dose from paper'
                }
                description="Enter the paper facts, check for conflicts, then request accountable confirmations."
                railIcon={FileText}
                railTitle="Paper record"
                railSub={`DT-${downtimeId}`}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (index === 0 && !form.processing && !checking) {
                        setStep(0);
                        form.setData('accountable_confirmation', false);
                    }
                }}
                footerStart={
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        disabled={form.processing || checking}
                        onClick={
                            step
                                ? () => {
                                      setStep(0);
                                      form.setData(
                                          'accountable_confirmation',
                                          false,
                                      );
                                  }
                                : close
                        }
                    >
                        {step ? 'Back to paper facts' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        className="frontline-tap"
                        disabled={
                            form.processing ||
                            checking ||
                            (step === 1 &&
                                (!preview?.can_submit ||
                                    !form.data.accountable_confirmation))
                        }
                        onClick={() => {
                            if (step === 0) {
                                void check();
                                return;
                            }
                            form.transform((data) => ({
                                ...data,
                                given_at: data.given_at + clockOffset,
                                witness_id: data.witness_id || null,
                            }));
                            form.post(`/emar/downtime/${downtimeId}/paper`, {
                                onSuccess: onClose,
                                preserveScroll: true,
                            });
                        }}
                    >
                        {(checking || form.processing) && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {step === 0
                            ? 'Preview paper entry'
                            : 'Save paper facts'}
                    </Button>
                }
            >
                <WizardStepPane key={steps[step].key}>
                    <div className="space-y-5">
                        <RequestErrors errors={form.errors} />
                        {dose && (
                            <ReviewCard
                                title={`Listed for downtime — DT-${downtimeId}`}
                                icon={FileText}
                            >
                                <ReviewRow
                                    label="Person / medicine"
                                    value={`${dose.snapshot.person} · ${dose.snapshot.medicine}`}
                                />
                                <ReviewRow
                                    label="Listed dose"
                                    value={`${dose.snapshot.dosage} · ${formatDateTime(dose.scheduled_for)}`}
                                />
                                <ReviewRow
                                    label="Starting point"
                                    value="Check against the signed paper. The outcome, actual time and actual giver are not assumed."
                                />
                            </ReviewCard>
                        )}
                        {step === 0 && (
                            <>
                                {!dose && (
                                    <Choice
                                        id="paper-prn"
                                        label="As-needed medicine on paper"
                                        value={form.data.client_medication_id}
                                        options={prnOrders.map((order) => ({
                                            id: String(order.id),
                                            label: `${order.person} · ${order.medicine}`,
                                        }))}
                                        onChange={(value) => {
                                            form.setData(
                                                'client_medication_id',
                                                value,
                                            );
                                            form.setData('observations', {});
                                        }}
                                    />
                                )}
                                <Choice
                                    id="paper-outcome"
                                    label="Outcome written on paper"
                                    value={form.data.outcome}
                                    options={[
                                        'given',
                                        'refused',
                                        'withheld',
                                    ].map((key) => ({
                                        id: key,
                                        label:
                                            key[0].toUpperCase() + key.slice(1),
                                    }))}
                                    onChange={(value) =>
                                        form.setData('outcome', value)
                                    }
                                />
                                <DateTimeField
                                    compact
                                    id="paper-time"
                                    label="The actual time on paper"
                                    value={form.data.given_at}
                                    onChange={(value) => {
                                        form.setData('given_at', value);
                                        setClockOffset('');
                                    }}
                                    hint="Use the full NZ date and time inside this downtime."
                                />
                                {(clockOffset ||
                                    form.errors.given_at?.includes(
                                        'happened twice',
                                    )) && (
                                    <Choice
                                        id="paper-clock-reading"
                                        label="Which clock reading is confirmed on the paper?"
                                        value={clockOffset}
                                        options={[
                                            {
                                                id: '+13:00',
                                                label: 'First time — NZ daylight time (NZDT)',
                                            },
                                            {
                                                id: '+12:00',
                                                label: 'Second time — NZ standard time (NZST)',
                                            },
                                        ]}
                                        onChange={setClockOffset}
                                    />
                                )}
                                <Choice
                                    id="paper-giver"
                                    label="Who actually gave it / recorded the outcome on paper"
                                    value={form.data.given_by}
                                    options={staff.map((person) => ({
                                        id: String(person.id),
                                        label: person.name,
                                    }))}
                                    onChange={(value) =>
                                        form.setData('given_by', value)
                                    }
                                />
                                {form.data.outcome === 'given' && (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="paper-dose">
                                                Actual dose written on paper
                                            </Label>
                                            <Input
                                                id="paper-dose"
                                                value={form.data.dose_on_paper}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'dose_on_paper',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Copy the actual amount; nothing is prefilled"
                                            />
                                        </div>
                                        <Choice
                                            id="paper-witness"
                                            label={
                                                dose?.snapshot
                                                    .second_person_required ||
                                                prn?.second_person_required
                                                    ? 'Required second person named on paper'
                                                    : 'Second person named on paper, if there was one'
                                            }
                                            value={form.data.witness_id}
                                            options={staff
                                                .filter(
                                                    (person) =>
                                                        String(person.id) !==
                                                        form.data.given_by,
                                                )
                                                .map((person) => ({
                                                    id: String(person.id),
                                                    label: person.name,
                                                }))}
                                            onChange={(value) =>
                                                form.setData(
                                                    'witness_id',
                                                    value,
                                                )
                                            }
                                        />
                                        {knownReadings
                                            .flatMap(
                                                (key) =>
                                                    observationFields[key] ??
                                                    [],
                                            )
                                            .map((field) => (
                                                <div
                                                    key={field.key}
                                                    className="space-y-2"
                                                >
                                                    <Label htmlFor={field.key}>
                                                        {field.label}
                                                    </Label>
                                                    <Input
                                                        id={field.key}
                                                        inputMode="decimal"
                                                        value={
                                                            form.data
                                                                .observations[
                                                                field.key
                                                            ] ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'observations',
                                                                {
                                                                    ...form.data
                                                                        .observations,
                                                                    [field.key]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            ))}
                                    </>
                                )}
                                <div className="space-y-2">
                                    <Label htmlFor="paper-note">
                                        Note / reason written on paper
                                    </Label>
                                    <Textarea
                                        id="paper-note"
                                        value={form.data.notes}
                                        onChange={(event) =>
                                            form.setData(
                                                'notes',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </>
                        )}
                        {step === 1 && preview && (
                            <>
                                <ReviewCard
                                    title="Actual facts entered"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="Outcome"
                                        value={form.data.outcome}
                                    />
                                    <ReviewRow
                                        label="Actual NZ time"
                                        value={
                                            form.data.given_at.replace(
                                                'T',
                                                ' · ',
                                            ) + clockOffset
                                        }
                                    />
                                    <ReviewRow
                                        label="Actual giver"
                                        value={
                                            staff.find(
                                                (person) =>
                                                    String(person.id) ===
                                                    form.data.given_by,
                                            )?.name ?? 'Not chosen'
                                        }
                                    />
                                    <ReviewRow
                                        label="Dose on paper"
                                        value={
                                            form.data.dose_on_paper ||
                                            'Not given'
                                        }
                                    />
                                    <ReviewRow
                                        label="Giver confirmation"
                                        value={
                                            preview.giver_confirmation_needed
                                                ? 'The actual giver must confirm in their own account'
                                                : 'You confirm your own actual outcome'
                                        }
                                    />
                                    <ReviewRow
                                        label="Second person"
                                        value={
                                            preview.second_person_required
                                                ? 'Their own PIN confirmation is required'
                                                : 'No required second person from current rules'
                                        }
                                    />
                                </ReviewCard>
                                {preview.conflicts.map((conflict) => (
                                    <p
                                        key={conflict.kind}
                                        role="alert"
                                        className="text-status-critical"
                                    >
                                        {conflict.message}
                                    </p>
                                ))}
                                {preview.errors.map((message) => (
                                    <p
                                        key={message}
                                        role="alert"
                                        className="text-status-critical"
                                    >
                                        {message}
                                    </p>
                                ))}
                                <p className="text-subtle">{preview.notice}</p>
                                <CheckPaper
                                    id="paper-accountable"
                                    checked={form.data.accountable_confirmation}
                                    onChange={(value) =>
                                        form.setData(
                                            'accountable_confirmation',
                                            value,
                                        )
                                    }
                                    label="I checked these facts against the signed paper. If I am the actual giver, I confirm this is what happened. This saves paper evidence before eMAR posting."
                                />
                                <StatusBadge
                                    variant={
                                        preview.can_submit
                                            ? 'warning'
                                            : 'critical'
                                    }
                                >
                                    {preview.can_submit
                                        ? 'Paper facts ready to collect'
                                        : 'Resolve before saving'}
                                </StatusBadge>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this paper-entry draft?"
                description="No paper facts have been saved."
                confirmText="Discard draft"
            />
        </>
    );
}

export function ConfirmationDialog({
    entry,
    downtimeId,
    kind,
    onClose,
}: {
    entry: PaperEntry;
    downtimeId: number;
    kind: 'giver' | 'witness' | 'reconcile';
    onClose: () => void;
}) {
    const form = useForm({
        kind,
        witness_pin: '',
        accountable_confirmation: false,
        preview_token: entry.reconciliation.preview_token,
    });
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) onClose();
            }}
        >
            <DialogContent
                className="frontline-dialog flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>
                        {kind === 'reconcile'
                            ? 'Apply the signed paper entry'
                            : 'Confirm your paper record'}
                    </DialogTitle>
                    <DialogDescription>
                        {kind === 'reconcile'
                            ? 'Post this signed paper outcome to eMAR. The original outcome time is preserved and recording checks run again. Stock will not change.'
                            : 'Your confirmation is appended to the evidence. It does not post the dose.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto px-5">
                    <ReviewCard
                        title={`${entry.snapshot.person} · ${entry.snapshot.medicine}`}
                        icon={FileText}
                    >
                        <ReviewRow
                            label="Actual outcome"
                            value={entry.outcome}
                        />
                        <ReviewRow
                            label="Given / outcome at"
                            value={formatDateTime(entry.given_at)}
                        />
                        <ReviewRow
                            label="Actual giver"
                            value={entry.given_by}
                        />
                        <ReviewRow
                            label="Entered by"
                            value={`${entry.entered_by} · ${formatDateTime(entry.entered_at)}`}
                        />
                        <ReviewRow
                            label="Dose on paper"
                            value={entry.dose_on_paper ?? 'Not given'}
                        />
                    </ReviewCard>
                    {kind === 'witness' && (
                        <WitnessPinInput
                            label="Your own witness PIN"
                            value={form.data.witness_pin}
                            onChange={(value) =>
                                form.setData('witness_pin', value)
                            }
                            error={form.errors.witness_pin}
                        />
                    )}
                    <CheckPaper
                        id="confirm-paper"
                        checked={form.data.accountable_confirmation}
                        onChange={(value) =>
                            form.setData('accountable_confirmation', value)
                        }
                        label={
                            kind === 'giver'
                                ? 'I am the actual giver. This actual outcome, dose and time match what happened and the signed paper.'
                                : kind === 'witness'
                                  ? 'I was the second person there. The actual dose and time match what I witnessed and signed on paper.'
                                  : 'I checked the signed paper, actual giver, required confirmations and the conflict preview. Apply this one entry through the normal recording checks.'
                        }
                    />
                    <RequestErrors errors={form.errors} />
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        disabled={form.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="frontline-tap"
                        disabled={
                            form.processing ||
                            !form.data.accountable_confirmation ||
                            (kind === 'witness' &&
                                form.data.witness_pin.length !== 6)
                        }
                        onClick={() =>
                            form.post(
                                `/emar/downtime/${downtimeId}/paper/${entry.id}/${kind === 'reconcile' ? 'reconcile' : 'confirm'}`,
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        {form.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {kind === 'reconcile'
                            ? 'Apply paper entry'
                            : 'Save my confirmation'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function PackDialog({
    sites,
    today,
    tomorrow,
    onClose,
}: {
    sites: Site[];
    today: string;
    tomorrow: string;
    onClose: () => void;
}) {
    const [siteId, setSiteId] = useState(
        sites.length === 1 ? String(sites[0].id) : '',
    );
    const [day, setDay] = useState(today);
    const [preview, setPreview] = useState<PackPreview | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    async function check() {
        setBusy(true);
        setErrors({});
        try {
            setPreview(
                (
                    await axios.post<PackPreview>(
                        '/emar/downtime/pack/preview',
                        { site_id: siteId, nz_date: day },
                    )
                ).data,
            );
        } catch (error) {
            applyFormRequestErrors(
                error,
                (key, message) =>
                    setErrors((current) => ({ ...current, [key]: message })),
                'Could not preview the pack.',
            );
        } finally {
            setBusy(false);
        }
    }
    async function make() {
        setBusy(true);
        setErrors({});
        try {
            const response = await axios.post(
                '/emar/downtime/pack',
                { site_id: siteId, nz_date: day },
                { responseType: 'blob' },
            );
            const url = URL.createObjectURL(response.data as Blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `downtime-pack-${siteId}-${day}.pdf`;
            anchor.click();
            URL.revokeObjectURL(url);
            toast.success(
                'Pack downloaded. Keep the printed copy in the house and the saved PDF on this device.',
            );
            onClose();
        } catch (error) {
            if (
                axios.isAxiosError(error) &&
                error.response?.data instanceof Blob
            ) {
                try {
                    error.response.data = JSON.parse(
                        await error.response.data.text(),
                    );
                } catch {
                    /* Preserve original error. */
                }
            }
            applyFormRequestErrors(
                error,
                (key, message) =>
                    setErrors((current) => ({ ...current, [key]: message })),
                'Could not make the pack. Nothing was downloaded.',
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent
                className="frontline-dialog flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 900px)',
                    maxWidth: 'min(92vw, 900px)',
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>Make the downtime pack</DialogTitle>
                    <DialogDescription>
                        One house, today or tomorrow. Check changes before each
                        round.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-5 overflow-y-auto px-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Choice
                            id="pack-house"
                            label="House"
                            value={siteId}
                            options={sites.map((site) => ({
                                id: String(site.id),
                                label: site.name,
                            }))}
                            onChange={(value) => {
                                setSiteId(value);
                                setPreview(null);
                            }}
                        />
                        <Choice
                            id="pack-day"
                            label="NZ day"
                            value={day}
                            options={[
                                {
                                    id: today,
                                    label: 'Today · ' + formatDateOnly(today),
                                },
                                {
                                    id: tomorrow,
                                    label:
                                        'Tomorrow · ' +
                                        formatDateOnly(tomorrow),
                                },
                            ]}
                            onChange={(value) => {
                                setDay(value);
                                setPreview(null);
                            }}
                        />
                    </div>
                    <ReviewCard title="What's in it" icon={Printer}>
                        <ReviewRow
                            label="Recording sheets"
                            value="Scheduled doses, blank actual-outcome and giver boxes, second-person and reading boxes where required"
                        />
                        <ReviewRow
                            label="As-needed / allergies"
                            value="Order limits and recorded allergies, with missing-information notices"
                        />
                        <ReviewRow
                            label="Round sheets"
                            value="Built from scheduled doses, including pending doses"
                        />
                        <ReviewRow
                            label="Controlled pages"
                            value="Only with controlled-medicine access; balance at print time and blank witnessed rows"
                        />
                        <ReviewRow
                            label="Purpose recorded"
                            value="Downtime — a paper copy in case the system is down"
                        />
                    </ReviewCard>
                    {preview?.controlled_notice && (
                        <p className="rounded-lg border bg-muted p-3">
                            {preview.controlled_notice}
                        </p>
                    )}
                    {preview && (
                        <section
                            aria-label="First page preview"
                            className="rounded-lg border p-4"
                        >
                            <p className="text-section-title">
                                First page —{' '}
                                {preview.first_page?.name ??
                                    'No people in your approved scope'}
                            </p>
                            {preview.first_page?.scheduled.map((dose) => (
                                <div
                                    key={dose.id}
                                    className="border-b py-2 last:border-0"
                                >
                                    <p>
                                        {dose.ordered_time} · {dose.medicine} ·{' '}
                                        {dose.dosage}
                                    </p>
                                    <p className="text-subtle">
                                        {dose.second_person_required
                                            ? 'Second-person signature required'
                                            : 'No required second-person box'}
                                        {dose.readings.length
                                            ? ` · ${dose.readings.join(', ')}`
                                            : ''}
                                    </p>
                                </div>
                            ))}
                        </section>
                    )}
                    <RequestErrors errors={errors} />
                    <p className="text-subtle">
                        Download while connected. During downtime, use the
                        printed copy or the PDF saved on this device.
                    </p>
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        disabled={busy}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="frontline-tap"
                        disabled={busy || !siteId}
                        onClick={() => {
                            void (preview ? make() : check());
                        }}
                    >
                        {busy ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <Printer className="size-4" />
                        )}
                        {preview
                            ? 'Make and download the pack'
                            : 'Preview first page'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
