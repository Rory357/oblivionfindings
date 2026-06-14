import { useForm } from '@inertiajs/react';
import { FileText, ListChecks, Plus, Receipt, Trash2 } from 'lucide-react';
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
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

export type ClientOption = { id: number; name: string };
export type TaxRateOption = { id: number; name: string; rate: string | number };

/** An existing draft invoice to prefill the wizard with (edit mode). */
export type EditableInvoiceLine = {
    description: string;
    quantity: string | number;
    unit_price: string | number;
    tax_rate_id: number | string | null;
};
export type EditableInvoice = {
    id: number;
    client_id: number | string | null;
    client_name: string | null;
    funding_body: string | null;
    invoice_date: string;
    due_date: string;
    notes: string | null;
    lines: EditableInvoiceLine[];
};

type LineForm = {
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate_id: string; // 'default' = NZ GST 15% (mapped to null server-side)
};

const emptyLine = (): LineForm => ({
    description: '',
    quantity: '1',
    unit_price: '',
    tax_rate_id: 'default',
});

/** Map a stored line (tax_rate_id FK or null) into the form shape ('default' = no rate). */
const lineFromInvoice = (l: EditableInvoiceLine): LineForm => ({
    description: l.description ?? '',
    quantity: String(l.quantity ?? '1'),
    unit_price: String(l.unit_price ?? ''),
    tax_rate_id: l.tax_rate_id != null ? String(l.tax_rate_id) : 'default',
});

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Who & when', icon: FileText },
    { key: 'lines', label: 'Line items', blurb: 'What you are billing', icon: Receipt },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: ListChecks },
];

const money = (n: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(n || 0));

const today = () => new Date().toISOString().split('T')[0];
const plusDays = (days: number) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
};

/**
 * New Invoice wizard — the multi-line AR invoice as an Add-Client-grade stepper
 * modal (Details → Line items → Review). Posts a draft to `finance.invoices.store`;
 * the controller computes line tax (a chosen rate, else NZ GST 15%) and totals with
 * bcmath, then redirects to the new invoice. Bill either a client or a funder/other.
 */
