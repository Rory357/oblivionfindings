import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Wallet } from 'lucide-react';

interface Fund {
    id: number;
    name: string;
    float_amount: number;
    current_balance: number;
    custodian_name: string | null;
    gl_account_name: string | null;
    is_active: boolean;
}

interface Props extends PageProps {
    funds: Fund[];
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

export default function PettyCashIndex({ funds }: Props) {
    return (
        <AppLayout>
            <Head title="Petty Cash" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Petty Cash Funds</h1>
                    <Button asChild>
                        <Link href={route('finance.petty-cash.create')}>
                            <Plus className="mr-1 h-4 w-4" />
                            New Fund
                        </Link>
                    </Button>
                </div>

                {funds.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Wallet className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">No petty cash funds yet.</p>
                            <p className="text-sm text-muted-foreground">Create your first fund to get started.</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {funds.map((fund) => {
                            const variance = fund.current_balance - fund.float_amount;
                            return (
                                <Link key={fund.id} href={route('finance.petty-cash.show', fund.id)}>
                                    <Card className="transition-shadow hover:shadow-md">
                                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                                            <CardTitle className="text-lg">{fund.name}</CardTitle>
                                            {fund.is_active ? (
                                                <Badge variant="outline" className="border-green-300 text-green-600">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge variant="secondary">Inactive</Badge>
                                            )}
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <div className="grid grid-cols-2 gap-2 text-sm">
                                                <div>
                                                    <p className="text-muted-foreground">Float</p>
                                                    <p className="font-semibold">
                                                        {formatCurrency(fund.float_amount)}
                                                    </p>
                                                </div>
                                                <div>
                                                    <p className="text-muted-foreground">Current Balance</p>
                                                    <p className="font-semibold">
                                                        {formatCurrency(fund.current_balance)}
                                                    </p>
                                                </div>
                                            </div>
                                            {variance !== 0 && (
                                                <div className="text-sm">
                                                    <span className="text-muted-foreground">Variance: </span>
                                                    <span
                                                        className={
                                                            variance < 0 ? 'font-medium text-red-600' : 'text-green-600'
                                                        }
                                                    >
                                                        {formatCurrency(variance)}
                                                    </span>
                                                </div>
                                            )}
                                            {fund.custodian_name && (
                                                <div className="text-sm">
                                                    <span className="text-muted-foreground">Custodian: </span>
                                                    <span>{fund.custodian_name}</span>
                                                </div>
                                            )}
                                            {fund.gl_account_name && (
                                                <div className="text-sm text-muted-foreground">
                                                    GL: {fund.gl_account_name}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
