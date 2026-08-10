/* eslint-disable no-restricted-syntax -- Wizard footer + mileage rail preview use
 * styled native elements to match the Add-Client modal chrome (see
 * components/wizard/shell.tsx). Every colour is a semantic design token. */
import { router } from '@inertiajs/react';
import {
    Car,
    ClipboardCheck,
    FileText,
    Gauge,
    Plus,
    Receipt,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Checkbox } from '@/components/ui/checkbox';
import {
    FileDropzone,
    formatFileSize,
    StagedFileCard,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    SubHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from './wizard';

/* ------------------------------------------------------------------ */
/*  Types & constants                                                   */
/* ------------------------------------------------------------------ */

/** Standard (non-mileage) expense line. Mirrors the store request item shape. */
type LineItem = {
    uid: number;
    description: string;
    category: string;
    amount: string;
    expense_date: string;
    tax_amount: string;
    notes: string;
    receipt: File | null;
};

const CATEGORY_LABELS: Record<string, string> = {
    travel: 'Travel',
    meals: 'Meals',
    accommodation: 'Accommodation',
    supplies: 'Supplies',
    mileage: 'Mileage',
    other: 'Other',
};

// Sentinel for the "file as myself" option — Radix SelectItem crashes on an
// empty-string value (documented house rule), so we never use '' as a value.
const SELF = '__self__';

// Default category list mirrors ExpenseService::CATEGORIES so the dialog works
// before the host page is wired to pass `categories` down from the controller.
const DEFAULT_CATEGORIES = [
    'travel',
    'meals',
    'accommodation',
    'supplies',
    'mileage',
    'other',
];

const STEPS: readonly WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Title & period', icon: FileText },
    { key: 'items', label: 'Items', blurb: 'Lines & mileage', icon: Receipt },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & submit',
        icon: ClipboardCheck,
    },
];

