import { useForm } from '@inertiajs/react';
import { FileMinus, FileText, ListChecks, Plus, Trash2 } from 'lucide-react';
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

export type CreditNoteVendorOption = { id: number; name: string };
export type CreditNoteClientOption = { id: number; name: string };
export type CreditNoteAccountOption = { id: number; code: string; name: string; type: string };

type CreditType = 'payable' | 'receivable';

type LineForm = {
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string; // percentage: '15' standard, '0' zero-rated
    account_id: string;
};

const emptyLine = (): LineForm => ({
    description: '',
    quantity: '1',
    unit_price: '',
    gst_rate: '15',
    account_id: '',
});

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Type, party & date', icon: FileText },
    { key: 'lines', label: 'Line items', blurb: 'What is being credited', icon: FileMinus },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: ListChecks },
];

const money = (n: number | string) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(n || 0));

const today = () => new Date().toISOString().split('T')[0];

/**
 * Credit Note wizard — the multi-line credit note as an Add-Client-grade stepper
 * modal (Details → Line items → Review). CREATE-ONLY: the controller has no
 * update endpoint, so there's no edit mode. A `payable` note credits a vendor,
 * a `receivable` note credits a client; each line posts to a GL account filtered
 * by the type (expense/asset for AP, revenue for AR). Posts a DRAFT to
 * `finance.credit-notes.store` — the GL journal is posted later on APPROVE, so
 * there is no posting preview at create.
 */
