import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ArrowLeftRight, Link2, Save } from 'lucide-react';
import { FormEvent } from 'react';

type LocalAccount = {
    id: number;
    code: string;
    name: string;
    type: string;
    sub_type: string | null;
    external_id: string | null;
};

type IntegrationData = {
    id: number;
    provider: 'xero' | 'myob';
    tenant_id: string | null;
    account_mapping: Record<string, string>;
    tax_mapping: Record<string, string>;
};

type PageProps = {
    integration: IntegrationData;
    localAccounts: LocalAccount[];
};

const providerLabels: Record<string, string> = {
    xero: 'Xero',
    myob: 'MYOB',
};

const typeColors: Record<string, string> = {
    asset: 'bg-status-info-bg text-status-info border-status-info/30',
    liability: 'bg-primary/10 text-primary border-primary/30',
    equity: 'bg-primary/10 text-primary border-primary/30',
    revenue:
        'bg-status-success-bg text-status-success border-status-success/30',
    expense:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
};

export default function AccountMapping({
    integration,
    localAccounts,
}: PageProps) {
    const providerName = providerLabels[integration.provider];
    const externalIdLabel =
        integration.provider === 'xero'
            ? 'Xero Account ID'
            : 'MYOB Account UID';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Integrations', href: '/finance/integrations' },
        {
            title: `${providerName} Mapping`,
            href: `/finance/integrations/${integration.id}/mapping`,
        },
    ];

    // Build initial mapping from existing data
    const initialMapping: Record<string, string> = {};
    localAccounts.forEach((account) => {
        const mapped =
            integration.account_mapping[String(account.id)] ??
            account.external_id ??
            '';
        initialMapping[String(account.id)] = mapped;
    });

    const { data, setData, put, processing, transform } = useForm({
        account_mapping: initialMapping,
        tax_mapping: integration.tax_mapping ?? {},
    });

    function handleMappingChange(accountId: number, externalId: string) {
        setData('account_mapping', {
            ...data.account_mapping,
            [String(accountId)]: externalId,
        });
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        // Filter out empty mappings
        const filteredMapping: Record<string, string> = {};
        Object.entries(data.account_mapping).forEach(([key, value]) => {
            if (value && value.trim()) {
                filteredMapping[key] = value.trim();
            }
        });

        transform(() => ({
            account_mapping: filteredMapping,
            tax_mapping: data.tax_mapping,
        }));

        put(`/finance/integrations/${integration.id}/mapping`, {
            onFinish: () => transform((currentData) => currentData),
        });
    }

    const mappedCount = Object.values(data.account_mapping).filter(
        (v) => v && v.trim(),
    ).length;

    // Group accounts by type
    const groupedAccounts = localAccounts.reduce<
        Record<string, LocalAccount[]>
    >((acc, account) => {
        const type = account.type;
        if (!acc[type]) acc[type] = [];
        acc[type].push(account);
        return acc;
    }, {});

    const typeOrder = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${providerName} Account Mapping`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/integrations"
                        title={`${providerName} Account Mapping`}
                        description={`Map your local chart of accounts to ${providerName} accounts for synchronisation`}
                        actions={
                            <>
                                <Badge variant="outline">
                                    {mappedCount} / {localAccounts.length}{' '}
                                    mapped
                                </Badge>
                                <Button
                                    onClick={handleSubmit}
                                    disabled={processing}
                                >
                                    <Save className="mr-2 h-4 w-4" />
                                    {processing ? 'Saving...' : 'Save Mapping'}
                                </Button>
                            </>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                            <div>
                                <CardTitle>Account Mapping</CardTitle>
                                <CardDescription>
                                    Enter the {externalIdLabel} for each local
                                    account. Leave blank to skip accounts you
                                    don't want to sync.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit}>
                            {typeOrder.map((type) => {
                                const accounts = groupedAccounts[type];
                                if (!accounts || accounts.length === 0)
                                    return null;

                                return (
                                    <div key={type} className="mb-6">
                                        <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                            <Badge
                                                variant="outline"
                                                className={typeColors[type]}
                                            >
                                                {type}
                                            </Badge>
                                            <span>
                                                ({accounts.length} accounts)
                                            </span>
                                        </h3>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="w-24">
                                                        Code
                                                    </TableHead>
                                                    <TableHead>
                                                        Account Name
                                                    </TableHead>
                                                    <TableHead className="w-32">
                                                        Sub Type
                                                    </TableHead>
                                                    <TableHead className="w-20 text-center">
                                                        <Link2 className="mx-auto h-4 w-4" />
                                                    </TableHead>
                                                    <TableHead className="w-72">
                                                        {externalIdLabel}
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {accounts.map((account) => (
                                                    <TableRow key={account.id}>
                                                        <TableCell className="font-mono text-sm">
                                                            {account.code}
                                                        </TableCell>
                                                        <TableCell className="font-medium">
                                                            {account.name}
                                                        </TableCell>
                                                        <TableCell className="text-sm text-muted-foreground">
                                                            {account.sub_type?.replace(
                                                                /_/g,
                                                                ' ',
                                                            ) || '-'}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            {data
                                                                .account_mapping[
                                                                String(
                                                                    account.id,
                                                                )
                                                            ] ? (
                                                                <ArrowLeftRight className="mx-auto h-4 w-4 text-status-success" />
                                                            ) : (
                                                                <span className="text-muted-foreground/30">
                                                                    -
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Input
                                                                value={
                                                                    data
                                                                        .account_mapping[
                                                                        String(
                                                                            account.id,
                                                                        )
                                                                    ] || ''
                                                                }
                                                                onChange={(e) =>
                                                                    handleMappingChange(
                                                                        account.id,
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder={`${providerName} ID`}
                                                                className="h-8 text-sm"
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                );
                            })}

                            <div className="flex justify-end border-t pt-4">
                                <Button type="submit" disabled={processing}>
                                    <Save className="mr-2 h-4 w-4" />
                                    {processing ? 'Saving...' : 'Save Mapping'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
