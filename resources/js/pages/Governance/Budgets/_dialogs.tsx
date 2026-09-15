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
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { financialYearLabel, formatNzd } from '@/lib/governance-labels';
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

export interface FinancialYearOption {
    /** Stored as the year the financial year ends (2027 = 2026/27). */
    value: number;
    label: string;
    range: string;
    is_current?: boolean;
}

export interface BudgetFormOptions {
    categories: Record<string, string>;
    financial_years?: FinancialYearOption[];
}

/** A year choice; `raw` keeps a budget saved under an older year format. */
type YearChoice = FinancialYearOption & { raw?: string };

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
    approved_on: string;
    approval_reference: string;
    line_items: LineRow[];
};

type StepKey = 'details' | 'lines' | 'total' | 'review';

export const BUDGET_STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'details',
        label: 'Budget',
        blurb: 'Financial year, title and purpose',
        icon: Wallet,
    },
    {
        key: 'lines',
        label: 'Budget lines',
        blurb: 'What the money is for',
        icon: ListPlus,
    },
    {
        key: 'total',
        label: 'Total',
        blurb: 'Total and board approval',
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
    total_budget: 'total',
    board_approved: 'total',
    approved_on: 'total',
    approval_reference: 'total',
};

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

/**
 * NZ financial years (1 July – 30 June) from the year before this one to two
 * years ahead, valued by the year each ends — the same convention the server
 * uses ("2027" = 2026/27).
 */
