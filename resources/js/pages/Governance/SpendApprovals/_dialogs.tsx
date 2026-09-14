import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    HandCoins,
    HandHeart,
    Handshake,
    Landmark,
    Loader2,
    Receipt,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface SpendApprovalFormOptions {
    categories: Record<string, string>;
    thresholds: Record<string, number>;
    sites: Array<{ id: number; name: string }>;
}

/** The draft fields an editor may change (mirrors the old Edit page). */
export interface EditableSpendApproval {
    id: number;
    reference: string;
    title: string;
    description: string | null;
    category: string;
    amount: number | string;
    currency: string;
    site_id: number | null;
    valid_until: string | null;
    version: number;
}

type SpendApprovalForm = {
    title: string;
    description: string;
    category: string;
    amount: string;
    currency: string;
    site_id: string;
    valid_until: string;
    expected_version?: number;
};

type StepKey = 'request' | 'amount' | 'details' | 'review';

export const SPEND_APPROVAL_STEPS: readonly (WizardStep & { key: StepKey })[] =
    [
        {
            key: 'request',
            label: 'Request',
            blurb: 'Title, category & site',
            icon: HandCoins,
        },
        {
            key: 'amount',
            label: 'Amount',
            blurb: 'NZD amount, threshold & expiry',
            icon: Wallet,
        },
        {
            key: 'details',
            label: 'Justification',
            blurb: 'What the spend is for',
            icon: FileText,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check and save the draft',
            icon: ClipboardCheck,
        },
    ];

const FIELD_STEPS: Record<string, StepKey> = {
    title: 'request',
    category: 'request',
    site_id: 'request',
    source_type: 'request',
    source_id: 'request',
    cost_centre_id: 'request',
    funding_stream_id: 'request',
    donor_fund_id: 'request',
    budget_id: 'request',
    budget_line_item_id: 'request',
    amount: 'amount',
    currency: 'amount',
    valid_until: 'amount',
    description: 'details',
    expected_version: 'review',
};

const CATEGORY_META: Record<string, { icon: LucideIcon; description: string }> =
    {
        capex: {
            icon: Landmark,
            description: 'Capital purchases and fit-outs.',
        },
        opex: {
            icon: Receipt,
            description: 'Operating spend outside the budget.',
        },
        supplier_contract: {
            icon: Handshake,
            description: 'New or renewed supplier commitments.',
        },
        donor_restricted: {
            icon: HandHeart,
            description: 'Spend from restricted donor funds.',
        },
    };

export const formatNzd = (amount: number | string | null | undefined) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(amount) || 0);

export function spendStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'approved':
            return 'success';
        case 'rejected':
            return 'critical';
        case 'submitted':
            return 'warning';
        default:
            return 'neutral';
    }
}

export const spendStatusLabel = (status: string) =>
    status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');

/* ------------------------------------------------------------------ */
/*  Validation + completeness                                          */
/* ------------------------------------------------------------------ */

function validateStep(
    step: StepKey,
    data: SpendApprovalForm,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'request') {
        if (!data.title.trim()) errors.title = 'Give the request a title.';
        if (!data.category) errors.category = 'Choose a spend category.';
        if (!data.site_id) errors.site_id = 'Choose the site this spend is for.';
    }
    if (step === 'amount') {
        const amount = Number(data.amount);
        if (data.amount.trim() === '' || !Number.isFinite(amount)) {
            errors.amount = 'Enter the amount in NZD.';
        } else if (amount < 0) {
            errors.amount = 'The amount cannot be negative.';
        }
    }
    return errors;
}

function completeness(data: SpendApprovalForm): number {
    const filled = [
        data.title.trim(),
        data.category,
        data.site_id,
        data.amount.trim(),
        data.valid_until,
        data.description.trim(),
    ].filter(Boolean).length;
    return Math.round((filled / 6) * 100);
}

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export interface SpendApprovalWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    options: SpendApprovalFormOptions;
    /** Edit mode — the same wizard, prefilled, PUTs with expected_version. */
    approval?: EditableSpendApproval | null;
}

export function SpendApprovalWizardDialog(
    props: SpendApprovalWizardDialogProps,
) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <SpendApprovalWizardBody {...props} /> : null;
}

