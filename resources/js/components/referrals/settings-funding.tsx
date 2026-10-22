import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput, TilePicker } from '@/components/wizard/primitives';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    BadgeCheck,
    BookOpen,
    Landmark,
    ListChecks,
    Plus,
    ShieldCheck,
    Trash2,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import {
    editConfiguration,
    referenceFor,
    type ApprovalLimit,
    type Pathway,
    type PayerKind,
    type ReferralConfiguration,
    type SettingsAreaProps,
} from './settings-types';
import {
    AlwaysOn,
    ConfigDialog,
    GroupGrid,
    OnOff,
    OverviewGrid,
    SettingGroup,
    SettingRow,
    SwitchRow,
} from './settings-ui';

const periods = [
    { value: 'week', label: 'per week' },
    { value: 'fortnight', label: 'per fortnight' },
    { value: 'month', label: 'per month' },
    { value: 'year', label: 'per year' },
    { value: 'total', label: 'in total' },
];
const money = (cents: number) =>
    Number.isFinite(cents)
        ? `$${(cents / 100).toLocaleString('en-NZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        : 'Not set';
const emptyContact = {
    organisation: null,
    phone: null,
    email: null,
    website: null,
    region_notes: null,
};

/** Funding: pathway templates and guidance, conversion safeguards and approval delegation. */
export function FundingSettings({
    value,
    onChange,
    readOnly,
    settings,
    tab,
    jump,
}: SettingsAreaProps) {
    const [pathway, setPathway] = useState<number | 'new' | null>(null);
    const [limit, setLimit] = useState<number | 'new' | null>(null);
    const edit = (change: (copy: ReferralConfiguration) => void) =>
        editConfiguration(value, onChange, change);
    const limits = value.proposals.approval_limits ?? [];
    const available = value.pathways.filter((item) => item.enabled);
    const published = settings.latest
        ? settings.configuration.pathways.map((item) => item.id)
        : [];
    const roleName = (id: number | null) =>
        id === null
            ? 'Any authorised approver'
            : (settings.roles.find((role) => role.id === id)?.name ??
              'A role that no longer exists');
    const loosensTemporary =
        value.proposals.temporary_funding_enabled &&
        !settings.configuration.proposals.temporary_funding_enabled;

    if (tab === 'overview')
        return (
            <OverviewGrid
                title="Funding"
                caption="Pathway guidance supports staff. It never decides eligibility or guarantees funding."
                cards={[
                    {
                        key: 'pathways',
                        icon: Wallet,
                        title: 'Funding pathways',
                        rows: [
                            [
                                'Available',
                                `${available.length} of ${value.pathways.length}`,
                            ],
                            [
                                'With contact details',
                                `${value.pathways.filter((item) => item.contact?.organisation || item.contact?.phone).length}`,
                            ],
                            [
                                'Guidance review due',
                                `${value.pathways.filter((item) => item.review_on && item.review_on <= new Date().toISOString().slice(0, 10)).length}`,
                            ],
                        ],
                        cta: 'Review funding pathways',
                        onReview: () => jump('funding', 'configuration'),
                    },
                    {
                        key: 'safeguards',
                        icon: ShieldCheck,
                        title: 'Conversion and funding safeguards',
                        rows: [
                            [
                                'Temporary company funding',
                                value.proposals.temporary_funding_enabled
                                    ? 'On'
                                    : 'Off',
                            ],
                            ['External decision check', 'Always required'],
                            ['Personal income kept separate', 'Always'],
                        ],
                        cta: 'Review safeguards',
                        onReview: () => jump('funding', 'configuration'),
                    },
                    {
                        key: 'approvals',
                        icon: BadgeCheck,
                        title: 'Approval delegation',
                        rows: [
                            [
                                'General weekly limit',
                                `${money(value.proposals.approval_limit_cents)} NZD`,
                            ],
                            ['Delegations by role', `${limits.length}`],
                            ['Approver', 'A different person from the author'],
                        ],
                        cta: 'Review approval delegation',
                        onReview: () => jump('funding', 'configuration'),
                    },
                ]}
            />
        );

    return (
        <div className="space-y-5">
            <GroupGrid>
                <SettingGroup
                    icon={Wallet}
                    title="Pathway templates"
                    caption="Each new funding case takes a copy of its pathway’s preparation steps and guidance."
                    wide
                    actions={
                        readOnly ? undefined : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setPathway('new')}
                            >
                                <Plus className="size-4" />
                                Add pathway
                            </Button>
                        )
                    }
                >
                    {value.pathways.map((item, index) => (
                        <SettingRow
                            key={item.id || index}
                            label={item.label || 'New pathway'}
                            hint={[
                                item.personal_income
                                    ? 'Personal income help · can’t fund provider services'
                                    : `${item.evidence_checklist.length} preparation ${item.evidence_checklist.length === 1 ? 'step' : 'steps'}`,
                                item.payer_kind === 'private'
                                    ? 'Paid by the person or their family'
                                    : null,
                                item.contact?.organisation
                                    ? `Contact: ${item.contact.organisation}`
                                    : null,
                                item.review_on
                                    ? `Guidance review due ${formatDateOnly(item.review_on)}`
                                    : 'No guidance review date',
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                            control={
                                <span className="inline-flex items-center gap-3">
                                    <OnOff
                                        label={`Offer ${item.label}`}
                                        checked={item.enabled}
                                        disabled={readOnly}
                                        onChange={(enabled) =>
                                            edit((copy) => {
                                                copy.pathways[index].enabled =
                                                    enabled;
                                            })
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setPathway(index)}
                                    >
                                        {readOnly ? 'Review' : 'Configure'}
                                    </Button>
                                </span>
                            }
                        />
                    ))}
                </SettingGroup>
                <SettingGroup
                    icon={ShieldCheck}
                    title="Conversion and funding safeguards"
                    caption="Starting support still needs verified funding, approval, capacity and a safe start."
                >
                    <SwitchRow
                        label="Allow temporary company funding"
                        hint={
                            <>
                                Each proposal needs a named company payer,
                                budget reference, full-period cap, evidence and
                                independent approval.{' '}
                                {loosensTemporary && (
                                    <StatusBadge variant="critical" size="sm">
                                        Loosens a safeguard
                                    </StatusBadge>
                                )}
                            </>
                        }
                        checked={value.proposals.temporary_funding_enabled}
                        disabled={readOnly}
                        onChange={(enabled) =>
                            edit((copy) => {
                                copy.proposals.temporary_funding_enabled =
                                    enabled;
                            })
                        }
                    />
                    <SettingRow
                        label="External decision verification"
                        hint="A different authorised person checks the decision letter, scope and conditions."
                        control={<AlwaysOn>Always required</AlwaysOn>}
                    />
                    <SettingRow
                        label="Personal income kept separate"
                        hint="Benefits and income help never count as a provider service budget."
                        control={<AlwaysOn>Always required</AlwaysOn>}
                    />
                    <SettingRow
                        label="Service start checks"
                        hint="Verified funding, an approved proposal, capacity and a safe start before support begins."
                        control={<AlwaysOn>Always required</AlwaysOn>}
                    />
                </SettingGroup>
                <SettingGroup
                    icon={BadgeCheck}
                    title="Approval delegation"
                    caption="Approvers need the approval permission and must not be the proposal’s author. Currencies and periods are never converted."
                    actions={
                        readOnly ? undefined : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setLimit('new')}
                            >
                                <Plus className="size-4" />
                                Add delegation
                            </Button>
                        )
                    }
                >
                    <MoneyRow
                        label="General weekly limit (NZD)"
                        hint="Applies when no role-specific delegation matches."
                        cents={value.proposals.approval_limit_cents}
                        disabled={readOnly}
                        onChange={(cents) =>
                            edit((copy) => {
                                copy.proposals.approval_limit_cents = cents;
                            })
                        }
                    />
                    {limits.map((item, index) => (
                        <SettingRow
                            key={`${item.currency}-${item.period}-${item.role_id}-${index}`}
                            label={roleName(item.role_id)}
                            hint={`Up to ${money(item.limit_cents)} ${item.currency} ${periods.find((period) => period.value === item.period)?.label ?? item.period}`}
                            control={
                                readOnly ? undefined : (
                                    <span className="inline-flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setLimit(index)}
                                        >
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label={`Remove delegation for ${roleName(item.role_id)}`}
                                            onClick={() =>
                                                edit((copy) => {
                                                    copy.proposals.approval_limits!.splice(
                                                        index,
                                                        1,
                                                    );
                                                })
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </span>
                                )
                            }
                        />
                    ))}
                </SettingGroup>
            </GroupGrid>
            {pathway !== null && (
                <PathwayDialog
                    pathway={pathway === 'new' ? null : value.pathways[pathway]}
                    services={value.services}
                    taken={value.pathways.map((item) => item.id)}
                    published={published}
                    readOnly={readOnly}
                    onClose={() => setPathway(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            if (pathway === 'new') copy.pathways.push(next);
                            else copy.pathways[pathway] = next;
                        })
                    }
                />
            )}
            {limit !== null && (
                <ConfigDialog<ApprovalLimit & { amount: string }>
                    title={
                        limit === 'new' ? 'Add a delegation' : 'Edit delegation'
                    }
                    description="The approver role can approve proposals up to this amount, for this currency and period only."
                    initial={(() => {
                        const current =
                            limit === 'new'
                                ? {
                                      currency: 'NZD',
                                      period: 'week',
                                      role_id: null,
                                      limit_cents: 0,
                                  }
                                : limits[limit];
                        return {
                            ...current,
                            amount: (current.limit_cents / 100).toFixed(2),
                        };
                    })()}
                    width={480}
                    onClose={() => setLimit(null)}
                    validate={(draft) =>
                        !/^[A-Z]{3}$/.test(draft.currency)
                            ? 'Use a three-letter currency code, such as NZD.'
                            : !/^\d+(\.\d{0,2})?$/.test(draft.amount)
                              ? 'Enter an amount with no more than two decimal places.'
                              : null
                    }
                    onApply={({ amount, ...draft }) =>
                        edit((copy) => {
                            const next = {
                                ...draft,
                                limit_cents: Math.round(Number(amount) * 100),
                            };
                            copy.proposals.approval_limits ??= [];
                            if (limit === 'new')
                                copy.proposals.approval_limits.push(next);
                            else copy.proposals.approval_limits[limit] = next;
                        })
                    }
                >
                    {(draft, set) => (
                        <>
                            <Field label="Approver role">
                                <SelectInput
                                    ariaLabel="Approver role"
                                    placeholder="Choose an approver role"
                                    value={String(draft.role_id ?? 'any')}
                                    options={[
                                        {
                                            value: 'any',
                                            label: 'Any authorised approver',
                                        },
                                        ...settings.roles.map((role) => ({
                                            value: String(role.id),
                                            label: role.name,
                                        })),
                                    ]}
                                    onChange={(role) =>
                                        set({
                                            ...draft,
                                            role_id:
                                                role === 'any'
                                                    ? null
                                                    : Number(role),
                                        })
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Amount">
                                    <Input
                                        inputMode="decimal"
                                        value={draft.amount}
                                        onChange={(event) =>
                                            set({
                                                ...draft,
                                                amount: event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Currency">
                                    <Input
                                        value={draft.currency}
                                        maxLength={3}
                                        onChange={(event) =>
                                            set({
                                                ...draft,
                                                currency:
                                                    event.target.value.toUpperCase(),
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Period">
                                    <SelectInput
                                        ariaLabel="Period"
                                        placeholder="Choose a period"
                                        value={draft.period}
                                        options={periods}
                                        onChange={(period) =>
                                            set({ ...draft, period })
                                        }
                                    />
                                </Field>
                            </div>
                        </>
                    )}
                </ConfigDialog>
            )}
        </div>
    );
}

function MoneyRow({
    label,
    hint,
    cents,
    onChange,
    disabled,
}: {
    label: string;
    hint?: string;
    cents: number;
    onChange: (cents: number) => void;
    disabled?: boolean;
}) {
    const id = useId();
    const [text, setText] = useState(
        Number.isFinite(cents) ? (cents / 100).toFixed(2) : '',
    );
    const [focused, setFocused] = useState(false);
    useEffect(() => {
        if (!focused && Number.isFinite(cents))
            setText((cents / 100).toFixed(2));
    }, [cents, focused]);
    return (
        <SettingRow
            label={label}
            hint={hint}
            htmlFor={id}
            control={
                <span className="inline-flex items-center gap-2">
                    <span className="text-subtle">$</span>
                    <Input
                        id={id}
                        inputMode="decimal"
                        value={text}
                        disabled={disabled}
                        onFocus={() => setFocused(true)}
                        onBlur={() => setFocused(false)}
                        onChange={(event) => {
                            setText(event.target.value);
                            onChange(
                                /^\d+(\.\d{0,2})?$/.test(event.target.value)
                                    ? Math.round(
                                          Number(event.target.value) * 100,
                                      )
                                    : Number.NaN,
                            );
                        }}
                        className="h-9 w-32 tabular-nums"
                    />
                </span>
            }
        />
    );
}

const pathwaySteps = [
    {
        key: 'details',
        label: 'Pathway',
        blurb: 'Name, availability and services',
        icon: Wallet,
    },
    {
        key: 'steps',
        label: 'Preparation steps',
        blurb: 'What staff gather for each case',
        icon: ListChecks,
    },
    {
        key: 'guidance',
        label: 'Guidance and contacts',
        blurb: 'Official link, review dates and who to call',
        icon: BookOpen,
    },
] as const;

function PathwayDialog({
    pathway,
    services,
    taken,
    published,
    readOnly,
    onClose,
    onApply,
}: {
    pathway: Pathway | null;
    services: ReferralConfiguration['services'];
    taken: string[];
    published: string[];
    readOnly: boolean;
    onClose: () => void;
    onApply: (pathway: Pathway) => void;
}) {
    const [step, setStep] = useState(0);
    const [draft, setDraft] = useState<Pathway>(() =>
        structuredClone(
            pathway ?? {
                id: '',
                label: '',
                enabled: false,
                personal_income: false,
                service_ids: [],
                evidence_checklist: [],
                source_url: null,
                verified_on: null,
                review_on: null,
                contact: emptyContact,
                payer_kind: 'external',
            },
        ),
    );
    const [newStep, setNewStep] = useState('');
    const [error, setError] = useState('');
    const contact = { ...emptyContact, ...draft.contact };
    const set = (patch: Partial<Pathway>) => {
        setError('');
        setDraft((current) => ({ ...current, ...patch }));
    };
    const setContact = (patch: Partial<typeof contact>) =>
        set({ contact: { ...contact, ...patch } });
    const blank = (text: string) => (text.trim() ? text.trim() : null);
    function apply() {
        const problem = !draft.label.trim()
            ? 'Give the pathway a name.'
            : draft.source_url && !/^https:\/\//.test(draft.source_url.trim())
              ? 'Use an https:// link for the official guidance.'
              : draft.verified_on &&
                  draft.review_on &&
                  draft.review_on < draft.verified_on
                ? 'The review date can’t be before the date it was checked.'
                : contact.website && !/^https:\/\//.test(contact.website.trim())
                  ? 'Use an https:// link for the website.'
                  : contact.email &&
                      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact.email.trim())
                    ? 'Check the contact email address.'
                    : contact.phone &&
                        !/^[0-9+() -]{5,40}$/.test(contact.phone.trim())
                      ? 'Use only numbers, spaces, brackets and + in the phone number.'
                      : null;
        if (problem) {
            setError(problem);
            return;
        }
        const cleaned = {
            organisation: blank(contact.organisation ?? ''),
            phone: blank(contact.phone ?? ''),
            email: blank(contact.email ?? ''),
            website: blank(contact.website ?? ''),
            region_notes: blank(contact.region_notes ?? ''),
        };
        onApply({
            ...draft,
            label: draft.label.trim(),
            id: draft.id || referenceFor(draft.label, taken),
            service_ids: draft.personal_income ? [] : draft.service_ids,
            source_url: blank(draft.source_url ?? ''),
            contact: Object.values(cleaned).some(Boolean) ? cleaned : null,
        });
        onClose();
    }
    const current = pathwaySteps[step];
    return (
        <WizardShell
            open
            maxWidth="min(92vw, 1100px)"
            onClose={onClose}
            title={pathway ? pathway.label : 'Add a funding pathway'}
            description="Pathway guidance supports staff. It doesn’t decide eligibility or guarantee funding."
            railIcon={Wallet}
            railTitle={draft.label || 'New pathway'}
            railSub={
                pathway && published.includes(pathway.id)
                    ? 'Published pathway · renaming keeps its history'
                    : 'Not published yet'
            }
            steps={pathwaySteps}
            stepIndex={step}
            onStepClick={setStep}
            sequential={false}
            headerLabel={current.label}
            pct={null}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    {readOnly ? 'Close' : 'Cancel'}
                </Button>
            }
            footerEnd={
                <>
                    {step < pathwaySteps.length - 1 && (
                        <Button
                            variant="outline"
                            onClick={() => setStep(step + 1)}
                        >
                            Next
                        </Button>
                    )}
                    {!readOnly && (
                        <Button onClick={apply}>
                            {pathway ? 'Apply to draft' : 'Add pathway'}
                        </Button>
                    )}
                </>
            }
        >
            <WizardStepPane key={current.key}>
                <fieldset disabled={readOnly} className="space-y-4">
                    {error && <SettingsNotice>{error}</SettingsNotice>}
                    {current.key === 'details' && (
                        <>
                            <Field label="Pathway name" required>
                                <Input
                                    value={draft.label}
                                    maxLength={120}
                                    onChange={(event) =>
                                        set({ label: event.target.value })
                                    }
                                />
                            </Field>
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-[13px] font-medium">
                                    Available for new funding cases
                                </span>
                                <OnOff
                                    label="Available for new funding cases"
                                    checked={draft.enabled}
                                    disabled={readOnly}
                                    onChange={(enabled) => set({ enabled })}
                                />
                            </div>
                            <div className="space-y-2">
                                <p
                                    id="pathway-payer-label"
                                    className="text-[13px] font-medium"
                                >
                                    Who pays?
                                </p>
                                <p
                                    id="pathway-payer-hint"
                                    className="text-caption"
                                >
                                    Private arrangements skip funder
                                    verification and use the person’s agreement
                                    instead.
                                </p>
                                <TilePicker
                                    cols={2}
                                    labelledBy="pathway-payer-label"
                                    describedBy="pathway-payer-hint"
                                    value={draft.payer_kind ?? 'external'}
                                    onChange={(payer) =>
                                        !readOnly &&
                                        set({
                                            payer_kind: payer as PayerKind,
                                        })
                                    }
                                    options={[
                                        {
                                            key: 'external',
                                            label: 'External funder',
                                            description:
                                                'Disability Support Services, ACC, Health NZ…',
                                            icon: Landmark,
                                        },
                                        {
                                            key: 'private',
                                            label: 'The person or their family (private)',
                                            description:
                                                'Paid under the person’s own agreement',
                                            icon: Users,
                                        },
                                    ]}
                                />
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-[13px] font-medium">
                                    Personal income help (can’t fund provider
                                    services)
                                </span>
                                <OnOff
                                    label="Personal income help"
                                    checked={draft.personal_income}
                                    disabled={
                                        readOnly ||
                                        draft.id === 'personal_income'
                                    }
                                    onChange={(personal_income) =>
                                        set({ personal_income })
                                    }
                                />
                            </div>
                            {!draft.personal_income && (
                                <fieldset className="space-y-2">
                                    <legend className="text-[13px] font-medium">
                                        Services it can pay for
                                    </legend>
                                    {services.map((service) => (
                                        <label
                                            key={service.id}
                                            className="text-subtle flex items-center gap-2"
                                        >
                                            <Checkbox
                                                checked={draft.service_ids.includes(
                                                    service.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    set({
                                                        service_ids:
                                                            checked === true
                                                                ? [
                                                                      ...draft.service_ids,
                                                                      service.id,
                                                                  ]
                                                                : draft.service_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          service.id,
                                                                  ),
                                                    })
                                                }
                                            />
                                            {service.label}
                                        </label>
                                    ))}
                                </fieldset>
                            )}
                        </>
                    )}
                    {current.key === 'steps' && (
                        <>
                            <p className="text-subtle">
                                New funding cases take a copy of these steps.
                                Removing a step loosens preparation, so someone
                                who can approve weaker safeguards must publish
                                it.
                            </p>
                            {draft.evidence_checklist.length ? (
                                <ul className="divide-y divide-border rounded-lg border border-border">
                                    {draft.evidence_checklist.map(
                                        (item, index) => (
                                            <li
                                                key={`${item}-${index}`}
                                                className="flex items-center justify-between gap-3 px-3 py-2 text-[13px]"
                                            >
                                                <span>{item}</span>
                                                {!readOnly && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Remove “${item}”`}
                                                        onClick={() =>
                                                            set({
                                                                evidence_checklist:
                                                                    draft.evidence_checklist.filter(
                                                                        (
                                                                            _,
                                                                            position,
                                                                        ) =>
                                                                            position !==
                                                                            index,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            ) : (
                                <p className="text-subtle">
                                    No preparation steps yet.
                                </p>
                            )}
                            {!readOnly && (
                                <div className="flex items-end gap-2">
                                    <Field label="New preparation step">
                                        <Input
                                            value={newStep}
                                            maxLength={200}
                                            onChange={(event) =>
                                                setNewStep(event.target.value)
                                            }
                                        />
                                    </Field>
                                    <Button
                                        variant="outline"
                                        disabled={
                                            !newStep.trim() ||
                                            draft.evidence_checklist.some(
                                                (item) =>
                                                    item.toLowerCase() ===
                                                    newStep
                                                        .trim()
                                                        .toLowerCase(),
                                            )
                                        }
                                        onClick={() => {
                                            set({
                                                evidence_checklist: [
                                                    ...draft.evidence_checklist,
                                                    newStep.trim(),
                                                ],
                                            });
                                            setNewStep('');
                                        }}
                                    >
                                        <Plus className="size-4" />
                                        Add step
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                    {current.key === 'guidance' && (
                        <>
                            <Field label="Official guidance link">
                                <Input
                                    type="url"
                                    placeholder="https://"
                                    value={draft.source_url ?? ''}
                                    onChange={(event) =>
                                        set({
                                            source_url:
                                                event.target.value || null,
                                        })
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Guidance last checked"
                                    htmlFor="pathway-verified-on"
                                >
                                    <DatePicker
                                        id="pathway-verified-on"
                                        label="Guidance last checked"
                                        value={draft.verified_on ?? ''}
                                        allowClear
                                        disabled={readOnly}
                                        onChange={(date) =>
                                            set({ verified_on: date || null })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Next guidance review"
                                    htmlFor="pathway-review-on"
                                >
                                    <DatePicker
                                        id="pathway-review-on"
                                        label="Next guidance review"
                                        value={draft.review_on ?? ''}
                                        allowClear
                                        disabled={readOnly}
                                        onChange={(date) =>
                                            set({ review_on: date || null })
                                        }
                                    />
                                </Field>
                            </div>
                            <p className="text-caption">
                                Contact details are shown to staff on funding
                                work. Nothing is sent to these contacts
                                automatically.
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Organisation">
                                    <Input
                                        value={contact.organisation ?? ''}
                                        maxLength={160}
                                        onChange={(event) =>
                                            setContact({
                                                organisation:
                                                    event.target.value || null,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Phone">
                                    <Input
                                        type="tel"
                                        value={contact.phone ?? ''}
                                        maxLength={40}
                                        onChange={(event) =>
                                            setContact({
                                                phone:
                                                    event.target.value || null,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Email">
                                    <Input
                                        type="email"
                                        value={contact.email ?? ''}
                                        maxLength={254}
                                        onChange={(event) =>
                                            setContact({
                                                email:
                                                    event.target.value || null,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Website">
                                    <Input
                                        type="url"
                                        placeholder="https://"
                                        value={contact.website ?? ''}
                                        onChange={(event) =>
                                            setContact({
                                                website:
                                                    event.target.value || null,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                            <Field label="Regions and notes">
                                <Textarea
                                    rows={3}
                                    maxLength={500}
                                    value={contact.region_notes ?? ''}
                                    onChange={(event) =>
                                        setContact({
                                            region_notes:
                                                event.target.value || null,
                                        })
                                    }
                                />
                            </Field>
                        </>
                    )}
                </fieldset>
            </WizardStepPane>
        </WizardShell>
    );
}
