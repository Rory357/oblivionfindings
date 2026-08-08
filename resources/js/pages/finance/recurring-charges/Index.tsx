import {
    formatMoney,
    ReceivablesTabsFooter,
    RecurringChargeDialog,
    type ChargeClientOption,
    type EditableRecurringCharge,
} from '@/components/finance';
import { OpsStatCard } from '@/components/ops-stat-card';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    DollarSign,
    Pencil,
    Plus,
    RefreshCw,
    Search,
} from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

type RecurringCharge = {
    id: number;
    name: string;
    client_id: number | null;
    description: string;
    amount: number;
    frequency: string;
    is_active: boolean;
    next_charge_date: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
};

type Props = {
    charges: {
        data: RecurringCharge[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        active: number;
        monthly_total: number;
        next_due: number;
    };
    canManage: boolean;
    clients: ChargeClientOption[];
};

const FREQUENCY_LABELS: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annually: 'Annually',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function RecurringChargesIndex({
    charges = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {} as any,
    stats = {} as any,
    canManage = false,
    clients = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editCharge, setEditCharge] =
        useState<EditableRecurringCharge | null>(null);

    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/finance/recurring-charges',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    const openEdit = (charge: RecurringCharge) =>
        setEditCharge({
            id: charge.id,
            client_id: charge.client_id ?? charge.client?.id ?? null,
            description: charge.description || charge.name,
            amount: charge.amount,
            frequency: charge.frequency,
            next_charge_date: charge.next_charge_date,
            is_active: charge.is_active,
        });

    return (
        <AppLayout>
            <Head title="Recurring Charges" />
            <PageHero
                category="finance"
                icon={RefreshCw}
                title="Recurring Charges"
                description="Manage recurring billing charges for clients."
                stats={[
                    { label: 'Active', value: stats?.active ?? 0 },
                    {
                        label: 'Monthly total',
                        value: formatMoney(stats?.monthly_total ?? 0),
                    },
                    { label: 'Next due', value: stats?.next_due ?? 0 },
                ]}
                footer={<ReceivablesTabsFooter active="recurring-charges" />}
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard
                        label="Active Charges"
                        value={stats?.active ?? 0}
                        icon={RefreshCw}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Monthly Total"
                        value={formatMoney(stats?.monthly_total ?? 0)}
                        icon={DollarSign}
                        color="emerald"
                    />
                    <OpsStatCard
                        label="Next Charges Due"
                        value={stats?.next_due ?? 0}
                        icon={CalendarDays}
                        color="amber"
                    />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search recurring charges..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) =>
                                updateFilters('q', e.target.value || null)
                            }
                        />
                    </div>
                    <Select
                        value={filters?.status ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('status', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-[130px] text-xs"
                            aria-label="Filter by status"
                        >
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    {canManage && (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Charge
                        </Button>
                    )}
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(charges?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <RefreshCw className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Recurring Charges
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    Create your first recurring charge to get
                                    started.
                                </p>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        className="mt-4"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        Create Charge
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    {(charges?.data ?? []).map((charge) => (
                        <Card
                            key={charge.id}
                            className="transition-all hover:border-border hover:shadow-sm"
                        >
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <RefreshCw className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            {charge.name}
                                        </span>
                                        <Badge
                                            variant={
                                                charge.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                            className="h-4 px-1.5 text-[9px]"
                                        >
                                            {charge.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            className="h-4 px-1.5 text-[9px]"
                                        >
                                            {FREQUENCY_LABELS[
                                                charge.frequency
                                            ] ?? charge.frequency}
                                        </Badge>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {charge.client && (
                                            <span>
                                                {charge.client.first_name}{' '}
                                                {charge.client.last_name}
                                            </span>
                                        )}
                                        <span className="font-semibold text-status-success tabular-nums dark:text-status-success">
                                            {formatMoney(charge.amount)}
                                        </span>
                                        {charge.next_charge_date && (
                                            <span className="flex items-center gap-1">
                                                <CalendarDays className="h-3 w-3" />
                                                Next:{' '}
                                                {formatDate(
                                                    charge.next_charge_date,
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                {canManage && (
                                    <div className="flex shrink-0 gap-1">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 w-7 p-0"
                                            aria-label={`Edit ${charge.name}`}
                                            onClick={() => openEdit(charge)}
                                        >
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(charges?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(charges?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>

            {canManage && (
                <RecurringChargeDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    clients={clients}
                />
            )}

            {canManage && editCharge && (
                <RecurringChargeDialog
                    key={editCharge.id}
                    open
                    charge={editCharge}
                    onClose={() => setEditCharge(null)}
                    clients={clients}
                />
            )}
        </AppLayout>
    );
}
