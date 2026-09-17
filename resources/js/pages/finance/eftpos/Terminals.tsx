import {
    ConfirmDialog,
    FinanceSectionRail,
    FinanceTierTwoNav,
} from '@/components/finance';
import {
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
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
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CreditCard,
    Landmark,
    Layers,
    Pencil,
    Plus,
    Power,
    Smartphone,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    EFTPOS_PROVIDERS,
    TerminalDialog,
    eftposProviderLabel,
    type EditableTerminal,
} from './_dialogs';

interface Terminal {
    id: number;
    terminal_id: string;
    name: string;
    location: string | null;
    provider: string;
    has_merchant_id: boolean;
    bank_account_id: number | null;
    gl_account_id: number | null;
    bank_account_name: string | null;
    gl_account_name: string | null;
    is_active: boolean;
    batch_count: number;
}

interface BankAccount {
    id: number;
    name: string;
}

interface GlAccount {
    id: number;
    code: string;
    name: string;
}

interface Props extends PageProps {
    terminals: Terminal[];
    bankAccounts: BankAccount[];
    glAccounts: GlAccount[];
}

const ALL = '__all';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'EFTPOS', href: '/finance/eftpos/terminals' },
    { title: 'Terminals', href: '/finance/eftpos/terminals' },
];

export default function EftposTerminals({
    terminals,
    bankAccounts,
    glAccounts,
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editTerminal, setEditTerminal] = useState<EditableTerminal | null>(
        null,
    );
    const [serviceTarget, setServiceTarget] = useState<Terminal | null>(null);
    const [updatingService, setUpdatingService] = useState(false);
    const [search, setSearch] = useState('');
    const [provider, setProvider] = useState(ALL);
    const [includeRetired, setIncludeRetired] = useState(true);
    const ctxMenu = useEntityContextMenu<Terminal>();

    const activeCount = terminals.filter(
        (terminal) => terminal.is_active,
    ).length;
    const totalBatches = terminals.reduce(
        (sum, terminal) => sum + terminal.batch_count,
        0,
    );

    const toEditable = (terminal: Terminal): EditableTerminal => ({
        id: terminal.id,
        terminal_id: terminal.terminal_id,
        name: terminal.name,
        location: terminal.location,
        provider: terminal.provider,
        has_merchant_id: terminal.has_merchant_id,
        bank_account_id: terminal.bank_account_id,
        gl_account_id: terminal.gl_account_id,
        is_active: terminal.is_active,
    });

    // The service toggle reuses the same update route the dialog posts to; the
    // merchant ID is deliberately absent so the stored value is left alone.
    const confirmServiceChange = () => {
        if (!serviceTarget) return;
        router.put(
            `/finance/eftpos/terminals/${serviceTarget.id}`,
            {
                name: serviceTarget.name,
                location: serviceTarget.location,
                provider: serviceTarget.provider,
                bank_account_id: serviceTarget.bank_account_id,
                gl_account_id: serviceTarget.gl_account_id,
                is_active: !serviceTarget.is_active,
            },
            {
                preserveScroll: true,
                onStart: () => setUpdatingService(true),
                onFinish: () => setUpdatingService(false),
                onSuccess: () => setServiceTarget(null),
            },
        );
    };

    const actionsFor = (terminal: Terminal): MenuItem[] =>
        compactMenu([
            {
                label: 'Edit terminal',
                icon: Pencil,
                onClick: () => setEditTerminal(toEditable(terminal)),
            },
            {
                label: 'View batches',
                icon: Layers,
                onClick: () =>
                    router.visit(
                        `/finance/eftpos/batches?terminal_id=${terminal.id}`,
                    ),
            },
            terminal.bank_account_id
                ? {
                      label: 'Open settlement account',
                      icon: Landmark,
                      onClick: () =>
                          router.visit(
                              `/finance/bank-accounts/${terminal.bank_account_id}`,
                          ),
                  }
                : null,
            { separator: true },
            {
                label: terminal.is_active
                    ? 'Take out of service'
                    : 'Put back in service',
                icon: Power,
                danger: terminal.is_active,
                onClick: () => setServiceTarget(terminal),
            },
        ]);

    const term = search.trim().toLowerCase();
    const visible = useMemo(
        () =>
            terminals.filter((terminal) => {
                if (!includeRetired && !terminal.is_active) return false;
                if (provider !== ALL && terminal.provider !== provider)
                    return false;
                if (!term) return true;
                return [
                    terminal.name,
                    terminal.terminal_id,
                    terminal.location ?? '',
                    eftposProviderLabel(terminal.provider),
                ]
                    .join(' ')
                    .toLowerCase()
                    .includes(term);
            }),
        [terminals, includeRetired, provider, term],
    );

    const clearFilters = () => {
        setSearch('');
        setProvider(ALL);
        setIncludeRetired(true);
    };

    const columns: EntityTableColumn<Terminal>[] = [
        {
            key: 'provider',
            label: 'Provider',
            width: '0.8fr',
            cell: (terminal) => eftposProviderLabel(terminal.provider),
        },
        {
            key: 'settlement',
            label: 'Settlement account',
            width: '1.1fr',
            cell: (terminal) =>
                terminal.bank_account_name ?? (
                    <span className="text-muted-foreground">Not linked</span>
                ),
        },
        {
            key: 'gl',
            label: 'GL clearing account',
            width: '1.2fr',
            cell: (terminal) =>
                terminal.gl_account_name ?? (
                    <span className="text-muted-foreground">Not linked</span>
                ),
        },
        {
            key: 'batches',
            label: 'Batches',
            width: '0.6fr',
            align: 'right',
            cell: (terminal) => (
                <span className="tabular-nums">{terminal.batch_count}</span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (terminal) => (
                <StatusBadge status={terminal.is_active ? 'active' : 'inactive'} />
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Smartphone}
            title="EFTPOS terminals"
            titleChip={
                <PageHeaderStatusChip
                    variant={activeCount > 0 ? 'success' : 'warning'}
                >
                    {activeCount} in service
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${terminals.length} terminals · ${totalBatches} batches taken`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search terminals, locations…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setCreateOpen(true)}
                    >
                        Add terminal
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Terminals"
                        href="/finance/eftpos/terminals"
                        ariaLabel="View every terminal"
                    >
                        <PageHeaderMeterBig>
                            {terminals.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Registered devices
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="In service"
                        tone="success"
                        onClick={() => setIncludeRetired(false)}
                        ariaLabel="Show only terminals in service"
                    >
                        <PageHeaderMeterBig>{activeCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {terminals.length - activeCount} out of service
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Batches"
                        href="/finance/eftpos/batches"
                        ariaLabel="View EFTPOS batches"
                    >
                        <PageHeaderMeterBig>{totalBatches}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Taken across every terminal
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unlinked"
                        tone={
                            terminals.some(
                                (terminal) => !terminal.bank_account_id,
                            )
                                ? 'warning'
                                : 'brand'
                        }
                        href="/finance/bank-accounts"
                        ariaLabel="View bank accounts"
                    >
                        <PageHeaderMeterBig>
                            {
                                terminals.filter(
                                    (terminal) => !terminal.bank_account_id,
                                ).length
                            }
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Terminals with no settlement account
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Provider"
                        value={provider}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any provider' },
                            ...EFTPOS_PROVIDERS,
                        ]}
                        onChange={setProvider}
                    />
                    <PageHeaderFilterCheck
                        label="Include out of service"
                        checked={includeRetired}
                        onChange={setIncludeRetired}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="EFTPOS terminals" />

            <PageLayout hero={header} tabs={<FinanceTierTwoNav />}>
                <div className="flex flex-col gap-5">
                    {terminals.length === 0 ? (
                        <EmptyList
                            icon={CreditCard}
                            itemName="EFTPOS terminal"
                            title="No EFTPOS terminals yet"
                            description="Add a terminal to start tracking the card takings it batches and settles."
                            action={
                                <Button
                                    size="sm"
                                    onClick={() => setCreateOpen(true)}
                                >
                                    Add terminal
                                </Button>
                            }
                        />
                    ) : (
                        <>
                            <ListCaption
                                title="Terminals"
                                caption={`${visible.length} of ${terminals.length} shown`}
                            />
                            {visible.length === 0 ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No terminals match your filters"
                                />
                            ) : (
                                <EntityTable
                                    rows={visible}
                                    rowKey={(terminal) => terminal.id}
                                    identityLabel="Terminal"
                                    identity={(terminal) => ({
                                        icon: CreditCard,
                                        name: terminal.name,
                                        subline: [
                                            terminal.terminal_id,
                                            terminal.location,
                                        ]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })}
                                    columns={columns}
                                    actionsFor={actionsFor}
                                    onRowContextMenu={ctxMenu.open}
                                    mutedFor={(terminal) => !terminal.is_active}
                                    minWidth={1080}
                                />
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={CreditCard}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <TerminalDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                bankAccounts={bankAccounts}
                glAccounts={glAccounts}
            />

            {editTerminal ? (
                <TerminalDialog
                    key={editTerminal.id}
                    open
                    terminal={editTerminal}
                    onClose={() => setEditTerminal(null)}
                    bankAccounts={bankAccounts}
                    glAccounts={glAccounts}
                />
            ) : null}

            <ConfirmDialog
                open={!!serviceTarget}
                onClose={() => setServiceTarget(null)}
                title={
                    serviceTarget?.is_active
                        ? 'Take this terminal out of service?'
                        : 'Put this terminal back in service?'
                }
                description={
                    serviceTarget?.is_active ? (
                        <>
                            <span className="font-medium text-foreground">
                                {serviceTarget?.name}
                            </span>{' '}
                            stops accepting new batches and drops out of the
                            terminal filter. Its existing batches stay on
                            record.
                        </>
                    ) : (
                        <>
                            <span className="font-medium text-foreground">
                                {serviceTarget?.name}
                            </span>{' '}
                            starts accepting batches again.
                        </>
                    )
                }
                confirmText={
                    serviceTarget?.is_active
                        ? 'Take out of service'
                        : 'Put in service'
                }
                variant={serviceTarget?.is_active ? 'destructive' : 'default'}
                processing={updatingService}
                onConfirm={confirmServiceChange}
            />
        </AppLayout>
    );
}
