import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, FileSearch, History } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface AuditEntry {
    id: number;
    user_id: number | null;
    action: string;
    auditable_type: string;
    auditable_id: number;
    old_values: Record<string, any> | null;
    new_values: Record<string, any> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
}

interface Filters {
    user_id: string | null;
    action: string | null;
    model_type: string | null;
    date_from: string | null;
    date_to: string | null;
}

interface Props {
    logs: { data: AuditEntry[]; links: any[] };
    actions: string[];
    modelTypes: string[];
    users: { id: number; name: string }[];
    filters: Filters;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Settings', href: '/hr/settings/webhooks' },
    { title: 'Audit Log', href: '/hr/settings/audit-log' },
];

const actionColors: Record<string, string> = {
    created: 'border-status-success/30 text-status-success bg-status-success',
    updated: 'border-status-info/30 text-status-info bg-status-info',
    deleted:
        'border-status-critical/30 text-status-critical bg-status-critical',
    viewed: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
    approved: 'border-status-success/30 text-status-success bg-status-success',
    rejected: 'border-status-warning/30 text-status-warning bg-status-warning',
    signed: 'border-primary/30 text-primary bg-primary/10',
    exported: 'border-status-info/30 text-status-info bg-status-info',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit',
          });
};

const shortModelType = (type: string) => {
    const parts = type.split('\\');
    return parts[parts.length - 1] ?? type;
};

function ChangesViewer({
    oldValues,
    newValues,
}: {
    oldValues: Record<string, any> | null;
    newValues: Record<string, any> | null;
}) {
    if (!oldValues && !newValues)
        return <span className="text-muted-foreground">-</span>;

    const allKeys = new Set([
        ...Object.keys(oldValues ?? {}),
        ...Object.keys(newValues ?? {}),
    ]);

    return (
        <div className="max-h-40 space-y-1 overflow-y-auto text-xs">
            {Array.from(allKeys).map((key) => {
                const oldVal = oldValues?.[key];
                const newVal = newValues?.[key];
                if (oldVal === newVal) return null;
                return (
                    <div key={key} className="font-mono">
                        <span className="text-muted-foreground">{key}:</span>{' '}
                        {oldVal !== undefined && (
                            <span className="text-status-critical line-through">
                                {JSON.stringify(oldVal)}
                            </span>
                        )}
                        {oldVal !== undefined && newVal !== undefined && ' -> '}
                        {newVal !== undefined && (
                            <span className="text-status-success">
                                {JSON.stringify(newVal)}
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export default function AuditLogIndex({
    logs,
    actions,
    modelTypes,
    users,
    filters,
}: Props) {
    const NONE = '__none__';
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());

    const toggleRow = (id: number) => {
        setExpandedRows((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    const onFilter = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string> = {};
        Object.entries(merged).forEach(([k, v]) => {
            if (v && v !== NONE) params[k] = v;
        });
        router.get('/hr/settings/audit-log', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Log - HR Settings" />
            <PageShell>
                <PageHero
                    icon={History}
                    title="Audit Log"
                    description="View all HR module activity and changes."
                    stats={[
                        { label: 'Entries', value: logs.data.length },
                        { label: 'Actions', value: actions.length },
                        { label: 'Model types', value: modelTypes.length },
                    ]}
                />

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <Label>User</Label>
                                <Select
                                    value={filters.user_id ?? NONE}
                                    onValueChange={(v) =>
                                        onFilter({
                                            user_id: v === NONE ? null : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All users" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All users
                                        </SelectItem>
                                        {users.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Action</Label>
                                <Select
                                    value={filters.action ?? NONE}
                                    onValueChange={(v) =>
                                        onFilter({
                                            action: v === NONE ? null : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All actions" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All actions
                                        </SelectItem>
                                        {actions.map((a) => (
                                            <SelectItem key={a} value={a}>
                                                {a}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Model Type</Label>
                                <Select
                                    value={filters.model_type ?? NONE}
                                    onValueChange={(v) =>
                                        onFilter({
                                            model_type: v === NONE ? null : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All types
                                        </SelectItem>
                                        {modelTypes.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {shortModelType(t)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Date From</Label>
                                <Input
                                    type="date"
                                    value={filters.date_from ?? ''}
                                    onChange={(e) =>
                                        onFilter({
                                            date_from: e.target.value || null,
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <Label>Date To</Label>
                                <Input
                                    type="date"
                                    value={filters.date_to ?? ''}
                                    onChange={(e) =>
                                        onFilter({
                                            date_to: e.target.value || null,
                                        })
                                    }
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileSearch className="h-5 w-5" />
                            Activity Log
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {logs.data.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No audit entries found.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-8"></TableHead>
                                        <TableHead>Timestamp</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Action</TableHead>
                                        <TableHead>Record Type</TableHead>
                                        <TableHead>Record ID</TableHead>
                                        <TableHead>IP Address</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {logs.data.map((entry) => (
                                        <Collapsible
                                            key={entry.id}
                                            asChild
                                            open={expandedRows.has(entry.id)}
                                        >
                                            <>
                                                <CollapsibleTrigger asChild>
                                                    <TableRow
                                                        className="cursor-pointer hover:bg-muted/50"
                                                        onClick={() =>
                                                            toggleRow(entry.id)
                                                        }
                                                    >
                                                        <TableCell>
                                                            {expandedRows.has(
                                                                entry.id,
                                                            ) ? (
                                                                <ChevronDown className="h-4 w-4" />
                                                            ) : (
                                                                <ChevronRight className="h-4 w-4" />
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {formatDate(
                                                                entry.created_at,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {entry.user?.name ??
                                                                'System'}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                className={
                                                                    actionColors[
                                                                        entry
                                                                            .action
                                                                    ] ?? ''
                                                                }
                                                            >
                                                                {entry.action}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {shortModelType(
                                                                entry.auditable_type,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {entry.auditable_id}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-muted-foreground">
                                                            {entry.ip_address ??
                                                                '-'}
                                                        </TableCell>
                                                    </TableRow>
                                                </CollapsibleTrigger>
                                                <CollapsibleContent asChild>
                                                    <TableRow>
                                                        <TableCell
                                                            colSpan={7}
                                                            className="bg-muted/30 p-4"
                                                        >
                                                            <div className="mb-2 text-sm font-medium">
                                                                Changes
                                                            </div>
                                                            <ChangesViewer
                                                                oldValues={
                                                                    entry.old_values
                                                                }
                                                                newValues={
                                                                    entry.new_values
                                                                }
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                </CollapsibleContent>
                                            </>
                                        </Collapsible>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        {/* Pagination links */}
                        {logs.links && logs.links.length > 3 && (
                            <LaravelPagination links={logs.links} />
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