export function CreditNoteDialog({
    open,
    onClose,
    vendors,
    clients,
    accounts,
}: {
    open: boolean;
    onClose: () => void;
    vendors: CreditNoteVendorOption[];
    clients: CreditNoteClientOption[];
    accounts: CreditNoteAccountOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        type: CreditType;
        vendor_id: string;
        client_id: string;
        credit_date: string;
        reason: string;
        lines: LineForm[];
    }>({
        type: 'payable',
        vendor_id: '',
        client_id: '',
        credit_date: today(),
        reason: '',
        lines: [emptyLine()],
    });
    const { data, setData, processing, errors } = form;

    const vendorOptions = vendors.map((v) => ({ value: String(v.id), label: v.name }));
    const clientOptions = clients.map((c) => ({ value: String(c.id), label: c.name }));
    const gstOptions = [
        { value: '15', label: 'GST 15%' },
        { value: '0', label: 'Zero-rated 0%' },
    ];

    // AP credits use expense/asset accounts; AR credits use revenue accounts.
    const accountOptions = useMemo(() => {
        const filtered = data.type === 'payable'
            ? accounts.filter((a) => a.type === 'expense' || a.type === 'asset')
            : accounts.filter((a) => a.type === 'revenue');
        return filtered.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }));
    }, [accounts, data.type]);

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
        setData('lines', data.lines.filter((_, idx) => idx !== i));
    };

    const setType = (t: CreditType) => {
        // Switching type invalidates the per-line accounts (different account set),
        // so clear them; keep the other line fields.
        setData((prev) => ({
            ...prev,
            type: t,
            vendor_id: t === 'payable' ? prev.vendor_id : '',
            client_id: t === 'receivable' ? prev.client_id : '',
            lines: prev.lines.map((l) => ({ ...l, account_id: '' })),
        }));
    };

    const partyLabel = data.type === 'payable'
        ? vendors.find((v) => String(v.id) === data.vendor_id)?.name ?? '—'
        : clients.find((c) => String(c.id) === data.client_id)?.name ?? '—';

    const detailsValid =
        !!data.credit_date && (data.type === 'payable' ? !!data.vendor_id : !!data.client_id);
    const linesValid =
        data.lines.every((l) => l.description.trim() && l.account_id && Number(l.unit_price) >= 0 && Number(l.quantity) > 0)
        && totals.subtotal > 0;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            type: d.type,
            vendor_id: d.type === 'payable' ? d.vendor_id : null,
            client_id: d.type === 'receivable' ? d.client_id : null,
            credit_date: d.credit_date,
            reason: d.reason || null,
            lines: d.lines.map((l) => ({
                description: l.description,
                quantity: l.quantity,
                unit_price: l.unit_price,
                gst_rate: l.gst_rate,
                account_id: l.account_id,
            })),
        }));
        form.post('/finance/credit-notes', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New credit note"
            description="Create a credit note for a vendor or client"
            railIcon={FileMinus}
            railTitle="New Credit Note"
            railSub="Credit notes"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={linesValid ? 100 : Math.min(90, data.lines.filter((l) => l.description).length * 30)}
            pctLabel="Total"
            footerStart={
                <span className="text-[13px] text-muted-foreground">
                    Total <span className="font-semibold text-foreground">{money(totals.total)}</span>
                    <span className="ml-1">(incl. {money(totals.gst)} GST)</span>
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
                            Create credit note
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={FileText} title="Credit note details" blurb="Who is being credited, and when." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Type" span>
                            <Segmented
                                value={data.type}
                                onChange={(v) => setType(v as CreditType)}
                                options={[
                                    { value: 'payable', label: 'Payable (vendor)' },
                                    { value: 'receivable', label: 'Receivable (client)' },
                                ]}
                            />
                        </Field>
                        {data.type === 'payable' ? (
                            <Field label="Vendor" span required error={errors.vendor_id}>
                                <SelectInput
                                    value={data.vendor_id}
                                    onChange={(v) => setData('vendor_id', v)}
                                    placeholder="Select vendor"
                                    options={vendorOptions}
                                />
                            </Field>
                        ) : (
                            <Field label="Client" span required error={errors.client_id}>
                                <SelectInput
                                    value={data.client_id}
                                    onChange={(v) => setData('client_id', v)}
                                    placeholder="Select client"
                                    options={clientOptions}
                                />
                            </Field>
                        )}
                        <Field label="Credit date" required error={errors.credit_date}>
                            <Input type="date" value={data.credit_date} onChange={(e) => setData('credit_date', e.target.value)} />
                        </Field>
                        <Field label="Reason" span hint="optional" error={errors.reason}>
                            <Textarea rows={2} value={data.reason} onChange={(e) => setData('reason', e.target.value)} placeholder="Reason for the credit note" />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={FileMinus} title="Line items" blurb="Each line posts to a GL account. GST is added per line." />
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
                                                placeholder="e.g. Overcharge correction"
                                            />
                                        </Field>
                                        <Field label={data.type === 'payable' ? 'Expense / asset account' : 'Revenue account'} span required error={errors[`lines.${i}.account_id` as keyof typeof errors] as string | undefined}>
                                            <SelectInput
                                                value={line.account_id}
                                                onChange={(v) => updateLine(i, 'account_id', v)}
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
                                                value={line.gst_rate}
                                                onChange={(v) => updateLine(i, 'gst_rate', v)}
                                                placeholder="GST 15%"
                                                options={gstOptions}
                                            />
                                        </Field>
                                        <Field label="Line total (incl GST)">
                                            <div className="flex h-9 items-center px-1 text-sm font-medium tabular-nums">
                                                {money(net * (1 + Number(line.gst_rate || 0) / 100))}
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
                        <div className="flex justify-between"><span className="text-muted-foreground">GST</span><span className="tabular-nums">{money(totals.gst)}</span></div>
                        <div className="flex justify-between border-t pt-1 font-semibold"><span>Total (NZD)</span><span className="tabular-nums">{money(totals.total)}</span></div>
                    </div>
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead icon={ListChecks} title="Review & create" blurb="Creates a draft credit note you can then approve — approval posts the GL journal." />
                    <ReviewCard icon={FileText} title="Credit note">
                        <ReviewRow label="Type" value={data.type === 'payable' ? 'Payable (vendor)' : 'Receivable (client)'} />
                        <ReviewRow label={data.type === 'payable' ? 'Vendor' : 'Client'} value={partyLabel} />
                        <ReviewRow label="Credit date" value={data.credit_date} />
                        <ReviewRow label="Lines" value={String(data.lines.length)} />
                        <ReviewRow label="Subtotal" value={money(totals.subtotal)} />
                        <ReviewRow label="GST" value={money(totals.gst)} />
                        <ReviewRow label="Total (NZD)" value={money(totals.total)} />
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">Creating…</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default CreditNoteDialog;
