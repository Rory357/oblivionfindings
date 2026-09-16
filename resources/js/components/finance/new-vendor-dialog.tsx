import { useForm } from '@inertiajs/react';
import { Building2, ListChecks, MapPin, Plus, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
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

/** A contact row on the vendor's repeatable contacts block. */
type ContactForm = {
    id: number | null;
    name: string;
    role: string;
    email: string;
    phone: string;
    is_primary: boolean;
};

/** An existing vendor to prefill the wizard with (edit mode). */
export type EditableVendor = {
    id: number;
    name: string;
    trading_name: string | null;
    vendor_type: string;
    gst_number: string | null;
    bank_account_number: string | null;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    region: string | null;
    postal_code: string | null;
    payment_terms_days: number | null;
    default_expense_account_id: number | string | null;
    is_active: boolean;
    notes: string | null;
    contacts?: Array<{
        id: number;
        name: string;
        role: string | null;
        email: string | null;
        phone: string | null;
        is_primary: boolean;
    }>;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Name & contact',
        icon: Building2,
    },
    {
        key: 'address',
        label: 'Address, bank & contacts',
        blurb: 'Where they are & who to call',
        icon: MapPin,
    },
    {
        key: 'review',
        label: 'Terms & review',
        blurb: 'Payment terms & confirm',
        icon: ListChecks,
    },
];

const emptyContact = (isFirst: boolean): ContactForm => ({
    id: null,
    name: '',
    role: '',
    email: '',
    phone: '',
    is_primary: isFirst,
});

/**
 * Vendor wizard — add or edit an AP vendor as an Add-Client-grade stepper modal
 * (Details → Address, bank & contacts → Terms & review). Posts to
 * `finance.vendors.store` / PUTs `finance.vendors.update`; it carries the full
 * field set of the retired routed Create/Edit pages, so nothing is lost by
 * opening the record from the list or the profile instead.
 *
 * `is_active` is edit-only: the store endpoint always creates an active vendor
 * (and StoreVendorRequest has no rule for it), so the toggle would be a lie on
 * create.
 */
