import { FinanceSectionRail } from '@/components/finance';
import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
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
import { ArrowLeftRight, Link2, Save, SearchX } from 'lucide-react';
import { FormEvent, useState } from 'react';

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

/** Account types in chart-of-accounts order, with their display labels. */
const TYPE_ORDER = [
    'asset',
    'liability',
    'equity',
    'revenue',
    'expense',
] as const;

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const typeLabel = (type: string) => typeLabels[type] ?? type;

export default function AccountMapping({
    integration,
    localAccounts,
}: PageProps) {
    const providerName = providerLabels[integration.provider];
    const externalIdLabel =
        integration.provider === 'xero'
            ? 'Xero account ID'
            : 'MYOB account UID';

    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('all');
    const [unmappedOnly, setUnmappedOnly] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Settings', href: '/finance/settings' },
        { title: 'Integrations', href: '/finance/integrations' },
        {
            title: providerName,
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

    const { data, setData, put, processing, transform, isDirty } = useForm({
        account_mapping: initialMapping,
        tax_mapping: integration.tax_mapping ?? {},
    });

    function handleMappingChange(accountId: number, externalId: string) {
        setData('account_mapping', {
            ...data.account_mapping,
            [String(accountId)]: externalId,
        });
    }

    const mappedFor = (account: LocalAccount) =>
        data.account_mapping[String(account.id)] ?? '';

    function handleSubmit(e?: FormEvent) {
        e?.preventDefault();
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
    const mappedPct =
        localAccounts.length === 0
            ? 0
            : (mappedCount / localAccounts.length) * 100;

    const query = search.trim().toLowerCase();
    const shown = localAccounts.filter((account) => {
        const matchesText =
            query === '' ||
            account.code.toLowerCase().includes(query) ||
            account.name.toLowerCase().includes(query) ||
            (account.sub_type ?? '').toLowerCase().includes(query);
        const matchesType = typeFilter === 'all' || account.type === typeFilter;
        const matchesMapped = !unmappedOnly || !mappedFor(account).trim();
        return matchesText && matchesType && matchesMapped;
    });

    // Group the visible accounts by type, keeping chart-of-accounts order.
    const groupedAccounts = shown.reduce<Record<string, LocalAccount[]>>(
        (acc, account) => {
            (acc[account.type] ??= []).push(account);
            return acc;
        },
        {},
    );
    const groupOrder = [
        ...TYPE_ORDER.filter((t) => groupedAccounts[t]?.length),
        ...Object.keys(groupedAccounts)
            .filter(
                (t) => !TYPE_ORDER.includes(t as (typeof TYPE_ORDER)[number]),
            )
            .sort(),
    ];

    const typeOptions = [
        { value: 'all', label: 'All account types' },
        ...TYPE_ORDER.filter((t) =>
            localAccounts.some((a) => a.type === t),
        ).map((t) => ({ value: t, label: typeLabels[t] })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={ArrowLeftRight}
            backHref="/finance/integrations"
            title={`${providerName} account mapping`}
            titleChip={
                isDirty ? (
                    <PageHeaderStatusChip variant="warning">
                        Unsaved changes
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip
                        variant={
                            mappedCount === localAccounts.length &&
                            localAccounts.length > 0
                                ? 'success'
                                : 'info'
                        }
                    >
                        {mappedCount} of {localAccounts.length} mapped
                    </PageHeaderStatusChip>
                )
            }
            subline={`Settings · Integrations · ${integration.tenant_id ?? 'no tenant configured'} · leave an account blank to keep it out of the sync`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search code, name or sub type…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Save}
                        onClick={() => handleSubmit()}
                        disabled={processing}
                    >
                        {processing ? 'Saving…' : 'Save mapping'}
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Mapped"
                        value={`${Math.round(mappedPct)}%`}
                        tone="success"
                        ariaLabel="Show every account"
                        onClick={() => {
                            setUnmappedOnly(false);
                            setTypeFilter('all');
                            setSearch('');
                        }}
                    >
                        <PageHeaderMeterBar percent={mappedPct} />
                        <PageHeaderMeterCaption>
                            {mappedCount} of {localAccounts.length} accounts
                            carry a {providerName} ID
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Unmapped"
                        tone="warning"
                        ariaLabel="Show only unmapped accounts"
                        onClick={() => setUnmappedOnly(true)}
                    >
                        <PageHeaderMeterBig>
                            {localAccounts.length - mappedCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            skipped when this connection syncs
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active accounts"
                        href="/finance/accounts"
                        ariaLabel="View the chart of accounts"
                    >
                        <PageHeaderMeterBig>
                            {localAccounts.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            in the chart of accounts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Account type"
                        value={typeFilter}
                        allValue="all"
                        options={typeOptions}
                        onChange={setTypeFilter}
                    />
                    <PageHeaderFilterCheck
                        label="Unmapped only"
                        checked={unmappedOnly}
                        onChange={setUnmappedOnly}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${providerName} account mapping`} />

            <PageLayout hero={header}>
                {shown.length === 0 ? (
                    <EmptyState
                        icon={localAccounts.length === 0 ? Link2 : SearchX}
                        heading={
                            localAccounts.length === 0
                                ? 'No active accounts to map'
                                : 'No accounts match these filters'
                        }
                        description={
                            localAccounts.length === 0
                                ? 'Add accounts to the chart of accounts before mapping them to ' +
                                  providerName +
                                  '.'
                                : 'Clear the search, account type or unmapped-only filter to see every account.'
                        }
                        action={
                            localAccounts.length === 0 ? undefined : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setSearch('');
                                        setTypeFilter('all');
                                        setUnmappedOnly(false);
                                    }}
                                >
                                    Clear filters
                                </Button>
                            )
                        }
                    />
                ) : (
                    /* The mapping workbench is an editable grid — one text
                     * input per account — so it stays a plain table rather
                     * than an EntityTable, which is a browse surface. */
                    <form
                        onSubmit={handleSubmit}
                        className="flex flex-col gap-5"
                    >
                        {groupOrder.map((type) => {
                            const accounts = groupedAccounts[type];
                            const groupMapped = accounts.filter((a) =>
                                mappedFor(a).trim(),
                            ).length;

                            return (
                                <section
                                    key={type}
                                    className="flex flex-col gap-5"
                                >
                                    <ListCaption
                                        title={typeLabel(type)}
                                        caption={`${groupMapped} of ${accounts.length} mapped`}
                                    />
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-24">
                                                    Code
                                                </TableHead>
                                                <TableHead>
                                                    Account name
                                                </TableHead>
                                                <TableHead className="w-32">
                                                    Sub type
                                                </TableHead>
                                                <TableHead className="w-20 text-center">
                                                    <Link2
                                                        aria-label="Mapped"
                                                        className="mx-auto h-4 w-4"
                                                    />
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
                                                        ) || '—'}
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        {mappedFor(
                                                            account,
                                                        ).trim() ? (
                                                            <ArrowLeftRight
                                                                aria-label={`${account.name} is mapped`}
                                                                className="mx-auto h-4 w-4 text-status-success"
                                                            />
                                                        ) : (
                                                            <span className="text-muted-foreground/40">
                                                                —
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            aria-label={`${externalIdLabel} for ${account.code} ${account.name}`}
                                                            value={mappedFor(
                                                                account,
                                                            )}
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
                                </section>
                            );
                        })}
                        {/* Keep Enter-to-save working without a second visible
                         * Save button — the header primary is the only one. */}
                        <button type="submit" className="sr-only">
                            Save mapping
                        </button>
                    </form>
                )}
            </PageLayout>
        </AppLayout>
    );
}
