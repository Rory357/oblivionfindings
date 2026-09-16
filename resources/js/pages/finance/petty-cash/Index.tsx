import type { AccountOption } from '@/components/finance';
import {
    FinanceSectionRail,
    PettyCashFundDialog,
    formatMoney,
    type UserOption,
} from '@/components/finance';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    Coins,
    Download,
    Eye,
    Plus,
    User,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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
    canManage: boolean;
    accounts: AccountOption[];
    users: UserOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Petty cash', href: '/finance/petty-cash' },
];

export default function PettyCashIndex({
    funds,
    canManage = false,
    accounts = [],
    users = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [includeClosed, setIncludeClosed] = useState(true);
    const ctxMenu = useEntityContextMenu<Fund>();

    const activeCount = funds.filter((fund) => fund.is_active).length;
    const totalFloat = funds.reduce((sum, fund) => sum + fund.float_amount, 0);
    const totalBalance = funds.reduce(
        (sum, fund) => sum + fund.current_balance,
        0,
    );
    const shortFunds = funds.filter(
        (fund) => fund.current_balance - fund.float_amount < 0,
    ).length;

    const actionsFor = (fund: Fund): MenuItem[] =>
        compactMenu([
            {
                label: 'Open fund',
                icon: Eye,
                onClick: () => router.visit(`/finance/petty-cash/${fund.id}`),
            },
            canManage
                ? {
                      label: 'Record a transaction',
                      icon: Coins,
                      onClick: () =>
                          router.visit(
                              `/finance/petty-cash/${fund.id}?record=1`,
                          ),
                  }
                : null,
        ]);

    const term = search.trim().toLowerCase();
    const visible = useMemo(
        () =>
            funds.filter((fund) => {
                if (!includeClosed && !fund.is_active) return false;
                if (!term) return true;
                return [fund.name, fund.custodian_name ?? '']
                    .join(' ')
                    .toLowerCase()
                    .includes(term);
            }),
        [funds, includeClosed, term],
    );

    const clearFilters = () => {
        setSearch('');
        setIncludeClosed(true);
    };

    const header = (
        <PageHeader
            variant="index"
            icon={Coins}
            title="Petty cash"
            titleChip={
                <PageHeaderStatusChip
                    variant={shortFunds > 0 ? 'warning' : 'success'}
                >
                    {shortFunds > 0
                        ? `${shortFunds} short of float`
                        : 'All floats intact'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${funds.length} funds · ${activeCount} active`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search funds, custodians…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href =
                                '/finance/petty-cash/export';
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New fund
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Cash on hand"
                        tone={totalBalance >= 0 ? 'success' : 'critical'}
                        href="/finance/cash-position"
                        ariaLabel="View the cash position"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalBalance)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {funds.length} funds
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Total float"
                        href="/finance/petty-cash"
                        ariaLabel="View every petty cash fund"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(totalFloat)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Authorised across every fund
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active funds"
                        onClick={() => setIncludeClosed(false)}
                        ariaLabel="Show only active funds"
                    >
                        <PageHeaderMeterBig>{activeCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {funds.length - activeCount} closed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Short of float"
                        tone={shortFunds > 0 ? 'warning' : 'brand'}
                        href="/finance/petty-cash"
                        ariaLabel="View petty cash funds"
                    >
                        <PageHeaderMeterBig>{shortFunds}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Funds spending below their float
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterCheck
                    label="Include closed"
                    checked={includeClosed}
                    onChange={setIncludeClosed}
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Petty cash" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {funds.length === 0 ? (
                        <EmptyList
                            icon={Wallet}
                            itemName="petty cash fund"
                            title="No petty cash funds yet"
                            description="Create a fund to track a float, its custodian and every note that leaves the tin."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New fund
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <>
                            <ListCaption
                                title="Petty cash funds"
                                caption={`${visible.length} of ${funds.length} shown`}
                            />

                            {visible.length === 0 ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No funds match your filters"
                                />
                            ) : (
                                <EntityCardGrid>
                                    {visible.map((fund) => {
                                        const variance =
                                            fund.current_balance -
                                            fund.float_amount;

                                        return (
                                            <EntityCard
                                                key={fund.id}
                                                meridian={
                                                    !fund.is_active
                                                        ? 'warning'
                                                        : variance < 0
                                                          ? 'warning'
                                                          : 'success'
                                                }
                                                icon={Wallet}
                                                name={fund.name}
                                                subline={
                                                    fund.custodian_name ??
                                                    'No custodian assigned'
                                                }
                                                sublineIcon={User}
                                                href={`/finance/petty-cash/${fund.id}`}
                                                onOpen={() =>
                                                    router.visit(
                                                        `/finance/petty-cash/${fund.id}`,
                                                    )
                                                }
                                                onContextMenu={(event) =>
                                                    ctxMenu.open(event, fund)
                                                }
                                                actions={actionsFor(fund)}
                                                muted={!fund.is_active}
                                                chips={
                                                    <>
                                                        <EntityStatusChip
                                                            variant={
                                                                fund.is_active
                                                                    ? 'success'
                                                                    : 'neutral'
                                                            }
                                                        >
                                                            {fund.is_active
                                                                ? 'Active'
                                                                : 'Closed'}
                                                        </EntityStatusChip>
                                                        <EntityChip>
                                                            Float{' '}
                                                            {formatMoney(
                                                                fund.float_amount,
                                                            )}
                                                        </EntityChip>
                                                    </>
                                                }
                                                metric={{
                                                    label: 'Current balance',
                                                    value: formatMoney(
                                                        fund.current_balance,
                                                    ),
                                                    percent:
                                                        fund.float_amount > 0
                                                            ? Math.min(
                                                                  100,
                                                                  Math.max(
                                                                      0,
                                                                      Math.round(
                                                                          (fund.current_balance /
                                                                              fund.float_amount) *
                                                                              100,
                                                                      ),
                                                                  ),
                                                              )
                                                            : null,
                                                    tone:
                                                        variance < 0
                                                            ? 'warning'
                                                            : 'success',
                                                }}
                                                alerts={
                                                    variance !== 0 ? (
                                                        <EntityStatusChip
                                                            variant={
                                                                variance < 0
                                                                    ? 'warning'
                                                                    : 'info'
                                                            }
                                                            icon={AlertCircle}
                                                        >
                                                            {formatMoney(
                                                                variance,
                                                            )}{' '}
                                                            against float
                                                        </EntityStatusChip>
                                                    ) : undefined
                                                }
                                                footer={{
                                                    personIcon: Coins,
                                                    primary:
                                                        fund.gl_account_name ??
                                                        'No GL account linked',
                                                    secondary:
                                                        'Petty cash asset account',
                                                }}
                                                openLabel="Open"
                                            />
                                        );
                                    })}
                                </EntityCardGrid>
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Wallet}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage && (
                <PettyCashFundDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    accounts={accounts}
                    users={users}
                />
            )}
        </AppLayout>
    );
}
