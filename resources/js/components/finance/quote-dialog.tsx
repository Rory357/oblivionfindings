import { useForm } from '@inertiajs/react';
import {
    Calculator,
    FileText,
    ListChecks,
    Plus,
    Receipt,
    Trash2,
} from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AmountField } from './money';
import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type QuoteClientOption = {
    id: number;
    first_name: string;
    last_name: string;
};

/** A price book with its rate items, for the "add from price book" quick-fill. */
export type QuotePriceBookItem = {
    id: number;
    service_code: string | null;
    name: string;
    unit: string;
    rate: string | number;
};
export type QuotePriceBook = {
    id: number;
    name: string;
    items: QuotePriceBookItem[];
};

/** An existing DRAFT quote to prefill the wizard with (edit mode — header only). */
export type EditableQuoteLine = {
    description: string;
    quantity: string | number;
    unit_price: string | number;
};
export type EditableQuote = {
    id: number;
    client_id: number | string | null;
    title: string;
    valid_until: string | null;
    notes: string | null;
    lines: EditableQuoteLine[];
};

type LineForm = {
    description: string;
    quantity: string;
    unit_price: string;
};

const emptyLine = (): LineForm => ({
    description: '',
    quantity: '1',
    unit_price: '',
});

const money = (n: number | string) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(n || 0));

/**
 * Quote wizard — the multi-line AR quote as an Add-Client-grade stepper modal.
 * CREATE runs Details → Line items → Review and posts to `finance.quotes.store`
 * (client_id, title, valid_until, notes, line_items[]); the controller rolls up
 * NZ GST 15% on the header. EDIT is DRAFT-ONLY and HEADER-ONLY (Details →
 * Review) because `finance.quotes.update` only validates client/title/valid_until/
 * notes — it never persists line changes — so the wizard doesn't pretend to edit
 * lines. No GL journal results, so there is no posting preview.
 */
