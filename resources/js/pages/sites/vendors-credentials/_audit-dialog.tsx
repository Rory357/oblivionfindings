/**
 * Reveal & audit log — a full-window dialog over the cross-site credential
 * audit trail (`GET /vendors/audit`). Opened from the hero ⋯ menu and from a
 * credential's "Reveal history" button (which pre-seeds the search).
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Check,
    Copy,
    Eye,
    FileText,
    History,
    KeyRound,
    Loader2,
    Pencil,
    Plus,
    RefreshCcw,
    Search,
    ShieldCheck,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { FilterSelect, type FilterOption } from '../_dialog-shared';

type AuditTone = 'info' | 'neutral' | 'success' | 'warning' | 'critical';

const ACTION_META: Record<string, { label: string; icon: LucideIcon; tone: AuditTone }> = {
    view_list: { label: 'Viewed list', icon: Eye, tone: 'neutral' },
    reveal: { label: 'Revealed', icon: Eye, tone: 'info' },
    copy: { label: 'Copied', icon: Copy, tone: 'neutral' },
    totp_code: { label: 'Viewed OTP', icon: KeyRound, tone: 'neutral' },
    create: { label: 'Created', icon: Plus, tone: 'success' },
    edit: { label: 'Updated', icon: Pencil, tone: 'warning' },
    rotate: { label: 'Rotated', icon: RefreshCcw, tone: 'info' },
    totp_setup: { label: 'Authenticator set', icon: ShieldCheck, tone: 'success' },
    totp_remove: { label: 'Authenticator removed', icon: ShieldCheck, tone: 'warning' },
    delete: { label: 'Deleted', icon: Trash2, tone: 'critical' },
};

const TONE_BADGE: Record<AuditTone, string> = {
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
    neutral: 'border-border bg-muted text-muted-foreground',
    success: 'border-status-success/30 bg-status-success-bg text-status-success',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
};

type AuditRow = {
    id: number;
    at: string;
    action: string;
    actor: { name: string; initials: string };
    target: string;
    target_type: string;
    site_name: string;
    ip: string;
    result: 'ok' | 'denied';
};

function actionMeta(action: string) {
    return ACTION_META[action] ?? { label: action, icon: History, tone: 'neutral' as AuditTone };
}

function formatDateTime(iso: string) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('en-NZ', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

export function AuditLogDialog({
    isOpen,
    onClose,
    focusLabel,
    siteId,
}: {
    isOpen: boolean;
    onClose: () => void;
    focusLabel?: string;
    /** Optional: scope the feed to a single site. */
    siteId?: number | null;
}) {
    const [rows, setRows] = useState<AuditRow[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [action, setAction] = useState('all');
    const [range, setRange] = useState('all');
    const [exporting, setExporting] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        setSearch(focusLabel ?? '');
        setAction('all');
        setRange('all');
        setError(null);
        setLoading(true);
        const url = new URL(`/vendors/audit`, window.location.origin);
        if (siteId) url.searchParams.set('site_id', String(siteId));
        fetch(url.toString(), {
            credentials: 'include',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = (await res.json()) as { logs: AuditRow[] };
                setRows(data.logs ?? []);
            })
            .catch((e) => setError(e instanceof Error ? e.message : 'Could not load the audit log.'))
            .finally(() => setLoading(false));
    }, [isOpen, focusLabel, siteId]);

    const rangeHours = useMemo(
        () => ({ '24h': 24, '7d': 168, '30d': 720, all: Infinity })[range] ?? Infinity,
        [range],
    );

    const filtered = useMemo(() => {
        const now = Date.now();
        const s = search.trim().toLowerCase();
        return rows.filter((row) => {
            if (action !== 'all' && row.action !== action) return false;
            if (rangeHours !== Infinity) {
                const age = (now - new Date(row.at).getTime()) / 3_600_000;
                if (age > rangeHours) return false;
            }
            if (s) {
                const hay = [row.target, row.site_name, row.actor.name, actionMeta(row.action).label]
                    .join(' ')
                    .toLowerCase();
                if (!hay.includes(s)) return false;
            }
            return true;
        });
    }, [rows, action, rangeHours, search]);

    const count = (a: string) => filtered.filter((r) => r.action === a).length;
    const deniedCount = filtered.filter((r) => r.result === 'denied').length;
    const summary: { label: string; value: number; icon: LucideIcon; tone: AuditTone }[] = [
        { label: 'Reveals', value: count('reveal'), icon: Eye, tone: 'info' },
        { label: 'Copies', value: count('copy'), icon: Copy, tone: 'neutral' },
        { label: 'Rotations', value: count('rotate'), icon: RefreshCcw, tone: 'success' },
        { label: 'Denied', value: deniedCount, icon: AlertTriangle, tone: deniedCount ? 'critical' : 'success' },
    ];

    const actionOptions: FilterOption[] = [
        { value: 'all', label: 'All actions' },
        ...Object.keys(ACTION_META).map((k) => ({ value: k, label: ACTION_META[k].label, icon: ACTION_META[k].icon })),
    ];
    const rangeOptions: FilterOption[] = [
        { value: '24h', label: 'Last 24 hours' },
        { value: '7d', label: 'Last 7 days' },
        { value: '30d', label: 'Last 30 days' },
        { value: 'all', label: 'All time' },
    ];

    const exportCsv = () => {
        setExporting(true);
        const head = ['Timestamp', 'Actor', 'Action', 'Target', 'Type', 'Site', 'Source IP', 'Result'];
        const lines = filtered.map((r) =>
            [
                new Date(r.at).toISOString(),
                r.actor.name,
                actionMeta(r.action).label,
                r.target,
                r.target_type,
                r.site_name,
                r.ip,
                r.result,
            ]
                .map((c) => `"${String(c).replace(/"/g, '""')}"`)
                .join(','),
        );
        const csv = [head.join(','), ...lines].join('\n');
        try {
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `vendor-credential-audit-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch {
            // download blocked; ignore
        }
        setExporting(false);
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 1080px)', width: 'min(92vw, 1080px)' }}
            >
                <DialogHeader className="flex-row items-start justify-between gap-4 space-y-0">
                    <div>
                        <DialogTitle className="flex items-center gap-2">
                            <History className="h-4 w-4 text-primary" />
                            Reveal &amp; audit log
                        </DialogTitle>
                        <DialogDescription>
                            Every reveal, copy, rotation and change — immutable, time-stamped, and
                            exportable.
                        </DialogDescription>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        onClick={exportCsv}
                        disabled={exporting || filtered.length === 0}
                    >
                        {exporting ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <FileText className="mr-2 h-4 w-4" />
                        )}
                        Export CSV
                    </Button>
                </DialogHeader>

                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {summary.map((tile) => {
                        const Icon = tile.icon;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- compact summary tile, not a Card
                            <div
                                key={tile.label}
                                className="flex items-center gap-3 rounded-xl border border-border bg-card/40 p-3"
                            >
                                <span
                                    className={cn(
                                        'flex h-9 w-9 items-center justify-center rounded-lg',
                                        TONE_BADGE[tile.tone],
                                    )}
                                >
                                    <Icon className="h-4 w-4" />
                                </span>
                                <div>
                                    <div className="text-lg font-bold tabular-nums">{tile.value}</div>
                                    <div className="text-[11px] uppercase tracking-wider text-muted-foreground">
                                        {tile.label}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[200px] flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search actor, target, or site…"
                            className="pl-9"
                        />
                    </div>
                    <FilterSelect
                        value={action}
                        onChange={setAction}
                        options={actionOptions}
                        aria-label="Filter by action"
                    />
                    <FilterSelect
                        value={range}
                        onChange={setRange}
                        options={rangeOptions}
                        searchable={false}
                        widthClass="w-40"
                        aria-label="Filter by time range"
                    />
                </div>

                <div className="mt-3 overflow-hidden rounded-xl border border-border">
                    <div className="max-h-[44vh] overflow-y-auto">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 z-10 border-b bg-muted">
                                <tr>
                                    {['When', 'Who', 'Action', 'Target', 'Site', 'Source', 'Result'].map((h) => (
                                        <th
                                            key={h}
                                            className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {loading ? (
                                    <tr>
                                        <td colSpan={7} className="px-3 py-12 text-center text-muted-foreground">
                                            <Loader2 className="mx-auto mb-2 h-6 w-6 animate-spin" />
                                            Loading audit trail…
                                        </td>
                                    </tr>
                                ) : error ? (
                                    <tr>
                                        <td colSpan={7} className="px-3 py-12 text-center text-status-critical">
                                            {error}
                                        </td>
                                    </tr>
                                ) : filtered.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-3 py-12 text-center text-muted-foreground">
                                            <History className="mx-auto mb-2 h-7 w-7 opacity-40" />
                                            No matching events
                                        </td>
                                    </tr>
                                ) : (
                                    filtered.map((row) => {
                                        const meta = actionMeta(row.action);
                                        const Icon = meta.icon;
                                        return (
                                            <tr key={row.id} className="hover:bg-muted/40">
                                                <td className="whitespace-nowrap px-3 py-2 text-muted-foreground">
                                                    {formatDateTime(row.at)}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2">
                                                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                            {row.actor.initials}
                                                        </span>
                                                        <span className="font-medium">{row.actor.name}</span>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Badge variant="outline" className={cn('gap-1', TONE_BADGE[meta.tone])}>
                                                        <Icon className="h-3 w-3" />
                                                        {meta.label}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="font-medium">{row.target}</div>
                                                    <div className="text-xs capitalize text-muted-foreground">
                                                        {row.target_type}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-muted-foreground">{row.site_name}</td>
                                                <td className="px-3 py-2 font-mono text-xs text-muted-foreground">
                                                    {row.ip}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {row.result === 'ok' ? (
                                                        <span className="inline-flex items-center text-status-success">
                                                            <Check className="h-4 w-4" />
                                                        </span>
                                                    ) : (
                                                        <Badge variant="outline" className={TONE_BADGE.critical}>
                                                            Denied
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <DialogFooter className="mt-3 flex-row items-center justify-between sm:justify-between">
                    <span className="text-xs text-muted-foreground">
                        Showing {filtered.length} of {rows.length} events
                    </span>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
