import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { formatNzd, refSuffix } from '@/lib/governance-labels';
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
            blurb: 'Title, kind of spend and site',
            icon: HandCoins,
        },
        {
            key: 'amount',
            label: 'Amount',
            blurb: 'Amount and who approves it',
            icon: Wallet,
        },
        {
            key: 'details',
            label: 'Reason',
            blurb: 'Why the spend is needed',
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
            description: 'Buying equipment, vehicles or building work.',
        },
        opex: {
            icon: Receipt,
            description: 'Running costs that are not in the budget.',
        },
        supplier_contract: {
            icon: Handshake,
            description: 'A new or renewed supplier agreement.',
        },
        donor_restricted: {
            icon: HandHeart,
            description: 'Spending money a donor gave for a set purpose.',
        },
    };

/**
 * "Below $5,000: approved by a finance approver. $5,000 and over: needs a
 * board resolution." Board sign-off starts AT the threshold.
 */
export function whoApprovesText(threshold: number): string {
    const amount = formatNzd(threshold);
    return `Below ${amount}: approved by a finance approver. ${amount} and over: needs a board resolution.`;
}

/** Mirrors SpendApprovalCommandService::requiresBoard (amount ≥ threshold). */
export function needsBoardResolution(amount: number, threshold: number) {
    return amount > 0 && amount >= threshold;
}

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
        if (!data.category)
            errors.category = 'Choose what kind of spend this is.';
        if (!data.site_id) errors.site_id = 'Choose the site this spend is for.';
    }
    if (step === 'amount') {
        const amount = Number(data.amount);
        if (data.amount.trim() === '' || !Number.isFinite(amount)) {
            errors.amount = 'Enter the amount in NZD.';
        } else if (amount < 0) {
            errors.amount = "The amount can't be negative.";
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
        category:
            approval?.category ??
            (categoryKeys.includes('capex') ? 'capex' : (categoryKeys[0] ?? '')),
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
    const requiresBoard = needsBoardResolution(numericAmount, threshold);
    const siteName =
        sites.find((site) => String(site.id) === data.site_id)?.name ?? null;
    const isReview = step.key === 'review';

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Request updated' : 'Request saved'}
            blurb={
                isEdit
                    ? 'Your changes are saved. The request stays a draft until you send it for a decision.'
                    : 'The spend request is saved as a draft. Add any quotes, then send it for a decision from the request page.'
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit spend request' : 'New spend request'}
                description="Ask for permission to spend money over the limit."
                railIcon={HandCoins}
                railTitle={isEdit ? 'Edit request' : 'New request'}
                railSub={
                    isEdit && approval
                        ? refSuffix(approval.reference)
                        : 'Spend request'
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
                            onClick={() =>
                                setStepIndex((i) => Math.max(i - 1, 0))
                            }
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
                                    title="What is the spend for?"
                                    blurb="Name the spend, choose what kind it is and the site it's for."
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
                                label="Kind of spend"
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
                                        meta: `Board resolution from ${formatNzd(thresholds[key] ?? 0)}`,
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
                                    title="Amount"
                                    blurb="The amount decides who can approve the request."
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
                                label="Approval needed by"
                                hint="optional — the request expires after this date"
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
                                <strong>Who approves what</strong> —{' '}
                                {categories[data.category] ?? 'This kind of spend'}
                                : {whoApprovesText(threshold)}{' '}
                                {numericAmount > 0
                                    ? requiresBoard
                                        ? 'This amount needs a board resolution before it can be approved.'
                                        : 'A finance approver can decide this amount.'
                                    : ''}
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
                                title="Why is it needed?"
                                blurb="Explain the spend so the person deciding doesn't have to chase details."
                            />
                            <Field label="Reason" error={err('description')}>
                                <Textarea
                                    id="spend-description"
                                    rows={7}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    placeholder="What is this spend for, and what happens if it isn't approved?"
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
                                        ? 'If someone else changed this request since you opened it, refresh the page first.'
                                        : 'The request is saved as a draft. Add quotes on the request page, then send it for a decision.'
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
                                        label="Kind of spend"
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
                                                ? formatNzd(numericAmount)
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Who approves"
                                        value={
                                            data.amount.trim()
                                                ? requiresBoard
                                                    ? 'Needs a board resolution'
                                                    : 'A finance approver'
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Approval needed by"
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
                                    title="Reason"
                                    onEdit={() => goTo('details')}
                                    span
                                >
                                    <p className="text-subtle whitespace-pre-wrap">
                                        {data.description.trim() ||
                                            'No reason added.'}
                                    </p>
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                mode={isEdit ? 'edit' : 'create'}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your changes to this spend request will be lost.'
                        : 'The details you entered for this spend request will be lost.'
                }
            />
        </>
    );
}
