import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    Activity,
    AlertTriangle,
    Edit3,
    Eye,
    History,
    Plus,
    Search,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export type AuditEntry = {
    id: number;
    action: string;
    auditable_type?: string | null;
    auditable_id?: number | null;
    meta?: Record<string, unknown> | null;
    actor?: { id: number; name?: string | null } | null;
    ip_address?: string | null;
    created_at?: string | null;
};

type AuditHistoryTabProps = {
    entries: AuditEntry[];
    canView?: boolean;
};

function actionIcon(action: string) {
    const a = action.toLowerCase();
    if (a.includes('create') || a.includes('add')) return Plus;
    if (a.includes('update') || a.includes('edit')) return Edit3;
    if (a.includes('delete') || a.includes('remove')) return Trash2;
    if (a.includes('view') || a.includes('read') || a.includes('access'))
        return Eye;
    if (a.includes('login') || a.includes('logout')) return UserRound;
    if (a.includes('flag') || a.includes('alert') || a.includes('escalate'))
        return AlertTriangle;
    return Activity;
}

function actionTone(action: string): string {
    const a = action.toLowerCase();
    if (a.includes('delete') || a.includes('remove'))
        return 'bg-status-critical-bg text-status-critical';
    if (a.includes('create') || a.includes('add'))
        return 'bg-status-success-bg text-status-success';
    if (a.includes('flag') || a.includes('alert') || a.includes('escalate'))
        return 'bg-status-warning-bg text-status-warning';
    if (a.includes('view') || a.includes('access'))
        return 'bg-muted text-muted-foreground';
    return 'bg-status-info-bg text-status-info';
}

function modelLabel(auditableType?: string | null): string {
    if (!auditableType) return 'system';
    const parts = auditableType.split('\\');
    return (parts[parts.length - 1] ?? auditableType).replace(/([A-Z])/g, ' $1').trim();
}

function dateLabel(value?: string | null) {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    } catch {
        return value;
    }
}

function groupByDay(entries: AuditEntry[]): Array<[string, AuditEntry[]]> {
    const map = new Map<string, AuditEntry[]>();
    for (const entry of entries) {
        const when = entry.created_at;
        if (!when) continue;
        const day = new Intl.DateTimeFormat('en-NZ', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }).format(new Date(when));
        const bucket = map.get(day) ?? [];
        bucket.push(entry);
        map.set(day, bucket);
    }
    return Array.from(map.entries());
}

export function AuditHistoryTab({
    entries,
    canView = true,
}: AuditHistoryTabProps) {
    const [query, setQuery] = useState('');
    const [actionFilter, setActionFilter] = useState<string>('all');
    const [modelFilter, setModelFilter] = useState<string>('all');

    const distinctActions = useMemo(() => {
        const set = new Set<string>();
        for (const e of entries) {
            if (e.action) set.add(e.action);
        }
        return Array.from(set).sort();
    }, [entries]);

    const distinctModels = useMemo(() => {
        const set = new Set<string>();
        for (const e of entries) {
            if (e.auditable_type) set.add(modelLabel(e.auditable_type));
        }
        return Array.from(set).sort();
    }, [entries]);

    const filtered = useMemo(() => {
        const search = query.trim().toLowerCase();
        return entries.filter((entry) => {
            if (actionFilter !== 'all' && entry.action !== actionFilter)
                return false;
            if (
                modelFilter !== 'all' &&
                modelLabel(entry.auditable_type) !== modelFilter
            )
                return false;
            if (!search) return true;
            return [
                entry.action,
                entry.actor?.name,
                modelLabel(entry.auditable_type),
                entry.ip_address,
                JSON.stringify(entry.meta ?? {}),
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        });
    }, [actionFilter, entries, modelFilter, query]);

    const grouped = useMemo(() => groupByDay(filtered), [filtered]);

    if (!canView) {
        return (
            <EmptyState
                icon={History}
                title="Restricted"
                description="Only managers and auditors with the audit.viewClient permission can see this tab."
            />
        );
    }

    if (entries.length === 0) {
        return (
            <EmptyState
                icon={History}
                title="No audit entries yet"
                description="As staff create, update, or delete records for this client, the actions will appear here."
            />
        );
    }

    return (
        <div className="space-y-6" data-test="client-audit-history-tab">
            {/* eslint-disable-next-line no-restricted-syntax -- intro panel without full Card chrome. */}
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">Audit history</h2>
                        <p className="text-sm text-muted-foreground">
                            {entries.length} recent change
                            {entries.length === 1 ? '' : 's'}, newest first.
                            Permission-gated to managers.
                        </p>
                    </div>
                    <Badge variant="outline">
                        Last 200 entries
                    </Badge>
                </div>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row">
                <div className="relative max-w-md flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search actor, action, model, meta"
                        className="min-h-11 pl-9"
                    />
                </div>
                <Select value={actionFilter} onValueChange={setActionFilter}>
                    <SelectTrigger className="min-h-11 sm:w-44">
                        <SelectValue placeholder="All actions" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All actions</SelectItem>
                        {distinctActions.map((a) => (
                            <SelectItem key={a} value={a}>
                                {a}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select value={modelFilter} onValueChange={setModelFilter}>
                    <SelectTrigger className="min-h-11 sm:w-44">
                        <SelectValue placeholder="All models" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All models</SelectItem>
                        {distinctModels.map((m) => (
                            <SelectItem key={m} value={m}>
                                {m}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {grouped.map(([day, items]) => (
                <Card key={day}>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between text-base">
                            <span>{day}</span>
                            <Badge variant="outline">{items.length}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {items.map((entry) => {
                            const Icon = actionIcon(entry.action);
                            return (
                                <div
                                    key={entry.id}
                                    className="flex items-start gap-3 rounded-md border p-3 text-sm"
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 rounded-md p-1.5',
                                            actionTone(entry.action),
                                        )}
                                    >
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium capitalize">
                                                {entry.action.replace(/_/g, ' ')}
                                            </span>
                                            {entry.auditable_type ? (
                                                <Badge variant="outline">
                                                    {modelLabel(
                                                        entry.auditable_type,
                                                    )}
                                                    {entry.auditable_id
                                                        ? ` #${entry.auditable_id}`
                                                        : ''}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {entry.actor?.name ?? 'System'}
                                            {entry.ip_address
                                                ? ` · ${entry.ip_address}`
                                                : ''}
                                            {entry.created_at
                                                ? ` · ${dateLabel(
                                                      entry.created_at,
                                                  )}`
                                                : ''}
                                        </p>
                                        {entry.meta && Object.keys(entry.meta).length > 0 ? (
                                            <details className="mt-2 text-xs text-muted-foreground">
                                                <summary className="cursor-pointer">
                                                    Show meta
                                                </summary>
                                                <pre className="mt-2 overflow-x-auto rounded bg-muted/50 p-2 text-[10px]">
                                                    {JSON.stringify(
                                                        entry.meta,
                                                        null,
                                                        2,
                                                    )}
                                                </pre>
                                            </details>
                                        ) : null}
                                    </div>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            ))}

            {filtered.length === 0 ? (
                <EmptyState
                    icon={Search}
                    title="No audit entries match"
                    description="Clear filters or change the search term."
                />
            ) : null}
        </div>
    );
}
