import { useForm } from '@inertiajs/react';
import { BookOpen, ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AmountField } from './money';
import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
} from './wizard';

/** An existing price book to prefill the wizard with (edit mode). */
export type EditablePriceBook = {
    id: number;
    name: string;
    description: string | null;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Name & effective dates',
        icon: BookOpen,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: ListChecks,
    },
];

const fmtDate = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * Price Book wizard — create/edit a rate-card price book as a stepper modal
 * (Details → Review). Posts to `finance.price_books.store` / PUTs
 * `finance.price_books.update` (name, description, effective_from/to,
 * is_active — the fields the controller actually validates; the retired pages'
 * `is_default` checkbox was never read by the backend). Item management stays
 * on the price book's Show page.
 */
export function PriceBookDialog({
    open,
    onClose,
    priceBook,
}: {
    open: boolean;
    onClose: () => void;
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    priceBook?: EditablePriceBook | null;
}) {
    const isEdit = !!priceBook;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        name: string;
        description: string;
        effective_from: string;
        effective_to: string;
        is_active: boolean;
    }>(
        priceBook
            ? {
                  name: priceBook.name ?? '',
                  description: priceBook.description ?? '',
                  effective_from: priceBook.effective_from
                      ? String(priceBook.effective_from).slice(0, 10)
                      : '',
                  effective_to: priceBook.effective_to
                      ? String(priceBook.effective_to).slice(0, 10)
                      : '',
                  is_active: priceBook.is_active,
              }
            : {
                  name: '',
                  description: '',
                  effective_from: '',
                  effective_to: '',
                  is_active: true,
              },
    );
    const { data, setData, processing, errors } = form;

    // Mirrors the backend `after_or_equal:effective_from` rule so users see the
    // problem before submit (ISO date strings compare lexicographically).
    const datesValid =
        !data.effective_from ||
        !data.effective_to ||
        data.effective_to >= data.effective_from;
    const detailsValid = !!data.name.trim() && datesValid;

    const close = () => {
        setSucceeded(false);
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const startAnother = () => {
        setSucceeded(false);
        reset();
        form.reset();
        form.clearErrors();
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            description: d.description || null,
            effective_from: d.effective_from || null,
            effective_to: d.effective_to || null,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        };
        if (isEdit && priceBook) {
            form.put(`/finance/price-books/${priceBook.id}`, opts);
        } else {
            form.post('/finance/price-books', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit price book' : 'New price book'}
            description={
                isEdit
                    ? 'Update this price book'
                    : 'Create a price book for service rates'
            }
            railIcon={BookOpen}
            railTitle={isEdit ? 'Edit Price Book' : 'New Price Book'}
            railSub="Rates & pricing"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Price book"
            success={
                succeeded ? (
                    <WizardSuccessPane
                        title={
                            isEdit
                                ? 'Price book updated'
                                : `${data.name || 'Price book'} created`
                        }
                        blurb={
                            isEdit
                                ? 'The price book details have been saved.'
                                : 'The price book is ready. Open it to add rate items for your services.'
                        }
                        actions={
                            <>
                                {!isEdit && (
                                    <Button
                                        variant="outline"
                                        onClick={startAnother}
                                    >
                                        <Plus className="h-4 w-4" /> Add another
                                    </Button>
                                )}
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={back}
                            disabled={processing}
                        >
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={!detailsValid}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !detailsValid}
                        >
                            {isEdit ? 'Save changes' : 'Create price book'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={BookOpen}
                        title="Price book details"
                        blurb="Name the rate card and set when it applies."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Name" span required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Standard Rate Card 2026"
                            />
                        </Field>
                        <Field
                            label="Description"
                            span
                            hint="optional"
                            error={errors.description}
                        >
                            <Textarea
                                rows={3}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                placeholder="Describe this price book…"
                            />
                        </Field>
                        <Field
                            label="Effective from"
                            hint="optional"
                            error={errors.effective_from}
                        >
                            <Input
                                type="date"
                                value={data.effective_from}
                                onChange={(e) =>
                                    setData('effective_from', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Effective to"
                            hint="optional"
                            error={
                                errors.effective_to ??
                                (!datesValid
                                    ? 'Must be on or after the effective-from date.'
                                    : undefined)
                            }
                        >
                            <Input
                                type="date"
                                value={data.effective_to}
                                onChange={(e) =>
                                    setData('effective_to', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Status" span error={errors.is_active}>
                            <Segmented
                                value={data.is_active ? 'active' : 'inactive'}
                                onChange={(v) =>
                                    setData('is_active', v === 'active')
                                }
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title={isEdit ? 'Review & save' : 'Review & create'}
                        blurb={
                            isEdit
                                ? 'Updates this price book.'
                                : 'Creates the price book — add rate items from its page afterwards.'
                        }
                    />
                    <ReviewCard icon={BookOpen} title="Price book">
                        <ReviewRow label="Name" value={data.name || '—'} />
                        {data.description && (
                            <ReviewRow
                                label="Description"
                                value={data.description}
                            />
                        )}
                        <ReviewRow
                            label="Effective"
                            value={
                                data.effective_from || data.effective_to
                                    ? `${data.effective_from ? fmtDate(data.effective_from) : 'Any time'} — ${data.effective_to ? fmtDate(data.effective_to) : 'ongoing'}`
                                    : 'Always'
                            }
                        />
                        <ReviewRow
                            label="Status"
                            value={data.is_active ? 'Active' : 'Inactive'}
                        />
                    </ReviewCard>
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            {isEdit ? 'Saving…' : 'Creating…'}
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Rate items                                                         */
/* ------------------------------------------------------------------ */

/** An existing rate item to prefill the item dialog with (edit mode). */
export type EditablePriceBookItem = {
    id: number;
    service_code: string | null;
    name: string;
    unit: string;
    rate: number | string;
    description?: string | null;
};

const UNIT_OPTIONS = [
    { value: 'hour', label: 'Per hour' },
    { value: 'day', label: 'Per day' },
    { value: 'week', label: 'Per week' },
    { value: 'each', label: 'Each' },
    { value: 'km', label: 'Per km' },
];

/**
 * Add / edit one price-book rate item. A one-screen form, so it is a simple
 * dialog (POPUP_STYLE_GUIDE §"Simple dialog"), not a wizard. Field names match
 * `PriceBookController::storeItem/updateItem` (`code`, `unit_price`).
 */
export function PriceBookItemDialog({
    open,
    onClose,
    priceBookId,
    item,
}: {
    open: boolean;
    onClose: () => void;
    priceBookId: number;
    /** When provided, the dialog opens in EDIT mode (prefilled, PUTs the update). */
    item?: EditablePriceBookItem | null;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <PriceBookItemBody
                        onClose={onClose}
                        priceBookId={priceBookId}
                        item={item ?? null}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function PriceBookItemBody({
    onClose,
    priceBookId,
    item,
}: {
    onClose: () => void;
    priceBookId: number;
    item: EditablePriceBookItem | null;
}) {
    const isEdit = !!item;
    const form = useForm<{
        name: string;
        code: string;
        unit: string;
        unit_price: string;
        description: string;
    }>({
        name: item?.name ?? '',
        code: item?.service_code ?? '',
        unit: item?.unit ?? 'hour',
        unit_price: item ? String(item.rate ?? '') : '',
        description: item?.description ?? '',
    });
    const { data, setData, processing, errors } = form;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            code: d.code || null,
            description: d.description || null,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };
        if (isEdit && item) {
            form.put(
                `/finance/price-books/${priceBookId}/items/${item.id}`,
                opts,
            );
        } else {
            form.post(`/finance/price-books/${priceBookId}/items`, opts);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <BookOpen className="h-4 w-4 text-primary" />
                    {isEdit ? 'Edit rate item' : 'Add rate item'}
                </DialogTitle>
                <DialogDescription>
                    A rate item is one priced service line that quotes and
                    invoices can be built from.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Name" span required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="e.g. Personal care"
                    />
                </Field>
                <Field
                    label="Service code"
                    hint="optional"
                    error={errors.code}
                >
                    <Input
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value)}
                        placeholder="e.g. SVC-001"
                    />
                </Field>
                <Field label="Unit" error={errors.unit}>
                    <SelectInput
                        value={data.unit}
                        onChange={(v) => setData('unit', v)}
                        placeholder="Per hour"
                        options={UNIT_OPTIONS}
                        ariaLabel="Rate unit"
                    />
                </Field>
                <Field
                    label="Rate (NZD, ex GST)"
                    span
                    required
                    error={errors.unit_price}
                >
                    <AmountField
                        value={data.unit_price}
                        onValueChange={(v) => setData('unit_price', v)}
                        aria-label="Rate"
                    />
                </Field>
                <Field
                    label="Description"
                    span
                    hint="optional"
                    error={errors.description}
                >
                    <Textarea
                        rows={2}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="What this rate covers"
                    />
                </Field>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        processing ||
                        !data.name.trim() ||
                        data.unit_price === ''
                    }
                >
                    {isEdit ? 'Save changes' : 'Add item'}
                </Button>
            </DialogFooter>
        </form>
    );
}

export default PriceBookDialog;
