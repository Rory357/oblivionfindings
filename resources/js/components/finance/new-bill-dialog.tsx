import { useForm } from '@inertiajs/react';
import { FileText, ListChecks, Plus, ReceiptText, Trash2 } from 'lucide-react';
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

export type VendorOption = { id: number; name: string };
export type AccountOption = { id: number; code: string; name: string };
/** An approved governance spend approval this bill can be linked to. */
export type SpendApprovalOption = {
    id: number;
    reference: string | null;
    title: string | null;
    amount: number | string;
    category?: string | null;
};

/** A cost centre or funding stream a bill line can be attributed to. */
export type BillAttributionOption = { id: number; code: string; name: string };
/** An approved purchase order this bill can be raised against. */
export type BillPurchaseOrderOption = {
    id: number;
    po_number: string;
    vendor_id: number | string | null;
    total_amount?: number | string | null;
};

/** An existing draft bill to prefill the wizard with (edit mode). */
export type EditableBillLine = {
    description: string;
    quantity: string | number;
    unit_price: string | number;
    account_id: number | string | null;
    gst_rate: string | number; // backend stores a FRACTION (0.15); prefilled back to a percentage
    cost_centre_id?: number | string | null;
    funding_stream_id?: number | string | null;
};
export type EditableBill = {
    id: number;
    vendor_id: number | string;
    vendor_reference: string | null;
    bill_date: string;
    due_date: string;
    notes: string | null;
    spend_approval_id?: number | string | null;
    purchase_order_id?: number | string | null;
    lines: EditableBillLine[];
};

type LineForm = {
    description: string;
    quantity: string;
    unit_price: string;
    account_id: string;
    gst_rate: string; // percentage: '15' standard, '0' zero-rated
    cost_centre_id: string;
    funding_stream_id: string;
};

/** Map a stored line (gst_rate as a fraction) back into the form's percentage shape. */
const lineFromBill = (l: EditableBillLine): LineForm => ({
    description: l.description ?? '',
    quantity: String(l.quantity ?? '1'),
    unit_price: String(l.unit_price ?? ''),
    account_id: l.account_id != null ? String(l.account_id) : '',
    gst_rate: String(Math.round(Number(l.gst_rate ?? 0.15) * 100)),
    cost_centre_id: l.cost_centre_id != null ? String(l.cost_centre_id) : '',
    funding_stream_id:
        l.funding_stream_id != null ? String(l.funding_stream_id) : '',
});

const emptyLine = (): LineForm => ({
    description: '',
    quantity: '1',
    unit_price: '',
    account_id: '',
    gst_rate: '15',
    cost_centre_id: '',
    funding_stream_id: '',
});

// Radix SelectItem cannot take an empty-string value, so every optional select
// clears through a sentinel that maps back to '' on change.
const NO_VALUE = '__none';

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
        blurb: 'What you are billed for',
        icon: ReceiptText,
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
const plusDays = (days: number) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
};

/**
 * New Bill wizard — the multi-line AP bill as an Add-Client-grade stepper modal
 * (Details → Line items → Review). Mirrors the New Invoice modal, but each line
 * requires an expense/asset GL account and tax is a raw GST rate (15% standard /
 * 0% zero-rated). Posts a draft to `finance.bills.store` (AccountsPayableService::
 * createBill computes line GST + totals with bcmath), then redirects to the bill.
 */
