import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    ListPlus,
    Loader2,
    Plus,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface BudgetFormOptions {
    categories: Record<string, string>;
}

/** A budgeted line as the budget page already loads it. */
export interface EditableBudgetLine {
    id: number;
    category: string;
    description: string;
    account_code: string | null;
    budget_amount: number | string;
    forecast_amount: number | string | null;
    notes: string | null;
}

/** The fields an editor may change (mirrors the retired Edit page + lines). */
export interface EditableBudget {
    id: number;
    fiscal_year: string | number;
    title: string | null;
    description: string | null;
    total_budget: number | string;
    status: string;
    version_number: number;
    line_items: EditableBudgetLine[];
}

type LineRow = {
    key: string;
    id?: number;
    category: string;
    description: string;
    account_code: string;
    budget_amount: string;
    forecast_amount: string;
    notes: string;
};

type BudgetForm = {
    fiscal_year: string;
    title: string;
    description: string;
    total_budget: string;
    board_approved: boolean;
    line_items: LineRow[];
};

type StepKey = 'details' | 'lines' | 'envelope' | 'review';

export const BUDGET_STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'details',
        label: 'Budget',
        blurb: 'Fiscal year, title & scope',
        icon: Wallet,
    },
    {
        key: 'lines',
        label: 'Line items',
        blurb: 'Budgeted lines by category',
        icon: ListPlus,
    },
    {
        key: 'envelope',
        label: 'Envelope',
        blurb: 'Total and board status',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save the budget',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StepKey> = {
    fiscal_year: 'details',
    title: 'details',
    description: 'details',
    line_items: 'lines',
    total_budget: 'envelope',
    board_approved: 'envelope',
};

export const formatNzd = (amount: number | string | null | undefined) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(amount) || 0);

let lineSeq = 0;
const nextKey = () => `line-${++lineSeq}`;

const blankLine = (category: string): LineRow => ({
    key: nextKey(),
    category,
    description: '',
    account_code: '',
    budget_amount: '',
    forecast_amount: '',
    notes: '',
});

const amountString = (value: number | string | null | undefined) =>
    value == null || value === '' ? '' : String(Number(value));

const isAmount = (value: string) =>
    value.trim() !== '' && Number.isFinite(Number(value)) && Number(value) >= 0;

/* ------------------------------------------------------------------ */
/*  Validation + completeness                                          */
/* ------------------------------------------------------------------ */

function validateStep(
    step: StepKey,
    data: BudgetForm,
    isEdit: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'details') {
        const year = data.fiscal_year.trim();
        if (!year) {
            errors.fiscal_year = 'Enter the fiscal year.';
        } else if (!isEdit) {
            const n = Number(year);
            if (!/^\d{4}$/.test(year) || n < 2000 || n > 2100) {
                errors.fiscal_year =
                    'Enter a four-digit year between 2000 and 2100.';
            }
        } else if (year.length > 20) {
            errors.fiscal_year = 'Keep the fiscal year to 20 characters.';
        }
    }
    if (step === 'lines') {
        data.line_items.forEach((line, i) => {
            if (!line.category)
                errors[`line_items.${i}.category`] = 'Choose a category.';
            if (!line.description.trim())
                errors[`line_items.${i}.description`] = 'Describe the line.';
            if (!isAmount(line.budget_amount))
                errors[`line_items.${i}.budget_amount`] =
                    'Enter the budgeted amount.';
            if (
                line.forecast_amount.trim() !== '' &&
                !isAmount(line.forecast_amount)
            )
                errors[`line_items.${i}.forecast_amount`] =
                    'The forecast must be zero or more.';
        });
    }
    if (step === 'envelope' && data.line_items.length === 0) {
        if (!isAmount(data.total_budget)) {
            errors.total_budget = 'Enter the total budget in NZD.';
        }
    }
    return errors;
}

function linesTotal(lines: LineRow[]): number {
    return lines.reduce(
        (sum, line) => sum + (Number(line.budget_amount) || 0),
        0,
    );
}

function completeness(data: BudgetForm): number {
    const total =
        data.line_items.length > 0
            ? linesTotal(data.line_items)
            : Number(data.total_budget);
    const filled = [
        data.fiscal_year.trim(),
        data.title.trim(),
        data.description.trim(),
        data.line_items.length > 0,
        total > 0,
    ].filter(Boolean).length;
    return Math.round((filled / 5) * 100);
}

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export interface BudgetWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    options: BudgetFormOptions;
    /** Edit mode — the same wizard, prefilled, PUTs to the budget. */
    budget?: EditableBudget | null;
}

