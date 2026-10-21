import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { toDatetimeLocal } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, UserRound, Wallet } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import type { FundingCase } from './funding-types';
import { referralHref, type Options, type ReferralDetail } from './types';

export function FundingOpenDialog({
    referral,
    options,
    previous,
    onClose,
}: {
    referral: ReferralDetail;
    options: Options;
    previous?: FundingCase;
    onClose: () => void;
}) {
    const [pathway, setPathway] = useState(previous?.pathway ?? '');
    const guide = options.pathways.find((item) => item.id === pathway);
    const [service, setService] = useState(
        previous?.details.service_id ?? referral.requested_service,
    );
    const [starting, setStarting] = useState('');
    const [assistance, setAssistance] = useState('');
    const [reviewPlan, setReviewPlan] = useState('');
    const [explained, setExplained] = useState(false);
    const [navigator, setNavigator] = useState('');
    const [query, setQuery] = useState('');
    const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
    const [staffError, setStaffError] = useState('');
    const [loading, setLoading] = useState(true);
    const [next, setNext] = useState('Agree the next funding assistance steps');
    const [due, setDue] = useState(toDatetimeLocal(Date.now() + 86400000));
    const [version, setVersion] = useState(referral.version);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [discard, setDiscard] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const pending = useRef(false);
    const steps = [
        {
            key: 'pathway',
            label: 'Pathway and assistance',
            blurb: 'Agree what help is wanted',
            icon: Wallet,
        },
        {
            key: 'owner',
            label: 'Navigator and next action',
            blurb: 'Keep assistance accountable',
            icon: UserRound,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Open the funding case',
            icon: ClipboardCheck,
        },
    ];
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            axios
                .get<{ staff: { id: number; name: string }[] }>(
                    '/operations/referrals/staff-options',
                    {
                        params: {
                            reference: referral.reference,
                            site_id: referral.site_id ?? '',
                            purpose: 'funding',
                            audience: 'funding',
                            q: query,
                        },
                        signal: controller.signal,
                    },
                )
                .then((response) => {
                    setStaff(response.data.staff);
                    setStaffError('');
                })
                .catch((caught) => {
                    if (!axios.isCancel(caught))
                        setStaffError(
                            'Eligible navigators could not be loaded. Check access or try another search.',
                        );
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, referral.reference, referral.site_id]);
    function go(index: number) {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function valid(index: number) {
        const message =
            index === 0 &&
            (!guide ||
                (!guide.personal_income &&
                    !guide.service_ids.includes(service)) ||
                starting.trim().length < 5 ||
                assistance.trim().length < 5 ||
                reviewPlan.trim().length < 5 ||
                !explained)
                ? 'Choose a compatible pathway and service, describe the assistance and review plan, and confirm the provider’s role was explained.'
                : index === 1 && (!navigator || !next.trim() || !due)
                  ? 'Choose an eligible navigator and a dated next action.'
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
            const result = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/funding`,
                {
                    version,
                    pathway,
                    navigator_id: Number(navigator),
                    previous_case_id: previous?.id ?? null,
                    service_id: guide?.personal_income ? null : service,
                    starting_point: starting,
                    assistance_requested: assistance,
                    person_review_plan: reviewPlan,
                    provider_role_explained: explained,
                    next_action: next,
                    next_due_at: due,
                },
            );
            if (!result.data.saved) throw new Error('Save not confirmed');
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
                          'Save was not confirmed. Reload and check the funding cases before trying again.'),
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
                    'Latest cases loaded below. If this case was already opened, close the draft. Otherwise review the current record before saving.',
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
                title={
                    previous
                        ? 'Open a linked funding review'
                        : 'Open funding assistance'
                }
                description={`Agree funding assistance for ${referral.name}`}
                railIcon={Wallet}
                railTitle="Funding assistance"
                railSub="Navigation does not guarantee funding or commit the person to this provider."
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
                            {busy ? 'Opening…' : 'Open funding case'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Funding case opened"
                            blurb="The named navigator and funding follow-up are saved. No application has been sent and no funding has been approved."
                            actions={
                                <Button onClick={onClose}>
                                    View funding cases
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
                            {previous && (
                                <Alert variant="info">
                                    <AlertDescription>
                                        The earlier case, decision and funding
                                        verification remain unchanged. This
                                        linked attempt starts with new
                                        preparation.
                                    </AlertDescription>
                                </Alert>
                            )}
                            <Field label="Funding pathway">
                                <SelectInput
                                    value={pathway}
                                    placeholder="Choose pathway"
                                    options={options.pathways
                                        .filter(
                                            (item) =>
                                                !previous ||
                                                item.id === previous.pathway,
                                        )
                                        .map((item) => ({
                                            value: item.id,
                                            label: item.label,
                                        }))}
                                    onChange={(value) => {
                                        setPathway(value);
                                        const selected = options.pathways.find(
                                            (item) => item.id === value,
                                        );
                                        setService(
                                            selected?.service_ids.includes(
                                                referral.requested_service,
                                            )
                                                ? referral.requested_service
                                                : (selected?.service_ids[0] ??
                                                      ''),
                                        );
                                    }}
                                />
                            </Field>
                            {guide?.personal_income ? (
                                <Alert variant="info">
                                    <AlertDescription>
                                        Personal income assistance is tracked
                                        separately. It cannot fund provider
                                        service lines or claims.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <Field label="Requested service">
                                    <SelectInput
                                        value={service}
                                        onChange={setService}
                                        placeholder="Choose compatible service"
                                        options={options.services
                                            .filter((item) =>
                                                guide?.service_ids.includes(
                                                    item.id,
                                                ),
                                            )
                                            .map((item) => ({
                                                value: item.id,
                                                label: item.label,
                                            }))}
                                    />
                                </Field>
                            )}
                            <Field label="Starting point — include what is still unknown">
                                <Textarea
                                    value={starting}
                                    onChange={(event) =>
                                        setStarting(event.target.value)
                                    }
                                    maxLength={4000}
                                />
                            </Field>
                            <Field label="Help the person has asked for">
                                <Textarea
                                    value={assistance}
                                    onChange={(event) =>
                                        setAssistance(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                            <Field label="How the person will review information before submission">
                                <Textarea
                                    value={reviewPlan}
                                    onChange={(event) =>
                                        setReviewPlan(event.target.value)
                                    }
                                    maxLength={2000}
                                />
                            </Field>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="funding-provider-role"
                                    checked={explained}
                                    onCheckedChange={(value) =>
                                        setExplained(value === true)
                                    }
                                />
                                <Label
                                    htmlFor="funding-provider-role"
                                    className="flex items-center"
                                >
                                    The person understands our role as a
                                    potential support provider and their freedom
                                    to choose
                                </Label>
                            </div>
                            {guide?.source_url && (
                                <a
                                    href={guide.source_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    Open the configured pathway reference
                                </a>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <Field label="Find eligible funding staff">
                                <Input
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Search by name"
                                />
                            </Field>
                            <Field label="Named navigator" error={staffError}>
                                <SelectInput
                                    value={navigator}
                                    onChange={setNavigator}
                                    placeholder={
                                        loading
                                            ? 'Loading eligible staff…'
                                            : 'Choose navigator'
                                    }
                                    options={staff.map((item) => ({
                                        value: String(item.id),
                                        label: item.name,
                                    }))}
                                />
                            </Field>
                            <Field label="Next funding action">
                                <Input
                                    value={next}
                                    onChange={(event) =>
                                        setNext(event.target.value)
                                    }
                                    maxLength={500}
                                />
                            </Field>
                            <ReferralDateTime
                                id="funding-first-due"
                                label="Next action due"
                                value={due}
                                onChange={setDue}
                            />
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <ReviewCard
                                icon={Wallet}
                                title="Funding assistance"
                                onEdit={() => go(0)}
                            >
                                <ReviewRow
                                    label="Pathway"
                                    value={guide?.label}
                                />
                                <ReviewRow
                                    label="Service"
                                    value={
                                        guide?.personal_income
                                            ? 'Personal income assistance only'
                                            : options.services.find(
                                                  (item) => item.id === service,
                                              )?.label
                                    }
                                />
                                <ReviewRow
                                    label="Starting point"
                                    value={starting}
                                />
                                <ReviewRow
                                    label="Requested assistance"
                                    value={assistance}
                                />
                                <ReviewRow
                                    label="Person’s review plan"
                                    value={reviewPlan}
                                />
                                <ReviewRow
                                    label="Navigator"
                                    value={
                                        staff.find(
                                            (item) =>
                                                String(item.id) === navigator,
                                        )?.name ?? 'Selected staff member'
                                    }
                                />
                                <ReviewRow label="Next action" value={next} />
                                <ReviewRow
                                    label="Due (NZ time)"
                                    value={due.replace('T', ' ')}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Existing cases on this referral"
                            >
                                {referral.funding?.cases.data.map((item) => (
                                    <ReviewRow
                                        key={item.id}
                                        label={item.pathway_label}
                                        value={`${item.navigator_name ?? 'Navigator unassigned'} · ${item.details.starting_point}`}
                                    />
                                ))}
                                {!referral.funding?.cases.total && (
                                    <p className="text-subtle">
                                        No funding cases recorded.
                                    </p>
                                )}
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
                title="Discard funding draft?"
                description="Unsaved answers will be cleared. If a save was not confirmed, check existing funding cases before opening another."
                confirmText="Discard draft"
            />
        </>
    );
}
