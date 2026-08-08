import { useForm } from '@inertiajs/react';
import { Building2, ListChecks } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { AccountOption } from './new-bill-dialog';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';

const VENDOR_TYPES = [
    { value: 'supplier', label: 'Supplier' },
    { value: 'contractor', label: 'Contractor' },
    { value: 'utility', label: 'Utility' },
    { value: 'government', label: 'Government' },
    { value: 'other', label: 'Other' },
];

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Name & contact',
        icon: Building2,
    },
    {
        key: 'review',
        label: 'Terms & review',
        blurb: 'Payment terms & confirm',
        icon: ListChecks,
    },
];

/**
 * New Vendor wizard — add an AP vendor as an Add-Client-grade stepper modal
 * (Details → Terms & review). Posts to `finance.vendors.store`. vendor_type is a
 * required enum Select; the optional default expense account pre-fills bill lines.
 */
export function NewVendorDialog({
    open,
    onClose,
    expenseAccounts,
}: {
    open: boolean;
    onClose: () => void;
    expenseAccounts: AccountOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        name: string;
        vendor_type: string;
        email: string;
        phone: string;
        gst_number: string;
        payment_terms_days: string;
        default_expense_account_id: string;
        notes: string;
    }>({
        name: '',
        vendor_type: 'supplier',
        email: '',
        phone: '',
        gst_number: '',
        payment_terms_days: '',
        default_expense_account_id: '',
        notes: '',
    });
    const { data, setData, processing, errors } = form;

    const accountOptions = expenseAccounts.map((a) => ({
        value: String(a.id),
        label: `${a.code} · ${a.name}`,
    }));
    const typeLabel =
        VENDOR_TYPES.find((t) => t.value === data.vendor_type)?.label ??
        data.vendor_type;
    const accountLabel = expenseAccounts.find(
        (a) => String(a.id) === data.default_expense_account_id,
    );

    const detailsValid = !!data.name.trim() && !!data.vendor_type;

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            // empty optional fields → null; payment_terms_days numeric or null.
            email: d.email || null,
            phone: d.phone || null,
            gst_number: d.gst_number || null,
            notes: d.notes || null,
            payment_terms_days:
                d.payment_terms_days === ''
                    ? null
                    : Number(d.payment_terms_days),
            default_expense_account_id: d.default_expense_account_id || null,
        }));
        form.post('/finance/vendors', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New vendor"
            description="Add a supplier, contractor or service provider"
            railIcon={Building2}
            railTitle="New Vendor"
            railSub="Accounts payable"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={detailsValid ? 100 : 40}
            pctLabel="Vendor"
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
                            Create vendor
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead
                        icon={Building2}
                        title="Vendor details"
                        blurb="Who they are and how to reach them."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Name" span required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Acme Supplies Ltd"
                            />
                        </Field>
                        <Field label="Type" required error={errors.vendor_type}>
                            <SelectInput
                                value={data.vendor_type}
                                onChange={(v) => setData('vendor_type', v)}
                                placeholder="Select type"
                                options={VENDOR_TYPES}
                            />
                        </Field>
                        <Field
                            label="GST number"
                            hint="optional"
                            error={errors.gst_number}
                        >
                            <Input
                                value={data.gst_number}
                                onChange={(e) =>
                                    setData('gst_number', e.target.value)
                                }
                                placeholder="123-456-789"
                            />
                        </Field>
                        <Field
                            label="Email"
                            hint="optional"
                            error={errors.email}
                        >
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Phone"
                            hint="optional"
                            error={errors.phone}
                        >
                            <Input
                                value={data.phone}
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead
                        icon={ListChecks}
                        title="Terms & review"
                        blurb="Default payment terms and account, then confirm."
                    />
                    <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Payment terms (days)"
                            hint="optional"
                            error={errors.payment_terms_days}
                        >
                            <Input
                                type="number"
                                min="0"
                                value={data.payment_terms_days}
                                onChange={(e) =>
                                    setData(
                                        'payment_terms_days',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 30"
                            />
                        </Field>
                        <Field
                            label="Default expense account"
                            hint="optional"
                            error={errors.default_expense_account_id}
                        >
                            <SelectInput
                                value={data.default_expense_account_id}
                                onChange={(v) =>
                                    setData('default_expense_account_id', v)
                                }
                                placeholder="None"
                                options={accountOptions}
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
                            />
                        </Field>
                    </div>
                    <ReviewCard icon={Building2} title="Vendor">
                        <ReviewRow label="Name" value={data.name || '—'} />
                        <ReviewRow label="Type" value={typeLabel} />
                        {data.email && (
                            <ReviewRow label="Email" value={data.email} />
                        )}
                        {data.payment_terms_days && (
                            <ReviewRow
                                label="Payment terms"
                                value={`${data.payment_terms_days} days`}
                            />
                        )}
                        {accountLabel && (
                            <ReviewRow
                                label="Default account"
                                value={`${accountLabel.code} · ${accountLabel.name}`}
                            />
                        )}
                    </ReviewCard>
                    {processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">
                            Creating…
                        </p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default NewVendorDialog;