export function BudgetWizardDialog(props: BudgetWizardDialogProps) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <BudgetWizardBody {...props} /> : null;
}

function BudgetWizardBody({
    isOpen,
    onClose,
    options,
    budget = null,
}: BudgetWizardDialogProps) {
    const isEdit = Boolean(budget);
    const categories = options.categories;
    const categoryKeys = Object.keys(categories);
    const defaultCategory = categoryKeys.includes('operations')
        ? 'operations'
        : (categoryKeys[0] ?? '');

    const form = useForm<BudgetForm>({
        fiscal_year: budget
            ? String(budget.fiscal_year ?? '')
            : String(new Date().getFullYear()),
        title: budget?.title ?? '',
        description: budget?.description ?? '',
        total_budget: budget ? amountString(budget.total_budget) : '',
        board_approved: false,
        line_items: (budget?.line_items ?? []).map((line) => ({
            key: nextKey(),
            id: line.id,
            category: line.category,
            description: line.description,
            account_code: line.account_code ?? '',
            budget_amount: amountString(line.budget_amount),
            forecast_amount: amountString(line.forecast_amount),
            notes: line.notes ?? '',
        })),
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const step = BUDGET_STEPS[stepIndex];
    const pct = useMemo(() => completeness(data), [data]);
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StepKey) => {
        const index = BUDGET_STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const next = () => {
        const errors = validateStep(step.key, data, isEdit);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, BUDGET_STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const patchLine = (key: string, patch: Partial<LineRow>) =>
        setData(
            'line_items',
            data.line_items.map((line) =>
                line.key === key ? { ...line, ...patch } : line,
            ),
        );

    const addLine = () =>
        setData('line_items', [...data.line_items, blankLine(defaultCategory)]);

    const removeLine = (key: string) => {
        setClientErrors({});
        setData(
            'line_items',
            data.line_items.filter((line) => line.key !== key),
        );
    };

    const hasLines = data.line_items.length > 0;
    const lineSum = linesTotal(data.line_items);
    const envelope = hasLines ? lineSum : Number(data.total_budget) || 0;

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of BUDGET_STEPS)
            Object.assign(all, validateStep(s.key, data, isEdit));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'details') ?? 'details');
            return;
        }
        setClientErrors({});

        form.transform((current) => {
            const lines = current.line_items.map((line) => ({
                ...(line.id ? { id: line.id } : {}),
                category: line.category,
                description: line.description.trim(),
                account_code: line.account_code.trim() || null,
                budget_amount: line.budget_amount,
                forecast_amount: line.forecast_amount.trim() || null,
                notes: line.notes.trim() || null,
            }));
            const payload: Record<string, unknown> = {
                fiscal_year: current.fiscal_year.trim(),
                title: current.title.trim() || null,
                description: current.description.trim() || null,
                total_budget:
                    lines.length > 0
                        ? linesTotal(current.line_items).toFixed(2)
                        : current.total_budget,
                line_items: lines,
            };
            if (!budget) payload.board_approved = current.board_approved;
            return payload;
        });

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

        if (budget) {
            form.put(`/governance/budgets/${budget.id}`, visit);
        } else {
            form.post('/governance/budgets', visit);
        }
    };

    const isReview = step.key === 'review';
    const proposed = budget?.status === 'proposed';
    const budgetName =
        data.title.trim() ||
        (data.fiscal_year ? `FY${data.fiscal_year} budget` : 'Budget');

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Budget updated' : 'Budget created'}
            blurb={
                isEdit
                    ? `${budgetName} has been saved with ${data.line_items.length} line item${data.line_items.length === 1 ? '' : 's'}.`
                    : `${budgetName} has been created. Submit it for board approval from the budget page.`
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit budget' : 'New budget'}
                description="A guided wizard to author a board budget, its budgeted lines and envelope."
                railIcon={Wallet}
                railTitle={isEdit ? 'Edit budget' : 'New budget'}
                railSub={
                    budget
                        ? `FY${budget.fiscal_year} · v${budget.version_number}`
                        : 'Finance'
                }
                steps={BUDGET_STEPS}
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
                                {isEdit ? 'Save budget' : 'Create budget'}
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
                    {step.key === 'details' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Wallet}
                                    title="Which budget is this?"
                                    blurb="Set the fiscal year it covers, a recognisable title and what it funds."
                                />
                            </div>
                            {proposed ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This budget has been proposed to the board.
                                    Changing its title, description, envelope or
                                    lines means the decision paper bound to it
                                    will no longer approve it.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Fiscal year"
                                required
                                error={err('fiscal_year')}
                            >
                                <Input
                                    id="budget-fiscal-year"
                                    inputMode="numeric"
                                    value={data.fiscal_year}
                                    onChange={(e) =>
                                        setData('fiscal_year', e.target.value)
                                    }
                                    placeholder="e.g. 2026"
                                />
                            </Field>
                            <Field
                                label="Title"
                                hint="optional"
                                error={err('title')}
                            >
                                <Input
                                    id="budget-title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g. FY2026 Operating Budget"
                                />
                            </Field>
                            <Field
                                label="Description"
                                hint="optional"
                                span
                                error={err('description')}
                            >
                                <Textarea
                                    id="budget-description"
                                    rows={5}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    placeholder="Budget purpose and scope — which homes, services or programmes it funds."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'lines' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ListPlus}
                                title="Budgeted lines"
                                blurb="Add each budgeted line. Actual spend and variance notes are recorded on the budget page."
                            />
                            {hasLines ? null : (
                                <InfoCard icon={ListPlus}>
                                    No lines yet. You can add them now or later
                                    from the budget page — a budget needs at
                                    least one line before it can be submitted to
                                    the board.
                                </InfoCard>
                            )}
                            {data.line_items.map((line, index) => (
                                <div
                                    key={line.key}
                                    className="rounded-xl border border-border bg-muted/20 p-4"
                                >
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-[13px] font-semibold text-muted-foreground">
                                            Line {index + 1}
                                            {line.id ? ' · saved' : ' · new'}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeLine(line.key)}
                                            aria-label={`Remove line ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field
                                            label="Description"
                                            required
                                            span
                                            error={err(
                                                `line_items.${index}.description`,
                                            )}
                                        >
                                            <Input
                                                value={line.description}
                                                onChange={(e) =>
                                                    patchLine(line.key, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. Support worker wages"
                                            />
                                        </Field>
                                        <Field
                                            label="Category"
                                            required
                                            error={err(
                                                `line_items.${index}.category`,
                                            )}
                                        >
                                            <SelectInput
                                                value={line.category}
                                                onChange={(value) =>
                                                    patchLine(line.key, {
                                                        category: value,
                                                    })
                                                }
                                                placeholder="Category"
                                                ariaLabel={`Line ${index + 1} category`}
                                                options={categoryKeys.map(
                                                    (key) => ({
                                                        value: key,
                                                        label:
                                                            categories[key] ??
                                                            key,
                                                    }),
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            label="Account code"
                                            hint="optional"
                                            error={err(
                                                `line_items.${index}.account_code`,
                                            )}
                                        >
                                            <Input
                                                value={line.account_code}
                                                onChange={(e) =>
                                                    patchLine(line.key, {
                                                        account_code:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. 6100"
                                            />
                                        </Field>
                                        <Field
                                            label="Budget amount (NZD)"
                                            required
                                            error={err(
                                                `line_items.${index}.budget_amount`,
                                            )}
                                        >
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                inputMode="decimal"
                                                value={line.budget_amount}
                                                onChange={(e) =>
                                                    patchLine(line.key, {
                                                        budget_amount:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. 120000"
                                            />
                                        </Field>
                                        <Field
                                            label="Forecast (NZD)"
                                            hint="defaults to the budget"
                                            error={err(
                                                `line_items.${index}.forecast_amount`,
                                            )}
                                        >
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                inputMode="decimal"
                                                value={line.forecast_amount}
                                                onChange={(e) =>
                                                    patchLine(line.key, {
                                                        forecast_amount:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Notes"
                                            hint="optional"
                                            span
                                            error={err(
                                                `line_items.${index}.notes`,
                                            )}
                                        >
                                            <Input
                                                value={line.notes}
                                                onChange={(e) =>
                                                    patchLine(line.key, {
                                                        notes: e.target.value,
                                                    })
                                                }
                                                placeholder="Assumptions behind the figure"
                                            />
                                        </Field>
                                    </div>
                                </div>
                            ))}
                            <FieldErr>{err('line_items')}</FieldErr>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addLine}
                                    disabled={categoryKeys.length === 0}
                                >
                                    <Plus className="h-4 w-4" /> Add line
                                </Button>
                                {hasLines ? (
                                    <span className="text-sm text-muted-foreground">
                                        {data.line_items.length} line
                                        {data.line_items.length === 1
                                            ? ''
                                            : 's'}{' '}
                                        ·{' '}
                                        <span className="font-semibold text-foreground tabular-nums">
                                            {formatNzd(lineSum)}
                                        </span>
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    ) : null}

                    {step.key === 'envelope' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={FileText}
                                    title="Budget envelope"
                                    blurb="The total the board is asked to approve."
                                />
                            </div>
                            <Field
                                label="Total budget (NZD)"
                                required
                                error={err('total_budget')}
                            >
                                <Input
                                    id="budget-total"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputMode="decimal"
                                    value={
                                        hasLines
                                            ? lineSum.toFixed(2)
                                            : data.total_budget
                                    }
                                    disabled={hasLines}
                                    onChange={(e) =>
                                        setData('total_budget', e.target.value)
                                    }
                                    placeholder="e.g. 1500000"
                                />
                            </Field>
                            <InfoCard icon={Wallet}>
                                {hasLines
                                    ? `The envelope is the sum of the ${data.line_items.length} budgeted line${data.line_items.length === 1 ? '' : 's'} and is recalculated whenever lines change.`
                                    : 'With no lines yet, enter the envelope. Adding lines later recalculates it to their sum.'}
                            </InfoCard>
                            {!isEdit ? (
                                <div className="sm:col-span-2">
                                    <div className="flex items-start gap-2.5">
                                        <Checkbox
                                            id="budget-board-approved"
                                            checked={data.board_approved}
                                            onCheckedChange={(value) =>
                                                setData(
                                                    'board_approved',
                                                    value === true,
                                                )
                                            }
                                        />
                                        <div>
                                            <Label htmlFor="budget-board-approved">
                                                Already approved by the board
                                            </Label>
                                            <p className="text-caption mt-0.5">
                                                Records a budget the board
                                                approved outside this system. No
                                                decision paper is linked.
                                            </p>
                                        </div>
                                    </div>
                                    <FieldErr>{err('board_approved')}</FieldErr>
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review the budget"
                                blurb={
                                    isEdit
                                        ? 'Saving updates the budget and applies your line changes.'
                                        : 'The budget is created in drafting. Submit it to the board from the budget page.'
                                }
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Wallet}
                                    title="Budget"
                                    onEdit={() => goTo('details')}
                                >
                                    <ReviewRow
                                        label="Fiscal year"
                                        value={data.fiscal_year}
                                    />
                                    <ReviewRow
                                        label="Title"
                                        value={data.title}
                                    />
                                    <ReviewRow
                                        label="Description"
                                        value={
                                            data.description.trim()
                                                ? 'Added'
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Envelope"
                                    onEdit={() => goTo('envelope')}
                                >
                                    <ReviewRow
                                        label="Total budget"
                                        value={
                                            hasLines || data.total_budget.trim()
                                                ? formatNzd(envelope)
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Source"
                                        value={
                                            hasLines
                                                ? 'Sum of lines'
                                                : 'Entered'
                                        }
                                    />
                                    {!isEdit ? (
                                        <ReviewRow
                                            label="Board status"
                                            value={
                                                data.board_approved
                                                    ? 'Already approved'
                                                    : 'Drafting'
                                            }
                                        />
                                    ) : null}
                                </ReviewCard>
                                <ReviewCard
                                    icon={ListPlus}
                                    title={`Line items (${data.line_items.length})`}
                                    onEdit={() => goTo('lines')}
                                    span
                                >
                                    {hasLines ? (
                                        data.line_items.map((line, index) => (
                                            <ReviewRow
                                                key={line.key}
                                                label={`${categories[line.category] ?? line.category} · ${line.description || `Line ${index + 1}`}`}
                                                value={formatNzd(
                                                    line.budget_amount,
                                                )}
                                            />
                                        ))
                                    ) : (
                                        <p className="text-caption">
                                            No lines added.
                                        </p>
                                    )}
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
                description="Any changes to this budget and its lines will be lost."
            />
        </>
    );
}
