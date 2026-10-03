import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    Brain,
    Check,
    ClipboardCheck,
    FileText,
    Pill,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { Errors, Field, Note, SourceFiles, useDraftClose } from './_parts';
export type Covert = {
    id: number;
    client_id: number;
    client_medication_id: number;
    status: string;
    authorised_by_name: string;
    authorised_date: string;
    review_date: string;
    legal_basis: string;
    administration_method: string;
    pharmacist_advice: string;
    revoke_reason: string | null;
    structured_evidence: {
        capacity_assessor?: string;
        capacity_date?: string;
        capacity_record?: string;
        consulted_name?: string;
        consulted_role?: string;
        consulted_record?: string;
        pharmacist_name?: string;
    } | null;
    medication: { name: string };
    client: { first_name: string; last_name: string };
    can_manage: boolean;
    files: { id: number; file_name: string; purpose: string }[];
};

export function CovertStatus({ record }: { record: Covert }) {
    if (record.status !== 'active')
        return <StatusBadge variant="neutral">Stopped</StatusBadge>;
    const today = toDateInput(new Date());
    const days = Math.round(
        (Date.parse(`${record.review_date}T00:00:00Z`) -
            Date.parse(`${today}T00:00:00Z`)) /
            86400000,
    );
    if (days < 0)
        return (
            <StatusBadge variant="critical">
                Review overdue — covert giving blocked
            </StatusBadge>
        );
    return (
        <StatusBadge variant={days <= 14 ? 'warning' : 'success'}>
            {days <= 14 ? 'Review due' : 'Active'}
        </StatusBadge>
    );
}