export function NewBillDialog({
    open,
    onClose,
    vendors,
    accounts,
    spendApprovals = [],
    purchaseOrders = [],
    costCentres = [],
    fundingStreams = [],
    bill,
}: {
    open: boolean;
    onClose: () => void;
    vendors: VendorOption[];
    accounts: AccountOption[];
    /** Approved governance spend approvals available to link (optional). */
    spendApprovals?: SpendApprovalOption[];
    /** Approved purchase orders this bill can be raised against (optional). */
    purchaseOrders?: BillPurchaseOrderOption[];
    /** Active cost centres a line can be attributed to (optional). */
    costCentres?: BillAttributionOption[];
    /** Active funding streams a line can be attributed to (optional). */
    fundingStreams?: BillAttributionOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    bill?: EditableBill | null;
}) {
    const isEdit = !!bill;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        vendor_id: string;
        vendor_reference: string;
        bill_date: string;
        due_date: string;
        notes: string;
        spend_approval_id: string;
        purchase_order_id: string;
        lines: LineForm[];
    }>(
        bill
            ? {
                  vendor_id: String(bill.vendor_id ?? ''),
                  vendor_reference: bill.vendor_reference ?? '',
                  bill_date: String(bill.bill_date).slice(0, 10),
                  due_date: String(bill.due_date).slice(0, 10),
                  notes: bill.notes ?? '',
                  spend_approval_id:
                      bill.spend_approval_id != null
                          ? String(bill.spend_approval_id)
                          : '',
                  purchase_order_id:
                      bill.purchase_order_id != null
                          ? String(bill.purchase_order_id)
                          : '',
                  lines: bill.lines.length
                      ? bill.lines.map(lineFromBill)
                      : [emptyLine()],
              }
            : {
                  vendor_id: '',
                  vendor_reference: '',
                  bill_date: today(),
                  due_date: plusDays(30),
                  notes: '',
                  spend_approval_id: '',
                  purchase_order_id: '',
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
        { value: NO_VALUE, label: 'None' },
        ...costCentres.map((c) => ({
            value: String(c.id),
            label: `${c.code} · ${c.name}`,
        })),
    ];
    const fundingStreamOptions = [
        { value: NO_VALUE, label: 'None' },
        ...fundingStreams.map((f) => ({
            value: String(f.id),
            label: `${f.code} · ${f.name}`,
        })),
    ];
    const gstOptions = [
        { value: '15', label: 'GST 15%' },
        { value: '0', label: 'Zero-rated 0%' },
    ];
    const spendApprovalOptions = [
        { value: NO_VALUE, label: 'No spend approval' },
        ...spendApprovals.map((s) => ({
            value: String(s.id),
            label: `${s.reference ? `${s.reference} · ` : ''}${s.title ?? 'Spend approval'} · ${money(s.amount)}`,
        })),
    ];
    const selectedApproval = spendApprovals.find(
        (s) => String(s.id) === data.spend_approval_id,
    );
    // Only POs for the chosen vendor can be billed — a PO already picked for a
    // different vendor is cleared when the vendor changes.
    const vendorPurchaseOrders = data.vendor_id
        ? purchaseOrders.filter(
              (p) => String(p.vendor_id ?? '') === data.vendor_id,
          )
        : purchaseOrders;
    const purchaseOrderOptions = [
        { value: NO_VALUE, label: 'No purchase order' },
        ...vendorPurchaseOrders.map((p) => ({
            value: String(p.id),
            label:
                p.total_amount != null
                    ? `${p.po_number} · ${money(p.total_amount)}`
                    : p.po_number,
        })),
    ];
    const selectedPurchaseOrder = purchaseOrders.find(
        (p) => String(p.id) === data.purchase_order_id,
    );

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
                l.account_id &&
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
            notes: d.notes || null,
            vendor_reference: d.vendor_reference || null,
            spend_approval_id: d.spend_approval_id || null,
            purchase_order_id: d.purchase_order_id || null,
            lines: d.lines.map((l) => ({
                description: l.description,
                quantity: l.quantity,
                unit_price: l.unit_price,
                gst_rate: l.gst_rate,
                account_id: l.account_id,
                cost_centre_id: l.cost_centre_id || null,
                funding_stream_id: l.funding_stream_id || null,
            })),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && bill) {
            form.put(`/finance/bills/${bill.id}`, opts);
        } else {
            form.post('/finance/bills', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit bill' : 'New bill'}
            description={
                isEdit
                    ? 'Update this draft accounts-payable bill'
                    : 'Record a draft accounts-payable bill'
            }
            railIcon={ReceiptText}
            railTitle={isEdit ? 'Edit Bill' : 'New Bill'}
            railSub="Accounts payable"
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
                            {isEdit ? 'Save changes' : 'Create bill'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={FileText}
                        title="Bill details"
                        blurb="Which vendor, and the key dates."
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
                                onChange={(v) =>
                                    setData((d) => ({
                                        ...d,
                                        vendor_id: v,
                                        // A PO belongs to one vendor — switching
                                        // vendors can't keep the old link.
                                        purchase_order_id:
                                            d.vendor_id === v
                                                ? d.purchase_order_id
                                                : '',
                                    }))
                                }
                                placeholder="Select vendor"
                                options={vendorOptions}
                            />
                        </Field>
                        <Field
                            label="Bill date"
                            required
                            error={errors.bill_date}
                        >
                            <Input
                                type="date"
                                value={data.bill_date}
                                onChange={(e) =>
                                    setData('bill_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Due date"
                            required
                            error={errors.due_date}
                        >
                            <Input
                                type="date"
                                value={data.due_date}
                                onChange={(e) =>
                                    setData('due_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Vendor reference"
                            hint="optional"
                            error={errors.vendor_reference}
                        >
                            <Input
                                value={data.vendor_reference}
                                onChange={(e) =>
                                    setData('vendor_reference', e.target.value)
                                }
                                placeholder="e.g. their invoice #"
                            />
                        </Field>
                        <Field
                            label="Notes"
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
                        {purchaseOrders.length > 0 && (
                            <Field
                                label="Purchase order"
                                span
                                hint="optional — bill against an approved PO"
                                error={errors.purchase_order_id}
                            >
                                <SelectInput
                                    value={data.purchase_order_id}
                                    onChange={(v) =>
                                        setData(
                                            'purchase_order_id',
                                            v === NO_VALUE ? '' : v,
                                        )
                                    }
                                    placeholder="No purchase order"
                                    options={purchaseOrderOptions}
                                />
                                {data.vendor_id &&
                                vendorPurchaseOrders.length === 0 ? (
                                    <p className="mt-1 text-[12px] text-muted-foreground">
                                        This vendor has no approved purchase
                                        orders to bill against.
                                    </p>
                                ) : null}
                            </Field>
                        )}
                        {spendApprovals.length > 0 && (
                            <Field
                                label="Spend approval"
                                span
                                hint="optional — link a governance sign-off"
                                error={errors.spend_approval_id}
                            >
                                <SelectInput
                                    value={data.spend_approval_id}
                                    onChange={(v) =>
                                        setData(
                                            'spend_approval_id',
                                            v === NO_VALUE ? '' : v,
                                        )
                                    }
                                    placeholder="No spend approval"
                                    options={spendApprovalOptions}
                                />
                                <p className="mt-1 text-[12px] text-muted-foreground">
                                    Large bills may require an approved spend
                                    approval before they can be approved.
                                </p>
                            </Field>
                        )}
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ReceiptText}
                        title="Line items"
                        blurb="Each line posts to an expense account. GST is added per line."
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
                                                placeholder="e.g. Cleaning supplies"
                                            />
                                        </Field>
                                        <Field
                                            label="Expense account"
                                            span
                                            required
                                            error={
                                                errors[
                                                    `lines.${i}.account_id` as keyof typeof errors
                                                ] as string | undefined
                                            }
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
                                                placeholder="Select account"
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
                                        {costCentres.length > 0 && (
                                            <Field
                                                label="Cost centre"
                                                hint="optional"
                                            >
                                                <SelectInput
                                                    value={line.cost_centre_id}
                                                    onChange={(v) =>
                                                        updateLine(
                                                            i,
                                                            'cost_centre_id',
                                                            v === NO_VALUE
                                                                ? ''
                                                                : v,
                                                        )
                                                    }
                                                    placeholder="None"
                                                    options={costCentreOptions}
                                                    ariaLabel={`Line ${i + 1} cost centre`}
                                                />
                                            </Field>
                                        )}
                                        {fundingStreams.length > 0 && (
                                            <Field
                                                label="Funding stream"
                                                hint="optional"
                                            >
                                                <SelectInput
                                                    value={
                                                        line.funding_stream_id
                                                    }
                                                    onChange={(v) =>
                                                        updateLine(
                                                            i,
                                                            'funding_stream_id',
                                                            v === NO_VALUE
                                                                ? ''
                                                                : v,
                                                        )
                                                    }
                                                    placeholder="None"
                                                    options={
                                                        fundingStreamOptions
                                                    }
                                                    ariaLabel={`Line ${i + 1} funding stream`}
                                                />
                                            </Field>
                                        )}
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
                                ? 'Updates this draft bill.'
                                : 'Creates a draft bill you can then approve.'
                        }
                    />
                    <ReviewCard icon={FileText} title="Bill">
                        <ReviewRow label="Vendor" value={vendorName} />
                        {data.vendor_reference && (
                            <ReviewRow
                                label="Vendor reference"
                                value={data.vendor_reference}
                            />
                        )}
                        <ReviewRow label="Bill date" value={data.bill_date} />
                        <ReviewRow label="Due date" value={data.due_date} />
                        {selectedPurchaseOrder && (
                            <ReviewRow
                                label="Purchase order"
                                value={selectedPurchaseOrder.po_number}
                            />
                        )}
                        {selectedApproval && (
                            <ReviewRow
                                label="Spend approval"
                                value={`${selectedApproval.reference ? `${selectedApproval.reference} · ` : ''}${selectedApproval.title ?? 'Approval'}`}
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

export default NewBillDialog;
