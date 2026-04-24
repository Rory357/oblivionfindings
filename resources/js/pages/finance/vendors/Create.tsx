import { Head, Link, useForm } from '@inertiajs/react';
import { type PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

interface ExpenseAccount {
    id: number;
    code: string;
    name: string;
}

interface ContactForm {
    name: string;
    role: string;
    email: string;
    phone: string;
    is_primary: boolean;
}

interface Props extends PageProps {
    expenseAccounts: ExpenseAccount[];
}

export default function VendorsCreate({ expenseAccounts }: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        trading_name: string;
        vendor_type: string;
        gst_number: string;
        email: string;
        phone: string;
        address_line_1: string;
        address_line_2: string;
        city: string;
        region: string;
        postal_code: string;
        payment_terms_days: string;
        bank_account_number: string;
        default_expense_account_id: string;
        notes: string;
        contacts: ContactForm[];
    }>({
        name: '',
        trading_name: '',
        vendor_type: '',
        gst_number: '',
        email: '',
        phone: '',
        address_line_1: '',
        address_line_2: '',
        city: '',
        region: '',
        postal_code: '',
        payment_terms_days: '',
        bank_account_number: '',
        default_expense_account_id: '',
        notes: '',
        contacts: [],
    });

    const addContact = () => {
        setData('contacts', [
            ...data.contacts,
            { name: '', role: '', email: '', phone: '', is_primary: data.contacts.length === 0 },
        ]);
    };

    const removeContact = (index: number) => {
        const updated = data.contacts.filter((_, i) => i !== index);
        setData('contacts', updated);
    };

    const updateContact = (index: number, field: keyof ContactForm, value: string | boolean) => {
        const updated = [...data.contacts];
        updated[index] = { ...updated[index], [field]: value };
        if (field === 'is_primary' && value === true) {
            updated.forEach((c, i) => {
                if (i !== index) c.is_primary = false;
            });
        }
        setData('contacts', updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/vendors');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Vendors', href: '/finance/vendors' },
                { title: 'Add Vendor', href: '/finance/vendors/create' },
            ]}
        >
            <Head title="Add Vendor" />

            <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center gap-4 mb-6">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/finance/vendors">
                            <ArrowLeft className="w-4 h-4 mr-1" />
                            Back
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Add Vendor</h1>
                        <p className="text-muted-foreground mt-1">Create a new vendor record</p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="name">
                                        Name <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className={errors.name ? 'border-status-critical/30' : ''}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-status-critical mt-1">{errors.name}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="trading_name">Trading Name</Label>
                                    <Input
                                        id="trading_name"
                                        value={data.trading_name}
                                        onChange={(e) => setData('trading_name', e.target.value)}
                                    />
                                    {errors.trading_name && (
                                        <p className="text-sm text-status-critical mt-1">{errors.trading_name}</p>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="vendor_type">
                                        Type <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.vendor_type}
                                        onValueChange={(value) => setData('vendor_type', value)}
                                    >
                                        <SelectTrigger className={errors.vendor_type ? 'border-status-critical/30' : ''}>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="supplier">Supplier</SelectItem>
                                            <SelectItem value="contractor">Contractor</SelectItem>
                                            <SelectItem value="utility">Utility</SelectItem>
                                            <SelectItem value="government">Government</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.vendor_type && (
                                        <p className="text-sm text-status-critical mt-1">{errors.vendor_type}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="gst_number">GST Number</Label>
                                    <Input
                                        id="gst_number"
                                        value={data.gst_number}
                                        onChange={(e) => setData('gst_number', e.target.value)}
                                    />
                                    {errors.gst_number && (
                                        <p className="text-sm text-status-critical mt-1">{errors.gst_number}</p>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                    />
                                    {errors.email && (
                                        <p className="text-sm text-status-critical mt-1">{errors.email}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="phone">Phone</Label>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                    />
                                    {errors.phone && (
                                        <p className="text-sm text-status-critical mt-1">{errors.phone}</p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Address */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Address</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="address_line_1">Address Line 1</Label>
                                <Input
                                    id="address_line_1"
                                    value={data.address_line_1}
                                    onChange={(e) => setData('address_line_1', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="address_line_2">Address Line 2</Label>
                                <Input
                                    id="address_line_2"
                                    value={data.address_line_2}
                                    onChange={(e) => setData('address_line_2', e.target.value)}
                                />
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <Label htmlFor="city">City</Label>
                                    <Input
                                        id="city"
                                        value={data.city}
                                        onChange={(e) => setData('city', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="region">Region</Label>
                                    <Input
                                        id="region"
                                        value={data.region}
                                        onChange={(e) => setData('region', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="postal_code">Postal Code</Label>
                                    <Input
                                        id="postal_code"
                                        value={data.postal_code}
                                        onChange={(e) => setData('postal_code', e.target.value)}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Payment */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Payment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <Label htmlFor="payment_terms_days">Payment Terms (days)</Label>
                                    <Input
                                        id="payment_terms_days"
                                        type="number"
                                        min="0"
                                        value={data.payment_terms_days}
                                        onChange={(e) => setData('payment_terms_days', e.target.value)}
                                    />
                                    {errors.payment_terms_days && (
                                        <p className="text-sm text-status-critical mt-1">{errors.payment_terms_days}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="bank_account_number">Bank Account Number</Label>
                                    <Input
                                        id="bank_account_number"
                                        value={data.bank_account_number}
                                        onChange={(e) => setData('bank_account_number', e.target.value)}
                                    />
                                    {errors.bank_account_number && (
                                        <p className="text-sm text-status-critical mt-1">
                                            {errors.bank_account_number}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="default_expense_account_id">Default Expense Account</Label>
                                    <Select
                                        value={data.default_expense_account_id}
                                        onValueChange={(value) =>
                                            setData('default_expense_account_id', value === 'none' ? '' : value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select account" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">None</SelectItem>
                                            {expenseAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.code} - {account.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.default_expense_account_id && (
                                        <p className="text-sm text-status-critical mt-1">
                                            {errors.default_expense_account_id}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                id="notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                rows={4}
                                placeholder="Internal notes about this vendor..."
                            />
                            {errors.notes && (
                                <p className="text-sm text-status-critical mt-1">{errors.notes}</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Contacts */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Contacts</CardTitle>
                                <Button type="button" variant="outline" size="sm" onClick={addContact}>
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Contact
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {data.contacts.length === 0 ? (
                                <p className="text-sm text-muted-foreground text-center py-4">
                                    No contacts added yet. Click "Add Contact" to add one.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {data.contacts.map((contact, index) => (
                                        <div
                                            key={index}
                                            className="border rounded-lg p-4 relative"
                                        >
                                            <div className="flex items-center justify-between mb-3">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium text-foreground">
                                                        Contact {index + 1}
                                                    </span>
                                                    <label className="flex items-center gap-1.5 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={contact.is_primary}
                                                            onChange={(e) =>
                                                                updateContact(index, 'is_primary', e.target.checked)
                                                            }
                                                            className="rounded border-border"
                                                        />
                                                        Primary
                                                    </label>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeContact(index)}
                                                    className="text-status-critical hover:text-status-critical"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </Button>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <Label>
                                                        Name <span className="text-status-critical">*</span>
                                                    </Label>
                                                    <Input
                                                        value={contact.name}
                                                        onChange={(e) =>
                                                            updateContact(index, 'name', e.target.value)
                                                        }
                                                        className={
                                                            errors[`contacts.${index}.name` as keyof typeof errors]
                                                                ? 'border-status-critical/30'
                                                                : ''
                                                        }
                                                    />
                                                    {errors[`contacts.${index}.name` as keyof typeof errors] && (
                                                        <p className="text-sm text-status-critical mt-1">
                                                            {errors[`contacts.${index}.name` as keyof typeof errors]}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label>Role</Label>
                                                    <Input
                                                        value={contact.role}
                                                        onChange={(e) =>
                                                            updateContact(index, 'role', e.target.value)
                                                        }
                                                    />
                                                </div>
                                                <div>
                                                    <Label>Email</Label>
                                                    <Input
                                                        type="email"
                                                        value={contact.email}
                                                        onChange={(e) =>
                                                            updateContact(index, 'email', e.target.value)
                                                        }
                                                    />
                                                    {errors[`contacts.${index}.email` as keyof typeof errors] && (
                                                        <p className="text-sm text-status-critical mt-1">
                                                            {errors[`contacts.${index}.email` as keyof typeof errors]}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label>Phone</Label>
                                                    <Input
                                                        value={contact.phone}
                                                        onChange={(e) =>
                                                            updateContact(index, 'phone', e.target.value)
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex items-center justify-end gap-3">
                        <Button variant="outline" asChild>
                            <Link href="/finance/vendors">Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Vendor'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
