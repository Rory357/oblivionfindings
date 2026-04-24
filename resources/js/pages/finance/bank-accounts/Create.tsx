import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface Props {
    glAccounts: GlAccount[];
}

export default function BankAccountCreate({ glAccounts }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        bank_name: '',
        account_number: '',
        account_type: 'cheque',
        gl_account_id: '',
        opening_balance: '0.00',
        is_primary: false,
        is_active: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/finance/bank-accounts');
    };

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
        { title: 'Add Bank Account', href: '/finance/bank-accounts/create' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Bank Account" />

            <div className="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-foreground">Add Bank Account</h1>
                    <p className="text-muted-foreground mt-1">Register a new bank account for reconciliation</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Account Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="name">Account Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. ANZ Business Cheque"
                                />
                                {errors.name && <p className="text-sm text-status-critical">{errors.name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="bank_name">Bank Name</Label>
                                <Input
                                    id="bank_name"
                                    value={data.bank_name}
                                    onChange={(e) => setData('bank_name', e.target.value)}
                                    placeholder="e.g. ANZ, Westpac, BNZ, ASB"
                                />
                                {errors.bank_name && <p className="text-sm text-status-critical">{errors.bank_name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="account_number">Account Number</Label>
                                <Input
                                    id="account_number"
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
                                    placeholder="XX-XXXX-XXXXXXX-XXX"
                                />
                                {errors.account_number && <p className="text-sm text-status-critical">{errors.account_number}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="account_type">Account Type</Label>
                                <Select
                                    value={data.account_type}
                                    onValueChange={(value) => setData('account_type', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="cheque">Cheque</SelectItem>
                                        <SelectItem value="savings">Savings</SelectItem>
                                        <SelectItem value="term_deposit">Term Deposit</SelectItem>
                                        <SelectItem value="credit_card">Credit Card</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.account_type && <p className="text-sm text-status-critical">{errors.account_type}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="gl_account_id">GL Account</Label>
                                <Select
                                    value={data.gl_account_id}
                                    onValueChange={(value) => setData('gl_account_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a GL account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {glAccounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.code} - {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.gl_account_id && <p className="text-sm text-status-critical">{errors.gl_account_id}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="opening_balance">Opening Balance (NZD)</Label>
                                <Input
                                    id="opening_balance"
                                    type="number"
                                    step="0.01"
                                    value={data.opening_balance}
                                    onChange={(e) => setData('opening_balance', e.target.value)}
                                />
                                {errors.opening_balance && <p className="text-sm text-status-critical">{errors.opening_balance}</p>}
                            </div>

                            <div className="flex items-center justify-between">
                                <div>
                                    <Label>Primary Account</Label>
                                    <p className="text-sm text-muted-foreground">Set as the primary bank account</p>
                                </div>
                                <Switch
                                    checked={data.is_primary}
                                    onCheckedChange={(checked) => setData('is_primary', checked)}
                                />
                            </div>

                            <div className="flex items-center justify-between">
                                <div>
                                    <Label>Active</Label>
                                    <p className="text-sm text-muted-foreground">Inactive accounts are hidden from lists</p>
                                </div>
                                <Switch
                                    checked={data.is_active}
                                    onCheckedChange={(checked) => setData('is_active', checked)}
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Bank Account'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
