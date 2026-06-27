/* eslint-disable no-restricted-syntax -- This builder mirrors the Add-Client /
 * BandWizard modal chrome (custom rail-preview + adjustment-line rows + native
 * footer controls). Every colour is a semantic design token. */
import { router } from '@inertiajs/react';
import {
    Banknote,
    CalendarRange,
    ClipboardCheck,
    DollarSign,
    Plus,
    Scale,
    Trash2,
    Users,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import { StatusBadge } from '@/components/hr/status-badge';
import {
    Field,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Types — mirror the props the host page already receives            */
/* ------------------------------------------------------------------ */

export type ReviewBuilderEmployee = {
    id: number;
    user: { id: number; name: string };
    position_title?: string | null;
    annual_salary?: string | null;
    hourly_rate?: string | null;
};

export type ReviewCycleOption = { value: string; label: string };

/** Optional active band passed from the controller for per-line placement. */
export type ReviewBuilderBand = {
    position_role: string;
    band_name?: string | null;
    min_salary: string | number;
    mid_salary?: string | number | null;
    max_salary: string | number;
    currency?: string | null;
};

type LineItem = {
    employee_profile_id: string;
    current_salary: string;
    proposed_salary: string;
    change_percentage: string;
    justification: string;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const toNum = (value?: string | number | null) => {
    if (value == null || value === '') return NaN;
    const n = typeof value === 'number' ? value : parseFloat(value);
    return Number.isNaN(n) ? NaN : n;
};

const currency = (value: number | string | null | undefined) => {
    const n = toNum(value);
    if (Number.isNaN(n)) return '—';
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(n);
};

const signedCurrency = (n: number) =>
    `${n > 0 ? '+' : n < 0 ? '−' : ''}${currency(Math.abs(n))}`;

const formatDate = (value?: string | null) => {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('') || '?';

const emptyLine = (): LineItem => ({
    employee_profile_id: '',
    current_salary: '',
    proposed_salary: '',
    change_percentage: '',
    justification: '',
});

const STEPS: readonly WizardStep[] = [
    {
        key: 'cycle',
        label: 'Cycle & identity',
        blurb: 'What & when',
        icon: CalendarRange,
    },
    { key: 'lines', label: 'Adjustments', blurb: 'People & pay', icon: Users },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Budget & submit',
        icon: ClipboardCheck,
    },
];

const CYCLE_ICON: Record<string, typeof CalendarRange> = {
    annual: CalendarRange,
    mid_year: Scale,
    ad_hoc: Banknote,
};

const CYCLE_BLURB: Record<string, string> = {
    annual: 'Org-wide yearly remuneration round.',
    mid_year: 'A mid-cycle check or true-up.',
    ad_hoc: 'A one-off, targeted adjustment.',
};

type Placement = {
    position: 'under' | 'in' | 'over';
    band: ReviewBuilderBand;
} | null;

/** Best-effort band placement for a proposed salary, by the employee's role. */
function placeInBand(
    bands: ReviewBuilderBand[] | undefined,
    positionTitle: string | null | undefined,
    proposed: number,
): Placement {
    if (!bands?.length || Number.isNaN(proposed)) return null;
    const role = (positionTitle ?? '').trim().toLowerCase();
    if (!role) return null;
    const band = bands.find(
        (b) => (b.position_role ?? '').trim().toLowerCase() === role,
    );
    if (!band) return null;
    const min = toNum(band.min_salary);
    const max = toNum(band.max_salary);
    if (Number.isNaN(min) || Number.isNaN(max)) return null;
    const position = proposed < min ? 'under' : proposed > max ? 'over' : 'in';
    return { position, band };
}

function PlacementChip({ placement }: { placement: Placement }) {
    if (!placement) return null;
    const { position } = placement;
    return (
        <StatusBadge
            status={position}
            tone={
                position === 'in'
                    ? 'success'
                    : position === 'under'
                      ? 'warning'
                      : 'critical'
            }
            label={
                position === 'in'
                    ? 'In band'
                    : position === 'under'
                      ? 'Under band'
                      : 'Over band'
            }
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Adjustment line row                                               */
/* ------------------------------------------------------------------ */

function AdjustmentRow({
    item,
    index,
    employees,
    takenIds,
    bands,
    onEmployee,
    onField,
    onRemove,
}: {
    item: LineItem;
    index: number;
    employees: ReviewBuilderEmployee[];
    takenIds: Set<string>;
    bands?: ReviewBuilderBand[];
    onEmployee: (idx: number, profileId: string) => void;
    onField: (idx: number, key: keyof LineItem, value: string) => void;
    onRemove: (idx: number) => void;
}) {
    const employee = employees.find(
        (e) => String(e.id) === item.employee_profile_id,
    );
    const proposed = toNum(item.proposed_salary);
    const current = toNum(item.current_salary);
    const delta =
        !Number.isNaN(proposed) && !Number.isNaN(current)
            ? proposed - current
            : NaN;
    const pct = toNum(item.change_percentage);
    const placement = placeInBand(bands, employee?.position_title, proposed);

    // Available employees = unselected ones + the one this row already holds.
    const options = employees
        .filter(
            (e) =>
                !takenIds.has(String(e.id)) ||
                String(e.id) === item.employee_profile_id,
        )
        .map((e) => ({ value: String(e.id), label: e.user.name }));

    return (
        <div className="rounded-xl border border-border bg-card/60 p-3.5">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-[11px] font-bold text-primary">
                    {employee ? initials(employee.user.name) : index + 1}
                </span>
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Field label="Employee" required>
                            <SelectInput
                                value={item.employee_profile_id}
                                onChange={(v) => onEmployee(index, v)}
                                placeholder="Select employee…"
                                options={options}
                                ariaLabel={`Employee for line ${index + 1}`}
                            />
                        </Field>
                        <div className="flex items-end justify-between gap-2">
                            <div className="min-w-0">
                                <span className="block text-[11px] text-muted-foreground">
                                    Role
                                </span>
                                <span className="block truncate text-sm font-medium">
                                    {employee?.position_title || '—'}
                                </span>
                            </div>
                            {placement ? (
                                <PlacementChip placement={placement} />
                            ) : null}
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <Field label="Current salary" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={item.current_salary}
                                onChange={(e) =>
                                    onField(
                                        index,
                                        'current_salary',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Proposed salary" required>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                value={item.proposed_salary}
                                onChange={(e) =>
                                    onField(
                                        index,
                                        'proposed_salary',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Change" hint="auto">
                            <div className="flex h-9 items-center rounded-md border border-border bg-muted px-3 text-sm tabular-nums">
                                {Number.isNaN(pct) ? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                ) : (
                                    <span
                                        className={cn(
                                            pct > 0
                                                ? 'text-status-success'
                                                : pct < 0
                                                  ? 'text-status-critical'
                                                  : 'text-muted-foreground',
                                        )}
                                    >
                                        {pct > 0 ? '+' : ''}
                                        {pct}% · {signedCurrency(delta)}
                                    </span>
                                )}
                            </div>
                        </Field>
                    </div>

                    <Field label="Justification" hint="optional">
                        <Input
                            value={item.justification}
                            onChange={(e) =>
                                onField(index, 'justification', e.target.value)
                            }
                            placeholder="Reason for the change…"
                        />
                    </Field>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0 text-muted-foreground hover:text-status-critical"
                    onClick={() => onRemove(index)}
                    aria-label={`Remove line ${index + 1}`}
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Builder dialog                                                    */
/* ------------------------------------------------------------------ */

export function ReviewBuilderDialog({
    open,
    onClose,
    employees,
    reviewCycles,
    bands,
}: {
    open: boolean;
    onClose: () => void;
    employees: ReviewBuilderEmployee[];
    reviewCycles: ReviewCycleOption[];
    /** Optional active salary bands for per-line in/under/over placement. */
    bands?: ReviewBuilderBand[];
}) {
    const [index, setIndex] = useState(0);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState({
        title: '',
        review_cycle: reviewCycles[0]?.value ?? 'annual',
        effective_date: '',
        budget_amount: '',
        notes: '',
        items: [] as LineItem[],
    });

    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((prev) => ({ ...prev, [key]: value }));

    const isLast = index === STEPS.length - 1;

    /* ---- line-item mutations (auto change %) ---- */
    const addItem = () => set('items', [...form.items, emptyLine()]);

    const removeItem = (idx: number) =>
        set(
            'items',
            form.items.filter((_, i) => i !== idx),
        );

    const recalc = (line: LineItem): LineItem => {
        const cur = toNum(line.current_salary);
        const prop = toNum(line.proposed_salary);
        if (cur > 0 && !Number.isNaN(prop)) {
            return {
                ...line,
                change_percentage: (((prop - cur) / cur) * 100).toFixed(2),
            };
        }
        return { ...line, change_percentage: '' };
    };

    const updateField = (idx: number, key: keyof LineItem, value: string) =>
        set(
            'items',
            form.items.map((line, i) =>
                i === idx
                    ? key === 'current_salary' || key === 'proposed_salary'
                        ? recalc({ ...line, [key]: value })
                        : { ...line, [key]: value }
                    : line,
            ),
        );

    const selectEmployee = (idx: number, profileId: string) => {
        const emp = employees.find((e) => String(e.id) === profileId);
        set(
            'items',
            form.items.map((line, i) =>
                i === idx
                    ? recalc({
                          ...line,
                          employee_profile_id: profileId,
                          // Seed current salary from the employee record (editable).
                          current_salary:
                              line.current_salary === '' && emp?.annual_salary
                                  ? String(emp.annual_salary)
                                  : line.current_salary,
                      })
                    : line,
            ),
        );
    };

    const takenIds = useMemo(
        () =>
            new Set(
                form.items.map((i) => i.employee_profile_id).filter(Boolean),
            ),
        [form.items],
    );

    /* ---- budget vs committed tally ---- */
    const committed = useMemo(
        () =>
            form.items.reduce((sum, line) => {
                const cur = toNum(line.current_salary);
                const prop = toNum(line.proposed_salary);
                if (Number.isNaN(cur) || Number.isNaN(prop)) return sum;
                return sum + (prop - cur);
            }, 0),
        [form.items],
    );
    const budget = toNum(form.budget_amount);
    const hasBudget = !Number.isNaN(budget) && budget > 0;
    const remaining = hasBudget ? budget - committed : NaN;
    const overBudget = hasBudget && committed > budget;
    const budgetPct = hasBudget
        ? Math.min(100, Math.max(0, (committed / budget) * 100))
        : 0;

    /* ---- validation ---- */
    const linesValid =
        form.items.length > 0 &&
        form.items.every(
            (l) =>
                l.employee_profile_id !== '' &&
                !Number.isNaN(toNum(l.current_salary)) &&
                toNum(l.current_salary) >= 0 &&
                !Number.isNaN(toNum(l.proposed_salary)) &&
                toNum(l.proposed_salary) >= 0,
        );

    const stepValid = (i: number) => {
        if (i === 0)
            return (
                form.title.trim() !== '' &&
                form.effective_date !== '' &&
                !!form.review_cycle
            );
        if (i === 1) return linesValid;
        return true;
    };
    const formValid = stepValid(0) && stepValid(1);

    const completeness = useMemo(() => {
        const checks = [
            form.title.trim() !== '',
            !!form.review_cycle,
            form.effective_date !== '',
            form.items.length > 0,
            linesValid,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [
        form.title,
        form.review_cycle,
        form.effective_date,
        form.items.length,
        linesValid,
    ]);

    const goTo = (i: number) => {
        const target = Math.max(0, Math.min(STEPS.length - 1, i));
        const forwardOk = Array.from({ length: target }, (_, s) =>
            stepValid(s),
        ).every(Boolean);
        if (target <= index || forwardOk) setIndex(target);
    };

    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        if (!formValid) return;
        setSaving(true);
        router.post(
            '/hr/compensation/reviews',
            {
                title: form.title,
                review_cycle: form.review_cycle,
                effective_date: form.effective_date,
                budget_amount:
                    form.budget_amount === '' ? null : form.budget_amount,
                notes: form.notes,
                items: form.items.map((l) => ({
                    employee_profile_id: l.employee_profile_id,
                    current_salary: l.current_salary,
                    proposed_salary: l.proposed_salary,
                    change_percentage:
                        l.change_percentage === '' ? '0' : l.change_percentage,
                    justification: l.justification,
                })),
            },
            {
                preserveScroll: true,
                onError: () => {
                    toast.error(
                        'Could not create the review. Check the highlighted fields.',
                    );
                    setIndex(0);
                },
                onFinish: () => setSaving(false),
                // Success redirects to the reviews index (Inertia follows it).
            },
        );
    };

    const cycleLabel =
        reviewCycles.find((c) => c.value === form.review_cycle)?.label ??
        form.review_cycle;
    const raises = form.items.filter(
        (l) => toNum(l.proposed_salary) > toNum(l.current_salary),
    ).length;

    const railExtra = (
        <div className="rounded-lg border border-border bg-card/60 p-3">
            <div className="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                Budget vs committed
            </div>
            <div className="space-y-1.5">
                <div className="flex items-baseline justify-between">
                    <span className="text-[11px] text-muted-foreground">
                        Committed
                    </span>
                    <span className="text-sm font-bold tabular-nums">
                        {currency(committed)}
                    </span>
                </div>
                <div className="flex items-baseline justify-between">
                    <span className="text-[11px] text-muted-foreground">
                        Budget
                    </span>
                    <span className="text-sm font-medium tabular-nums">
                        {hasBudget ? currency(budget) : '—'}
                    </span>
                </div>
                {hasBudget ? (
                    <>
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full transition-[width] duration-500',
                                    overBudget
                                        ? 'bg-status-critical'
                                        : 'bg-primary',
                                )}
                                style={{ width: `${budgetPct}%` }}
                            />
                        </div>
                        <div
                            className={cn(
                                'text-[11px] font-medium tabular-nums',
                                overBudget
                                    ? 'text-status-critical'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {overBudget
                                ? `${currency(committed - budget)} over budget`
                                : `${currency(remaining)} remaining`}
                        </div>
                    </>
                ) : (
                    <p className="text-[11px] text-muted-foreground">
                        Add a budget on step 1 to track headroom.
                    </p>
                )}
            </div>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Build pay review"
            description="Create a compensation review cycle and its adjustment lines."
            railIcon={ClipboardCheck}
            railTitle="Pay review"
            railSub="Compensation"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={completeness}
            railExtra={railExtra}
            footerStart={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <>
                    {index > 0 ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => goTo(index - 1)}
                        >
                            Back
                        </Button>
                    ) : null}
                    {!isLast ? (
                        <Button
                            type="button"
                            onClick={() => goTo(index + 1)}
                            disabled={!stepValid(index)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={() => submit()}
                            disabled={saving || !formValid}
                        >
                            {saving ? 'Creating…' : 'Create review'}
                        </Button>
                    )}
                </>
            }
        >
            {/* Step 1 — cycle & identity */}
            {index === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarRange}
                        title="Cycle & identity"
                        blurb="Name the review, pick its cycle, and set when adjustments take effect."
                    />
                    <div className="space-y-4">
                        <Field label="Review cycle" required>
                            <Segmented
                                value={form.review_cycle}
                                onChange={(v) => set('review_cycle', v)}
                                options={reviewCycles.map((c) => ({
                                    value: c.value,
                                    label: c.label,
                                    icon: CYCLE_ICON[c.value],
                                }))}
                            />
                            {CYCLE_BLURB[form.review_cycle] ? (
                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    {CYCLE_BLURB[form.review_cycle]}
                                </p>
                            ) : null}
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="Title" required span>
                                <Input
                                    value={form.title}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    placeholder="e.g. FY26 Annual Review"
                                />
                            </Field>
                            <Field label="Effective date" required>
                                <Input
                                    type="date"
                                    value={form.effective_date}
                                    onChange={(e) =>
                                        set('effective_date', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Budget amount" hint="optional · NZD">
                                <Input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.budget_amount}
                                    onChange={(e) =>
                                        set('budget_amount', e.target.value)
                                    }
                                    placeholder="0.00"
                                />
                            </Field>
                            <Field label="Notes" hint="optional" span>
                                <Textarea
                                    rows={3}
                                    value={form.notes}
                                    onChange={(e) =>
                                        set('notes', e.target.value)
                                    }
                                    placeholder="Context, scope, or guidance for approvers…"
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 2 — adjustment lines */}
            {index === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Employees & adjustments"
                        blurb="Add each person under review with their current and proposed salary. Change % is derived automatically."
                    />
                    {form.items.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border px-4 py-10 text-center">
                            <span className="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-full bg-primary/10 text-primary">
                                <Users className="h-5 w-5" />
                            </span>
                            <p className="text-sm font-medium">
                                No employees added yet
                            </p>
                            <p className="mx-auto mt-1 max-w-sm text-xs text-muted-foreground">
                                Add adjustment lines for the people in this
                                review cycle.
                            </p>
                            <Button
                                type="button"
                                className="mt-4"
                                onClick={addItem}
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add employee
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {form.items.map((item, idx) => (
                                <AdjustmentRow
                                    key={idx}
                                    item={item}
                                    index={idx}
                                    employees={employees}
                                    takenIds={takenIds}
                                    bands={bands}
                                    onEmployee={selectEmployee}
                                    onField={updateField}
                                    onRemove={removeItem}
                                />
                            ))}
                            <div className="flex items-center justify-between gap-3 pt-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addItem}
                                    disabled={takenIds.size >= employees.length}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Add employee
                                </Button>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    Committed delta:{' '}
                                    <span
                                        className={cn(
                                            'font-semibold',
                                            overBudget
                                                ? 'text-status-critical'
                                                : 'text-foreground',
                                        )}
                                    >
                                        {currency(committed)}
                                    </span>
                                </span>
                            </div>
                            {overBudget ? (
                                <InfoCard icon={Scale} tone="crit">
                                    Committed adjustments ({currency(committed)}
                                    ) exceed the budget of {currency(budget)} by{' '}
                                    <strong>
                                        {currency(committed - budget)}
                                    </strong>
                                    .
                                </InfoCard>
                            ) : null}
                        </div>
                    )}
                </WizardStepPane>
            ) : null}

            {/* Step 3 — review */}
            {index === 2 ? (
                <WizardStepPane>
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={completeness} />
                        <div>
                            <h2 className="text-lg font-bold">
                                Review &amp; create
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Confirm the cycle and adjustment lines, then
                                create the review.
                            </p>
                        </div>
                    </div>

                    {/* Budget tally */}
                    <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div className="rounded-xl border border-border bg-card/60 p-3 text-center">
                            <div className="text-lg font-bold tabular-nums">
                                {hasBudget ? currency(budget) : '—'}
                            </div>
                            <div className="text-[11px] text-muted-foreground">
                                Budget
                            </div>
                        </div>
                        <div className="rounded-xl border border-border bg-card/60 p-3 text-center">
                            <div className="text-lg font-bold tabular-nums">
                                {currency(committed)}
                            </div>
                            <div className="text-[11px] text-muted-foreground">
                                Committed
                            </div>
                        </div>
                        <div
                            className={cn(
                                'col-span-2 rounded-xl border p-3 text-center sm:col-span-1',
                                overBudget
                                    ? 'border-status-critical/35 bg-status-critical-bg'
                                    : 'border-border bg-card/60',
                            )}
                        >
                            <div
                                className={cn(
                                    'text-lg font-bold tabular-nums',
                                    overBudget
                                        ? 'text-status-critical'
                                        : 'text-status-success',
                                )}
                            >
                                {hasBudget
                                    ? overBudget
                                        ? signedCurrency(remaining)
                                        : currency(remaining)
                                    : '—'}
                            </div>
                            <div className="text-[11px] text-muted-foreground">
                                {overBudget ? 'Over budget' : 'Remaining'}
                            </div>
                        </div>
                    </div>

                    {overBudget ? (
                        <div className="mb-4 grid grid-cols-1">
                            <InfoCard icon={Scale} tone="crit">
                                This review commits{' '}
                                <strong>{currency(committed - budget)}</strong>{' '}
                                more than the budget. You can still create it —
                                approvers will see the overage.
                            </InfoCard>
                        </div>
                    ) : null}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={CalendarRange}
                            title="Cycle & identity"
                            onEdit={() => setIndex(0)}
                        >
                            <ReviewRow label="Title" value={form.title} />
                            <ReviewRow label="Cycle" value={cycleLabel} />
                            <ReviewRow
                                label="Effective"
                                value={formatDate(form.effective_date)}
                            />
                            <ReviewRow
                                label="Budget"
                                value={hasBudget ? currency(budget) : undefined}
                            />
                            <ReviewRow label="Notes" value={form.notes} />
                        </ReviewCard>
                        <ReviewCard
                            icon={DollarSign}
                            title="Adjustment summary"
                            onEdit={() => setIndex(1)}
                        >
                            <ReviewRow
                                label="People"
                                value={String(form.items.length)}
                            />
                            <ReviewRow label="Raises" value={String(raises)} />
                            <ReviewRow
                                label="Total uplift"
                                value={signedCurrency(committed)}
                            />
                        </ReviewCard>
                    </div>

                    {form.items.length > 0 ? (
                        <ReviewCard
                            icon={Users}
                            title={`Lines (${form.items.length})`}
                            span
                            onEdit={() => setIndex(1)}
                        >
                            <ul className="divide-y divide-border">
                                {form.items.map((line, idx) => {
                                    const emp = employees.find(
                                        (e) =>
                                            String(e.id) ===
                                            line.employee_profile_id,
                                    );
                                    const cur = toNum(line.current_salary);
                                    const prop = toNum(line.proposed_salary);
                                    const delta =
                                        !Number.isNaN(cur) &&
                                        !Number.isNaN(prop)
                                            ? prop - cur
                                            : NaN;
                                    const placement = placeInBand(
                                        bands,
                                        emp?.position_title,
                                        prop,
                                    );
                                    return (
                                        <li
                                            key={idx}
                                            className="flex items-center justify-between gap-3 py-2"
                                        >
                                            <span className="flex min-w-0 items-center gap-2.5">
                                                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                    {emp
                                                        ? initials(
                                                              emp.user.name,
                                                          )
                                                        : idx + 1}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-medium">
                                                        {emp?.user.name ??
                                                            'Unassigned'}
                                                    </span>
                                                    <span className="block text-[11px] text-muted-foreground tabular-nums">
                                                        {currency(cur)} →{' '}
                                                        {currency(prop)}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                {placement ? (
                                                    <PlacementChip
                                                        placement={placement}
                                                    />
                                                ) : null}
                                                <span
                                                    className={cn(
                                                        'text-[13px] font-semibold tabular-nums',
                                                        Number.isNaN(delta)
                                                            ? 'text-muted-foreground'
                                                            : delta > 0
                                                              ? 'text-status-success'
                                                              : delta < 0
                                                                ? 'text-status-critical'
                                                                : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {Number.isNaN(delta)
                                                        ? '—'
                                                        : signedCurrency(delta)}
                                                </span>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </ReviewCard>
                    ) : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default ReviewBuilderDialog;
