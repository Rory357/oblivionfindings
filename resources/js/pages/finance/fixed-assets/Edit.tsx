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
import { AlertTriangle, Calculator } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { FormEvent, useMemo } from 'react';

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface FixedAsset {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    category: string;
    purchase_date: string;
    purchase_cost: string;
    residual_value: string;
    useful_life_months: number;
    depreciation_method: string;
    accumulated_depreciation: string;
    status: string;
    gl_asset_account_id: number | null;
    gl_depreciation_account_id: number | null;
    gl_expense_account_id: number | null;
    linked_asset_id: number | null;
    notes: string | null;
}

interface Props {
    asset: FixedAsset;
    hasDepreciations: boolean;
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

export default function FixedAssetEdit({ asset, hasDepreciations, assetAccounts, expenseAccounts }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        asset_name: asset.asset_name,
        asset_tag: asset.asset_tag || '',
        category: asset.category,
        purchase_date: asset.purchase_date ? asset.purchase_date.split('T')[0] : '',
        purchase_cost: asset.purchase_cost,
        residual_value: asset.residual_value || '',
        useful_life_months: String(asset.useful_life_months),
        depreciation_method: asset.depreciation_method,
        gl_asset_account_id: asset.gl_asset_account_id ? String(asset.gl_asset_account_id) : '',
        gl_depreciation_account_id: asset.gl_depreciation_account_id ? String(asset.gl_depreciation_account_id) : '',
        gl_expense_account_id: asset.gl_expense_account_id ? String(asset.gl_expense_account_id) : '',
        notes: asset.notes || '',
    });

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Fixed Assets', href: '/finance/fixed-assets' },
        { title: asset.asset_name, href: `/finance/fixed-assets/${asset.id}` },
        { title: 'Edit', href: `/finance/fixed-assets/${asset.id}/edit` },
    ];
    const generalError = (errors as Record<string, string | undefined>).general;

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        put(`/finance/fixed-assets/${asset.id}`);
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
            return cost * (2 / months);
        }

        return null;
    }, [data.purchase_cost, data.residual_value, data.useful_life_months, data.depreciation_method]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${asset.asset_name}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref={`/finance/fixed-assets/${asset.id}`}
                        title="Edit Fixed Asset"
                        description={`Update details for ${asset.asset_name}`}
                    />
                }
            >
                {hasDepreciations && (
                    <div className="rounded-lg bg-status-warning-bg border border-status-warning/30 p-4 flex items-start gap-3">
                        <AlertTriangle className="h-5 w-5 text-status-warning mt-0.5 shrink-0" />
                        <div>
                            <p className="text-sm font-medium text-status-warning">Depreciation records exist</p>
                            <p className="text-sm text-status-warning mt-1">
                                Purchase cost and purchase date cannot be changed because depreciation has already been recorded
                                for this asset.
                            </p>
                        </div>
                    </div>
                )}

                {generalError && (
                    <div className="rounded-lg bg-destructive/10 p-4 text-sm text-destructive">
                        {generalError}
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
                                        disabled={hasDepreciations}
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
                                        disabled={hasDepreciations}
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
                        <Link href={`/finance/fixed-assets/${asset.id}`}>
                            <Button type="button" variant="outline">Cancel</Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
