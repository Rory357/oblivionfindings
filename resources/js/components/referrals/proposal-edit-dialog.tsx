import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    ClipboardList,
    Plus,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { FundingCase } from './funding-types';
import type {
    ProposalAllocation,
    ProposalData,
    ProposalLine,
    SupportProposal,
} from './proposal-types';
import {
    label,
    referralHref,
    type Options,
    type ReferralDetail,
} from './types';

const choices = (values: string[]) =>
    values.map((value) => ({ value, label: label(value) }));
export function ProposalEditDialog({
    referral,
    current,
    taxes,
    options,
    onClose,
}: {
    referral: ReferralDetail;
    current: SupportProposal | null;
    taxes: ProposalData['tax_rates'];
    options: Options;
    onClose: () => void;
}) {
    const [terms, setTerms] = useState({
        title: current?.terms.title ?? 'Supported living proposal',
        starts_on: current?.terms.starts_on ?? toDateInput(Date.now()),
        ends_on: current?.terms.ends_on ?? '',
        support_rationale: current?.terms.support_rationale ?? '',
        funding_position: current?.terms.funding_position ?? '',
        capacity_plan: current?.terms.capacity_plan ?? '',
        person_participation: current?.terms.person_participation ?? '',
        reason: '',
    });
    const makeLine = (): ProposalLine => ({
        key: crypto.randomUUID(),
        service_id: referral.requested_service,
        description: '',
        quantity: null,
        unit: 'hours',
        unit_label: null,
        period: 'week',
        currency: 'NZD',
        unit_rate: null,
        tax_rate_id: null,
        price_basis: 'unknown',
        price_source: '',
    });
    const [lines, setLines] = useState<ProposalLine[]>(
        current?.terms.lines ?? [makeLine()],
    );
    const [allocations, setAllocations] = useState<ProposalAllocation[]>(
        current?.terms.allocations ?? [],
    );
    const firstPage = referral.funding?.cases.current_page === 1;
    const [cases, setCases] = useState<FundingCase[]>(
        firstPage ? referral.funding!.cases.data : [],
    );
    const [casePage, setCasePage] = useState(firstPage ? 1 : 0);
    const [caseLastPage, setCaseLastPage] = useState(
        referral.funding?.cases.last_page ?? 1,
    );
    const [loadingCases, setLoadingCases] = useState(false);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const pending = useRef(false);
    const body = useRef<HTMLDivElement>(null);
    const steps = [
        {
            key: 'support',
            label: 'Support and dates',
            blurb: 'Person’s choice and capacity',
            icon: ClipboardList,
        },
        {
            key: 'pricing',
            label: 'Support lines and prices',
            blurb: 'Original units and known rates',
            icon: Wallet,
        },
        {
            key: 'coverage',
            label: 'Funding allocation',
            blurb: 'Identify sources and gaps',
            icon: Wallet,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Create a new proposal version',
            icon: ClipboardCheck,
        },
    ];
    const go = (index: number) => {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    const text = (
        title: string,
        value: string,
        setter: (value: string) => void,
        short = false,
        limit = 4000,
    ) => (
        <Field label={title}>
            {short ? (
                <Input
                    value={value}
                    onChange={(event) => setter(event.target.value)}
                    maxLength={limit}
                />
            ) : (
                <Textarea
                    value={value}
                    onChange={(event) => setter(event.target.value)}
                    maxLength={limit}
                />
            )}
        </Field>
    );
    const setLine = (index: number, update: Partial<ProposalLine>) =>
        setLines((rows) =>
            rows.map((line, position) =>
                position === index ? { ...line, ...update } : line,
            ),
        );
    const setAllocation = (
        index: number,
        update: Partial<ProposalAllocation>,
    ) =>
        setAllocations((rows) =>
            rows.map((row, position) =>
                position === index ? { ...row, ...update } : row,
            ),
        );
    const sources = cases
        .filter((entry) => !entry.personal_income)
        .flatMap((entry) =>
            (entry.latest_decision?.terms.lines ?? []).map((award, index) => ({
                key: `${entry.id}:${entry.latest_decision!.id}:${index}`,
                item: entry,
                award,
                index,
                label: `${entry.pathway_label} · ${entry.latest_decision!.terms.external_reference} · ${award.unit === 'money' ? award.currency : award.unit === 'other' ? award.unit_label : award.unit} / ${label(award.period)}${entry.availability.usable ? '' : ' · needs review'}`,
            })),
        );
    async function loadCases() {
        if (loadingCases) return;
        setLoadingCases(true);
        try {
            const response = await axios.get<{ referral: ReferralDetail }>(
                `${referralHref(referral.reference)}/data`,
                { params: { funding_page: casePage + 1 } },
            );
            const next = response.data.referral.funding?.cases;
            if (!next) throw new Error('Case access unavailable');
            setCases((currentCases) => [
                ...currentCases,
                ...next.data.filter(
                    (entry) =>
                        !currentCases.some((known) => known.id === entry.id),
                ),
            ]);
            setCasePage(next.current_page);
            setCaseLastPage(next.last_page);
        } catch {
            setError(
                'Funding sources could not be loaded. Check current access and try again.',
            );
        } finally {
            setLoadingCases(false);
        }
    }
    function valid(index: number) {
        let message = '';
        if (
            index === 0 &&
            (!terms.title.trim() ||
                !terms.starts_on ||
                !terms.ends_on ||
                terms.ends_on < terms.starts_on ||
                terms.support_rationale.trim().length < 5 ||
                terms.capacity_plan.trim().length < 5 ||
                terms.person_participation.trim().length < 5)
        )
            message =
                'Record the support, valid proposed dates, person’s participation and capacity plan.';
        if (
            index === 1 &&
            (!lines.length ||
                lines.some(
                    (line) =>
                        !line.service_id ||
                        line.description.trim().length < 3 ||
                        line.price_source.trim().length < 5 ||
                        (line.unit === 'other' && !line.unit_label),
                ))
        )
            message =
                'Give every line a service, description and price source. Name any other unit; leave unknown quantities or prices blank.';
        if (
            index === 2 &&
            (terms.funding_position.trim().length < 5 ||
                allocations.some(
                    (allocation) =>
                        !allocation.funding_case_id ||
                        !allocation.decision_id ||
                        !allocation.line_key ||
                        !allocation.quantity.trim() ||
                        !allocation.support_quantity.trim(),
                ))
        )
            message =
                'Describe the funding position and complete each selected allocation. You can save with no allocations while funding is being arranged.';
        if (index === 3 && terms.reason.trim().length < 5)
            message = 'Record why this proposal version is being prepared.';
        if (message) {
            setError(message);
            return false;
        }
        return true;
    }
    async function save() {
        if (pending.current || uncertain) return;
        for (const index of [0, 1, 2, 3])
            if (!valid(index)) {
                go(index);
                return;
            }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post<{ saved: boolean }>(
                `${referralHref(referral.reference)}/proposals`,
                {
                    version: referral.version,
                    previous_proposal_id: current?.id ?? null,
                    ...terms,
                    lines: lines.map((line) => ({
                        key: line.key,
                        service_id: line.service_id,
                        description: line.description,
                        quantity: line.quantity,
                        unit: line.unit,
                        unit_label: line.unit_label,
                        period: line.period,
                        currency: line.currency,
                        unit_rate: line.unit_rate,
                        tax_rate_id: line.tax_rate_id,
                        price_basis: line.price_basis,
                        price_source: line.price_source,
                    })),
                    allocations: allocations.map((allocation) => ({
                        line_key: allocation.line_key,
                        funding_case_id: allocation.funding_case_id,
                        decision_id: allocation.decision_id,
                        decision_line: allocation.decision_line,
                        quantity: allocation.quantity,
                        support_quantity: allocation.support_quantity,
                    })),
                },
            );
            if (!response.data.saved) throw new Error('Save not confirmed');
            setSaved(true);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const response = axios.isAxiosError(caught)
                ? caught.response
                : undefined;
            setUncertain(!response || response.status === 409);
            setError(
                response?.data?.errors
                    ? Object.values(
                          response.data.errors as Record<string, string[]>,
                      )
                          .flat()
                          .join(' ')
                    : (response?.data?.message ??
                          'Save was not confirmed. Review the latest proposal history before creating another version.'),
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
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
                    current
                        ? 'Revise support proposal'
                        : 'Prepare support proposal'
                }
                description={`${referral.name} · ${current ? `new version after ${current.version}` : 'first version'}`}
                railTitle="Support proposal"
                railIcon={ClipboardList}
                railSub="Saving creates a proposal, not a live agreement. A new version requires its own acceptance and independent approval."
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
                    step < 3 ? (
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
                            disabled={busy || uncertain}
                            onClick={() => void save()}
                        >
                            {busy ? 'Saving…' : 'Save proposal version'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Proposal version saved"
                            blurb="Estimates and coverage checks are now available. Acceptance and independent approval must be recorded against this version before agreement readiness can be confirmed."
                            actions={
                                <Button onClick={onClose}>
                                    Review proposal
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        data-heading
                        tabIndex={-1}
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {uncertain && (
                                    <p>
                                        Close this draft and review the current
                                        version and history before trying again.
                                        Earlier approval will not be copied to
                                        changed terms.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <>
                            {text(
                                'Proposal title',
                                terms.title,
                                (value) => setTerms({ ...terms, title: value }),
                                true,
                                200,
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <DatePicker
                                    id="proposal-start"
                                    label="Proposed start"
                                    value={terms.starts_on}
                                    onChange={(value) =>
                                        setTerms({ ...terms, starts_on: value })
                                    }
                                />
                                <DatePicker
                                    id="proposal-end"
                                    label="Proposed end"
                                    value={terms.ends_on}
                                    onChange={(value) =>
                                        setTerms({ ...terms, ends_on: value })
                                    }
                                />
                            </div>
                            {text(
                                'Support rationale and goals',
                                terms.support_rationale,
                                (value) =>
                                    setTerms({
                                        ...terms,
                                        support_rationale: value,
                                    }),
                            )}
                            {text(
                                'Person’s participation and choices',
                                terms.person_participation,
                                (value) =>
                                    setTerms({
                                        ...terms,
                                        person_participation: value,
                                    }),
                            )}
                            {text(
                                'Staffing and service capacity plan',
                                terms.capacity_plan,
                                (value) =>
                                    setTerms({
                                        ...terms,
                                        capacity_plan: value,
                                    }),
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <Alert variant="info">
                                <AlertDescription>
                                    Keep unknown values blank. Prices use the
                                    selected canonical finance tax treatment; no
                                    standard rate or universal GST assumption is
                                    inserted.
                                </AlertDescription>
                            </Alert>
                            {lines.map((line, index) => (
                                <div
                                    key={line.key}
                                    className="space-y-4 rounded-lg border p-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <h3 className="font-medium">
                                            Support line {index + 1}
                                        </h3>
                                        <Button
                                            variant="ghost"
                                            aria-label={`Remove support line ${index + 1}`}
                                            onClick={() => {
                                                setLines((rows) =>
                                                    rows.filter(
                                                        (row) =>
                                                            row.key !==
                                                            line.key,
                                                    ),
                                                );
                                                setAllocations((rows) =>
                                                    rows.filter(
                                                        (row) =>
                                                            row.line_key !==
                                                            line.key,
                                                    ),
                                                );
                                            }}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                    <Field label="Service">
                                        <SelectInput
                                            value={line.service_id}
                                            onChange={(value) =>
                                                setLine(index, {
                                                    service_id: value,
                                                })
                                            }
                                            placeholder="Choose offered service"
                                            options={options.services.map(
                                                (service) => ({
                                                    value: service.id,
                                                    label: service.label,
                                                }),
                                            )}
                                        />
                                    </Field>
                                    {text(
                                        'Description of support',
                                        line.description,
                                        (value) =>
                                            setLine(index, {
                                                description: value,
                                            }),
                                        true,
                                        500,
                                    )}
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {text(
                                            'Quantity per period',
                                            line.quantity ?? '',
                                            (value) =>
                                                setLine(index, {
                                                    quantity: value || null,
                                                }),
                                            true,
                                            15,
                                        )}
                                        <Field label="Support unit">
                                            <SelectInput
                                                value={line.unit}
                                                onChange={(value) =>
                                                    setLine(index, {
                                                        unit: value,
                                                    })
                                                }
                                                placeholder="Choose unit"
                                                options={choices([
                                                    'hours',
                                                    'days',
                                                    'visits',
                                                    'other',
                                                ])}
                                            />
                                        </Field>
                                        {line.unit === 'other' &&
                                            text(
                                                'Name of original unit',
                                                line.unit_label ?? '',
                                                (value) =>
                                                    setLine(index, {
                                                        unit_label:
                                                            value || null,
                                                    }),
                                                true,
                                                80,
                                            )}
                                        <Field label="Budget period">
                                            <SelectInput
                                                value={line.period}
                                                onChange={(value) =>
                                                    setLine(index, {
                                                        period: value,
                                                    })
                                                }
                                                placeholder="Choose period"
                                                options={choices([
                                                    'week',
                                                    'fortnight',
                                                    'month',
                                                    'year',
                                                    'total',
                                                ])}
                                            />
                                        </Field>
                                        {text(
                                            'Price per unit',
                                            line.unit_rate ?? '',
                                            (value) =>
                                                setLine(index, {
                                                    unit_rate: value || null,
                                                }),
                                            true,
                                            15,
                                        )}
                                        {text(
                                            'Currency',
                                            line.currency,
                                            (value) =>
                                                setLine(index, {
                                                    currency:
                                                        value.toUpperCase(),
                                                }),
                                            true,
                                            3,
                                        )}
                                        <Field label="Price basis">
                                            <SelectInput
                                                value={line.price_basis}
                                                onChange={(value) =>
                                                    setLine(index, {
                                                        price_basis: value,
                                                    })
                                                }
                                                placeholder="Choose basis"
                                                options={[
                                                    {
                                                        value: 'unknown',
                                                        label: 'Not yet reviewed',
                                                    },
                                                    {
                                                        value: 'exclusive',
                                                        label: 'Excluding tax',
                                                    },
                                                    {
                                                        value: 'inclusive',
                                                        label: 'Including tax',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        <Field label="Canonical tax treatment">
                                            {taxes.length === 0 && (
                                                <p className="text-subtle">
                                                    No active tax treatments are
                                                    available. Ask your finance
                                                    administrator to configure
                                                    the appropriate treatment.
                                                    You can save this proposal
                                                    with tax unresolved, but it
                                                    cannot be approved until
                                                    pricing is complete.
                                                </p>
                                            )}
                                            <SelectInput
                                                value={
                                                    line.tax_rate_id
                                                        ? String(
                                                              line.tax_rate_id,
                                                          )
                                                        : 'unknown'
                                                }
                                                onChange={(value) =>
                                                    setLine(index, {
                                                        tax_rate_id:
                                                            value === 'unknown'
                                                                ? null
                                                                : Number(value),
                                                    })
                                                }
                                                placeholder="Choose tax treatment"
                                                options={[
                                                    {
                                                        value: 'unknown',
                                                        label: 'Not yet reviewed',
                                                    },
                                                    ...taxes.map((tax) => ({
                                                        value: String(tax.id),
                                                        label: `${tax.name} · ${label(tax.type)}`,
                                                    })),
                                                ]}
                                            />
                                        </Field>
                                    </div>
                                    {text(
                                        'Price source / agreed rate reference',
                                        line.price_source,
                                        (value) =>
                                            setLine(index, {
                                                price_source: value,
                                            }),
                                        false,
                                        2000,
                                    )}
                                </div>
                            ))}
                            <Button
                                variant="outline"
                                disabled={lines.length >= 30}
                                onClick={() =>
                                    setLines((rows) => [...rows, makeLine()])
                                }
                            >
                                <Plus className="size-4" />
                                Add support line
                            </Button>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            {text(
                                'Funding position, gaps and next steps',
                                terms.funding_position,
                                (value) =>
                                    setTerms({
                                        ...terms,
                                        funding_position: value,
                                    }),
                            )}
                            <p className="text-subtle">
                                Link an original authorization line to the
                                support it covers. Funding units and periods
                                must match. Monetary coverage needs the explicit
                                recorded support price. Personal income cannot
                                be selected.
                            </p>
                            {casePage < caseLastPage && (
                                <Button
                                    variant="outline"
                                    disabled={loadingCases}
                                    onClick={() => void loadCases()}
                                >
                                    {loadingCases
                                        ? 'Loading sources…'
                                        : 'Load more funding sources'}
                                </Button>
                            )}
                            {allocations.map((allocation, index) => {
                                const sourceKey = `${allocation.funding_case_id}:${allocation.decision_id}:${allocation.decision_line}`;
                                const source = sources.find(
                                    (entry) => entry.key === sourceKey,
                                );
                                return (
                                    <div
                                        key={index}
                                        className="space-y-4 rounded-lg border p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <h3 className="font-medium">
                                                Allocation {index + 1}
                                            </h3>
                                            <Button
                                                variant="ghost"
                                                aria-label={`Remove allocation ${index + 1}`}
                                                onClick={() =>
                                                    setAllocations((rows) =>
                                                        rows.filter(
                                                            (_, position) =>
                                                                position !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                        <Field label="Support line">
                                            <SelectInput
                                                value={allocation.line_key}
                                                onChange={(value) =>
                                                    setAllocation(index, {
                                                        line_key: value,
                                                    })
                                                }
                                                placeholder="Choose support line"
                                                options={lines.map(
                                                    (line, position) => ({
                                                        value: line.key,
                                                        label:
                                                            line.description ||
                                                            `Support line ${position + 1}`,
                                                    }),
                                                )}
                                            />
                                        </Field>
                                        <Field label="Funding authorization line">
                                            <SelectInput
                                                value={
                                                    allocation.funding_case_id
                                                        ? sourceKey
                                                        : ''
                                                }
                                                onChange={(value) => {
                                                    const chosen = sources.find(
                                                        (entry) =>
                                                            entry.key === value,
                                                    );
                                                    if (chosen)
                                                        setAllocation(index, {
                                                            funding_case_id:
                                                                chosen.item.id,
                                                            decision_id:
                                                                chosen.item
                                                                    .latest_decision!
                                                                    .id,
                                                            decision_line:
                                                                chosen.index,
                                                        });
                                                }}
                                                placeholder="Choose source authorization"
                                                options={[
                                                    ...(!source &&
                                                    allocation.funding_case_id
                                                        ? [
                                                              {
                                                                  value: sourceKey,
                                                                  label: 'Previously linked authorization — review against current sources',
                                                              },
                                                          ]
                                                        : []),
                                                    ...sources.map((entry) => ({
                                                        value: entry.key,
                                                        label: entry.label,
                                                    })),
                                                ]}
                                            />
                                        </Field>
                                        {source && (
                                            <p className="text-subtle">
                                                Provider authorization:{' '}
                                                {source.award
                                                    .provider_quantity ??
                                                    'Unknown'}{' '}
                                                {source.award.unit === 'money'
                                                    ? source.award.currency
                                                    : source.award.unit}{' '}
                                                / {label(source.award.period)}
                                            </p>
                                        )}
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {text(
                                                'Original funding quantity allocated',
                                                allocation.quantity,
                                                (value) =>
                                                    setAllocation(index, {
                                                        quantity: value,
                                                    }),
                                                true,
                                                15,
                                            )}
                                            {text(
                                                'Quantity of support covered',
                                                allocation.support_quantity,
                                                (value) =>
                                                    setAllocation(index, {
                                                        support_quantity: value,
                                                    }),
                                                true,
                                                15,
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            <Button
                                variant="outline"
                                disabled={
                                    !lines.length || allocations.length >= 100
                                }
                                onClick={() =>
                                    setAllocations((rows) => [
                                        ...rows,
                                        {
                                            line_key: lines[0].key,
                                            funding_case_id: 0,
                                            decision_id: 0,
                                            decision_line: 0,
                                            quantity: '',
                                            support_quantity: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Allocate funding
                            </Button>
                        </>
                    )}
                    {step === 3 && (
                        <>
                            <ReviewCard
                                title="New proposal version"
                                icon={ClipboardCheck}
                            >
                                <ReviewRow label="Title" value={terms.title} />
                                <ReviewRow
                                    label="Version"
                                    value={String((current?.version ?? 0) + 1)}
                                />
                                <ReviewRow
                                    label="Support lines"
                                    value={String(lines.length)}
                                />
                                <ReviewRow
                                    label="Proposed start"
                                    value={formatDateOnly(terms.starts_on)}
                                />
                                <ReviewRow
                                    label="Proposed end"
                                    value={formatDateOnly(terms.ends_on)}
                                />
                                <ReviewRow
                                    label="Support rationale and goals"
                                    value={terms.support_rationale}
                                />
                                <ReviewRow
                                    label="Person’s participation and choices"
                                    value={terms.person_participation}
                                />
                                <ReviewRow
                                    label="Staffing and service capacity plan"
                                    value={terms.capacity_plan}
                                />
                                <ReviewRow
                                    label="Funding position"
                                    value={terms.funding_position}
                                />
                            </ReviewCard>
                            {lines.map((line, index) => (
                                <ReviewCard
                                    key={line.key}
                                    title={`Support line ${index + 1}`}
                                    icon={Wallet}
                                >
                                    <ReviewRow
                                        label="Support"
                                        value={line.description}
                                    />
                                    <ReviewRow
                                        label="Service"
                                        value={
                                            options.services.find(
                                                (service) =>
                                                    service.id ===
                                                    line.service_id,
                                            )?.label ??
                                            'Unavailable service — review required'
                                        }
                                    />
                                    <ReviewRow
                                        label="Quantity"
                                        value={`${line.quantity ?? 'Unknown'} ${line.unit === 'other' ? line.unit_label : line.unit} / ${label(line.period)}`}
                                    />
                                    <ReviewRow
                                        label="Price per unit"
                                        value={
                                            line.unit_rate === null
                                                ? 'Not yet costed'
                                                : `${line.currency} ${line.unit_rate}`
                                        }
                                    />
                                    <ReviewRow
                                        label="Price basis"
                                        value={
                                            line.price_basis === 'unknown'
                                                ? 'Not yet reviewed'
                                                : line.price_basis ===
                                                    'inclusive'
                                                  ? 'Including tax'
                                                  : 'Excluding tax'
                                        }
                                    />
                                    <ReviewRow
                                        label="Tax treatment"
                                        value={
                                            line.tax_rate_id
                                                ? (taxes.find(
                                                      (tax) =>
                                                          tax.id ===
                                                          line.tax_rate_id,
                                                  )?.name ??
                                                  'Unavailable treatment — review required')
                                                : 'Not yet reviewed'
                                        }
                                    />
                                    <ReviewRow
                                        label="Price source"
                                        value={line.price_source}
                                    />
                                </ReviewCard>
                            ))}
                            <ReviewCard
                                title="Funding allocations"
                                icon={Wallet}
                            >
                                {allocations.length === 0 ? (
                                    <p className="text-subtle">
                                        No funding allocated. Funding coverage
                                        still needs review.
                                    </p>
                                ) : (
                                    allocations.map((allocation, index) => {
                                        const line = lines.find(
                                            (entry) =>
                                                entry.key ===
                                                allocation.line_key,
                                        );
                                        const source = sources.find(
                                            (entry) =>
                                                entry.key ===
                                                `${allocation.funding_case_id}:${allocation.decision_id}:${allocation.decision_line}`,
                                        );
                                        return (
                                            <div
                                                key={index}
                                                className="space-y-1 border-b border-border pb-3 last:border-0 last:pb-0"
                                            >
                                                <ReviewRow
                                                    label={`Allocation ${index + 1}`}
                                                    value={
                                                        line?.description ??
                                                        'Unavailable support line — review required'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Source authorization"
                                                    value={
                                                        source?.label ??
                                                        'Source not loaded — review required'
                                                    }
                                                />
                                                <ReviewRow
                                                    label="Funding quantity"
                                                    value={`${allocation.quantity} ${source ? (source.award.unit === 'other' ? source.award.unit_label : source.award.unit) : 'original units'} / ${source ? label(source.award.period) : 'unknown period'}`}
                                                />
                                                <ReviewRow
                                                    label="Support covered"
                                                    value={`${allocation.support_quantity} ${line ? (line.unit === 'other' ? line.unit_label : line.unit) : 'support units'} / ${line ? label(line.period) : 'unknown period'}`}
                                                />
                                            </div>
                                        );
                                    })
                                )}
                            </ReviewCard>
                            {text(
                                'Reason for this proposal version',
                                terms.reason,
                                (value) =>
                                    setTerms({ ...terms, reason: value }),
                                false,
                                2000,
                            )}
                            <Alert variant="info">
                                <AlertDescription>
                                    Saving calculates the estimates and checks
                                    funding coverage. Review those results
                                    before recording acceptance or approval.
                                    Previous responses stay with the earlier
                                    version.
                                </AlertDescription>
                            </Alert>
                        </>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                variant="destructive"
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard proposal draft?"
                description="Unsaved terms will be cleared. If a save was not confirmed, review the proposal history before repeating it."
                confirmText="Discard draft"
            />
        </>
    );
}
