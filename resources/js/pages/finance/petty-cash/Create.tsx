import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FormEvent } from 'react';

interface Account {
    id: number;
    code: string;
    name: string;
}

interface User {
    id: number;
    name: string;
}

interface Props extends PageProps {
    accounts: Account[];
    users: User[];
}

export default function PettyCashCreate({ accounts, users }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        float_amount: '',
        gl_account_id: '',
        custodian_user_id: '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('finance.petty-cash.store'));
    };

    return (
        <AppLayout>
            <Head title="New Petty Cash Fund" />

            <div className="mx-auto max-w-2xl space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">New Petty Cash Fund</h1>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Fund Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <Label htmlFor="name">Fund Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. Office Petty Cash"
                                />
                                {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                            </div>

                            <div>
                                <Label htmlFor="float_amount">Float Amount (NZD)</Label>
                                <Input
                                    id="float_amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    value={data.float_amount}
                                    onChange={(e) => setData('float_amount', e.target.value)}
                                    placeholder="200.00"
                                />
                                {errors.float_amount && (
                                    <p className="mt-1 text-sm text-red-600">{errors.float_amount}</p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="gl_account_id">GL Account</Label>
                                <Select
                                    value={data.gl_account_id}
                                    onValueChange={(val) => setData('gl_account_id', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select GL account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {accounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.code} - {account.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.gl_account_id && (
                                    <p className="mt-1 text-sm text-red-600">{errors.gl_account_id}</p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="custodian_user_id">Custodian (Optional)</Label>
                                <Select
                                    value={data.custodian_user_id}
                                    onValueChange={(val) => setData('custodian_user_id', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select custodian" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {users.map((user) => (
                                            <SelectItem key={user.id} value={String(user.id)}>
                                                {user.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.custodian_user_id && (
                                    <p className="mt-1 text-sm text-red-600">{errors.custodian_user_id}</p>
                                )}
                            </div>

                            <div className="flex gap-2 pt-4">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Fund'}
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={route('finance.petty-cash.index')}>Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