function SpendApprovalWizardBody({
    isOpen,
    onClose,
    options,
    approval = null,
}: SpendApprovalWizardDialogProps) {
    const isEdit = Boolean(approval);
    const { categories, thresholds, sites } = options;
    const categoryKeys = Object.keys(categories);

    const form = useForm<SpendApprovalForm>({
        title: approval?.title ?? '',
        description: approval?.description ?? '',
        category: approval?.category ?? (categoryKeys.includes('capex') ? 'capex' : (categoryKeys[0] ?? '')),
        amount: approval ? String(approval.amount) : '',
        currency: approval?.currency ?? 'NZD',
        site_id: approval?.site_id
            ? String(approval.site_id)
            : !approval && sites.length === 1
              ? String(sites[0].id)
              : '',
        valid_until: approval?.valid_until?.slice(0, 10) ?? '',
        ...(approval ? { expected_version: approval.version } : {}),
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const step = SPEND_APPROVAL_STEPS[stepIndex];
    const pct = useMemo(() => completeness(data), [data]);
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StepKey) => {
        const index = SPEND_APPROVAL_STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const next = () => {
        const errors = validateStep(step.key, data);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, SPEND_APPROVAL_STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of SPEND_APPROVAL_STEPS)
            Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'request') ?? 'request');
            return;
        }
        setClientErrors({});

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const target = firstErrorStep(errors, FIELD_STEPS, 'review');
                if (target) goTo(target);
            },
        };

        if (approval) {
            form.put(`/governance/spend-approvals/${approval.id}`, visit);
        } else {
            form.post('/governance/spend-approvals', visit);
        }
    };

    const threshold = thresholds[data.category] ?? 0;
    const numericAmount = Number(data.amount) || 0;
    const requiresBoard = numericAmount > 0 && numericAmount >= threshold;
    const siteName =
        sites.find((site) => String(site.id) === data.site_id)?.name ?? null;
    const isReview = step.key === 'review';

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Request updated' : 'Request drafted'}
            blurb={
                isEdit
                    ? `${approval?.reference ?? 'The request'} has been saved. It stays in draft until it is submitted for sign-off.`
                    : 'The spend request is saved as a draft. Submit it for sign-off from the request page.'
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit spend approval' : 'Request spend approval'}
                description="A guided wizard to draft a spend item for board or finance-committee sign-off."
                railIcon={HandCoins}
                railTitle={isEdit ? 'Edit request' : 'New request'}
                railSub={
                    isEdit
                        ? `${approval?.reference} · v${approval?.version}`
                        : 'Spend approval'
                }
                steps={SPEND_APPROVAL_STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save changes' : 'Save draft'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={step.key}>
                    {step.key === 'request' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={HandCoins}
                                    title="What needs sign-off?"
                                    blurb="Name the spend, pick its category and the site it belongs to."
                                />
                            </div>
                            <Field
                                label="Title"
                                required
                                span
                                error={err('title')}
                            >
                                <Input
                                    id="spend-title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g. Replace Aurora House hot water cylinder"
                                />
                            </Field>
                            <Field
                                label="Category"
                                required
                                span
                                error={err('category')}
                            >
                                <TilePicker
                                    value={data.category}
                                    onChange={(value) =>
                                        setData('category', value)
                                    }
                                    options={categoryKeys.map((key) => ({
                                        key,
                                        label: categories[key] ?? key,
                                        description:
                                            CATEGORY_META[key]?.description,
                                        icon: CATEGORY_META[key]?.icon ?? Wallet,
                                        meta: `Sign-off from ${formatNzd(thresholds[key] ?? 0)}`,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Site"
                                required
                                span
                                error={err('site_id')}
                            >
                                <SelectInput
                                    value={data.site_id}
                                    onChange={(value) =>
                                        setData('site_id', value)
                                    }
                                    placeholder="Select a site"
                                    ariaLabel="Site"
                                    options={sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    }))}
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'amount' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Wallet}
                                    title="Amount and validity"
                                    blurb="Amounts at or above the category threshold need a board resolution."
                                />
                            </div>
                            <Field
                                label="Amount (NZD)"
                                required
                                error={err('amount')}
                            >
                                <Input
                                    id="spend-amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputMode="decimal"
                                    value={data.amount}
                                    onChange={(e) =>
                                        setData('amount', e.target.value)
                                    }
                                    placeholder="e.g. 18500"
                                />
                            </Field>
                            <Field
                                label="Valid until"
                                hint="optional"
                                error={err('valid_until')}
                            >
                                <Input
                                    id="spend-valid-until"
                                    type="date"
                                    value={data.valid_until}
                                    onChange={(e) =>
                                        setData('valid_until', e.target.value)
                                    }
                                />
                            </Field>
                            <InfoCard
                                icon={requiresBoard ? AlertTriangle : Wallet}
                                tone={requiresBoard ? 'warn' : 'info'}
                            >
                                Threshold for{' '}
                                {categories[data.category] ?? data.category}:{' '}
                                <strong>{formatNzd(threshold)}</strong>.{' '}
                                {requiresBoard
                                    ? 'This amount meets the threshold and will require a board resolution.'
                                    : 'Amounts below the threshold are decided without a board resolution.'}
                            </InfoCard>
                            {err('currency') ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {err('currency')}
                                </InfoCard>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'details' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={FileText}
                                title="Justification"
                                blurb="Explain the spend so decision-makers can weigh it without chasing context."
                            />
                            <Field label="Description" error={err('description')}>
                                <Textarea
                                    id="spend-description"
                                    rows={7}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    placeholder="What is this spend for? Which site, service or project does it relate to?"
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review the request"
                                blurb={
                                    isEdit
                                        ? 'Changes are saved against the version you opened; if someone else changed it, reload first.'
                                        : 'The request is saved as a draft. Supporting documents are attached on the request page.'
                                }
                            />
                            {err('expected_version') ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {err('expected_version')}
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={HandCoins}
                                    title="Request"
                                    onEdit={() => goTo('request')}
                                >
                                    <ReviewRow label="Title" value={data.title} />
                                    <ReviewRow
                                        label="Category"
                                        value={categories[data.category]}
                                    />
                                    <ReviewRow label="Site" value={siteName} />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Wallet}
                                    title="Amount"
                                    onEdit={() => goTo('amount')}
                                >
                                    <ReviewRow
                                        label="Amount"
                                        value={
                                            data.amount.trim()
                                                ? formatNzd(data.amount)
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Board sign-off"
                                        value={
                                            data.amount.trim()
                                                ? requiresBoard
                                                    ? 'Required'
                                                    : 'Not required'
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Valid until"
                                        value={
                                            data.valid_until
                                                ? formatDateOnly(
                                                      data.valid_until,
                                                  )
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Justification"
                                    onEdit={() => goTo('details')}
                                    span
                                >
                                    <p className="text-[13px] whitespace-pre-wrap text-muted-foreground">
                                        {data.description.trim() ||
                                            'No description added.'}
                                    </p>
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description="Any changes to this spend request will be lost."
            />
        </>
    );
}
