import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { LeaveHubTabs } from '@/components/hr';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, Download, Pencil, Search, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type BreadcrumbItem = { title: string; href: string };

type LeaveBalance = {
    id: number;
    user: { id: number; name: string; email: string };
    leave_type: string;
    year: number;
    entitlement_hours: number;
    taken_hours: number;
    pending_hours: number;
    remaining_hours: number;
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
    balances: { data: LeaveBalance[]; links: { url: string | null; label: string; active: boolean }[] };
    year: number;
    leaveTypes: string[];
    filters: { year: string | number | null; q: string | null };
    can: { manage: boolean };
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

const getUsageColor = (remaining: number, entitlement: number) => {
    if (entitlement === 0) return 'text-muted-foreground';
    const pct = (remaining / entitlement) * 100;
    if (pct <= 10) return 'text-status-critical font-semibold';
    if (pct <= 25) return 'text-status-warning';
    return 'text-status-success';
};

export default function LeaveBalances({ balances, year, leaveTypes, filters, can }: Props) {
    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: 5 }, (_, i) => currentYear - 2 + i);
    const people = Array.from(
        new Map(balances.data.map((b) => [b.user.id, b.user])).values(),
    );

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/leave/balances', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    // --- Adjust modal ---
    const [adjustOpen, setAdjustOpen] = useState(false);
    const [adjust, setAdjust] = useState({
        user_id: '',
        leave_type: 'annual',
        mode: 'credit',
        hours: '8',
        reason: '',
    });
    const [processing, setProcessing] = useState(false);

    const openAdjust = (preset?: Partial<typeof adjust>) => {
        setAdjust((a) => ({ ...a, ...preset }));
        setAdjustOpen(true);
    };

    const submitAdjust = () => {
        if (!adjust.user_id) {
            toast.error('Pick a person to adjust.');
            return;
        }
        setProcessing(true);
        router.post(
            '/hr/leave/balances/adjust',
            { ...adjust, year, hours: Number(adjust.hours) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAdjustOpen(false);
                    toast.success('Balance adjusted — ledger entry recorded.');
                },
                onError: () => toast.error('Could not apply the adjustment.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    // --- Ledger drawer ---
    const [ledgerOpen, setLedgerOpen] = useState(false);
    const [ledgerLoading, setLedgerLoading] = useState(false);
    const [ledgerName, setLedgerName] = useState('');
    const [ledger, setLedger] = useState<LedgerEntry[]>([]);

    const openLedger = (b: LeaveBalance) => {
        setLedgerName(b.user.name);
        setLedgerOpen(true);
        setLedgerLoading(true);
        setLedger([]);
        fetch(`/hr/leave/balances/${b.user.id}/ledger?year=${year}&leave_type=${b.leave_type}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : { entries: [] }))
            .then((d) => setLedger(d.entries ?? []))
            .catch(() => setLedger([]))
            .finally(() => setLedgerLoading(false));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Balances" />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={CalendarDays}
                        title="Leave Balances"
                        description={`Staff leave entitlements and usage for ${year}.`}
                        stats={[
                            { label: 'Year', value: year },
                            { label: 'Records', value: balances.data.length },
                        ]}
                    />
                }
            >
                <LeaveHubTabs active="balances" />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">
                        Hours-based · click a row to open the immutable ledger.
                    </p>
                    <div className="flex items-center gap-2">
                        {can.manage && (
                            <Button variant="outline" size="sm" onClick={() => openAdjust()}>
                                <Pencil className="mr-1.5 h-4 w-4" /> Adjust / opening balance
                            </Button>
                        )}
                        <Button asChild variant="outline" size="sm">
                            <a href={`/hr/leave/balances/export?format=csv&year=${year}`}>
                                <Download className="mr-1.5 h-4 w-4" /> Export
                            </a>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">Year</Label>
                            <Select value={String(filters.year || year)} onValueChange={(v) => onFilter({ year: v })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Year" />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((y) => (
                                        <SelectItem key={y} value={String(y)}>
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by staff name or email..."
                                    value={filters.q || ''}
                                    onChange={(e) => onFilter({ q: e.target.value })}
                                    className="pl-9"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Staff Member</TableHead>
                                    <TableHead>Leave Type</TableHead>
                                    <TableHead className="text-right">Entitlement</TableHead>
                                    <TableHead className="text-right">Taken</TableHead>
                                    <TableHead className="text-right">Pending</TableHead>
                                    <TableHead className="text-right">Remaining</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {balances.data.map((balance) => (
                                    <TableRow
                                        key={balance.id}
                                        className="cursor-pointer"
                                        onClick={() => openLedger(balance)}
                                    >
                                        <TableCell>
                                            <div className="font-medium">{balance.user.name}</div>
                                            <div className="text-xs text-muted-foreground">{balance.user.email}</div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className="capitalize">
                                                {balance.leave_type.replace(/_/g, ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatHours(balance.entitlement_hours)}
                                        </TableCell>
                                        <TableCell className="text-right">{formatHours(balance.taken_hours)}</TableCell>
                                        <TableCell className="text-right">
                                            {balance.pending_hours > 0 ? (
                                                <span className="text-status-warning">{formatHours(balance.pending_hours)}</span>
                                            ) : (
                                                <span className="text-muted-foreground">0h</span>
                                            )}
                                        </TableCell>
                                        <TableCell className={`text-right ${getUsageColor(balance.remaining_hours, balance.entitlement_hours)}`}>
                                            {formatHours(balance.remaining_hours)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!balances.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                                            No leave balances found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {balances?.links?.length ? <LaravelPagination links={balances.links} /> : null}
            </PageLayout>

            {/* Adjust balance modal */}
            <Dialog open={adjustOpen} onOpenChange={setAdjustOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Adjust balance</DialogTitle>
                    </DialogHeader>
                    <p className="-mt-2 text-xs text-muted-foreground">
                        Writes a ledger entry — keeps the audit trail complete.
                    </p>
                    <div className="grid gap-3 py-2">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-xs">Person</Label>
                                <Select value={adjust.user_id} onValueChange={(v) => setAdjust((a) => ({ ...a, user_id: v }))}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {people.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Leave type</Label>
                                <Select value={adjust.leave_type} onValueChange={(v) => setAdjust((a) => ({ ...a, leave_type: v }))}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {leaveTypes.map((t) => (
                                            <SelectItem key={t} value={t} className="capitalize">
                                                {t.replace(/_/g, ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label className="text-xs">Adjustment</Label>
                                <Select value={adjust.mode} onValueChange={(v) => setAdjust((a) => ({ ...a, mode: v }))}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="credit">Credit +</SelectItem>
                                        <SelectItem value="debit">Debit −</SelectItem>
                                        <SelectItem value="set_opening">Set opening</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Hours</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.5"
                                    value={adjust.hours}
                                    onChange={(e) => setAdjust((a) => ({ ...a, hours: e.target.value }))}
                                />
                            </div>
                        </div>
                        <div>
                            <Label className="text-xs">Reason</Label>
                            <Textarea
                                rows={2}
                                value={adjust.reason}
                                onChange={(e) => setAdjust((a) => ({ ...a, reason: e.target.value }))}
                                placeholder="e.g. Opening balance migrated from PayHero"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAdjustOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAdjust} disabled={processing}>
                            {processing ? 'Applying…' : 'Apply adjustment'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Ledger drawer */}
            {ledgerOpen && (
                <div className="fixed inset-0 z-50 bg-black/40" onClick={() => setLedgerOpen(false)}>
                    <div
                        className="absolute top-0 right-0 flex h-full w-[420px] max-w-[92vw] flex-col bg-card shadow-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <div className="text-base font-bold">{ledgerName}</div>
                                <div className="text-xs text-muted-foreground">Leave ledger · {year}</div>
                            </div>
                            <Button variant="ghost" size="icon" onClick={() => setLedgerOpen(false)}>
                                <X className="h-4 w-4" />
                            </Button>
                        </div>
                        <div className="flex-1 overflow-y-auto px-5 py-3">
                            {ledgerLoading ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">Loading…</p>
                            ) : ledger.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">No ledger entries yet.</p>
                            ) : (
                                ledger.map((e) => (
                                    <div key={e.id} className="flex items-center gap-3 border-b py-2.5 last:border-b-0">
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-semibold capitalize">
                                                {e.entry_type.replace(/_/g, ' ')}
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {e.created_at?.slice(0, 16).replace('T', ' ')}
                                                {e.created_by ? ` · ${e.created_by}` : ''}
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
                                            <div className="text-[11px] text-muted-foreground">→ {e.balance_after}h</div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