export function NewVendorDialog({
    open,
    onClose,
    expenseAccounts,
    vendor,
}: {
    open: boolean;
    onClose: () => void;
    expenseAccounts: AccountOption[];
    /** When provided, the wizard opens in EDIT mode (prefilled, PUTs the update). */
    vendor?: EditableVendor | null;
}) {
    const isEdit = !!vendor;
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;

    const form = useForm<{
        name: string;
        trading_name: string;
        vendor_type: string;
        email: string;
        phone: string;
        gst_number: string;
        address_line_1: string;
        address_line_2: string;
        city: string;
        region: string;
        postal_code: string;
        bank_account_number: string;
        payment_terms_days: string;
        default_expense_account_id: string;
        is_active: boolean;
        notes: string;
        contacts: ContactForm[];
    }>(
        vendor
            ? {
                  name: vendor.name ?? '',
                  trading_name: vendor.trading_name ?? '',
                  vendor_type: vendor.vendor_type ?? 'supplier',
                  email: vendor.email ?? '',
                  phone: vendor.phone ?? '',
                  gst_number: vendor.gst_number ?? '',
                  address_line_1: vendor.address_line_1 ?? '',
                  address_line_2: vendor.address_line_2 ?? '',
                  city: vendor.city ?? '',
                  region: vendor.region ?? '',
                  postal_code: vendor.postal_code ?? '',
                  bank_account_number: vendor.bank_account_number ?? '',
                  payment_terms_days:
                      vendor.payment_terms_days != null
                          ? String(vendor.payment_terms_days)
                          : '',
                  default_expense_account_id:
                      vendor.default_expense_account_id != null
                          ? String(vendor.default_expense_account_id)
                          : '',
                  is_active: vendor.is_active,
                  notes: vendor.notes ?? '',
                  contacts: (vendor.contacts ?? []).map((c) => ({
                      id: c.id,
                      name: c.name ?? '',
                      role: c.role ?? '',
                      email: c.email ?? '',
                      phone: c.phone ?? '',
                      is_primary: c.is_primary,
                  })),
              }
            : {
                  name: '',
                  trading_name: '',
                  vendor_type: 'supplier',
                  email: '',
                  phone: '',
                  gst_number: '',
                  address_line_1: '',
                  address_line_2: '',
                  city: '',
                  region: '',
                  postal_code: '',
                  bank_account_number: '',
                  payment_terms_days: '',
                  default_expense_account_id: '',
                  is_active: true,
                  notes: '',
                  contacts: [],
              },
    );
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
    const contactsValid = data.contacts.every((c) => c.name.trim().length > 0);

    const addContact = () =>
        setData('contacts', [
            ...data.contacts,
            emptyContact(data.contacts.length === 0),
        ]);

    const removeContact = (i: number) =>
        setData(
            'contacts',
            data.contacts.filter((_, idx) => idx !== i),
        );

    const updateContact = (
        i: number,
        field: keyof ContactForm,
        value: string | boolean,
    ) => {
        const updated: ContactForm[] = [...data.contacts];
        updated[i] = { ...updated[i], [field]: value };
        // Exactly one primary contact.
        if (field === 'is_primary' && value === true) {
            updated.forEach((c, idx) => {
                if (idx !== i) c.is_primary = false;
            });
        }
        setData('contacts', updated);
    };

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => {
            const payload: Record<string, unknown> = {
                name: d.name,
                trading_name: d.trading_name || null,
                vendor_type: d.vendor_type,
                gst_number: d.gst_number || null,
                bank_account_number: d.bank_account_number || null,
                email: d.email || null,
                phone: d.phone || null,
                address_line_1: d.address_line_1 || null,
                address_line_2: d.address_line_2 || null,
                city: d.city || null,
                region: d.region || null,
                postal_code: d.postal_code || null,
                payment_terms_days:
                    d.payment_terms_days === ''
                        ? null
                        : Number(d.payment_terms_days),
                default_expense_account_id:
                    d.default_expense_account_id || null,
                notes: d.notes || null,
                contacts: d.contacts.map((c) => ({
                    ...(isEdit && c.id != null ? { id: c.id } : {}),
                    name: c.name,
                    role: c.role || null,
                    email: c.email || null,
                    phone: c.phone || null,
                    is_primary: c.is_primary,
                })),
            };
            // The store endpoint always creates an active vendor.
            if (isEdit) payload.is_active = d.is_active;
            return payload;
        });
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => goTo(0),
        };
        if (isEdit && vendor) {
            form.put(`/finance/vendors/${vendor.id}`, opts);
        } else {
            form.post('/finance/vendors', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit vendor' : 'New vendor'}
            description={
                isEdit
                    ? 'Update this supplier, contractor or service provider'
                    : 'Add a supplier, contractor or service provider'
            }
            railIcon={Building2}
            railTitle={isEdit ? 'Edit vendor' : 'New vendor'}
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
                            disabled={
                                (index === 0 && !detailsValid) ||
                                (index === 1 && !contactsValid)
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
                                processing || !detailsValid || !contactsValid
                            }
                        >
                            {isEdit ? 'Save changes' : 'Create vendor'}
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
                        <Field label="Name" required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="e.g. Acme Supplies Ltd"
                            />
                        </Field>
                        <Field
                            label="Trading name"
                            hint="optional"
                            error={errors.trading_name}
                        >
                            <Input
                                value={data.trading_name}
                                onChange={(e) =>
                                    setData('trading_name', e.target.value)
                                }
                                placeholder="e.g. Acme"
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
                        icon={MapPin}
                        title="Address, bank & contacts"
                        blurb="Postal address, the account you pay into, and the people you deal with."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Address line 1"
                            span
                            hint="optional"
                            error={errors.address_line_1}
                        >
                            <Input
                                value={data.address_line_1}
                                onChange={(e) =>
                                    setData('address_line_1', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Address line 2"
                            span
                            hint="optional"
                            error={errors.address_line_2}
                        >
                            <Input
                                value={data.address_line_2}
                                onChange={(e) =>
                                    setData('address_line_2', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="City" hint="optional" error={errors.city}>
                            <Input
                                value={data.city}
                                onChange={(e) =>
                                    setData('city', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Region"
                            hint="optional"
                            error={errors.region}
                        >
                            <Input
                                value={data.region}
                                onChange={(e) =>
                                    setData('region', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Postcode"
                            hint="optional"
                            error={errors.postal_code}
                        >
                            <Input
                                value={data.postal_code}
                                onChange={(e) =>
                                    setData('postal_code', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Bank account number"
                            hint="optional"
                            error={errors.bank_account_number}
                        >
                            <Input
                                value={data.bank_account_number}
                                onChange={(e) =>
                                    setData(
                                        'bank_account_number',
                                        e.target.value,
                                    )
                                }
                                placeholder="00-0000-0000000-00"
                            />
                        </Field>
                        {isEdit && (
                            <Field label="Status" span>
                                <div className="flex items-center gap-2">
                                    <Switch
                                        id="vendor-is-active"
                                        checked={data.is_active}
                                        onCheckedChange={(v) =>
                                            setData('is_active', v)
                                        }
                                    />
                                    <Label
                                        htmlFor="vendor-is-active"
                                        className="text-[13px] font-normal text-muted-foreground"
                                    >
                                        {data.is_active
                                            ? 'Active — can be used on new bills and purchase orders'
                                            : 'Inactive — hidden from new bills and purchase orders'}
                                    </Label>
                                </div>
                            </Field>
                        )}
                    </div>

                    <div className="mt-5">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-[13px] font-semibold text-foreground">
                                Contacts
                            </h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addContact}
                            >
                                <Plus className="mr-1 h-4 w-4" /> Add contact
                            </Button>
                        </div>
                        {data.contacts.length === 0 ? (
                            <p className="text-[13px] text-muted-foreground">
                                No contacts yet. Add one if you deal with a
                                named person at this vendor.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {data.contacts.map((contact, i) => (
                                    // eslint-disable-next-line no-restricted-syntax -- per-contact field-group panel, not a content card
                                    <div
                                        key={i}
                                        className="rounded-xl border border-border bg-card/60 p-3"
                                    >
                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                            <Field
                                                label="Name"
                                                required
                                                error={
                                                    errors[
                                                        `contacts.${i}.name` as keyof typeof errors
                                                    ] as string | undefined
                                                }
                                            >
                                                <Input
                                                    value={contact.name}
                                                    onChange={(e) =>
                                                        updateContact(
                                                            i,
                                                            'name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Role"
                                                hint="optional"
                                            >
                                                <Input
                                                    value={contact.role}
                                                    onChange={(e) =>
                                                        updateContact(
                                                            i,
                                                            'role',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Account manager"
                                                />
                                            </Field>
                                            <Field
                                                label="Email"
                                                hint="optional"
                                                error={
                                                    errors[
                                                        `contacts.${i}.email` as keyof typeof errors
                                                    ] as string | undefined
                                                }
                                            >
                                                <Input
                                                    type="email"
                                                    value={contact.email}
                                                    onChange={(e) =>
                                                        updateContact(
                                                            i,
                                                            'email',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                label="Phone"
                                                hint="optional"
                                            >
                                                <Input
                                                    value={contact.phone}
                                                    onChange={(e) =>
                                                        updateContact(
                                                            i,
                                                            'phone',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                        </div>
                                        <div className="mt-2 flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <Switch
                                                    id={`vendor-contact-${i}-primary`}
                                                    checked={contact.is_primary}
                                                    onCheckedChange={(v) =>
                                                        updateContact(
                                                            i,
                                                            'is_primary',
                                                            v,
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`vendor-contact-${i}-primary`}
                                                    className="text-[13px] font-normal text-muted-foreground"
                                                >
                                                    Primary contact
                                                </Label>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeContact(i)
                                                }
                                                className="text-muted-foreground hover:text-status-critical"
                                            >
                                                <Trash2 className="mr-1 h-4 w-4" />{' '}
                                                Remove contact
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {index === 2 && (
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
                        {data.trading_name && (
                            <ReviewRow
                                label="Trading name"
                                value={data.trading_name}
                            />
                        )}
                        <ReviewRow label="Type" value={typeLabel} />
                        {data.email && (
                            <ReviewRow label="Email" value={data.email} />
                        )}
                        {data.city && (
                            <ReviewRow
                                label="Address"
                                value={[
                                    data.address_line_1,
                                    data.address_line_2,
                                    data.city,
                                    data.region,
                                    data.postal_code,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                            />
                        )}
                        {data.bank_account_number && (
                            <ReviewRow
                                label="Bank account"
                                value={data.bank_account_number}
                            />
                        )}
                        <ReviewRow
                            label="Contacts"
                            value={String(data.contacts.length)}
                        />
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
                        {isEdit && (
                            <ReviewRow
                                label="Status"
                                value={
                                    data.is_active ? 'Active' : 'Inactive'
                                }
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

export default NewVendorDialog;