export function NewInvoiceDialog({
    open,
    onClose,
    clients,
    taxRates,
    invoice,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    taxRates: TaxRateOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    invoice?: EditableInvoice | null;
}) {
    const isEdit = !!invoice;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        bill_to: 'client' | 'funder';
        client_id: string;
        client_name: string;
        funding_body: string;
        invoice_date: string;
        due_date: string;
        notes: string;
        lines: LineForm[];
    }>(invoice ? {
        bill_to: invoice.client_id ? 'client' : 'funder',
        client_id: invoice.client_id != null ? String(invoice.client_id) : '',
        client_name: invoice.client_name ?? '',
        funding_body: invoice.funding_body ?? '',
        invoice_date: String(invoice.invoice_date).slice(0, 10),
        due_date: String(invoice.due_date).slice(0, 10),
        notes: invoice.notes ?? '',
        lines: invoice.lines.length ? invoice.lines.map(lineFromInvoice) : [emptyLine()],
    } : {
        bill_to: 'client',
        client_id: '',
        client_name: '',
        funding_body: '',
        invoice_date: today(),
        due_date: plusDays(30),
        notes: '',
        lines: [emptyLine()],
    });
    const { data, setData, processing, errors } = form;

    const clientOptions = clients.map((c) => ({ value: String(c.id), label: c.name }));
    // 'default' (not '') keeps Radix happy and the request maps it to null → NZ GST 15%.
    const taxOptions = [
        { value: 'default', label: 'GST 15% (standard)' },
        ...taxRates.map((t) => ({ value: String(t.id), label: `${t.name} (${Number(t.rate)}%)` })),
    ];
    const rateFor = (id: string): number => {
        if (id === 'default') return 15;
        const t = taxRates.find((x) => String(x.id) === id);
        return t ? Number(t.rate) : 15;
    };

    const totals = useMemo(() => {
        let subtotal = 0;
        let tax = 0;
        for (const l of data.lines) {
            const net = Number(l.quantity || 0) * Number(l.unit_price || 0);
            subtotal += net;
            tax += net * (rateFor(l.tax_rate_id) / 100);
        }
        return { subtotal, tax, total: subtotal + tax };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.lines, taxRates]);

    const updateLine = (i: number, field: keyof LineForm, value: string) => {
        const updated = [...data.lines];
        updated[i] = { ...updated[i], [field]: value };
        setData('lines', updated);
    };
    const addLine = () => setData('lines', [...data.lines, emptyLine()]);
    const removeLine = (i: number) => {
        if (data.lines.length <= 1) return;
        setData('lines', data.lines.filter((_, idx) => idx !== i));
    };

    const billToName = data.bill_to === 'client'
        ? clients.find((c) => String(c.id) === data.client_id)?.name ?? '—'
        : data.client_name || '—';

    const detailsValid = data.bill_to === 'client' ? !!data.client_id : !!data.client_name.trim();
    const linesValid = data.lines.every((l) => l.description.trim() && Number(l.unit_price) >= 0 && Number(l.quantity) > 0)
        && totals.subtotal > 0;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            client_id: d.bill_to === 'client' ? d.client_id || null : null,
            client_name: d.bill_to === 'funder' ? d.client_name : null,
            funding_body: d.bill_to === 'funder' ? d.funding_body || null : null,
            invoice_date: d.invoice_date,
            due_date: d.due_date,
            notes: d.notes || null,
            lines: d.lines.map((l) => ({
                description: l.description,
                quantity: l.quantity,
                unit_price: l.unit_price,
                tax_rate_id: l.tax_rate_id, // 'default' → null server-side
            })),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && invoice) {
            form.put(`/finance/invoices/${invoice.id}`, opts);
        } else {
            form.post('/finance/invoices', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit invoice' : 'New invoice'}
            description={isEdit ? 'Update this draft AR invoice' : 'Create a draft AR invoice'}
            railIcon={Receipt}
            railTitle={isEdit ? 'Edit Invoice' : 'New Invoice'}
            railSub="Accounts receivable"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={linesValid ? 100 : Math.min(90, data.lines.filter((l) => l.description).length * 30)}
            pctLabel="Total"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Total <span className="font-semibold text-foreground">{money(totals.total)}</span>
                    <span className="ml-1">(incl. {money(totals.tax)} GST)</span>
                </span>
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={(index === 0 && !detailsValid) || (index === 1 && !linesValid)}
                        >
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !detailsValid || !linesValid}>
                            {isEdit ? 'Save changes' : 'Create invoice'}
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={FileText} title="Invoice details" blurb="Who is being billed, and the key dates." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Bill to" span>
                            <Segmented
                                value={data.bill_to}
                                onChange={(v) => setData('bill_to', v as 'client' | 'funder')}
                                options={[
                                    { value: 'client', label: 'Client' },
                                    { value: 'funder', label: 'Funder / other' },
                                ]}
                            />
                        </Field>
                        {data.bill_to === 'client' ? (
                            <Field label="Client" span required error={errors.client_id}>
                                <SelectInput
                                    value={data.client_id}
                                    onChange={(v) => setData('client_id', v)}
                                    placeholder="Select client"
                                    options={clientOptions}
                                />
                            </Field>
                        ) : (
                            <>
                                <Field label="Bill to name" required error={errors.client_name}>
                                    <Input
                                        value={data.client_name}
                                        onChange={(e) => setData('client_name', e.target.value)}
                                        placeholder="e.g. Te Whatu Ora"
                                    />
                                </Field>
                                <Field label="Funding body" hint="optional" error={errors.funding_body}>
                                    <Input
                                        value={data.funding_body}
                                        onChange={(e) => setData('funding_body', e.target.value)}
                                        placeholder="e.g. NASC"
                                    />
                                </Field>
                            </>
                        )}
                        <Field label="Invoice date" required error={errors.invoice_date}>
                            <Input type="date" value={data.invoice_date} onChange={(e) => setData('invoice_date', e.target.value)} />
                        </Field>
                        <Field label="Due date" required error={errors.due_date}>
                            <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                        </Field>
                        <Field label="Notes" span hint="optional" error={errors.notes}>
                            <Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Visible on the invoice" />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={Receipt} title="Line items" blurb="Each line is billed net; GST is added per line." />
                    {typeof errors.lines === 'string' && <FieldErr>{errors.lines}</FieldErr>}
                    <div className="space-y-3">
                        {data.lines.map((line, i) => {
                            const net = Number(line.quantity || 0) * Number(line.unit_price || 0);
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- per-line field-group panel, not a content card
                                <div key={i} className="rounded-xl border border-border bg-card/60 p-3">
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <Field label="Description" span required error={errors[`lines.${i}.description` as keyof typeof errors] as string | undefined}>
                                            <Input
                                                value={line.description}
                                                onChange={(e) => updateLine(i, 'description', e.target.value)}
                                                placeholder="e.g. Supported living — week ending 14 Jun"
                                            />
                                        </Field>
                                        <Field label="Quantity" required>
                                            <Input
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={line.quantity}
                                                onChange={(e) => updateLine(i, 'quantity', e.target.value)}
                                            />
                                        </Field>
                                        <Field label="Unit price (ex GST)" required>
                                            <AmountField
                                                value={line.unit_price}
                                                onValueChange={(v) => updateLine(i, 'unit_price', v)}
                                                aria-label={`Line ${i + 1} unit price`}
                                            />
                                        </Field>
                                        <Field label="Tax">
                                            <SelectInput
                                                value={line.tax_rate_id}
                                                onChange={(v) => updateLine(i, 'tax_rate_id', v)}
                                                placeholder="GST 15%"
                                                options={taxOptions}
                                            />
                                        </Field>
                                        <Field label="Line net">
                                            <div className="flex h-9 items-center px-1 text-sm font-medium tabular-nums">{money(net)}</div>
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
                                            <Trash2 className="mr-1 h-4 w-4" /> Remove line
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={addLine} className="mt-3">
                        <Plus className="mr-1 h-4 w-4" /> Add line
                    </Button>
                    {/* eslint-disable-next-line no-restricted-syntax -- totals summary panel, not a content card */}
                    <div className="mt-4 space-y-1 rounded-xl border border-border bg-card/60 p-3 text-sm">
                        <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span className="tabular-nums">{money(totals.subtotal)}</span></div>
                        <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span className="tabular-nums">{money(totals.tax)}</span></div>
                        <div className="flex justify-between border-t pt-1 font-semibold"><span>Total (NZD)</span><span className="tabular-nums">{money(totals.total)}</span></div>
                    </div>
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead icon={ListChecks} title={isEdit ? 'Review & save' : 'Review & create'} blurb={isEdit ? 'Updates this draft invoice.' : 'Creates a draft invoice you can then send.'} />
                    <ReviewCard icon={FileText} title="Invoice">
                        <ReviewRow label="Bill to" value={billToName} />
                        {data.bill_to === 'funder' && data.funding_body && <ReviewRow label="Funding body" value={data.funding_body} />}
                        <ReviewRow label="Invoice date" value={data.invoice_date} />
                        <ReviewRow label="Due date" value={data.due_date} />
                        <ReviewRow label="Lines" value={String(data.lines.length)} />
                        <ReviewRow label="Subtotal" value={money(totals.subtotal)} />
                        <ReviewRow label="GST" value={money(totals.tax)} />
                        <ReviewRow label="Total (NZD)" value={money(totals.total)} />
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">{isEdit ? 'Saving…' : 'Creating…'}</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default NewInvoiceDialog;
