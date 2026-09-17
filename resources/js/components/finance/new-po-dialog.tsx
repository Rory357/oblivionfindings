import { useForm } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    ListChecks,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AmountField } from './money';
import type { AccountOption, VendorOption } from './new-bill-dialog';
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

type LineForm = {
    description: string;
    quantity: string;
    unit_price: string;
    account_id: string; // optional for POs
    gst_rate: string; // percentage: '15' standard, '0' zero-rated
};

/** A cost centre or funding stream the PO can be attributed to. */
export type PoAttributionOption = { id: number; code: string; name: string };

/** An existing draft purchase order to prefill the wizard with (edit mode). */
export type EditablePurchaseOrderLine = {
    description: string;
    quantity: string | number;
    unit_price: string | number;
    account_id: number | string | null;
    /** Backend stores a FRACTION (0.15); prefilled back to a percentage. */
    gst_rate: string | number;
};
export type EditablePurchaseOrder = {
    id: number;
    vendor_id: number | string;
    order_date: string;
    expected_date: string | null;
    notes: string | null;
    cost_centre_id: number | string | null;
    funding_stream_id: number | string | null;
    lines: EditablePurchaseOrderLine[];
};

const emptyLine = (): LineForm => ({
    description: '',
    quantity: '1',
    unit_price: '',
    account_id: '',
    gst_rate: '15',
});

/** Map a stored line (gst_rate as a fraction) back into the form's percentage shape. */
const lineFromPo = (l: EditablePurchaseOrderLine): LineForm => ({
    description: l.description ?? '',
    quantity: String(l.quantity ?? '1'),
    unit_price: String(l.unit_price ?? ''),
    account_id: l.account_id != null ? String(l.account_id) : '',
    gst_rate: String(Math.round(Number(l.gst_rate ?? 0.15) * 100)),
});

// Radix SelectItem cannot take an empty-string value, so the "clear" row on the
// optional attribution selects uses a sentinel that maps back to '' on change.
const NO_ATTRIBUTION = '__none';

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Vendor & dates',
        icon: FileText,
    },
    {
        key: 'lines',
        label: 'Line items',
        blurb: 'What you are ordering',
        icon: ClipboardList,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ListChecks,
    },
];

const money = (n: number | string) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(Number(n || 0));

const today = () => new Date().toISOString().split('T')[0];

/**
 * Purchase Order wizard — the multi-line PO as an Add-Client-grade stepper
 * modal (Details → Line items → Review). Mirrors the New Bill modal, but the GL
 * account is optional per line and the dates are order/expected. Posts a draft to
 * `finance.purchase-orders.store` / PUTs `finance.purchase-orders.update`; it
 * carries the cost centre and funding stream the retired routed Edit page set,
 * so a PO created here can be attributed without a second trip.
 */
