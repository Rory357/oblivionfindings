import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, Phone, UserRound } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    label,
    referralHref,
    type Options,
    type ReferralDetail,
} from './types';

export function IntakeCorrectionDialog({
    referral,
    options,
    onClose,
}: {
    referral: ReferralDetail;
    options: Options;
    onClose: () => void;
}) {
    const [person, setPerson] = useState({
        first_name: referral.identity.first_name ?? '',
        last_name: referral.identity.last_name ?? '',
        preferred_name: referral.identity.preferred_name ?? '',
        date_of_birth: referral.identity.date_of_birth ?? '',
    });
    const [contact, setContact] = useState({
        name: referral.contact.name ?? '',
        email: referral.contact.email ?? '',
        phone: referral.contact.phone ?? '',
        channel: referral.contact.channel ?? 'phone',
        safe_time: referral.contact.safe_time ?? '',
        leave_message: referral.contact.leave_message ?? false,
        communication: referral.contact.communication ?? '',
    });
    const [service, setService] = useState(referral.requested_service);
    const [goals, setGoals] = useState(referral.intake.goals ?? '');
    const [region, setRegion] = useState(referral.intake.region ?? '');
    const [promote, setPromote] = useState(false);
    const [reason, setReason] = useState('');
    const [version, setVersion] = useState(referral.version);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const pending = useRef(false);
    const minimal = referral.general_enquiry && !promote;
    const steps = [
        {
            key: 'person',
            label: 'Person and support',
            blurb: 'Correct or complete intake',
            icon: UserRound,
        },
        {
            key: 'contact',
            label: 'Safe contact',
            blurb: 'Preferences and reason',
            icon: Phone,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm the correction',
            icon: ClipboardCheck,
        },
    ];
    function go(index: number) {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function valid(index: number) {
        const message =
            index === 0 && !minimal && !person.first_name.trim()
                ? 'Record the person’s given name.'
                : index === 1 &&
                    (!contact.name.trim() ||
                        !contact[
                            contact.channel === 'email' ? 'email' : 'phone'
                        ].trim() ||
                        reason.trim().length < 5)
                  ? 'Record the contact name, preferred contact detail and reason for this correction.'
                  : '';
        if (message) {
            setError(message);
            return false;
        }
        return true;
    }
    async function save() {
        if (pending.current || conflict) return;
        for (const index of [0, 1])
            if (!valid(index)) {
                go(index);
                return;
            }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.patch<{ saved: boolean }>(
                `${referralHref(referral.reference)}/intake`,
                {
                    version,
                    person,
                    contact,
                    requested_service: service,
                    goals,
                    region,
                    convert_to_referral: promote,
                    reason,
                },
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? (caught.response?.status ?? null)
                : null;
            const response = axios.isAxiosError(caught)
                ? caught.response?.data
                : null;
            setConflict(status === 409 || status === null);
            setError(
                response?.errors
                    ? Object.values(response.errors as Record<string, string[]>)
                          .flat()
                          .join(' ')
                    : (response?.message ??
                          'Save was not confirmed. Reload the record and compare it with this draft before saving again.'),
            );
            if (status === 422 && response?.errors)
                go(
                    Object.keys(response.errors).some(
                        (key) =>
                            key.startsWith('person.') ||
                            key === 'requested_service',
                    )
                        ? 0
                        : 1,
                );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    function refresh() {
        router.reload({
            only: ['referral', 'options'],
            preserveScroll: true,
            onSuccess: (page) => {
                setVersion((page.props.referral as ReferralDetail).version);
                setConflict(false);
                setError(
                    'Current saved details loaded. Compare them with your draft before saving. Close the draft if the earlier save already succeeded.',
                );
                go(2);
            },
        });
    }
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) {
                        if (saved) onClose();
                        else setDiscard(true);
                    }
                }}
                title="Correct or complete intake"
                description={`Review intake information for ${referral.name}`}
                railIcon={UserRound}
                railTitle="Review intake details"
                railSub="Corrections retain the previous information and reason."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => (step ? go(step - 1) : setDiscard(true))}
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < 2 ? (
                        <Button
                            onClick={() => {
                                if (valid(step)) {
                                    setError('');
                                    go(step + 1);
                                }
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || conflict}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save correction'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Intake updated"
                            blurb="The correction is recorded with its history. Identity or service changes require a fresh readiness review."
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        className="text-section-title"
                        data-heading
                        tabIndex={-1}
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertTitle>Review needed</AlertTitle>
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <Button variant="outline" onClick={refresh}>
                                        Reload and keep draft
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <>
                            {referral.general_enquiry && (
                                <>
                                    <Alert variant="info">
                                        <AlertDescription>
                                            {referral.can_complete_intake
                                                ? 'Collection authority has been reviewed. You can now complete the person’s intake.'
                                                : 'Keep this enquiry minimal until a current collection authority review is recorded.'}
                                        </AlertDescription>
                                    </Alert>
                                    <div className="flex items-center gap-3">
                                        <Checkbox
                                            id="complete-enquiry"
                                            checked={promote}
                                            disabled={
                                                !referral.can_complete_intake
                                            }
                                            onCheckedChange={(value) => {
                                                setPromote(value === true);
                                                if (value !== true) {
                                                    setPerson({
                                                        first_name: '',
                                                        last_name: '',
                                                        preferred_name: '',
                                                        date_of_birth: '',
                                                    });
                                                    setGoals('');
                                                }
                                            }}
                                        />
                                        <Label
                                            htmlFor="complete-enquiry"
                                            className="flex items-center"
                                        >
                                            Complete this enquiry as a person
                                            referral
                                        </Label>
                                    </div>
                                </>
                            )}
                            {!minimal && (
                                <>
                                    {(
                                        [
                                            'first_name',
                                            'last_name',
                                            'preferred_name',
                                        ] as const
                                    ).map((key) => (
                                        <Field key={key} label={label(key)}>
                                            <Input
                                                value={person[key]}
                                                maxLength={120}
                                                onChange={(event) =>
                                                    setPerson((current) => ({
                                                        ...current,
                                                        [key]: event.target
                                                            .value,
                                                    }))
                                                }
                                            />
                                        </Field>
                                    ))}
                                    <Field label="Date of birth (if known)">
                                        <DatePicker
                                            id="referral-birth-date"
                                            label="Date of birth"
                                            value={person.date_of_birth}
                                            onChange={(value) =>
                                                setPerson((current) => ({
                                                    ...current,
                                                    date_of_birth: value,
                                                }))
                                            }
                                            allowClear
                                        />
                                    </Field>
                                    <Field label="Goals — general intake information">
                                        <Textarea
                                            value={goals}
                                            onChange={(event) =>
                                                setGoals(event.target.value)
                                            }
                                            maxLength={2000}
                                        />
                                    </Field>
                                </>
                            )}
                            <Field label="Requested service">
                                <SelectInput
                                    value={service}
                                    onChange={setService}
                                    placeholder="Select service"
                                    options={[
                                        ...options.services.map((item) => ({
                                            value: item.id,
                                            label: item.label,
                                        })),
                                        ...(!options.services.some(
                                            (item) =>
                                                item.id ===
                                                referral.requested_service,
                                        )
                                            ? [
                                                  {
                                                      value: referral.requested_service,
                                                      label:
                                                          label(
                                                              referral.requested_service,
                                                          ) +
                                                          ' (previous selection)',
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            </Field>
                            <Field label="Region">
                                <Input
                                    value={region}
                                    onChange={(event) =>
                                        setRegion(event.target.value)
                                    }
                                    maxLength={120}
                                />
                            </Field>
                        </>
                    )}
                    {step === 1 && (
                        <>
                            {(
                                [
                                    'name',
                                    'phone',
                                    'email',
                                    'safe_time',
                                    'communication',
                                ] as const
                            ).map((key) => (
                                <Field
                                    key={key}
                                    label={
                                        {
                                            name: 'Contact person',
                                            phone: 'Phone',
                                            email: 'Email',
                                            safe_time: 'Safe time to contact',
                                            communication:
                                                'Communication support',
                                        }[key]
                                    }
                                >
                                    <Input
                                        type={
                                            key === 'email' ? 'email' : 'text'
                                        }
                                        value={contact[key]}
                                        maxLength={
                                            {
                                                name: 160,
                                                phone: 50,
                                                email: 254,
                                                safe_time: 200,
                                                communication: 500,
                                            }[key]
                                        }
                                        onChange={(event) =>
                                            setContact((current) => ({
                                                ...current,
                                                [key]: event.target.value,
                                            }))
                                        }
                                    />
                                </Field>
                            ))}
                            <Field label="Preferred channel">
                                <SelectInput
                                    value={contact.channel}
                                    onChange={(value) =>
                                        setContact((current) => ({
                                            ...current,
                                            channel: value,
                                        }))
                                    }
                                    placeholder="Select channel"
                                    options={[
                                        { value: 'phone', label: 'Phone' },
                                        { value: 'email', label: 'Email' },
                                    ]}
                                />
                            </Field>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="correction-message"
                                    checked={contact.leave_message}
                                    onCheckedChange={(value) =>
                                        setContact((current) => ({
                                            ...current,
                                            leave_message: value === true,
                                        }))
                                    }
                                />
                                <Label
                                    htmlFor="correction-message"
                                    className="flex items-center"
                                >
                                    It is safe to leave a message
                                </Label>
                            </div>
                            <Field label="Reason and source for this correction">
                                <Textarea
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Proposed correction"
                                onEdit={() => go(0)}
                            >
                                <ReviewRow
                                    label="Person"
                                    value={
                                        [person.first_name, person.last_name]
                                            .filter(Boolean)
                                            .join(' ') ||
                                        'Minimal process enquiry'
                                    }
                                />
                                <ReviewRow
                                    label="Preferred name"
                                    value={person.preferred_name}
                                />
                                <ReviewRow
                                    label="Date of birth"
                                    value={person.date_of_birth || 'Unknown'}
                                />
                                <ReviewRow
                                    label="Requested service"
                                    value={
                                        options.services.find(
                                            (item) => item.id === service,
                                        )?.label ?? label(service)
                                    }
                                />
                                <ReviewRow label="Goals" value={goals} />
                                <ReviewRow label="Region" value={region} />
                                <ReviewRow
                                    label="Contact"
                                    value={`${contact.name} · ${contact.phone} · ${contact.email}`}
                                />
                                <ReviewRow
                                    label="Safe contact"
                                    value={`${contact.channel} · ${contact.safe_time || 'No time preference'} · ${contact.leave_message ? 'Message permitted' : 'No messages'}`}
                                />
                                <ReviewRow
                                    label="Communication support"
                                    value={contact.communication}
                                />
                                <ReviewRow
                                    label="Enquiry type after saving"
                                    value={
                                        promote
                                            ? 'Person referral — collection authority will be checked again'
                                            : referral.general_enquiry
                                              ? 'General enquiry — minimal information only'
                                              : 'Person referral'
                                    }
                                />
                                <ReviewRow label="Reason" value={reason} />
                            </ReviewCard>
                            <ReviewCard
                                icon={UserRound}
                                title="Current saved intake"
                            >
                                <ReviewRow
                                    label="Person"
                                    value={referral.name}
                                />
                                <ReviewRow
                                    label="Contact"
                                    value={`${referral.contact.name ?? ''} · ${referral.contact.phone ?? ''} · ${referral.contact.email ?? ''}`}
                                />
                                <ReviewRow
                                    label="Goals"
                                    value={referral.intake.goals}
                                />
                            </ReviewCard>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard correction draft?"
                description="Your unsaved corrections will be cleared. If a save was not confirmed, check the history before trying again."
                confirmText="Discard draft"
            />
        </>
    );
}