const nzd = (value: number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(
        Number.isFinite(value) ? value : 0,
    );

const toLabel = (cat: string) => CATEGORY_LABELS[cat] ?? cat;

let uidSeq = 0;
const nextUid = () => ++uidSeq;

const blankItem = (): LineItem => ({
    uid: nextUid(),
    description: '',
    category: 'other',
    amount: '',
    expense_date: '',
    tax_amount: '',
    notes: '',
    receipt: null,
});

const num = (v: string) => {
    const n = parseFloat(v);
    return Number.isNaN(n) ? 0 : n;
};

/* ------------------------------------------------------------------ */
/*  Dialog                                                              */
/* ------------------------------------------------------------------ */

export function ExpenseClaimDialog({
    open,
    onClose,
    /** Read-only IRD mileage rate (NZD/km) from config('finance.mileage_rate_per_km').
     *  Defaults to 0 so the dialog compiles/renders before the controller prop lands. */
    mileageRatePerKm = 0,
    categories = DEFAULT_CATEGORIES,
    currency = 'NZD',
    /** Managers only: employees the claim can be filed on behalf of. */
    employees = [],
    canFileOnBehalf = false,
}: {
    open: boolean;
    onClose: () => void;
    mileageRatePerKm?: number;
    categories?: string[];
    currency?: string;
    employees?: { id: number; name: string }[];
    canFileOnBehalf?: boolean;
}) {
    const wiz = useWizard(STEPS.length);

    const [title, setTitle] = useState('');
    const [claimDate, setClaimDate] = useState(
        new Date().toISOString().slice(0, 10),
    );
    const [notes, setNotes] = useState('');
    // Radix SelectItem throws on an empty-string value, so "myself" uses a
    // sentinel; otherwise the value is the target employee's user id (string).
    const [onBehalfOf, setOnBehalfOf] = useState(SELF);
    const [items, setItems] = useState<LineItem[]>([blankItem()]);

    // Mileage line is a config-driven computed item — the user enters distance,
    // the rate is read-only, and amount = distance × rate.
    const [mileageOn, setMileageOn] = useState(false);
    const [mileageDate, setMileageDate] = useState(
        new Date().toISOString().slice(0, 10),
    );
    const [distanceKm, setDistanceKm] = useState('');
    const [mileageDesc, setMileageDesc] = useState('');

    const [saving, setSaving] = useState(false);

    /* ----- derived ----- */

    const rate = Number.isFinite(mileageRatePerKm) ? mileageRatePerKm : 0;
    const km = num(distanceKm);
    const mileageAmount = useMemo(
        () => Math.round(km * rate * 100) / 100,
        [km, rate],
    );

    // An all-blank standard row is ignored (so a mileage-only claim is valid and
    // submittable); a partially-filled row still has to be completed.
    const isBlankItem = (it: LineItem) =>
        it.description.trim() === '' && num(it.amount) <= 0 && !it.receipt;
    const filledItems = items.filter((it) => !isBlankItem(it));

    const itemsTotal = filledItems.reduce((sum, it) => sum + num(it.amount), 0);
    const total = itemsTotal + (mileageOn ? mileageAmount : 0);

    const mileageValid =
        !mileageOn || (km > 0 && rate > 0 && mileageDate !== '');

    const standardItemsValid = filledItems.every(
        (it) =>
            it.description.trim() !== '' &&
            num(it.amount) > 0 &&
            it.expense_date !== '',
    );

    const basicsValid = title.trim() !== '' && claimDate !== '';
    // Need at least one payable line — either a valid standard item or mileage.
    const hasPayableLine =
        filledItems.length > 0 || (mileageOn && mileageAmount > 0);
    const itemsStepValid = standardItemsValid && mileageValid && hasPayableLine;

    const stepValid = (i: number) => {
        if (i === 0) return basicsValid;
        if (i === 1) return itemsStepValid;
        return true;
    };
    const formValid = basicsValid && itemsStepValid;

    const completeness = useMemo(() => {
        const checks = [
            title.trim() !== '',
            claimDate !== '',
            hasPayableLine,
            standardItemsValid && mileageValid,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [title, claimDate, hasPayableLine, standardItemsValid, mileageValid]);

    /* ----- item mutators ----- */

    const addItem = () => setItems((prev) => [...prev, blankItem()]);
    const removeItem = (uid: number) =>
        setItems((prev) => prev.filter((it) => it.uid !== uid));
    const patchItem = (uid: number, patch: Partial<LineItem>) =>
        setItems((prev) =>
            prev.map((it) => (it.uid === uid ? { ...it, ...patch } : it)),
        );

    const reset = () => {
        setTitle('');
        setClaimDate(new Date().toISOString().slice(0, 10));
        setNotes('');
        setItems([blankItem()]);
        setMileageOn(false);
        setMileageDate(new Date().toISOString().slice(0, 10));
        setDistanceKm('');
        setMileageDesc('');
        wiz.reset();
    };

    const close = () => {
        reset();
        onClose();
    };

    /* ----- submit ----- */

    const submit = () => {
        if (!formValid || saving) return;
        setSaving(true);

        // The mileage line is submitted as a normal `mileage`-category item: the
        // computed amount plus a notes string that records distance × rate so the
        // calculation round-trips into the stored claim (the store endpoint does
        // not accept a distance_km field — see backendNeeded).
        const payloadItems = [
            ...filledItems.map((it) => ({
                description: it.description,
                category: it.category,
                amount: num(it.amount),
                expense_date: it.expense_date,
                tax_amount: it.tax_amount ? num(it.tax_amount) : null,
                notes: it.notes || null,
                receipt: it.receipt,
            })),
            ...(mileageOn && mileageAmount > 0
                ? [
                      {
                          description:
                              mileageDesc.trim() ||
                              `Mileage — ${km} km @ ${nzd(rate, currency)}/km`,
                          category: 'mileage',
                          amount: mileageAmount,
                          expense_date: mileageDate,
                          tax_amount: null,
                          notes: `${km} km × ${nzd(rate, currency)}/km (IRD rate)`,
                          receipt: null as File | null,
                      },
                  ]
                : []),
        ];

        router.post(
            // Canonical store route (named compensation.expenses.store → POST
            // /hr/compensation/expenses). NB: the path is /expenses, not /expenses/store.
            '/hr/compensation/expenses',
            {
                title,
                notes: notes || null,
                currency,
                on_behalf_user_id:
                    canFileOnBehalf && onBehalfOf !== SELF
                        ? Number(onBehalfOf)
                        : null,
                items: payloadItems,
            },
            {
                // Any per-item receipt File forces a multipart submission.
                forceFormData: true,
                preserveScroll: true,
                onSuccess: (page: { props: Record<string, unknown> }) => {
                    // A back()->with('error') redirect fires Inertia onSuccess
                    // (not onError) — gate the success path on the flash bag of
                    // the fresh page (see reference_inertia_flash_error).
                    const flash = (page.props as { flash?: { error?: string } })
                        .flash;
                    if (flash?.error) {
                        toast.error(flash.error);
                        setSaving(false);
                        return;
                    }
                    toast.success('Expense claim created.');
                    close();
                },
                onError: () => {
                    toast.error(
                        'Could not create the claim. Check the highlighted fields.',
                    );
                    setSaving(false);
                },
            },
        );
    };

    /* ----- rail preview ----- */

    const railExtra = (
        <div className="rounded-lg border border-border bg-card/60 p-3">
            <div className="mb-1.5 flex items-center justify-between text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                <span>Running total</span>
            </div>
            <div className="text-xl font-bold tabular-nums">
                {nzd(total, currency)}
            </div>
            <div className="mt-1 text-[11px] text-muted-foreground">
                {items.length} item{items.length === 1 ? '' : 's'}
                {mileageOn && mileageAmount > 0 ? ' + mileage' : ''}
            </div>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New expense claim"
            description="Add expense line items and submit a reimbursement claim."
            railIcon={Receipt}
            railTitle="New claim"
            railSub="Compensation"
            steps={STEPS}
            stepIndex={wiz.index}
            onStepClick={(i) => {
                const forwardOk = Array.from({ length: i }, (_, s) =>
                    stepValid(s),
                ).every(Boolean);
                if (i <= wiz.index || forwardOk) wiz.goTo(i);
            }}
            pct={completeness}
            railExtra={railExtra}
            footerStart={
                <button
                    type="button"
                    onClick={close}
                    className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                >
                    Cancel
                </button>
            }
            footerEnd={
                <>
                    {!wiz.isFirst ? (
                        <button
                            type="button"
                            onClick={wiz.back}
                            className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    {!wiz.isLast ? (
                        <button
                            type="button"
                            onClick={wiz.next}
                            disabled={!stepValid(wiz.index)}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                !stepValid(wiz.index) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            Continue
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!formValid || saving}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!formValid || saving) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {saving ? 'Creating…' : 'Create claim'}
                        </button>
                    )}
                </>
            }
        >
            {/* Step 1 — basics */}
            {wiz.index === 0 ? (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Claim basics"
                        blurb="Give the claim a title and the period it covers."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {canFileOnBehalf && employees.length > 0 ? (
                            <Field
                                label="File on behalf of"
                                hint="defaults to you"
                                span
                            >
                                <SelectInput
                                    value={onBehalfOf}
                                    onChange={setOnBehalfOf}
                                    placeholder="Myself"
                                    ariaLabel="File on behalf of"
                                    options={[
                                        { value: SELF, label: 'Myself' },
                                        ...employees.map((e) => ({
                                            value: String(e.id),
                                            label: e.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        <Field label="Title" required span>
                            <Input
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                placeholder="e.g. March client-visit expenses"
                            />
                        </Field>
                        <Field
                            label="Claim date"
                            required
                            hint="period this claim covers"
                        >
                            <Input
                                type="date"
                                value={claimDate}
                                onChange={(e) => setClaimDate(e.target.value)}
                            />
                        </Field>
                        <Field label="Notes" hint="optional" span>
                            <Textarea
                                rows={3}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Any context for the approver…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 2 — items */}
            {wiz.index === 1 ? (
                <WizardStepPane>
                    <StepHead
                        icon={Receipt}
                        title="Expense items"
                        blurb="Add each expense line. Attach a receipt where you have one."
                    />

                    <div className="space-y-4">
                        {items.map((it, index) => (
                            <div
                                key={it.uid}
                                className="rounded-xl border border-border bg-card/50 p-4"
                            >
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-[13px] font-semibold text-muted-foreground">
                                        Item {index + 1}
                                    </span>
                                    {items.length > 1 ? (
                                        <button
                                            type="button"
                                            onClick={() => removeItem(it.uid)}
                                            aria-label={`Remove item ${index + 1}`}
                                            className="text-muted-foreground transition-colors hover:text-status-critical"
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    ) : null}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label="Description" required span>
                                        <Input
                                            value={it.description}
                                            onChange={(e) =>
                                                patchItem(it.uid, {
                                                    description: e.target.value,
                                                })
                                            }
                                            placeholder="What was the expense for?"
                                        />
                                    </Field>
                                    <Field label="Category">
                                        <SelectInput
                                            value={it.category}
                                            onChange={(v) =>
                                                patchItem(it.uid, {
                                                    category: v,
                                                })
                                            }
                                            placeholder="Category"
                                            ariaLabel="Expense category"
                                            options={categories.map((c) => ({
                                                value: c,
                                                label: toLabel(c),
                                            }))}
                                        />
                                    </Field>
                                    <Field label="Amount" required>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            value={it.amount}
                                            onChange={(e) =>
                                                patchItem(it.uid, {
                                                    amount: e.target.value,
                                                })
                                            }
                                            placeholder="0.00"
                                        />
                                    </Field>
                                    <Field label="Date" required>
                                        <Input
                                            type="date"
                                            value={it.expense_date}
                                            onChange={(e) =>
                                                patchItem(it.uid, {
                                                    expense_date:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Tax amount" hint="optional">
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={it.tax_amount}
                                            onChange={(e) =>
                                                patchItem(it.uid, {
                                                    tax_amount: e.target.value,
                                                })
                                            }
                                            placeholder="0.00"
                                        />
                                    </Field>
                                    <div className="hidden sm:block" />
                                    <div className="sm:col-span-3">
                                        <span className="mb-1.5 block text-[13px] font-medium text-foreground">
                                            Receipt{' '}
                                            <span className="text-xs font-normal text-muted-foreground">
                                                optional · PDF or image, max 5MB
                                            </span>
                                        </span>
                                        {it.receipt ? (
                                            <StagedFileCard
                                                file={it.receipt}
                                                onRemove={() =>
                                                    patchItem(it.uid, {
                                                        receipt: null,
                                                    })
                                                }
                                            />
                                        ) : (
                                            <FileDropzone
                                                multiple={false}
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                title="Drag & drop a receipt"
                                                hint="PDF or image, up to 5MB"
                                                onFiles={(files) =>
                                                    patchItem(it.uid, {
                                                        receipt:
                                                            files[0] ?? null,
                                                    })
                                                }
                                            />
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}

                        <button
                            type="button"
                            onClick={addItem}
                            className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-3 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
                        >
                            <Plus className="h-4 w-4" /> Add item
                        </button>

                        {/* ── Mileage line (config-driven) ── */}
                        <div
                            className={cn(
                                'rounded-xl border p-4 transition-colors',
                                mileageOn
                                    ? 'border-primary/40 bg-primary/5'
                                    : 'border-border bg-card/50',
                            )}
                        >
                            <label className="flex items-start gap-3">
                                <Checkbox
                                    checked={mileageOn}
                                    onCheckedChange={(v) =>
                                        setMileageOn(v === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span className="min-w-0">
                                    <span className="flex items-center gap-1.5 text-sm font-semibold">
                                        <Car className="h-4 w-4 text-primary" />
                                        Add a mileage line
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Reimburse vehicle use at the IRD rate of{' '}
                                        {nzd(rate, currency)}/km. Enter the
                                        distance — the amount is calculated for
                                        you.
                                    </span>
                                </span>
                            </label>

                            {mileageOn ? (
                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    <SubHead icon={Gauge}>
                                        Mileage details
                                    </SubHead>
                                    <Field label="Distance (km)" required>
                                        <Input
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            value={distanceKm}
                                            onChange={(e) =>
                                                setDistanceKm(e.target.value)
                                            }
                                            placeholder="0"
                                        />
                                    </Field>
                                    <Field
                                        label="Rate (NZD/km)"
                                        hint="from config — read-only"
                                    >
                                        <Input
                                            type="text"
                                            value={nzd(rate, currency)}
                                            readOnly
                                            aria-readonly="true"
                                            tabIndex={-1}
                                            className="cursor-not-allowed bg-muted text-muted-foreground"
                                        />
                                    </Field>
                                    <Field label="Date" required>
                                        <Input
                                            type="date"
                                            value={mileageDate}
                                            onChange={(e) =>
                                                setMileageDate(e.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Description"
                                        hint="optional"
                                        span
                                    >
                                        <Input
                                            value={mileageDesc}
                                            onChange={(e) =>
                                                setMileageDesc(e.target.value)
                                            }
                                            placeholder={`Mileage — ${
                                                km || 0
                                            } km`}
                                        />
                                    </Field>
                                    <div className="sm:col-span-1">
                                        <span className="mb-1.5 block text-[13px] font-medium text-foreground">
                                            Calculated amount
                                        </span>
                                        <div className="flex h-9 items-center rounded-md border border-primary/30 bg-primary/10 px-3 text-sm font-bold text-primary tabular-nums">
                                            {nzd(mileageAmount, currency)}
                                        </div>
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        {/* Running total */}
                        <div className="flex items-center justify-between rounded-xl border border-border bg-muted/30 px-4 py-3">
                            <span className="text-sm font-medium text-muted-foreground">
                                Claim total
                            </span>
                            <span className="text-xl font-bold tabular-nums">
                                {nzd(total, currency)}
                            </span>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* Step 3 — review */}
            {wiz.index === 2 ? (
                <WizardStepPane>
                    {/* Warm gradient summary card */}
                    <div className="mb-5 overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-primary/15 via-primary/5 to-transparent p-5">
                        <div className="flex items-center justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 text-xs font-semibold tracking-wide text-primary uppercase">
                                    <Receipt className="h-4 w-4" /> Expense
                                    claim
                                </div>
                                <h2 className="mt-1 truncate text-xl font-bold">
                                    {title || 'Untitled claim'}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {items.length} item
                                    {items.length === 1 ? '' : 's'}
                                    {mileageOn && mileageAmount > 0
                                        ? ' + mileage'
                                        : ''}{' '}
                                    · claim date {claimDate || '—'}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <div className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    Total
                                </div>
                                <div className="text-2xl font-bold tabular-nums">
                                    {nzd(total, currency)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={FileText}
                            title="Basics"
                            onEdit={() => wiz.goTo(0)}
                        >
                            <ReviewRow label="Title" value={title} />
                            <ReviewRow label="Claim date" value={claimDate} />
                            <ReviewRow label="Notes" value={notes} />
                        </ReviewCard>

                        <ReviewCard
                            icon={Receipt}
                            title="Items"
                            onEdit={() => wiz.goTo(1)}
                        >
                            {items.map((it, i) => (
                                <ReviewRow
                                    key={it.uid}
                                    label={
                                        it.description.trim() ||
                                        `Item ${i + 1} (${toLabel(it.category)})`
                                    }
                                    value={
                                        <span className="inline-flex items-center gap-1.5">
                                            {it.receipt ? (
                                                <FileText className="h-3 w-3 text-muted-foreground" />
                                            ) : null}
                                            {nzd(num(it.amount), currency)}
                                        </span>
                                    }
                                />
                            ))}
                            {mileageOn && mileageAmount > 0 ? (
                                <ReviewRow
                                    label={`Mileage · ${km} km @ ${nzd(
                                        rate,
                                        currency,
                                    )}/km`}
                                    value={nzd(mileageAmount, currency)}
                                />
                            ) : null}
                        </ReviewCard>

                        {items.some((it) => it.receipt) ? (
                            <div className="rounded-xl border border-border bg-card/70 p-4 sm:col-span-2">
                                <div className="mb-2 flex items-center gap-2 text-sm font-bold">
                                    <FileText className="h-4 w-4 text-primary" />{' '}
                                    Attached receipts
                                </div>
                                <ul className="space-y-1.5">
                                    {items
                                        .filter((it) => it.receipt)
                                        .map((it) => (
                                            <li
                                                key={it.uid}
                                                className="flex items-center justify-between gap-3 text-[13px]"
                                            >
                                                <span className="min-w-0 truncate text-muted-foreground">
                                                    {it.receipt?.name}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground tabular-nums">
                                                    {it.receipt
                                                        ? formatFileSize(
                                                              it.receipt.size,
                                                          )
                                                        : ''}
                                                </span>
                                            </li>
                                        ))}
                                </ul>
                            </div>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default ExpenseClaimDialog;
