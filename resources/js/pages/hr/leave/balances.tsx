import {
    LeaveAdjustDialog,
    LeaveAvatar,
    LeaveHubHero,
    LeaveHubTabs,
    useLeaveContextMenu,
    type HubHero,
    type LeaveCtxItem,
} from '@/components/hr';
import { PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    Download,
    Eye,
    Pencil,
    Plus,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { useRef, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type TypeBalance = { remaining: number; entitlement: number };

type BalanceRow = {
    user_id: number;
    name: string;
    email: string | null;
    annual: TypeBalance;
    sick: TypeBalance;
    alternative: TypeBalance;
    pending: number;
    low: boolean;
};

type LedgerEntry = {
    id: number;
    leave_type: string;
    entry_type: string;
    hours_delta: number;
    balance_after: number;
    used_after: number;
    pending_after: number;
    notes: string | null;
    created_by: string | null;
    created_at: string | null;
};

type Props = {
    balances: BalanceRow[];
    year: number;
    hero: HubHero;
    leaveTypes: string[];
    filters: { year: string | number | null; q: string | null };
    can: { manage: boolean; approve?: boolean; create?: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave Balances', href: '/hr/leave/balances' },
];

const formatHours = (hours: number) => {
    if (hours === 0) return '0h';
    const h = Math.floor(hours);
    const m = Math.round((hours - h) * 60);
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
};

function BalanceCell({
    value,
    of,
    low,
}: {
    value: number;
    of: number;
    low?: boolean;
}) {
    return (
        <div>
            <div className={cn('font-bold', low && 'text-status-critical')}>
                {formatHours(value)}
            </div>
            {of > 0 ? (
                <div className="text-[10.5px] text-muted-foreground">
                    of {formatHours(of)}
                </div>
            ) : null}
        </div>
    );
}

export default function LeaveBalances({
    balances,
    year,
    hero,
    leaveTypes,
    filters,
    can,
}: Props) {
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - 2 + i);
    const people = balances.map((b) => ({ id: b.user_id, name: b.name }));
    const { open: openCtx, element: ctxElement } = useLeaveContextMenu();

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/leave/balances',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    // --- Adjust / opening-balance wizard ---
    type AdjustPreset = {
        user_id?: string;
        leave_type?: string;
        mode?: 'credit' | 'debit' | 'set_opening';
    };
    const [adjustOpen, setAdjustOpen] = useState(false);
    const [adjustPreset, setAdjustPreset] = useState<AdjustPreset | undefined>(
        undefined,
    );

    const openAdjust = (preset?: AdjustPreset) => {
        setAdjustPreset(preset);
        setAdjustOpen(true);
    };

    /** Current remaining hours for a staff member + type (Annual/Sick/Alt only). */
    const currentBalanceFor = (userId: number, type: string): number | null => {
        const row = balances.find((b) => b.user_id === userId);
        if (!row) return null;
        if (type === 'annual') return row.annual.remaining;
        if (type === 'sick') return row.sick.remaining;
        if (type === 'alternative') return row.alternative.remaining;
        return null;
    };

    // --- Ledger drawer ---
    const [ledgerOpen, setLedgerOpen] = useState(false);
    const [ledgerLoading, setLedgerLoading] = useState(false);
    const [ledgerName, setLedgerName] = useState('');
    const [ledger, setLedger] = useState<LedgerEntry[]>([]);
    // Generation token: only the latest open's response is allowed to win, so
    // a slow earlier fetch can't overwrite a quickly-opened later one.
    const ledgerReqRef = useRef(0);

    const openLedger = (row: BalanceRow) => {
        const reqId = ++ledgerReqRef.current;
        setLedgerName(row.name);
        setLedgerOpen(true);
        setLedgerLoading(true);
        setLedger([]);
        fetch(`/hr/leave/balances/${row.user_id}/ledger?year=${year}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : { entries: [] }))
            .then((d) => {
                if (ledgerReqRef.current === reqId) setLedger(d.entries ?? []);
            })
            .catch(() => {
                if (ledgerReqRef.current === reqId) setLedger([]);
            })
            .finally(() => {
                if (ledgerReqRef.current === reqId) setLedgerLoading(false);
            });
    };

    // Right-click a staff row → adjust / set-opening / view-ledger / request-leave.
    const rowMenu = (row: BalanceRow): LeaveCtxItem[] => {
        const items: LeaveCtxItem[] = [];
        if (can.manage) {
            items.push({
                kind: 'item',
                label: 'Adjust balance…',
                icon: Pencil,
                onSelect: () =>
                    openAdjust({
                        user_id: String(row.user_id),
                        mode: 'credit',
                    }),
            });
            items.push({
                kind: 'item',
                label: 'Set opening balance…',
                icon: SlidersHorizontal,
                onSelect: () =>
                    openAdjust({
                        user_id: String(row.user_id),
                        mode: 'set_opening',
                    }),
            });
        }
        items.push({
            kind: 'item',
            label: 'View ledger',
            icon: Eye,
            onSelect: () => openLedger(row),
        });
        items.push({ kind: 'divider' });
        items.push({
            kind: 'item',
            label: 'Request leave for…',
            icon: Plus,
            onSelect: () => router.visit('/hr/leave?new=1'),
        });
        return items;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Balances" />

            <PageLayout hero={<LeaveHubHero hero={hero} can={can} />}>
                <LeaveHubTabs active="balances" />

                {/* toolbar */}
                <div className="flex flex-wrap items-center gap-2.5">
                    <span className="text-[13px] font-bold">
                        Entitlements &amp; balances
                    </span>
                    <span className="text-[11.5px] text-muted-foreground">
                        Hours-based · accrues nightly
                    </span>
                    <div className="relative ml-2 flex items-center">
                        <Search className="pointer-events-none absolute left-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search staff…"
                            defaultValue={filters.q || ''}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter')
                                    onFilter({
                                        q: (e.target as HTMLInputElement).value,
                                    });
                            }}
                            className="h-9 w-[210px] pl-8"
                        />
                    </div>
                    <Select
                        value={String(filters.year || year)}
                        onValueChange={(v) => onFilter({ year: v })}
                    >
                        <SelectTrigger className="h-9 w-24">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {years.map((y) => (
                                <SelectItem key={y} value={String(y)}>
                                    {y}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-auto flex items-center gap-2">
                        {can.manage && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openAdjust()}
                            >
                                <Pencil className="mr-1.5 h-4 w-4" /> Adjust
                            </Button>
                        )}
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={`/hr/leave/balances/export?format=csv&year=${year}`}
                            >
                                <Download className="mr-1.5 h-4 w-4" /> Export
                            </a>
                        </Button>
                    </div>
                </div>

                {/* grid */}
                <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                    <div className="grid grid-cols-[1.6fr_1.1fr_1.1fr_1fr_1fr] gap-2 border-b border-border bg-muted px-4 py-2.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        <span>Staff</span>
                        <span>Annual</span>
                        <span>Sick</span>
                        <span>Alt / lieu</span>
                        <span>Pending</span>
                    </div>
                    {balances.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-14 text-center text-sm text-muted-foreground">
                            <CalendarDays className="h-10 w-10 opacity-40" />
                            No leave balances found.
                        </div>
                    ) : (
                        balances.map((row) => (
                            // eslint-disable-next-line no-restricted-syntax -- dense clickable balance row opens the ledger drawer, not a form Button
                            <div
                                key={row.user_id}
                                role="button"
                                tabIndex={0}
                                onClick={() => openLedger(row)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        openLedger(row);
                                    }
                                }}
                                onContextMenu={openCtx(rowMenu(row))}
                                className="grid cursor-pointer grid-cols-[1.6fr_1.1fr_1.1fr_1fr_1fr] items-center gap-2 border-b border-border px-4 py-2.5 text-[13px] last:border-b-0 hover:bg-muted"
                            >
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <LeaveAvatar name={row.name} size={32} />
                                    <div className="min-w-0">
                                        <div className="truncate font-bold">
                                            {row.name}
                                        </div>
                                        {row.email ? (
                                            <div className="truncate text-[11px] text-muted-foreground">
                                                {row.email}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                <BalanceCell
                                    value={row.annual.remaining}
                                    of={row.annual.entitlement}
                                    low={row.low}
                                />
                                <BalanceCell
                                    value={row.sick.remaining}
                                    of={row.sick.entitlement}
                                />
                                <div className="font-bold">
                                    {formatHours(row.alternative.remaining)}
                                </div>
                                <div className="flex items-center gap-1.5">
                                    {row.pending > 0 ? (
                                        <span className="inline-flex items-center rounded-full border border-status-warning/30 bg-status-warning-bg px-2 py-0.5 text-[11px] font-bold text-status-warning">
                                            {formatHours(row.pending)}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                    {row.low ? (
                                        <span className="text-[11px] font-bold text-status-critical">
                                            ⚠ low
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                        ))
                    )}
                </div>
                <p className="text-[11.5px] text-muted-foreground">
                    Click a row to open the immutable ledger
                    {can.manage
                        ? ' · right-click for adjust & opening balance'
                        : ''}{' '}
                    — every reserve, accrual, taken &amp; adjustment.
                </p>
            </PageLayout>

            {ctxElement}

            {/* Adjust / opening-balance wizard (Add-Client modal chrome) */}
            {can.manage && (
                <LeaveAdjustDialog
                    open={adjustOpen}
                    onClose={() => setAdjustOpen(false)}
                    people={people}
                    leaveTypes={leaveTypes}
                    year={year}
                    currentBalanceFor={currentBalanceFor}
                    preset={adjustPreset}
                />
            )}

            {/* Ledger drawer */}
            <Sheet open={ledgerOpen} onOpenChange={setLedgerOpen}>
                <SheetContent
                    side="right"
                    className="w-[420px] max-w-[92vw] gap-0 bg-card p-0 sm:max-w-[420px]"
                >
                    <SheetHeader className="gap-0 border-b px-5 py-4">
                        <SheetTitle className="text-base font-bold">
                            {ledgerName}
                        </SheetTitle>
                        <div className="text-xs text-muted-foreground">
                            Leave ledger · {year}
                        </div>
                    </SheetHeader>
                    <div className="flex-1 overflow-y-auto px-5 py-3">
                        {ledgerLoading ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                Loading…
                            </p>
                        ) : ledger.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No ledger entries yet.
                            </p>
                        ) : (
                            ledger.map((e) => (
                                <div
                                    key={e.id}
                                    className="flex items-center gap-3 border-b py-2.5 last:border-b-0"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-semibold capitalize">
                                            {e.entry_type.replace(/_/g, ' ')}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {e.created_at
                                                ? new Date(
                                                      e.created_at,
                                                  ).toLocaleString('en-NZ', {
                                                      day: 'numeric',
                                                      month: 'short',
                                                      year: 'numeric',
                                                      hour: 'numeric',
                                                      minute: '2-digit',
                                                  })
                                                : ''}
                                            {e.created_by
                                                ? ` · ${e.created_by}`
                                                : ''}
                                            {e.notes ? ` · ${e.notes}` : ''}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div
                                            className={
                                                e.hours_delta >= 0
                                                    ? 'text-sm font-bold text-status-success'
                                                    : 'text-sm font-bold text-status-critical'
                                            }
                                        >
                                            {e.hours_delta >= 0 ? '+' : ''}
                                            {e.hours_delta}h
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            → {e.balance_after}h
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}