export function financialYearOptions(
    today: Date = new Date(),
): FinancialYearOption[] {
    const [year, month] = toDateInput(today).split('-').map(Number);
    const currentEnd = month >= 7 ? year + 1 : year;
    return [currentEnd - 1, currentEnd, currentEnd + 1, currentEnd + 2].map(
        (end) => ({
            value: end,
            label: financialYearLabel(end),
            range: `1 July ${end - 1} – 30 June ${end}`,
            is_current: end === currentEnd,
        }),
    );
}

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
        if (!data.fiscal_year.trim()) {
            errors.fiscal_year = 'Choose the financial year this budget covers.';
        }
    }
    if (step === 'lines') {
        data.line_items.forEach((line, i) => {
            if (!line.category)
                errors[`line_items.${i}.category`] =
                    'Choose a category for this line.';
            if (!line.description.trim())
                errors[`line_items.${i}.description`] =
                    'Describe what this line pays for.';
            if (!isAmount(line.budget_amount))
                errors[`line_items.${i}.budget_amount`] =
                    'Enter the amount budgeted for this line.';
            if (
                line.forecast_amount.trim() !== '' &&
                !isAmount(line.forecast_amount)
            )
                errors[`line_items.${i}.forecast_amount`] =
                    "The forecast can't be negative.";
        });
    }
    if (step === 'total') {
        if (data.line_items.length === 0 && !isAmount(data.total_budget)) {
            errors.total_budget = 'Enter the total budget in NZD.';
        }
        if (!isEdit && data.board_approved) {
            if (!data.approved_on) {
                errors.approved_on =
                    'Enter the date the board approved this budget.';
            } else if (data.approved_on > toDateInput(new Date())) {
                errors.approved_on = "The approval date can't be in the future.";
            }
            if (!data.approval_reference.trim()) {
                errors.approval_reference =
                    'Enter the minutes reference for the meeting that approved it.';
            }
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

    const yearOptions = useMemo((): YearChoice[] => {
        const base: YearChoice[] = options.financial_years?.length
            ? options.financial_years
            : financialYearOptions();
        const stored = budget ? String(budget.fiscal_year ?? '') : '';
        // A budget saved under an older year format stays selectable.
        return stored && !base.some((option) => String(option.value) === stored)
            ? [
                  {
                      value: Number(stored) || 0,
                      label: financialYearLabel(stored),
                      range: '',
                      raw: stored,
                  },
                  ...base,
              ]
            : base;
    }, [budget, options.financial_years]);

    const currentYear =
        yearOptions.find((option) => option.is_current) ?? yearOptions[0];

    const form = useForm<BudgetForm>({
        fiscal_year: budget
            ? String(budget.fiscal_year ?? '')
            : String(currentYear?.value ?? ''),
        title: budget?.title ?? '',
        description: budget?.description ?? '',
        total_budget: budget ? amountString(budget.total_budget) : '',
        board_approved: false,
        approved_on: '',
        approval_reference: '',
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
    const total = hasLines ? lineSum : Number(data.total_budget) || 0;
    const selectedYear = yearOptions.find(
        (option) =>
            String(option.value) === data.fiscal_year ||
            option.raw === data.fiscal_year,
    );
    const yearLabel = data.fiscal_year
        ? (selectedYear?.label ?? financialYearLabel(data.fiscal_year))
        : null;

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
            if (!budget) {
                payload.board_approved = current.board_approved;
                if (current.board_approved) {
                    payload.approved_on = current.approved_on;
                    payload.approval_reference =
                        current.approval_reference.trim();
                }
            }
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
        data.title.trim() || (yearLabel ? `${yearLabel} budget` : 'The budget');

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Budget updated' : 'Budget created'}
            blurb={
                isEdit
                    ? `${budgetName} has been saved with ${data.line_items.length} line${data.line_items.length === 1 ? '' : 's'}.`
                    : data.board_approved
                      ? `${budgetName} has been recorded as approved by the board.`
                      : `${budgetName} is saved as a draft. When it's ready, send it to the board from the budget page.`
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
                description={
                    isEdit
                        ? 'Update this budget and its lines.'
                        : 'Set up a yearly budget for the board to approve.'
                }
                railIcon={Wallet}
                railTitle={isEdit ? 'Edit budget' : 'New budget'}
                railSub={
                    budget
                        ? `${financialYearLabel(budget.fiscal_year)} · version ${budget.version_number}`
                        : 'Board finance'
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
                                    blurb="Choose the financial year it covers, give it a recognisable title and say what it funds."
                                />
                            </div>
                            {proposed ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This budget has been sent to the board.
                                    Changing its title, description, total or
                                    lines means the board must see the updated
                                    budget before it can be approved.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Financial year"
                                required
                                hint="1 July – 30 June"
                                error={err('fiscal_year')}
                            >
                                <SelectInput
                                    value={data.fiscal_year}
                                    onChange={(value) =>
                                        setData('fiscal_year', value)
                                    }
                                    placeholder="Choose the financial year"
                                    ariaLabel="Financial year"
                                    options={yearOptions.map((option) => ({
                                        value: option.raw ?? String(option.value),
                                        label: option.range
                                            ? `${option.label} (${option.range})`
                                            : option.label,
                                    }))}
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
                                    placeholder="e.g. 2026/27 operating budget"
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
                                    placeholder="What this budget pays for — which homes, services or programmes."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'lines' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ListPlus}
                                title="Budget lines"
                                blurb="Add a line for each thing the money is for. Actual spend is recorded on the budget page once the budget is approved."
                            />
                            {proposed ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    Changing these lines means the board must
                                    see the updated budget before it can be
                                    approved.
                                </InfoCard>
                            ) : null}
                            {hasLines ? null : (
                                <InfoCard icon={ListPlus}>
                                    No lines yet. You can add them now or later
                                    from the budget page — a budget needs at
                                    least one line before it can be sent to the
                                    board.
                                </InfoCard>
                            )}
                            {data.line_items.map((line, index) => (
                                <div
                                    key={line.key}
                                    className="rounded-xl border border-border bg-muted/20 p-4"
                                >
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-caption font-semibold">
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
                                            hint="from your accounting system, if known"
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
                                            label="Amount budgeted (NZD)"
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
                                            hint="what you now expect to spend (optional)"
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
                                                placeholder="How the figure was worked out"
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
                                    <span className="text-subtle">
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

                    {step.key === 'total' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={FileText}
                                    title="Total budget"
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
                                    ? `The total is the sum of the ${data.line_items.length} line${data.line_items.length === 1 ? '' : 's'} and updates whenever the lines change.`
                                    : 'With no lines yet, enter the total. Adding lines later changes it to their sum.'}
                            </InfoCard>
                            {!isEdit ? (
                                <div className="grid gap-3 sm:col-span-2">
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
                                                The board has already approved
                                                this budget
                                            </Label>
                                            <p className="text-caption mt-0.5">
                                                Only for a budget the board
                                                approved outside this system.
                                                It's recorded as approved
                                                straight away, so give the
                                                meeting details.
                                            </p>
                                        </div>
                                    </div>
                                    <FieldErr>{err('board_approved')}</FieldErr>
                                    {data.board_approved ? (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field
                                                label="Date the board approved it"
                                                required
                                                error={err('approved_on')}
                                            >
                                                <Input
                                                    id="budget-approved-on"
                                                    type="date"
                                                    max={toDateInput(
                                                        new Date(),
                                                    )}
                                                    value={data.approved_on}
                                                    onChange={(e) =>
                                                        setData(
                                                            'approved_on',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Minutes reference"
                                                required
                                                error={err(
                                                    'approval_reference',
                                                )}
                                            >
                                                <Input
                                                    id="budget-approval-reference"
                                                    value={
                                                        data.approval_reference
                                                    }
                                                    onChange={(e) =>
                                                        setData(
                                                            'approval_reference',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Board minutes 24 June 2026, item 5"
                                                />
                                            </Field>
                                        </div>
                                    ) : null}
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
                                        ? 'Saving updates the budget and its lines.'
                                        : data.board_approved
                                          ? 'The budget is recorded as already approved by the board.'
                                          : 'The budget is saved as a draft. Send it to the board from the budget page when it is ready.'
                                }
                            />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Wallet}
                                    title="Budget"
                                    onEdit={() => goTo('details')}
                                >
                                    <ReviewRow
                                        label="Financial year"
                                        value={yearLabel}
                                    />
                                    <ReviewRow
                                        label="Title"
                                        value={data.title}
                                    />
                                    <ReviewRow
                                        label="Description"
                                        value={data.description.trim() || null}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={FileText}
                                    title="Total"
                                    onEdit={() => goTo('total')}
                                >
                                    <ReviewRow
                                        label="Total budget"
                                        value={
                                            hasLines || data.total_budget.trim()
                                                ? formatNzd(total)
                                                : null
                                        }
                                    />
                                    <ReviewRow
                                        label="Worked out from"
                                        value={
                                            hasLines
                                                ? 'The budget lines'
                                                : 'The total you entered'
                                        }
                                    />
                                    {!isEdit ? (
                                        <ReviewRow
                                            label="Board approval"
                                            value={
                                                data.board_approved
                                                    ? `Approved on ${formatDateOnly(data.approved_on, 'a date not given')} (${data.approval_reference.trim() || 'no minutes reference'})`
                                                    : 'Draft — not sent to the board yet'
                                            }
                                        />
                                    ) : null}
                                </ReviewCard>
                                <ReviewCard
                                    icon={ListPlus}
                                    title={`Budget lines (${data.line_items.length})`}
                                    onEdit={() => goTo('lines')}
                                    span
                                >
                                    {hasLines ? (
                                        data.line_items.map((line, index) => (
                                            <ReviewRow
                                                key={line.key}
                                                label={`${categories[line.category] ?? line.category} · ${line.description || `Line ${index + 1}`}`}
                                                value={formatNzd(
                                                    Number(
                                                        line.budget_amount,
                                                    ) || 0,
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
                mode={isEdit ? 'edit' : 'create'}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your changes to this budget and its lines will be lost.'
                        : 'The details you entered for this budget will be lost.'
                }
            />
        </>
    );
}
