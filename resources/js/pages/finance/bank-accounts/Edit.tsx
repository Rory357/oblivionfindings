import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { PageHero, PageLayout } from '@/components/page';

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
    account_number: string;
    account_type: string;
    gl_account_id: number;
    opening_balance: string;
    is_primary: boolean;
    is_active: boolean;
}

interface Props {
    bankAccount: BankAccount;
    glAccounts: GlAccount[];
}

export default function BankAccountEdit({ bankAccount, glAccounts }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: bankAccount.name,
        bank_name: bankAccount.bank_name,
        account_number: bankAccount.account_number,
        account_type: bankAccount.account_type,
        gl_account_id: String(bankAccount.gl_account_id),
        is_primary: bankAccount.is_primary,
        is_active: bankAccount.is_active,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/finance/bank-accounts/${bankAccount.id}`);
    };

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Accounts', href: '/finance/bank-accounts' },
        { title: bankAccount.name, href: `/finance/bank-accounts/${bankAccount.id}` },
        { title: 'Edit', href: `/finance/bank-accounts/${bankAccount.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${bankAccount.name}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/finance/bank-accounts/${bankAccount.id}`}
                        title="Edit Bank Account"
                        description={`Update details for ${bankAccount.name}`}
                    />
                }
            >
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
                                />
                                {errors.name && <p className="text-sm text-status-critical">{errors.name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="bank_name">Bank Name</Label>
                                <Input
                                    id="bank_name"
                                    value={data.bank_name}
                                    onChange={(e) => setData('bank_name', e.target.value)}
                                />
                                {errors.bank_name && <p className="text-sm text-status-critical">{errors.bank_name}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="account_number">Account Number</Label>
                                <Input
                                    id="account_number"
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
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
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
