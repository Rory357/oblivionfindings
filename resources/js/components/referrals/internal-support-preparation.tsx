import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { formatDateTimeLong } from '@/lib/datetime';
import axios from 'axios';
import { ClipboardCheck, ListTodo, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import { label } from './types';

type Options = {
    person: {
        id: number;
        name: string;
        status: string;
        fingerprint: string;
        site_id: number | null;
        service_context_id: number | null;
    };
    settings_version_id: number;
    team: string;
    services: { id: string; label: string; context_types?: string[] | null }[];
    sites: { id: number; name: string }[];
    contexts: {
        id: number;
        name: string;
        site_id: number | null;
        type: string;
    }[];
    existing: {
        reference: string;
        service: string;
        stage: string;
        url: string;
    }[];
    existing_more?: boolean;
};
const steps = [
    {
        key: 'support',
        label: 'Support to prepare',
        blurb: 'Existing person and service',
        icon: UserRound,
    },
    {
        key: 'next',
        label: 'Next action',
        blurb: 'Responsibility and due date',
        icon: ListTodo,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
        icon: ClipboardCheck,
    },
];

export function InternalSupportPreparation({
    personId,
    defaultSiteId,
    defaultContextId,
}: {
    personId: number;
    defaultSiteId?: number;
    defaultContextId?: number;
}) {
    const [open, setOpen] = useState(false);
    return (
        <div className="space-y-2">
            <p className="text-subtle">
                Review an existing referral or prepare a new service through
                assessment, funding assistance and independent start approval.
                Choose Prepare support to see this person’s existing cases
                first.
            </p>
            <Button
                variant="outline"
                className="frontline-tap"
                onClick={() => setOpen(true)}
            >
                Prepare support
            </Button>
            {open && (
                <PreparationDialog
                    personId={personId}
                    defaultSiteId={defaultSiteId}
                    defaultContextId={defaultContextId}
                    onClose={() => setOpen(false)}
                />
            )}
        </div>
    );
}

function PreparationDialog({
    personId,
    defaultSiteId,
    defaultContextId,
    onClose,
}: {
    personId: number;
    defaultSiteId?: number;
    defaultContextId?: number;
    onClose: () => void;
}) {
    const endpoint = `/operations/clients/${personId}/support-preparation`;
    const [options, setOptions] = useState<Options | null>(null);
    const [load, setLoad] = useState(0);
    const [loading, setLoading] = useState(true);
    const [step, setStep] = useState(0);
    const [site, setSite] = useState('');
    const [context, setContext] = useState('');
    const [service, setService] = useState('');
    const [team, setTeam] = useState('');
    const [reason, setReason] = useState('');
    const [next, setNext] = useState('');
    const [due, setDue] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [receipt, setReceipt] = useState<{ url: string } | null>(null);
    const inFlight = useRef(false);
    const intent = useRef<Record<string, unknown> | null>(null);
    const body = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const controller = new AbortController();
        setOptions(null);
        setLoading(true);
        setError('');
        axios
            .get<Options>(endpoint, { signal: controller.signal })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                setOptions(data);
                setSite(
                    defaultSiteId
                        ? String(defaultSiteId)
                        : data.person.site_id
                          ? String(data.person.site_id)
                          : '',
                );
                setContext(
                    defaultContextId
                        ? String(defaultContextId)
                        : data.person.service_context_id
                          ? String(data.person.service_context_id)
                          : '',
                );
                setTeam(data.team);
                setAcknowledged(false);
                setConflict(false);
                setStep(0);
                intent.current = null;
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Preparation could not be loaded. Check your access and the published settings, then try again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [endpoint, load, defaultSiteId, defaultContextId]);
    const allowedTypes = options?.services.find(
        (item) => item.id === service,
    )?.context_types;
    const contexts =
        options?.contexts.filter(
            (item) =>
                (item.site_id === null || String(item.site_id) === site) &&
                (allowedTypes == null || allowedTypes.includes(item.type)),
        ) ?? [];
    const go = (index: number) => {
        setStep(index);
        setError('');
        requestAnimationFrame(() =>
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus(),
        );
    };
    const validSupport = Boolean(
        options?.sites.some((item) => String(item.id) === site) &&
        contexts.some((item) => String(item.id) === context) &&
        options?.services.some((item) => item.id === service) &&
        team.trim().length >= 2 &&
        reason.trim().length >= 10,
    );
    const validAction =
        next.trim().length >= 5 &&
        due.length > 16 &&
        Number.isFinite(Date.parse(due)) &&
        Date.parse(due) > Date.now();
    const advance = () => {
        if ((step === 0 && !validSupport) || (step === 1 && !validAction)) {
            setError(
                step === 0
                    ? 'Choose a site, service and available setting. Add the responsible team and a reason of at least 10 characters.'
                    : 'Describe the next action and choose a future Auckland date and time.',
            );
            return;
        }
        go(step + 1);
    };
    const save = async () => {
        if (inFlight.current || !options || conflict) return;
        if (
            !intent.current &&
            (!validSupport || !validAction || !acknowledged)
        ) {
            setError(
                'Review the support details, future next action and confirmation before saving.',
            );
            return;
        }
        intent.current ??= {
            submission_key: crypto.randomUUID(),
            person_fingerprint: options.person.fingerprint,
            settings_version_id: options.settings_version_id,
            site_id: Number(site),
            service_context_id: Number(context),
            requested_service: service,
            team: team.trim(),
            reason: reason.trim(),
            next_action: next.trim(),
            next_due_at: due,
            acknowledge_review: acknowledged,
        };
        inFlight.current = true;
        setBusy(true);
        setError('');
        try {
            const { data } = await axios.post<{ url: string }>(
                endpoint,
                intent.current,
            );
            setReceipt(data);
            setUncertain(false);
        } catch (failure) {
            const status = axios.isAxiosError(failure)
                ? failure.response?.status
                : undefined;
            if (status === 403 || status === 404) {
                setOptions(null);
                setUncertain(false);
                intent.current = null;
                setError(
                    'Your access changed. Close this review and ask an authorised manager to check the person’s support cases.',
                );
            } else if (status === 409) {
                setUncertain(false);
                setConflict(true);
                setError(
                    'The person, settings or existing support case changed. Reload the current details and review existing cases before continuing.',
                );
            } else if (status === 422) {
                setUncertain(false);
                intent.current = null;
                setError(
                    'Some details are no longer valid. Check the service, setting, next-action date and confirmation before saving again.',
                );
            } else {
                setUncertain(true);
                setError(
                    'The save could not be confirmed. Retry this same request to recover its result without creating another case. The details are locked until the result is known.',
                );
            }
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    };
    const close = () => {
        if (inFlight.current) return;
        if (receipt || (!reason && !next && !uncertain)) onClose();
        else setDiscard(true);
    };
    const locked = busy || uncertain || conflict;
    return (
        <>
            <WizardShell
                open
                frontline
                onClose={close}
                title="Prepare support"
                description="Open an internal support case for an existing person."
                railIcon={UserRound}
                railTitle="Prepare support"
                railSub="You become the case owner. This does not start services."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!locked && options && index < step) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            step > 0 && !locked ? go(step - 1) : close()
                        }
                    >
                        {step > 0 && !locked ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    conflict ? (
                        <Button onClick={() => setLoad((value) => value + 1)}>
                            Reload current details
                        </Button>
                    ) : !options ? (
                        <Button
                            disabled={loading}
                            onClick={() => setLoad((value) => value + 1)}
                        >
                            {loading ? 'Loading…' : 'Try again'}
                        </Button>
                    ) : step < 2 ? (
                        <Button onClick={advance}>Continue</Button>
                    ) : (
                        <Button disabled={busy} onClick={() => void save()}>
                            {busy
                                ? 'Saving…'
                                : uncertain
                                  ? 'Retry same request'
                                  : 'Open support case'}
                        </Button>
                    )
                }
                success={
                    receipt ? (
                        <WizardSuccessPane
                            title="Support case opened"
                            blurb="The case is assigned to you. Review consent, assessment and funding before handover. The existing person is unchanged and services have not started."
                            actions={
                                <Button asChild>
                                    <a href={receipt.url}>Open support case</a>
                                </Button>
                            }
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
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    {loading && (
                        <p role="status">
                            Loading current person and service settings…
                        </p>
                    )}
                    {options && (
                        <fieldset disabled={locked} className="space-y-5">
                            {step === 0 && (
                                <>
                                    <ReviewCard
                                        title="Existing person"
                                        icon={UserRound}
                                    >
                                        <ReviewRow
                                            label="Person"
                                            value={options.person.name}
                                        />
                                        <ReviewRow
                                            label="Current status"
                                            value={label(options.person.status)}
                                        />
                                    </ReviewCard>
                                    <p className="text-subtle">
                                        This internal case stays linked to this
                                        person. It does not record a website
                                        referral, consent or contact that has
                                        not happened.
                                    </p>
                                    {options.existing.length > 0 && (
                                        <div className="space-y-2">
                                            <p className="font-medium">
                                                Check existing support first
                                            </p>
                                            <ul className="space-y-2">
                                                {options.existing.map(
                                                    (item) => (
                                                        <li
                                                            key={item.reference}
                                                        >
                                                            <Button
                                                                variant="link"
                                                                className="frontline-tap h-auto text-left whitespace-normal"
                                                                asChild
                                                            >
                                                                <a
                                                                    href={
                                                                        item.url
                                                                    }
                                                                >
                                                                    {label(
                                                                        item.service,
                                                                    )}{' '}
                                                                    ·{' '}
                                                                    {label(
                                                                        item.stage,
                                                                    )}{' '}
                                                                    ·{' '}
                                                                    {
                                                                        item.reference
                                                                    }
                                                                </a>
                                                            </Button>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                            {options.existing_more && (
                                                <p className="text-subtle">
                                                    Showing the 20 most recent
                                                    visible cases. Ask the
                                                    responsible manager to
                                                    review older support before
                                                    opening another case.
                                                </p>
                                            )}
                                        </div>
                                    )}
                                    <Field label="Site" required>
                                        <SelectInput
                                            ariaLabel="Site"
                                            value={site}
                                            onChange={(value) => {
                                                setSite(value);
                                                setContext('');
                                            }}
                                            options={options.sites.map(
                                                (item) => ({
                                                    value: String(item.id),
                                                    label: item.name,
                                                }),
                                            )}
                                            placeholder="Choose an approved site"
                                        />
                                    </Field>
                                    <Field label="Service" required>
                                        <SelectInput
                                            ariaLabel="Service"
                                            value={service}
                                            onChange={(next) => {
                                                setService(next);
                                                const types =
                                                    options.services.find(
                                                        (item) =>
                                                            item.id === next,
                                                    )?.context_types;
                                                const current =
                                                    options.contexts.find(
                                                        (item) =>
                                                            String(item.id) ===
                                                            context,
                                                    );
                                                if (
                                                    types != null &&
                                                    (!current ||
                                                        !types.includes(
                                                            current.type,
                                                        ))
                                                )
                                                    setContext('');
                                            }}
                                            options={options.services.map(
                                                (item) => ({
                                                    value: item.id,
                                                    label: item.label,
                                                }),
                                            )}
                                            placeholder="Choose support"
                                        />
                                    </Field>
                                    <Field label="Service setting" required>
                                        <SelectInput
                                            ariaLabel="Service setting"
                                            value={context}
                                            onChange={setContext}
                                            options={contexts.map((item) => ({
                                                value: String(item.id),
                                                label: item.name,
                                            }))}
                                            placeholder="Choose an available setting"
                                        />
                                    </Field>
                                    <Field label="Responsible team" required>
                                        <Input
                                            aria-label="Responsible team"
                                            value={team}
                                            maxLength={160}
                                            onChange={(event) =>
                                                setTeam(event.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Why is support being prepared?"
                                        required
                                    >
                                        <Textarea
                                            aria-label="Why is support being prepared?"
                                            value={reason}
                                            maxLength={2000}
                                            onChange={(event) =>
                                                setReason(event.target.value)
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <p className="text-subtle">
                                        You will own the case and this next
                                        action. You can assign an eligible
                                        colleague after opening it.
                                    </p>
                                    <Field label="Next action" required>
                                        <Textarea
                                            aria-label="Next action"
                                            value={next}
                                            maxLength={500}
                                            onChange={(event) =>
                                                setNext(event.target.value)
                                            }
                                        />
                                    </Field>
                                    <ReferralDateTime
                                        id="support-preparation-due"
                                        label="Next action due"
                                        value={due}
                                        onChange={setDue}
                                    />
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <ReviewCard
                                        title="Support preparation"
                                        icon={ClipboardCheck}
                                    >
                                        <ReviewRow
                                            label="Person"
                                            value={options.person.name}
                                        />
                                        <ReviewRow
                                            label="Site"
                                            value={
                                                options.sites.find(
                                                    (item) =>
                                                        String(item.id) ===
                                                        site,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow
                                            label="Service"
                                            value={
                                                options.services.find(
                                                    (item) =>
                                                        item.id === service,
                                                )?.label
                                            }
                                        />
                                        <ReviewRow
                                            label="Setting"
                                            value={
                                                contexts.find(
                                                    (item) =>
                                                        String(item.id) ===
                                                        context,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow label="Team" value={team} />
                                        <ReviewRow
                                            label="Reason"
                                            value={reason}
                                        />
                                        <ReviewRow
                                            label="Next action"
                                            value={next}
                                        />
                                        <ReviewRow
                                            label="Due"
                                            value={formatDateTimeLong(due)}
                                        />
                                    </ReviewCard>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="prepare-support-confirm"
                                            checked={acknowledged}
                                            onCheckedChange={(value) =>
                                                setAcknowledged(value === true)
                                            }
                                        />
                                        <Label htmlFor="prepare-support-confirm">
                                            I have checked existing cases.
                                            Consent, assessment, funding and an
                                            independent decision must be
                                            reviewed before support starts.
                                        </Label>
                                    </div>
                                </>
                            )}
                        </fieldset>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                variant="destructive"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Close support preparation?"
                description={
                    uncertain
                        ? 'The save result is unknown. Before opening another case, check existing support cases or retry this request.'
                        : 'Unsaved preparation details will be cleared.'
                }
                confirmText="Close preparation"
            />
        </>
    );
}
