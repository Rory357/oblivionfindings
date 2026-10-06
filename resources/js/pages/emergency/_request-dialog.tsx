import InputError from '@/components/input-error';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TilePicker } from '@/components/wizard/primitives';
import { formatDateOnly, formatDurationMinutes } from '@/lib/datetime';
import { emergencyDurationOptions } from '@/lib/emergency-access';
import { router } from '@inertiajs/react';
import {
    Check,
    Clock,
    KeyRound,
    Search,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type {
    Approver,
    ClientLite,
    EmergencyPolicy,
    OnCallContact,
} from './_types';
export type { Approver, ClientLite } from './_types';

const reasons = [
    'Nobody signed off is on shift',
    'Covering an absence',
    'Urgent dose or as-needed medicine',
    'New or after-hours admission',
    'Other',
];

export function RequestAccessDialog({
    results,
    query,
    approvers,
    policy,
    prefillClient,
    onCallContacts,
    onSearch,
    onClose,
}: {
    results: ClientLite[];
    query: string;
    approvers: Approver[];
    policy: EmergencyPolicy;
    prefillClient?: ClientLite | null;
    onCallContacts: Record<number, OnCallContact>;
    onSearch: (q: string) => void;
    onClose: () => void;
}) {
    const [step, setStep] = useState(prefillClient ? 1 : 0);
    const [client, setClient] = useState<ClientLite | null>(
        prefillClient ?? null,
    );
    const [category, setCategory] = useState('');
    const [reason, setReason] = useState('');
    const [minutes, setMinutes] = useState(
        Math.min(policy.default_minutes, policy.max_minutes),
    );
    const [selectedMode, setMode] = useState<'self' | 'co_sign'>(
        policy.second_person === 'required' ? 'co_sign' : 'self',
    );
    // Validation redirects can refresh policy while preserving this request draft.
    const mode =
        policy.second_person === 'required'
            ? 'co_sign'
            : policy.second_person === 'off'
              ? 'self'
              : selectedMode;
    const [colleague, setColleague] = useState<number | null>(null);
    const [pin, setPin] = useState('');
    const [ackMinimum, setAckMinimum] = useState(false);
    const [ackReview, setAckReview] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [search, setSearch] = useState(query);
    const [nobodyHere, setNobodyHere] = useState(false);
    const houseApprovers = approvers.filter(
        (a) => client?.site && a.site_ids.includes(client.site.id),
    );
    const eligible = houseApprovers.filter((a) => a.witness_pin === 'set');
    const contact = client?.site ? onCallContacts[client.site.id] : null;
    const firstStep = prefillClient ? 1 : 0;
    const name = client
        ? `${client.first_name} ${client.last_name}`
        : 'Choose a person';
    const valid = [
        !!client,
        !!category &&
            (reason.trim().length >= 5 ||
                (!policy.reason_required && reason.trim().length === 0)),
        minutes >= 5 && minutes <= policy.max_minutes,
        (policy.second_person !== 'required' && mode === 'self') ||
            (eligible.some((a) => a.id === colleague) && pin.length === 6),
        ackMinimum && ackReview,
    ];

    function close() {
        if (!busy) onClose();
    }
    function goBack(target: number) {
        if (busy) return;
        setAckMinimum(false);
        setAckReview(false);
        setStep(target);
    }

    function submit() {
        if (!client || !valid.every(Boolean) || busy) return;
        setBusy(true);
        setErrors({});
        router.post(
            `/clients/${client.id}/break-glass`,
            {
                reason,
                reason_category: category,
                minutes,
                authorization_mode: mode,
                co_signed_by: mode === 'co_sign' ? colleague : null,
                co_signer_pin: mode === 'co_sign' ? pin : null,
                acknowledged_min_necessary: ackMinimum,
                acknowledged_incident_report: ackReview,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (next) => {
                    setErrors(next);
                    setStep(
                        next.reason || next.reason_category
                            ? 1
                            : next.minutes
                              ? 2
                              : next.co_signed_by ||
                                  next.co_signer_pin ||
                                  next.authorization_mode
                                ? 3
                                : next.client_id && !prefillClient
                                  ? 0
                                  : 4,
                    );
                },
                onFinish: () => {
                    setBusy(false);
                    setPin('');
                },
            },
        );
    }

    const draftValues = JSON.stringify([
        client?.id,
        category,
        reason,
        minutes,
        mode,
        colleague,
        pin,
        ackMinimum,
        ackReview,
        nobodyHere,
    ]);
    const [draftBaseline] = useState(draftValues);
    return (
        <MedsWizardDialog
            formState={{
                isDirty: draftValues !== draftBaseline,
                processing: busy,
                errors: errors,
            }}
            open
            onClose={close}
            title="Start emergency access"
            description="Temporary access to one person’s medication record."
            railIcon={KeyRound}
            railTitle="Emergency access"
            railSubtitle={name}
            stepIndex={step}
            onStepClick={(i) => i >= firstStep && i < step && goBack(i)}
            steps={[
                {
                    key: 'person',
                    label: 'Choose the person',
                    blurb: 'One medication record',
                    icon: Search,
                },
                {
                    key: 'why',
                    label: 'Why it’s needed',
                    blurb: 'Explain the gap',
                    icon: ShieldCheck,
                },
                {
                    key: 'length',
                    label: 'How long',
                    blurb: 'It ends by itself',
                    icon: Clock,
                },
                {
                    key: 'second',
                    label: 'A second person',
                    blurb: 'Their own witness PIN',
                    icon: Users,
                },
                {
                    key: 'check',
                    label: 'Check and start',
                    blurb: 'What it covers',
                    icon: Check,
                },
            ]}
            footer={
                <>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        disabled={busy}
                        onClick={() =>
                            step > firstStep ? goBack(step - 1) : close()
                        }
                    >
                        {step > firstStep ? 'Back' : 'Cancel'}
                    </Button>
                    <Button
                        className="frontline-tap"
                        disabled={busy || !valid[step]}
                        onClick={() =>
                            step < 4 ? setStep(step + 1) : submit()
                        }
                    >
                        {busy
                            ? 'Starting…'
                            : step === 4
                              ? 'Start emergency access'
                              : 'Next'}
                    </Button>
                </>
            }
        >
            <div className="space-y-5">
                <h2 className="text-section-title">
                    {
                        [
                            'Choose the person',
                            'Why it’s needed',
                            'How long do you need?',
                            'A second person',
                            'Check and start',
                        ][step]
                    }
                </h2>
                {step === 0 && (
                    <>
                        <Label htmlFor="emergency-person-search">
                            Find someone at your houses
                        </Label>
                        <Input
                            id="emergency-person-search"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                if (e.target.value.trim().length >= 2)
                                    onSearch(e.target.value);
                            }}
                            placeholder="Type at least two letters"
                        />
                        {results.map((person) => (
                            <Button
                                key={person.id}
                                variant={
                                    client?.id === person.id
                                        ? 'default'
                                        : 'outline'
                                }
                                className="frontline-tap h-auto w-full justify-start py-3 whitespace-normal"
                                onClick={() => {
                                    setClient(person);
                                    setColleague(null);
                                    setPin('');
                                    setNobodyHere(false);
                                    setAckMinimum(false);
                                    setAckReview(false);
                                }}
                            >
                                {person.first_name} {person.last_name} ·{' '}
                                {person.site?.name}
                                {person.date_of_birth
                                    ? ` · Born ${formatDateOnly(person.date_of_birth)}`
                                    : ''}
                            </Button>
                        ))}
                        <InputError message={errors.client_id} />
                    </>
                )}
                {step === 1 && (
                    <>
                        <Label id="emergency-reason-label">
                            What has happened?
                        </Label>
                        <TilePicker
                            value={category}
                            onChange={setCategory}
                            labelledBy="emergency-reason-label"
                            options={reasons.map((r) => ({ key: r, label: r }))}
                            frontline
                        />
                        <Label htmlFor="emergency-reason">
                            Explain why it’s needed{' '}
                            {policy.reason_required
                                ? '(required)'
                                : '(optional)'}
                        </Label>
                        <Textarea
                            id="emergency-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            rows={3}
                        />
                        <InputError message={errors.reason} />
                    </>
                )}
                {step === 2 && (
                    <>
                        <Label id="emergency-length-label">
                            It ends by itself after
                        </Label>
                        <TilePicker
                            value={String(minutes)}
                            onChange={(v) => setMinutes(Number(v))}
                            labelledBy="emergency-length-label"
                            frontline
                            options={emergencyDurationOptions(policy).map(
                                (m) => ({
                                    key: String(m),
                                    label: formatDurationMinutes(m),
                                }),
                            )}
                        />
                        <p className="text-caption">
                            Including extensions, never longer than{' '}
                            {formatDurationMinutes(policy.max_minutes)} from
                            when it starts.
                        </p>
                        <InputError message={errors.minutes} />
                    </>
                )}
                {step === 3 && (
                    <>
                        {policy.second_person === 'off' ? (
                            <p>
                                The policy does not ask for a second person.
                                Every use is still independently reviewed.
                            </p>
                        ) : (
                            <>
                                {policy.second_person === 'optional' && (
                                    <TilePicker
                                        value={mode}
                                        onChange={(v) => {
                                            setMode(v as 'self' | 'co_sign');
                                            setPin('');
                                        }}
                                        frontline
                                        options={[
                                            {
                                                key: 'self',
                                                label: 'Start without a second person',
                                                description:
                                                    'The current policy makes this optional',
                                            },
                                            {
                                                key: 'co_sign',
                                                label: 'A colleague confirms',
                                                description:
                                                    'They type their own witness PIN',
                                            },
                                        ]}
                                    />
                                )}
                                {mode === 'co_sign' && (
                                    <>
                                        <Command className="rounded-lg border">
                                            <CommandInput placeholder="Find a colleague" />
                                            <CommandList>
                                                <CommandEmpty>
                                                    Nobody can confirm at this
                                                    house.
                                                </CommandEmpty>
                                                {houseApprovers.map((a) => (
                                                    <CommandItem
                                                        key={a.id}
                                                        value={a.name}
                                                        disabled={
                                                            a.witness_pin !==
                                                            'set'
                                                        }
                                                        onSelect={() => {
                                                            setColleague(a.id);
                                                            setPin('');
                                                            setNobodyHere(
                                                                false,
                                                            );
                                                        }}
                                                    >
                                                        {colleague === a.id && (
                                                            <Check className="size-4" />
                                                        )}{' '}
                                                        {a.name}
                                                        {a.witness_pin !== 'set'
                                                            ? ' — PIN unavailable'
                                                            : ''}
                                                    </CommandItem>
                                                ))}
                                            </CommandList>
                                        </Command>
                                        <WitnessPinInput
                                            value={pin}
                                            onChange={setPin}
                                            label={`${approvers.find((a) => a.id === colleague)?.name ?? 'Their'} witness PIN`}
                                            error={errors.co_signer_pin}
                                        />
                                        <InputError
                                            message={errors.co_signed_by}
                                        />
                                    </>
                                )}
                                {policy.second_person === 'required' && (
                                    <>
                                        {eligible.length > 0 && (
                                            <Button
                                                variant="outline"
                                                className="frontline-tap"
                                                onClick={() => {
                                                    setNobodyHere(true);
                                                    setColleague(null);
                                                    setPin('');
                                                }}
                                            >
                                                Nobody who can confirm is here
                                            </Button>
                                        )}
                                        {(eligible.length === 0 ||
                                            nobodyHere) && (
                                            <Alert>
                                                <AlertTitle>
                                                    A second person is required
                                                </AlertTitle>
                                                <AlertDescription className="space-y-2">
                                                    <p>
                                                        Emergency access can’t
                                                        start until a colleague
                                                        confirms with their own
                                                        witness PIN.
                                                    </p>
                                                    {contact?.name ? (
                                                        <p>
                                                            Call {contact.name}
                                                            {contact.phone ? (
                                                                <>
                                                                    {' '}
                                                                    on{' '}
                                                                    <a
                                                                        className="underline"
                                                                        href={`tel:${contact.phone}`}
                                                                    >
                                                                        {
                                                                            contact.phone
                                                                        }
                                                                    </a>
                                                                </>
                                                            ) : (
                                                                ' — no work phone is recorded'
                                                            )}
                                                            .
                                                            {contact.how && (
                                                                <>
                                                                    {' '}
                                                                    {
                                                                        contact.how
                                                                    }
                                                                    .
                                                                </>
                                                            )}
                                                        </p>
                                                    ) : (
                                                        <p>
                                                            {contact?.warning ??
                                                                'No on-call contact is set for this house. Ask the house lead to arrange an eligible colleague.'}
                                                        </p>
                                                    )}
                                                    <p>
                                                        Required confirmation
                                                        cannot be skipped.
                                                    </p>
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                    </>
                                )}
                            </>
                        )}
                    </>
                )}
                {step !== 4 &&
                    Object.values(errors).map((error) => (
                        <InputError key={error} message={error} />
                    ))}
                {step === 4 && (
                    <>
                        <SummaryRow label="Person" value={name} />
                        <SummaryRow
                            label="House"
                            value={client?.site?.name ?? 'Not recorded'}
                        />
                        {client?.date_of_birth && (
                            <SummaryRow
                                label="Date of birth"
                                value={formatDateOnly(client.date_of_birth)}
                            />
                        )}
                        <SummaryRow
                            label="Reason"
                            value={`${category} — ${reason}`}
                        />
                        <SummaryRow
                            label="Length"
                            value={formatDurationMinutes(minutes)}
                        />
                        <SummaryRow
                            label="Second person"
                            value={
                                mode === 'co_sign'
                                    ? (approvers.find((a) => a.id === colleague)
                                          ?.name ?? 'Choose a colleague')
                                    : 'None'
                            }
                        />
                        <Alert>
                            <AlertTitle>
                                Only {name}’s medication record
                            </AlertTitle>
                            <AlertDescription>
                                Emergency access does not grant prescribing
                                authority or skip competency, safety checks,
                                required readings, or a dose’s second person.
                            </AlertDescription>
                        </Alert>
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="emergency-ack-minimum"
                                checked={ackMinimum}
                                onCheckedChange={(v) =>
                                    setAckMinimum(v === true)
                                }
                            />
                            <Label
                                htmlFor="emergency-ack-minimum"
                                className="frontline-tap"
                            >
                                I’ll do only what’s needed for this person’s
                                medication care.
                            </Label>
                        </div>
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="emergency-ack-review"
                                checked={ackReview}
                                onCheckedChange={(v) =>
                                    setAckReview(v === true)
                                }
                            />
                            <Label
                                htmlFor="emergency-ack-review"
                                className="frontline-tap"
                            >
                                I understand that someone else reviews this use
                                after it ends.
                            </Label>
                        </div>
                        {Object.values(errors).map((error) => (
                            <InputError key={error} message={error} />
                        ))}
                    </>
                )}
            </div>
        </MedsWizardDialog>
    );
}
