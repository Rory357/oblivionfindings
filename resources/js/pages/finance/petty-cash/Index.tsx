import { Head, Link } from '@inertiajs/react';
import { PageProps } from '@/types';
import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Plus, Wallet, Coins } from 'lucide-react';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Petty Cash', href: '/finance/petty-cash' },
];

export default function PettyCashIndex({ funds }: Props) {
    const activeCount = funds.filter((f) => f.is_active).length;
    const totalFloat = funds.reduce((s, f) => s + f.float_amount, 0);
    const totalBalance = funds.reduce((s, f) => s + f.current_balance, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Petty Cash" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Coins}
                        title="Petty Cash Funds"
                        description="Manage petty cash floats and transactions"
                        stats={[
                            { label: 'Funds', value: funds.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Total float', value: formatCurrency(totalFloat) },
                            { label: 'Total balance', value: formatCurrency(totalBalance) },
                        ]}
                        actions={
                            <Button asChild size="sm">
                                <Link href={'/finance/petty-cash/create'}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Fund
                                </Link>
                            </Button>
                        }
                    />
                }
            >
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
                                <Link key={fund.id} href={`/finance/petty-cash/${fund.id}`}>
                                    <Card className="transition-shadow hover:shadow-md">
                                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                                            <CardTitle className="text-lg">{fund.name}</CardTitle>
                                            {fund.is_active ? (
                                                <Badge variant="outline" className="border-status-success/30 text-status-success">
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
                                                            variance < 0 ? 'font-medium text-destructive' : 'text-status-success'
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
            </PageLayout>
        </AppLayout>
    );
}