export function QuoteDialog({
    open,
    onClose,
    clients,
    priceBooks,
    quote,
}: {
    open: boolean;
    onClose: () => void;
    clients: QuoteClientOption[];
    priceBooks: QuotePriceBook[];
    /** When provided, the wizard opens in EDIT mode (draft-only, header fields only). */
    quote?: EditableQuote | null;
}) {
    const isEdit = !!quote;

    const STEPS: readonly WizardStep[] = isEdit
        ? [
              {
                  key: 'details',
                  label: 'Details',
                  blurb: 'Client & title',
                  icon: FileText,
              },
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Confirm & save',
                  icon: ListChecks,
              },
          ]
        : [
              {
                  key: 'details',
                  label: 'Details',
                  blurb: 'Client & title',
                  icon: FileText,
              },
              {
                  key: 'lines',
                  label: 'Line items',
                  blurb: 'What you are quoting',
                  icon: Receipt,
              },
              {
                  key: 'review',
                  label: 'Review',
                  blurb: 'Confirm & create',
                  icon: ListChecks,
              },
          ];

    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        client_id: string;
        title: string;
        valid_until: string;
        notes: string;
        lines: LineForm[];
    }>(
        quote
            ? {
                  client_id:
                      quote.client_id != null ? String(quote.client_id) : '',
                  title: quote.title ?? '',
                  valid_until: quote.valid_until
                      ? String(quote.valid_until).slice(0, 10)
                      : '',
                  notes: quote.notes ?? '',
                  lines: quote.lines.length
                      ? quote.lines.map((l) => ({
                            description: l.description ?? '',
                            quantity: String(l.quantity ?? '1'),
                            unit_price: String(l.unit_price ?? ''),
                        }))
                      : [emptyLine()],
              }
            : {
                  client_id: '',
                  title: '',
                  valid_until: '',
                  notes: '',
                  lines: [emptyLine()],
              },
    );
    const { data, setData, processing, errors } = form;
    // Server-side validation keys line-item errors under `line_items.*`, which the
    // form-field-typed `errors` object doesn't know about — read them via a cast.
    const lineErrors = errors as Record<string, string | undefined>;

    const clientOptions = clients.map((c) => ({
        value: String(c.id),
        label: `${c.first_name} ${c.last_name}`,
    }));
    const clientLabel =
        clientOptions.find((c) => c.value === data.client_id)?.label ?? '—';

    // Flatten every price-book item into one "add from price book" list.
    const priceBookOptions = useMemo(
        () =>
            priceBooks.flatMap((pb) =>
                pb.items.map((it) => ({
                    value: `${pb.id}:${it.id}`,
                    label: `${it.name} · ${money(it.rate)}/${it.unit}`,
                    item: it,
                })),
            ),
        [priceBooks],
    );

    const totals = useMemo(() => {
        let subtotal = 0;
        for (const l of data.lines) {
            subtotal += Number(l.quantity || 0) * Number(l.unit_price || 0);
        }
        const gst = subtotal * 0.15;
        return { subtotal, gst, total: subtotal + gst };
    }, [data.lines]);

    const updateLine = (i: number, field: keyof LineForm, value: string) => {
        const updated = [...data.lines];
        updated[i] = { ...updated[i], [field]: value };
        setData('lines', updated);
    };
    const addLine = () => setData('lines', [...data.lines, emptyLine()]);
    const removeLine = (i: number) => {
        if (data.lines.length <= 1) return;
        setData(
            'lines',
            data.lines.filter((_, idx) => idx !== i),
        );
    };
    const addFromPriceBook = (key: string) => {
        const opt = priceBookOptions.find((o) => o.value === key);
        if (!opt) return;
        const newLine: LineForm = {
            description: opt.item.name,
            quantity: '1',
            unit_price: String(opt.item.rate ?? ''),
        };
        // Replace a single empty starter line, else append.
        const onlyEmptyStarter =
            data.lines.length === 1 &&
            !data.lines[0].description.trim() &&
            !data.lines[0].unit_price;
        setData(
            'lines',
            onlyEmptyStarter ? [newLine] : [...data.lines, newLine],
        );
    };

    const detailsValid = !!data.client_id && !!data.title.trim();
    const linesValid =
        data.lines.every(
            (l) =>
                l.description.trim() &&
                Number(l.unit_price) >= 0 &&
                Number(l.quantity) > 0,
        ) && totals.subtotal > 0;
    const canContinueDetails = detailsValid;
    // Edit is header-only, so the wizard is complete once details are valid.
    const submitReady = isEdit ? detailsValid : detailsValid && linesValid;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && quote) {
            form.transform((d) => ({
                client_id: d.client_id,
                title: d.title,
                valid_until: d.valid_until || null,
                notes: d.notes || null,
            }));
            form.put(`/finance/quotes/${quote.id}`, opts);
        } else {
            form.transform((d) => ({
                client_id: d.client_id,
                title: d.title,
                valid_until: d.valid_until || null,
                notes: d.notes || null,
                line_items: d.lines.map((l) => ({
                    description: l.description,
                    quantity: l.quantity,
                    unit_price: l.unit_price,
                })),
            }));
            form.post('/finance/quotes', opts);
        }
    };

    const reviewIndex = STEPS.length - 1;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit quote' : 'New quote'}
            description={
                isEdit
                    ? 'Update this draft quote'
                    : 'Create a service quote for a client'
            }
            railIcon={Calculator}
            railTitle={isEdit ? 'Edit Quote' : 'New Quote'}
            railSub="Quotes"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={
                submitReady
                    ? 100
                    : Math.min(
                          90,
                          (detailsValid ? 50 : 0) + (linesValid ? 40 : 0),
                      )
            }
            pctLabel={isEdit ? 'Quote' : 'Total'}
            footerStart={
                !isEdit ? (
                    <span className="text-[13px] text-muted-foreground">
                        Total{' '}
                        <span className="font-semibold text-foreground">
                            {money(totals.total)}
                        </span>
                        <span className="ml-1">
                            (incl. {money(totals.gst)} GST)
                        </span>
                    </span>
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
                            disabled={
                                (index === 0 && !canContinueDetails) ||
                                (!isEdit && index === 1 && !linesValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !submitReady}
                        >
                            {isEdit ? 'Save changes' : 'Create quote'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={FileText}
                        title="Quote details"
                        blurb="Who the quote is for, and what it covers."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Client" required error={errors.client_id}>
                            <SelectInput
                                value={data.client_id}
                                onChange={(v) => setData('client_id', v)}
                                placeholder="Select client"
                                options={clientOptions}
                            />
                        </Field>
                        <Field
                            label="Valid until"
                            hint="optional"
                            error={errors.valid_until}
                        >
                            <Input
                                type="date"
                                value={data.valid_until}
                                onChange={(e) =>
                                    setData('valid_until', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Title" span required error={errors.title}>
                            <Input
                                value={data.title}
                                onChange={(e) =>
                                    setData('title', e.target.value)
                                }
                                placeholder="e.g. Support Services Quote"
                            />
                        </Field>
                        <Field
                            label="Notes"
                            span
                            hint="optional"
                            error={errors.notes}
                        >
                            <Textarea
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                placeholder="Additional notes for the client"
                            />
                        </Field>
                    </div>
                    {isEdit && (
                        <p className="mt-4 text-[13px] text-muted-foreground">
                            Line items are locked once a quote exists — edit the
                            header details here, or create a new quote to change
                            what's being quoted.
                        </p>
                    )}
                </div>
            )}

            {!isEdit && index === 1 && (
                <div>
                    <StepHead
                        icon={Receipt}
                        title="Line items"
                        blurb="Each line is quoted net; GST is added at 15% on the total."
                    />
                    {typeof lineErrors.line_items === 'string' && (
                        <FieldErr>{lineErrors.line_items}</FieldErr>
                    )}
                    {priceBookOptions.length > 0 && (
                        <div className="mb-3 sm:max-w-xs">
                            <Field label="Add from price book" hint="optional">
                                <SelectInput
                                    value=""
                                    onChange={addFromPriceBook}
                                    placeholder="Pick a rate item"
                                    options={priceBookOptions.map(
                                        ({ value, label }) => ({
                                            value,
                                            label,
                                        }),
                                    )}
                                    ariaLabel="Add a line from a price book"
                                />
                            </Field>
                        </div>
                    )}
                    <div className="space-y-3">
                        {data.lines.map((line, i) => {
                            const net =
                                Number(line.quantity || 0) *
                                Number(line.unit_price || 0);
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- per-line field-group panel, not a content card
                                <div
                                    key={i}
                                    className="rounded-xl border border-border bg-card/60 p-3"
                                >
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <Field
                                            label="Description"
                                            span
                                            required
                                            error={
                                                lineErrors[
                                                    `line_items.${i}.description`
                                                ]
                                            }
                                        >
                                            <Input
                                                value={line.description}
                                                onChange={(e) =>
                                                    updateLine(
                                                        i,
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. Community access support — weekly"
                                            />
                                        </Field>
                                        <Field label="Quantity" required>
                                            <Input
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    updateLine(
                                                        i,
                                                        'quantity',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Unit price (ex GST)"
                                            required
                                        >
                                            <AmountField
                                                value={line.unit_price}
                                                onValueChange={(v) =>
                                                    updateLine(
                                                        i,
                                                        'unit_price',
                                                        v,
                                                    )
                                                }
                                                aria-label={`Line ${i + 1} unit price`}
                                            />
                                        </Field>
                                        <Field label="Line net">
                                            <div className="flex h-9 items-center px-1 text-sm font-medium tabular-nums">
                                                {money(net)}
                                            </div>
                                        </Field>
                                    </div>
                                    <div className="mt-2 flex justify-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeLine(i)}
                                            disabled={data.lines.length <= 1}
                                            className="text-muted-foreground hover:text-status-critical"
                                        >
                                            <Trash2 className="mr-1 h-4 w-4" />{' '}
                                            Remove line
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addLine}
                        className="mt-3"
                    >
                        <Plus className="mr-1 h-4 w-4" /> Add line
                    </Button>
                    {/* eslint-disable-next-line no-restricted-syntax -- totals summary panel, not a content card */}
                    <div className="mt-4 space-y-1 rounded-xl border border-border bg-card/60 p-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">
                                Subtotal
                            </span>
                            <span className="tabular-nums">
                                {money(totals.subtotal)}
                            </span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">
                                GST (15%)
                            </span>
                            <span className="tabular-nums">
                                {money(totals.gst)}
                            </span>
                        </div>
                        <div className="flex justify-between border-t pt-1 font-semibold">
                            <span>Total (NZD)</span>
                            <span className="tabular-nums">
                                {money(totals.total)}
                            </span>
                        </div>
                    </div>
                </div>
            )}

            {index === reviewIndex && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title={isEdit ? 'Review & save' : 'Review & create'}
                        blurb={
                            isEdit
                                ? 'Updates this draft quote.'
                                : 'Creates a draft quote you can then send.'
                        }
                    />
                    <ReviewCard icon={FileText} title="Quote">
                        <ReviewRow label="Client" value={clientLabel} />
                        <ReviewRow label="Title" value={data.title || '—'} />
                        {data.valid_until && (
                            <ReviewRow
                                label="Valid until"
                                value={data.valid_until}
                            />
                        )}
                        {!isEdit && (
                            <ReviewRow
                                label="Lines"
                                value={String(data.lines.length)}
                            />
                        )}
                        {!isEdit && (
                            <ReviewRow
                                label="Subtotal"
                                value={money(totals.subtotal)}
                            />
                        )}
                        {!isEdit && (
                            <ReviewRow
                                label="GST (15%)"
                                value={money(totals.gst)}
                            />
                        )}
                        {!isEdit && (
                            <ReviewRow
                                label="Total (NZD)"
                                value={money(totals.total)}
                            />
                        )}
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

export default QuoteDialog;