export function CovertWizard({
    orderId,
    medicine,
    person,
    existing,
    reviewDefault,
    onClose,
    onStop,
}: {
    orderId: number;
    medicine: string;
    person: string;
    existing?: Covert;
    reviewDefault: string;
    onClose: () => void;
    onStop: () => void;
}) {
    const [step, setStep] = useState(0);
    const form = useForm({
        capacity_lacking: false,
        capacity_assessor: '',
        capacity_date: toDateInput(new Date()),
        capacity_record: '',
        consulted_name: '',
        consulted_role: '',
        consulted_record: '',
        pharmacist_name: '',
        pharmacist_advice: '',
        authorised_by_name: '',
        authorised_date: toDateInput(new Date()),
        legal_basis: '',
        administration_method: '',
        review_date: reviewDefault,
        gp_file: null as File | null,
        request_key: crypto.randomUUID(),
    });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    const steps = [
        {
            key: 'capacity',
            label: 'Capacity',
            blurb: 'Assessment for this decision',
            icon: Brain,
        },
        {
            key: 'consulted',
            label: 'Who was consulted',
            blurb: 'Guardian or EPOA',
            icon: Users,
        },
        {
            key: 'pharmacist',
            label: 'Pharmacist’s advice',
            blurb: 'Medicine-specific advice',
            icon: Pill,
        },
        {
            key: 'gp',
            label: 'GP and method',
            blurb: 'Signed authorisation and review',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Keep the evidence together',
            icon: ClipboardCheck,
        },
    ];
    return (
        <>
            <WizardShell
                open
                onClose={close.close}
                title={
                    existing
                        ? 'Review covert giving'
                        : 'Authorise covert giving'
                }
                description="Record capacity, consultation, the pharmacist’s advice and the prescriber’s authorisation. The prescription itself is unchanged."
                railIcon={ShieldCheck}
                railTitle={existing ? 'Review covert giving' : 'Covert giving'}
                railSub={`${person} · ${medicine}`}
                steps={steps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        form.data.capacity_record,
                        form.data.consulted_record,
                        form.data.pharmacist_advice,
                        form.data.gp_file,
                        form.data.administration_method,
                    ].filter(Boolean).length /
                        5) *
                        100,
                )}
                footerStart={
                    <Button
                        variant="outline"
                        onClick={step ? () => setStep(step - 1) : close.close}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={
                            form.processing || !form.data.capacity_lacking
                        }
                        onClick={
                            step < 4
                                ? () => setStep(step + 1)
                                : () =>
                                      form.post(
                                          `/emar/orders/${orderId}/covert`,
                                          {
                                              preserveScroll: true,
                                              onSuccess: onClose,
                                          },
                                      )
                        }
                    >
                        {step < 4
                            ? 'Continue'
                            : form.processing
                              ? 'Saving…'
                              : 'Save authorisation'}
                    </Button>
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    {existing && (
                        <ReviewCard
                            icon={FileText}
                            title="Previous signed authorisation"
                        >
                            <SourceFiles files={existing.files ?? []} />
                        </ReviewCard>
                    )}
                    {step === 0 && (
                        <>
                            <TilePicker
                                value={
                                    form.data.capacity_lacking
                                        ? 'lacks'
                                        : 'can_decide'
                                }
                                onChange={(value) =>
                                    form.setData(
                                        'capacity_lacking',
                                        value === 'lacks',
                                    )
                                }
                                options={[
                                    {
                                        key: 'lacks',
                                        label: 'Lacks capacity for this decision',
                                        description:
                                            'Assessment evidence required',
                                        icon: Brain,
                                    },
                                    {
                                        key: 'can_decide',
                                        label: 'Can decide',
                                        description:
                                            'Covert giving cannot be authorised',
                                        icon: Check,
                                    },
                                ]}
                            />
                            {!form.data.capacity_lacking ? (
                                <Note>
                                    The person can decide, so covert giving
                                    cannot be authorised.
                                    {existing && (
                                        <Button
                                            variant="destructive"
                                            onClick={onStop}
                                        >
                                            Stop covert giving
                                        </Button>
                                    )}
                                </Note>
                            ) : (
                                <>
                                    <Field
                                        id="capacity-assessor"
                                        label="Capacity assessor"
                                    >
                                        <Input
                                            id="capacity-assessor"
                                            value={form.data.capacity_assessor}
                                            onChange={(event) =>
                                                form.setData(
                                                    'capacity_assessor',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="capacity-date"
                                        label="Assessment date"
                                    >
                                        <DatePicker
                                            id="capacity-date"
                                            label="Capacity assessment"
                                            value={form.data.capacity_date}
                                            onChange={(value) =>
                                                form.setData(
                                                    'capacity_date',
                                                    value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        id="capacity-record"
                                        label="Assessment and its source"
                                    >
                                        <Textarea
                                            id="capacity-record"
                                            value={form.data.capacity_record}
                                            onChange={(event) =>
                                                form.setData(
                                                    'capacity_record',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <Field id="consulted-name" label="Person consulted">
                                <Input
                                    id="consulted-name"
                                    value={form.data.consulted_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'consulted_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="consulted-role"
                                label="Their authority or role"
                            >
                                <Input
                                    id="consulted-role"
                                    value={form.data.consulted_role}
                                    onChange={(event) =>
                                        form.setData(
                                            'consulted_role',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Welfare guardian or EPOA — record the source"
                                />
                            </Field>
                            <Field
                                id="consulted-record"
                                label="Consultation and what they said"
                            >
                                <Textarea
                                    id="consulted-record"
                                    value={form.data.consulted_record}
                                    onChange={(event) =>
                                        form.setData(
                                            'consulted_record',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <Field id="pharmacist-name" label="Pharmacist">
                                <Input
                                    id="pharmacist-name"
                                    value={form.data.pharmacist_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'pharmacist_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="pharmacist-advice"
                                label="Their advice for this medicine and method"
                            >
                                <Textarea
                                    id="pharmacist-advice"
                                    rows={5}
                                    value={form.data.pharmacist_advice}
                                    onChange={(event) =>
                                        form.setData(
                                            'pharmacist_advice',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Note>
                                Pharmacist’s advice is required. Do not assume
                                this medicine can be crushed, mixed or altered.
                            </Note>
                        </>
                    )}
                    {step === 3 && (
                        <>
                            <Field id="gp-name" label="Authorising prescriber">
                                <Input
                                    id="gp-name"
                                    value={form.data.authorised_by_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'authorised_by_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field id="gp-date" label="Authorised date">
                                <DatePicker
                                    id="gp-date"
                                    label="Authorised"
                                    value={form.data.authorised_date}
                                    onChange={(value) =>
                                        form.setData('authorised_date', value)
                                    }
                                />
                            </Field>
                            <FileDropzone
                                multiple={false}
                                title="Attach the signed GP authorisation"
                                accept=".pdf,.jpg,.jpeg,.png"
                                hint="PDF, JPG or PNG · up to 10 MB"
                                onFiles={(files) =>
                                    form.setData('gp_file', files[0] ?? null)
                                }
                            />
                            {form.data.gp_file && (
                                <StagedFileCard
                                    file={form.data.gp_file}
                                    onRemove={() =>
                                        form.setData('gp_file', null)
                                    }
                                />
                            )}
                            <Field
                                id="covert-legal"
                                label="Recorded legal basis and source"
                            >
                                <Textarea
                                    id="covert-legal"
                                    value={form.data.legal_basis}
                                    onChange={(event) =>
                                        form.setData(
                                            'legal_basis',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id="covert-method"
                                label="Authorised administration method"
                            >
                                <Textarea
                                    id="covert-method"
                                    value={form.data.administration_method}
                                    onChange={(event) =>
                                        form.setData(
                                            'administration_method',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field id="covert-review" label="Review by">
                                <DatePicker
                                    id="covert-review"
                                    label="Review by"
                                    value={form.data.review_date}
                                    onChange={(value) =>
                                        form.setData('review_date', value)
                                    }
                                />
                            </Field>
                        </>
                    )}
                    {step === 4 && (
                        <>
                            <ReviewCard
                                icon={Brain}
                                title="Capacity"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Assessment"
                                    value={`${form.data.capacity_assessor} · ${formatDateOnly(form.data.capacity_date)}`}
                                />
                                <ReviewRow
                                    label="Evidence"
                                    value={form.data.capacity_record}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Users}
                                title="Consulted"
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="Who"
                                    value={`${form.data.consulted_name} · ${form.data.consulted_role}`}
                                />
                                <ReviewRow
                                    label="What they said"
                                    value={form.data.consulted_record}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Pill}
                                title="Pharmacist’s advice"
                                onEdit={() => setStep(2)}
                            >
                                <ReviewRow
                                    label="Pharmacist"
                                    value={form.data.pharmacist_name}
                                />
                                <ReviewRow
                                    label="Advice"
                                    value={form.data.pharmacist_advice}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={FileText}
                                title="Prescriber and method"
                                onEdit={() => setStep(3)}
                            >
                                <ReviewRow
                                    label="Prescriber"
                                    value={form.data.authorised_by_name}
                                />
                                <ReviewRow
                                    label="Authorisation"
                                    value={form.data.gp_file?.name}
                                />
                                <ReviewRow
                                    label="Method"
                                    value={form.data.administration_method}
                                />
                                <ReviewRow
                                    label="Review by"
                                    value={formatDateOnly(
                                        form.data.review_date,
                                    )}
                                />
                            </ReviewCard>
                            <Note>
                                An overdue review blocks covert giving. Earlier
                                authorisations and the reason they ended are
                                kept.
                            </Note>
                        </>
                    )}
                </div>
            </WizardShell>
            {close.confirm}
        </>
    );
}

export function StopCovert({
    record,
    onClose,
}: {
    record: Covert;
    onClose: () => void;
}) {
    const [confirm, setConfirm] = useState(false);
    const form = useForm({ reason: '' });
    const close = useDraftClose(form.isDirty, form.processing, onClose);
    return (
        <>
            <WizardShell
                open
                onClose={close.close}
                title="Stop covert giving"
                description="The prescription carries on, offered openly."
                railIcon={ShieldCheck}
                railTitle="Stop covert giving"
                railSub={record.medication.name}
                steps={[
                    {
                        key: 'why',
                        label: 'Why it is stopping',
                        blurb: 'Reason and what happened',
                        icon: ClipboardCheck,
                    },
                ]}
                stepIndex={0}
                onStepClick={() => undefined}
                pct={null}
                footerStart={
                    <Button variant="outline" onClick={close.close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() => setConfirm(true)}
                    >
                        Stop covert giving
                    </Button>
                }
            >
                <div className="grid gap-5">
                    <Errors errors={form.errors} />
                    <Field
                        id="covert-stop-reason"
                        label="Why it is stopping and what happened"
                    >
                        <Textarea
                            id="covert-stop-reason"
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </Field>
                    <Note>
                        The authorisation and this reason are kept. A new
                        authorisation is required before covert giving can
                        restart.
                    </Note>
                </div>
            </WizardShell>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                title={`Stop covert giving of ${record.medication.name}?`}
                description="From now on it can only be offered openly."
                confirmText="Stop covert giving"
                processing={form.processing}
                onConfirm={() =>
                    form.post(`/emar/order-covert/${record.id}/revoke`, {
                        preserveScroll: true,
                        onSuccess: onClose,
                    })
                }
            />
            {close.confirm}
        </>
    );
}
