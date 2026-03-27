import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ArrowLeft, Calculator } from 'lucide-react';
import { FormEvent, useMemo } from 'react';

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface Props {
    assetAccounts: GlAccount[];
    expenseAccounts: GlAccount[];
}

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const categories = [
    { value: 'vehicle', label: 'Vehicle' },
    { value: 'equipment', label: 'Equipment' },
    { value: 'building', label: 'Building' },
    { value: 'furniture', label: 'Furniture' },
    { value: 'it_equipment', label: 'IT Equipment' },
    { value: 'land', label: 'Land' },
];

const depreciationMethods = [
    { value: 'straight_line', label: 'Straight Line' },
    { value: 'diminishing_value', label: 'Diminishing Value' },
];

export default function FixedAssetCreate({ assetAccounts, expenseAccounts }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        asset_name: '',
        asset_tag: '',
        category: '',
        purchase_date: '',
        purchase_cost: '',
        residual_value: '',
        useful_life_months: '',
        depreciation_method: 'straight_line',
        gl_asset_account_id: '',
        gl_depreciation_account_id: '',
        gl_expense_account_id: '',
        notes: '',
    });

    const breadcrumbs = [
        { title: 'Finance', href: route('finance.dashboard') },
        { title: 'Fixed Assets', href: route('finance.fixed-assets.index') },
        { title: 'Add Asset', href: route('finance.fixed-assets.create') },
    ];

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(route('finance.fixed-assets.store'));
    }

    // Preview monthly depreciation calculation
    const monthlyDepreciation = useMemo(() => {
        const cost = parseFloat(data.purchase_cost) || 0;
        const residual = parseFloat(data.residual_value) || 0;
        const months = parseInt(data.useful_life_months) || 0;

        if (cost <= 0 || months <= 0) return null;

        if (data.depreciation_method === 'straight_line') {
            return (cost - residual) / months;
        }

        if (data.depreciation_method === 'diminishing_value') {
            // First month calculation
            return cost * (2 / months);
        }

        return null;
    }, [data.purchase_cost, data.residual_value, data.useful_life_months, data.depreciation_method]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Fixed Asset" />

            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div className="flex items-center gap-4">
                    <Link href={route('finance.fixed-assets.index')}>
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Add Fixed Asset</h1>
                        <p className="text-muted-foreground">Register a new fixed asset in the asset register</p>
                    </div>
                </div>

                {errors.general && (
                    <div className="rounded-lg bg-destructive/10 p-4 text-sm text-destructive">
                        {errors.general}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Asset Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Asset Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="asset_name">Asset Name *</Label>
                                    <Input
                                        id="asset_name"
                                        value={data.asset_name}
                                        onChange={(e) => setData('asset_name', e.target.value)}
                                        placeholder="e.g. Toyota Hiace 2024"
                                    />
                                    {errors.asset_name && (
                                        <p className="text-sm text-destructive">{errors.asset_name}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="asset_tag">Asset Tag</Label>
                                    <Input
                                        id="asset_tag"
                                        value={data.asset_tag}
                                        onChange={(e) => setData('asset_tag', e.target.value)}
                                        placeholder="e.g. FA-001"
                                    />
                                    {errors.asset_tag && (
                                        <p className="text-sm text-destructive">{errors.asset_tag}</p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-1.5">
                                    <Label>Category *</Label>
                                    <Select
                                        value={data.category}
                                        onValueChange={(value) => setData('category', value)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((c) => (
                                                <SelectItem key={c.value} value={c.value}>
                                                    {c.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.category && (
                                        <p className="text-sm text-destructive">{errors.category}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="purchase_date">Purchase Date *</Label>
                                    <Input
                                        id="purchase_date"
                                        type="date"
                                        value={data.purchase_date}
                                        onChange={(e) => setData('purchase_date', e.target.value)}
                                    />
                                    {errors.purchase_date && (
                                        <p className="text-sm text-destructive">{errors.purchase_date}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Any additional notes about this asset"
                                    rows={3}
                                />
                                {errors.notes && (
                                    <p className="text-sm text-destructive">{errors.notes}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Depreciation Settings */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Depreciation Settings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-3 gap-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="purchase_cost">Purchase Cost (NZD) *</Label>
                                    <Input
                                        id="purchase_cost"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.purchase_cost}
                                        onChange={(e) => setData('purchase_cost', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    {errors.purchase_cost && (
                                        <p className="text-sm text-destructive">{errors.purchase_cost}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="residual_value">Residual Value (NZD)</Label>
                                    <Input
                                        id="residual_value"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.residual_value}
                                        onChange={(e) => setData('residual_value', e.target.value)}
                                        placeholder="0.00"
                                    />
                                    {errors.residual_value && (
                                        <p className="text-sm text-destructive">{errors.residual_value}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="useful_life_months">Useful Life (months) *</Label>
                                    <Input
                                        id="useful_life_months"
                                        type="number"
                                        min="1"
                                        value={data.useful_life_months}
                                        onChange={(e) => setData('useful_life_months', e.target.value)}
                                        placeholder="e.g. 60"
                                    />
                                    {errors.useful_life_months && (
                                        <p className="text-sm text-destructive">{errors.useful_life_months}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label>Depreciation Method *</Label>
                                <Select
                                    value={data.depreciation_method}
                                    onValueChange={(value) => setData('depreciation_method', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select method" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {depreciationMethods.map((m) => (
                                            <SelectItem key={m.value} value={m.value}>
                                                {m.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.depreciation_method && (
                                    <p className="text-sm text-destructive">{errors.depreciation_method}</p>
                                )}
                            </div>

                            {/* Depreciation Preview */}
                            {monthlyDepreciation !== null && monthlyDepreciation > 0 && (
                                <div className="rounded-lg bg-muted/50 p-4 flex items-center gap-3">
                                    <Calculator className="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <p className="text-sm font-medium">
                                            Estimated Monthly Depreciation: {formatNZD(monthlyDepreciation)}
                                        </p>
                                        {data.depreciation_method === 'diminishing_value' && (
                                            <p className="text-xs text-muted-foreground">
                                                (First month amount -- diminishing value decreases over time)
                                            </p>
                                        )}
                                        {data.depreciation_method === 'straight_line' && (
                                            <p className="text-xs text-muted-foreground">
                                                Fixed monthly amount over {data.useful_life_months} months
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* GL Account Mapping */}
                    <Card>
                        <CardHeader>
                            <CardTitle>GL Account Mapping</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                Link this asset to GL accounts for automatic journal posting. If an asset account is selected,
                                an acquisition journal will be posted on creation.
                            </p>

                            <div className="space-y-1.5">
                                <Label>Fixed Asset Account</Label>
                                <Select
                                    value={data.gl_asset_account_id}
                                    onValueChange={(value) => setData('gl_asset_account_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assetAccounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.code} - {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.gl_asset_account_id && (
                                    <p className="text-sm text-destructive">{errors.gl_asset_account_id}</p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label>Accumulated Depreciation Account</Label>
                                <Select
                                    value={data.gl_depreciation_account_id}
                                    onValueChange={(value) => setData('gl_depreciation_account_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None (defaults to 1590)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assetAccounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.code} - {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.gl_depreciation_account_id && (
                                    <p className="text-sm text-destructive">{errors.gl_depreciation_account_id}</p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label>Depreciation Expense Account</Label>
                                <Select
                                    value={data.gl_expense_account_id}
                                    onValueChange={(value) => setData('gl_expense_account_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None (defaults to 8000)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {expenseAccounts.map((a) => (
                                            <SelectItem key={a.id} value={String(a.id)}>
                                                {a.code} - {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.gl_expense_account_id && (
                                    <p className="text-sm text-destructive">{errors.gl_expense_account_id}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="flex justify-end gap-3">
                        <Link href={route('finance.fixed-assets.index')}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Asset'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