export function NewPoDialog({
    open,
    onClose,
    vendors,
    accounts,
    costCentres = [],
    fundingStreams = [],
    purchaseOrder,
}: {
    open: boolean;
    onClose: () => void;
    vendors: VendorOption[];
    accounts: AccountOption[];
    /** Active cost centres this PO can be attributed to (optional). */
    costCentres?: PoAttributionOption[];
    /** Active funding streams this PO can be attributed to (optional). */
    fundingStreams?: PoAttributionOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    purchaseOrder?: EditablePurchaseOrder | null;
}) {
    const isEdit = !!purchaseOrder;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        vendor_id: string;
        order_date: string;
        expected_date: string;
        notes: string;
        cost_centre_id: string;
        funding_stream_id: string;
        lines: LineForm[];
    }>(
        purchaseOrder
            ? {
                  vendor_id: String(purchaseOrder.vendor_id ?? ''),
                  order_date: String(purchaseOrder.order_date).slice(0, 10),
                  expected_date: purchaseOrder.expected_date
                      ? String(purchaseOrder.expected_date).slice(0, 10)
                      : '',
                  notes: purchaseOrder.notes ?? '',
                  cost_centre_id:
                      purchaseOrder.cost_centre_id != null
                          ? String(purchaseOrder.cost_centre_id)
                          : '',
                  funding_stream_id:
                      purchaseOrder.funding_stream_id != null
                          ? String(purchaseOrder.funding_stream_id)
                          : '',
                  lines: purchaseOrder.lines.length
                      ? purchaseOrder.lines.map(lineFromPo)
                      : [emptyLine()],
              }
            : {
                  vendor_id: '',
                  order_date: today(),
                  expected_date: '',
                  notes: '',
                  cost_centre_id: '',
                  funding_stream_id: '',
                  lines: [emptyLine()],
              },
    );
    const { data, setData, processing, errors } = form;

    const vendorOptions = vendors.map((v) => ({
        value: String(v.id),
        label: v.name,
    }));
    const accountOptions = accounts.map((a) => ({
        value: String(a.id),
        label: `${a.code} · ${a.name}`,
    }));
    const costCentreOptions = [
        { value: NO_ATTRIBUTION, label: 'None' },
        ...costCentres.map((c) => ({
            value: String(c.id),
            label: `${c.code} · ${c.name}`,
        })),
    ];
    const fundingStreamOptions = [
        { value: NO_ATTRIBUTION, label: 'None' },
        ...fundingStreams.map((f) => ({
            value: String(f.id),
            label: `${f.code} · ${f.name}`,
        })),
    ];
    const costCentreLabel = costCentres.find(
        (c) => String(c.id) === data.cost_centre_id,
    );
    const fundingStreamLabel = fundingStreams.find(
        (f) => String(f.id) === data.funding_stream_id,
    );
    const gstOptions = [
        { value: '15', label: 'GST 15%' },
        { value: '0', label: 'Zero-rated 0%' },
    ];

    const totals = useMemo(() => {
        let subtotal = 0;
        let gst = 0;
        for (const l of data.lines) {
            const net = Number(l.quantity || 0) * Number(l.unit_price || 0);
            subtotal += net;
            gst += net * (Number(l.gst_rate || 0) / 100);
        }
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

    const vendorName =
        vendors.find((v) => String(v.id) === data.vendor_id)?.name ?? '—';
    const detailsValid = !!data.vendor_id;
    const linesValid =
        data.lines.every(
            (l) =>
                l.description.trim() &&
                Number(l.unit_price) >= 0 &&
                Number(l.quantity) > 0,
        ) && totals.subtotal > 0;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        // Drop empty optional ids so the nullable rules pass.
        form.transform((d) => ({
            ...d,
            expected_date: d.expected_date || null,
            notes: d.notes || null,
            cost_centre_id: d.cost_centre_id || null,
            funding_stream_id: d.funding_stream_id || null,
            lines: d.lines.map((l) => ({
                description: l.description,
                quantity: l.quantity,
                unit_price: l.unit_price,
                gst_rate: l.gst_rate,
                account_id: l.account_id || null,
            })),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && purchaseOrder) {
            form.put(`/finance/purchase-orders/${purchaseOrder.id}`, opts);
        } else {
            form.post('/finance/purchase-orders', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit purchase order' : 'New purchase order'}
            description={
                isEdit
                    ? 'Update this draft purchase order'
                    : 'Create a draft purchase order'
            }
            railIcon={ClipboardList}
            railTitle={isEdit ? 'Edit PO' : 'New PO'}
            railSub="Purchases"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={
                linesValid
                    ? 100
                    : Math.min(
                          90,
                          data.lines.filter((l) => l.description).length * 30,
                      )
            }
            pctLabel="Total"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Total{' '}
                    <span className="font-semibold text-foreground">
                        {money(totals.total)}
                    </span>
                    <span className="ml-1">
                        (incl. {money(totals.gst)} GST)
                    </span>
                </span>
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
                                (index === 0 && !detailsValid) ||
                                (index === 1 && !linesValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={
                                processing || !detailsValid || !linesValid
                            }
                        >
                            {isEdit ? 'Save changes' : 'Create PO'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={FileText}
                        title="Order details"
                        blurb="Which vendor, and when you need it."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Vendor"
                            span
                            required
                            error={errors.vendor_id}
                        >
                            <SelectInput
                                value={data.vendor_id}
                                onChange={(v) => setData('vendor_id', v)}
                                placeholder="Select vendor"
                                options={vendorOptions}
                            />
                        </Field>
                        <Field
                            label="Order date"
                            required
                            error={errors.order_date}
                        >
                            <Input
                                type="date"
                                value={data.order_date}
                                onChange={(e) =>
                                    setData('order_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Expected date"
                            hint="optional"
                            error={errors.expected_date}
                        >
                            <Input
                                type="date"
                                value={data.expected_date}
                                onChange={(e) =>
                                    setData('expected_date', e.target.value)
                                }
                            />
                        </Field>
                        {costCentres.length > 0 && (
                            <Field
                                label="Cost centre"
                                hint="optional"
                                error={errors.cost_centre_id}
                            >
                                <SelectInput
                                    value={data.cost_centre_id}
                                    onChange={(v) =>
                                        setData(
                                            'cost_centre_id',
                                            v === NO_ATTRIBUTION ? '' : v,
                                        )
                                    }
                                    placeholder="None"
                                    options={costCentreOptions}
                                />
                            </Field>
                        )}
                        {fundingStreams.length > 0 && (
                            <Field
                                label="Funding stream"
                                hint="optional"
                                error={errors.funding_stream_id}
                            >
                                <SelectInput
                                    value={data.funding_stream_id}
                                    onChange={(v) =>
                                        setData(
                                            'funding_stream_id',
                                            v === NO_ATTRIBUTION ? '' : v,
                                        )
                                    }
                                    placeholder="None"
                                    options={fundingStreamOptions}
                                />
                            </Field>
                        )}
                        <Field
                            label="Notes"
                            span
                            hint="optional"
                            error={errors.notes}
                        >
                            <Textarea
                                rows={1}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ClipboardList}
                        title="Line items"
                        blurb="What you are ordering. GST is added per line."
                    />
                    {typeof errors.lines === 'string' && (
                        <FieldErr>{errors.lines}</FieldErr>
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
                                                errors[
                                                    `lines.${i}.description` as keyof typeof errors
                                                ] as string | undefined
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
                                                placeholder="e.g. Office chairs ×4"
                                            />
                                        </Field>
                                        <Field
                                            label="Expense account"
                                            span
                                            hint="optional"
                                        >
                                            <SelectInput
                                                value={line.account_id}
                                                onChange={(v) =>
                                                    updateLine(
                                                        i,
                                                        'account_id',
                                                        v,
                                                    )
                                                }
                                                placeholder="None"
                                                options={accountOptions}
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
                                        <Field label="Tax">
                                            <SelectInput
                                                value={line.gst_rate}
                                                onChange={(v) =>
                                                    updateLine(i, 'gst_rate', v)
                                                }
                                                placeholder="GST 15%"
                                                options={gstOptions}
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
                            <span className="text-muted-foreground">GST</span>
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

            {index === 2 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title={isEdit ? 'Review & save' : 'Review & create'}
                        blurb={
                            isEdit
                                ? 'Updates this draft PO.'
                                : 'Creates a draft PO you can then approve + convert to a bill.'
                        }
                    />
                    <ReviewCard icon={FileText} title="Purchase order">
                        <ReviewRow label="Vendor" value={vendorName} />
                        <ReviewRow label="Order date" value={data.order_date} />
                        {data.expected_date && (
                            <ReviewRow
                                label="Expected"
                                value={data.expected_date}
                            />
                        )}
                        {costCentreLabel && (
                            <ReviewRow
                                label="Cost centre"
                                value={`${costCentreLabel.code} · ${costCentreLabel.name}`}
                            />
                        )}
                        {fundingStreamLabel && (
                            <ReviewRow
                                label="Funding stream"
                                value={`${fundingStreamLabel.code} · ${fundingStreamLabel.name}`}
                            />
                        )}
                        <ReviewRow
                            label="Lines"
                            value={String(data.lines.length)}
                        />
                        <ReviewRow
                            label="Subtotal"
                            value={money(totals.subtotal)}
                        />
                        <ReviewRow label="GST" value={money(totals.gst)} />
                        <ReviewRow
                            label="Total (NZD)"
                            value={money(totals.total)}
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

export default NewPoDialog;
