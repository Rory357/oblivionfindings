import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ArrowLeft } from 'lucide-react';
import { FormEvent } from 'react';

type ParentAccount = {
    id: number;
    code: string;
    name: string;
    type: string;
};

type TaxRate = {
    id: number;
    name: string;
    code: string;
    rate: string;
};

type FundingStream = {
    id: number;
    code: string;
    name: string;
};

type PageProps = {
    parentAccounts: ParentAccount[];
    taxRates: TaxRate[];
    fundingStreams: FundingStream[];
};

const accountTypes = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'equity', label: 'Equity' },
    { value: 'revenue', label: 'Revenue' },
    { value: 'expense', label: 'Expense' },
];

const subTypes: Record<string, { value: string; label: string }[]> = {
    asset: [
        { value: 'current_asset', label: 'Current Asset' },
        { value: 'fixed_asset', label: 'Fixed Asset' },
        { value: 'bank', label: 'Bank' },
        { value: 'accounts_receivable', label: 'Accounts Receivable' },
        { value: 'inventory', label: 'Inventory' },
        { value: 'other_asset', label: 'Other Asset' },
    ],
    liability: [
        { value: 'current_liability', label: 'Current Liability' },
        { value: 'long_term_liability', label: 'Long Term Liability' },
        { value: 'accounts_payable', label: 'Accounts Payable' },
        { value: 'tax_payable', label: 'Tax Payable' },
        { value: 'other_liability', label: 'Other Liability' },
    ],
    equity: [
        { value: 'retained_earnings', label: 'Retained Earnings' },
        { value: 'share_capital', label: 'Share Capital' },
        { value: 'reserves', label: 'Reserves' },
        { value: 'other_equity', label: 'Other Equity' },
    ],
    revenue: [
        { value: 'operating_revenue', label: 'Operating Revenue' },
        { value: 'grant_income', label: 'Grant Income' },
        { value: 'funding_income', label: 'Funding Income' },
        { value: 'other_income', label: 'Other Income' },
    ],
    expense: [
        { value: 'operating_expense', label: 'Operating Expense' },
        { value: 'cost_of_sales', label: 'Cost of Sales' },
        { value: 'payroll', label: 'Payroll' },
        { value: 'depreciation', label: 'Depreciation' },
        { value: 'administration', label: 'Administration' },
        { value: 'other_expense', label: 'Other Expense' },
    ],
};

export default function AccountCreate({ parentAccounts, taxRates, fundingStreams }: PageProps) {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        name: '',
        type: '',
        sub_type: '',
        parent_id: '',
        description: '',
        gst_applicable: false,
        is_active: true,
        default_tax_rate_id: '',
        funding_stream_id: '',
    });

    const breadcrumbs = [
        { title: 'Finance', href: route('finance.dashboard') },
        { title: 'Chart of Accounts', href: route('finance.accounts.index') },
        { title: 'Create Account', href: route('finance.accounts.create') },
    ];

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(route('finance.accounts.store'));
    }

    const filteredParents = data.type
        ? parentAccounts.filter((a) => a.type === data.type)
        : parentAccounts;

    const currentSubTypes = data.type ? (subTypes[data.type] || []) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Account" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center gap-4">
                    <Link href={route('finance.accounts.index')}>
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Create Account</h1>
                        <p className="text-muted-foreground">Add a new account to the chart of accounts</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Account Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="code">Account Code *</Label>
                                    <Input
                                        id="code"
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value)}
                                        placeholder="e.g. 1000"
                                        maxLength={20}
                                    />
                                    {errors.code && (
                                        <p className="text-sm text-destructive">{errors.code}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="name">Account Name *</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="e.g. Cash at Bank"
                                        maxLength={255}
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Account Type *</Label>
                                    <Select
                                        value={data.type}
                                        onValueChange={(value) => {
                                            setData((prev) => ({
                                                ...prev,
                                                type: value,
                                                sub_type: '',
                                                parent_id: '',
                                            }));
                                        }}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {accountTypes.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.type && (
                                        <p className="text-sm text-destructive">{errors.type}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Sub Type</Label>
                                    <Select
                                        value={data.sub_type}
                                        onValueChange={(value) => setData('sub_type', value)}
                                        disabled={!data.type}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select sub type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {currentSubTypes.map((st) => (
                                                <SelectItem key={st.value} value={st.value}>
                                                    {st.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.sub_type && (
                                        <p className="text-sm text-destructive">{errors.sub_type}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label>Parent Account</Label>
                                <Select
                                    value={data.parent_id}
                                    onValueChange={(value) => setData('parent_id', value)}
                                    disabled={!data.type}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None (top-level account)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {filteredParents.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.code} - {p.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.parent_id && (
                                    <p className="text-sm text-destructive">{errors.parent_id}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Default Tax Rate</Label>
                                    <Select
                                        value={data.default_tax_rate_id}
                                        onValueChange={(value) => setData('default_tax_rate_id', value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="None" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {taxRates.map((tr) => (
                                                <SelectItem key={tr.id} value={String(tr.id)}>
                                                    {tr.name} ({tr.rate}%)
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Funding Stream</Label>
                                    <Select
                                        value={data.funding_stream_id}
                                        onValueChange={(value) => setData('funding_stream_id', value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="None" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {fundingStreams.map((fs) => (
                                                <SelectItem key={fs.id} value={String(fs.id)}>
                                                    {fs.code} - {fs.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Optional description for this account"
                                    rows={3}
                                />
                                {errors.description && (
                                    <p className="text-sm text-destructive">{errors.description}</p>
                                )}
                            </div>

                            <div className="flex items-center gap-6">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="gst_applicable"
                                        checked={data.gst_applicable}
                                        onCheckedChange={(checked) =>
                                            setData('gst_applicable', checked === true)
                                        }
                                    />
                                    <Label htmlFor="gst_applicable" className="font-normal">
                                        GST Applicable
                                    </Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_active"
                                        checked={data.is_active}
                                        onCheckedChange={(checked) =>
                                            setData('is_active', checked === true)
                                        }
                                    />
                                    <Label htmlFor="is_active" className="font-normal">
                                        Active
                                    </Label>
                                </div>
                            </div>

                            <div className="flex justify-end gap-3">
                                <Link href={route('finance.accounts.index')}>
                                    <Button type="button" variant="outline">Cancel</Button>
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Account'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
