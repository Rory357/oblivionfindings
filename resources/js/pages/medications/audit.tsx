import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    ChevronDown,
    ChevronRight,
    Download,
    Search,
    Plus,
    Pencil,
    Trash2,
    Eye,
    FileText,
    User,
    Calendar,
    X,
} from 'lucide-react';
import React, { useMemo, useState } from 'react';
import { cn } from '@/lib/utils';

type AuditLog = {
    id: number;
    created_at: string;
    action: string;
    auditable_type: string;
    auditable_id: number;
    client?: { id: number; name: string } | null;
    user?: { id: number; name: string } | null;
    meta?: any;
};

type Props = {
    filters: { client_id?: any; user_id?: any; from?: string; to?: string };
    logs: AuditLog[];
};

function ActionBadge({ action }: { action: string }) {
    const a = action?.toLowerCase?.() ?? '';
    const config: Record<string, { class: string; icon: React.ReactNode; label: string }> = {
        created: {
            class: 'bg-emerald-100 text-emerald-800 border-emerald-200',
            icon: <Plus className="h-3 w-3 mr-1" />,
            label: 'Created',
        },
        updated: {
            class: 'bg-amber-100 text-amber-800 border-amber-200',
            icon: <Pencil className="h-3 w-3 mr-1" />,
            label: 'Updated',
        },
        deleted: {
            class: 'bg-rose-100 text-rose-800 border-rose-200',
            icon: <Trash2 className="h-3 w-3 mr-1" />,
            label: 'Deleted',
        },
    };
    const c = config[a] || { class: 'bg-slate-100 text-slate-700 border-slate-200', icon: <Eye className="h-3 w-3 mr-1" />, label: action };
    
    return (
        <Badge variant="outline" className={cn('flex items-center w-fit', c.class)}>
            {c.icon}
            {c.label}
        </Badge>
    );
}

function formatModelName(type: string): string {
    // Convert CamelCase to readable format
    return type
        .replace(/^App\\Models\\/, '')
        .replace(/([A-Z])/g, ' $1')
        .trim();
}

function JsonPreview({ data, maxLines = 3 }: { data: any; maxLines?: number }) {
    const json = JSON.stringify(data, null, 2);
    const lines = json.split('\n');
    const hasMore = lines.length > maxLines;
    const preview = lines.slice(0, maxLines).join('\n') + (hasMore ? '\n...' : '');
    
    return (
        <pre className="text-xs text-muted-foreground font-mono bg-muted/50 rounded p-2 overflow-x-auto">
            {preview}
        </pre>
    );
}

export default function MedicationsAudit({ filters, logs }: Props) {
    const [clientId, setClientId] = useState(filters.client_id ?? '');
    const [userId, setUserId] = useState(filters.user_id ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
    const [selectedLog, setSelectedLog] = useState<AuditLog | null>(null);

    const query = useMemo(() => {
        const q: any = {};
        if (clientId) q.client_id = clientId;
        if (userId) q.user_id = userId;
        if (from) q.from = from;
        if (to) q.to = to;
        return q;
    }, [clientId, userId, from, to]);

    const toggleRow = (id: number) => {
        const newExpanded = new Set(expandedRows);
        if (newExpanded.has(id)) {
            newExpanded.delete(id);
        } else {
            newExpanded.add(id);
        }
        setExpandedRows(newExpanded);
    };

    const clearFilters = () => {
        setClientId('');
        setUserId('');
        setFrom('');
        setTo('');
        router.get('/medications/audit', {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Medications', href: '/medications' }, { title: 'Audit Log', href: '/medications/audit' }]}>
            <Head title="Medication Audit Log" />

            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Medication Audit Log</h1>
                        <p className="text-sm text-muted-foreground mt-1">
                            Track medication orders, administrations, controlled register updates, and break-glass access.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => window.location.href = `/medications/audit/export?${new URLSearchParams(query).toString()}`}
                    >
                        <Download className="mr-2 h-4 w-4" />
                        Export CSV
                    </Button>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Search className="h-4 w-4" />
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="client-id" className="text-xs">Client ID</Label>
                                <Input 
                                    id="client-id"
                                    value={clientId} 
                                    onChange={(e) => setClientId(e.target.value)} 
                                    placeholder="e.g. 12"
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="user-id" className="text-xs">User ID</Label>
                                <Input 
                                    id="user-id"
                                    value={userId} 
                                    onChange={(e) => setUserId(e.target.value)} 
                                    placeholder="e.g. 7"
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="from-date" className="text-xs">From Date</Label>
                                <Input 
                                    id="from-date"
                                    type="date" 
                                    value={from} 
                                    onChange={(e) => setFrom(e.target.value)}
                                    className="h-9"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="to-date" className="text-xs">To Date</Label>
                                <Input 
                                    id="to-date"
                                    type="date" 
                                    value={to} 
                                    onChange={(e) => setTo(e.target.value)}
                                    className="h-9"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex items-center gap-2">
                            <Button 
                                onClick={() => router.get('/medications/audit', query, { preserveState: true, preserveScroll: true })}
                                size="sm"
                            >
                                Apply Filters
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={clearFilters}
                            >
                                <X className="mr-2 h-3.5 w-3.5" />
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Results Summary */}
                <div className="flex items-center justify-between">
                    <div className="text-sm text-muted-foreground">
                        Showing <span className="font-medium text-foreground">{logs.length}</span> entries
                        {logs.length >= 200 && ' (max 200)'}
                    </div>
                </div>

                {/* Audit Log Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[100px]">Action</TableHead>
                                    <TableHead>Record</TableHead>
                                    <TableHead className="w-[180px]">
                                        <div className="flex items-center gap-1.5">
                                            <Calendar className="h-3.5 w-3.5" />
                                            Timestamp
                                        </div>
                                    </TableHead>
                                    <TableHead>
                                        <div className="flex items-center gap-1.5">
                                            <User className="h-3.5 w-3.5" />
                                            User
                                        </div>
                                    </TableHead>
                                    <TableHead className="w-[100px]">Details</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                                            <FileText className="h-8 w-8 mx-auto mb-2 opacity-50" />
                                            No audit entries found.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {logs.map((log) => {
                                    const isExpanded = expandedRows.has(log.id);
                                    const hasMeta = log.meta && Object.keys(log.meta).length > 0;
                                    
                                    return (
                                        <React.Fragment key={log.id}>
                                            <TableRow
                                                className={cn(
                                                    "cursor-pointer transition-colors",
                                                    isExpanded && "bg-muted/50"
                                                )}
                                                onClick={() => hasMeta && toggleRow(log.id)}
                                            >
                                                <TableCell>
                                                    <ActionBadge action={log.action} />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-medium text-sm">
                                                        {formatModelName(log.auditable_type)} #{log.auditable_id}
                                                    </div>
                                                    {log.client?.name && (
                                                        <div className="text-xs text-muted-foreground mt-0.5">
                                                            Client: {log.client.name}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm text-muted-foreground">
                                                    {log.created_at 
                                                        ? new Date(log.created_at).toLocaleString('en-NZ', {
                                                              day: '2-digit',
                                                              month: 'short',
                                                              year: 'numeric',
                                                              hour: '2-digit',
                                                              minute: '2-digit',
                                                          })
                                                        : '-'
                                                    }
                                                </TableCell>
                                                <TableCell>
                                                    {log.user?.name ? (
                                                        <div className="text-sm">{log.user.name}</div>
                                                    ) : (
                                                        <span className="text-sm text-muted-foreground">System</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {hasMeta ? (
                                                        <Button 
                                                            variant="ghost" 
                                                            size="sm" 
                                                            className="h-8 px-2"
                                                            onClick={(e) => { e.stopPropagation(); toggleRow(log.id); }}
                                                        >
                                                            {isExpanded ? (
                                                                <ChevronDown className="h-4 w-4" />
                                                            ) : (
                                                                <ChevronRight className="h-4 w-4" />
                                                            )}
                                                            <span className="ml-1 text-xs">View</span>
                                                        </Button>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                            {isExpanded && hasMeta && (
                                                <TableRow className="bg-muted/30">
                                                    <TableCell colSpan={5} className="p-4">
                                                        <div className="space-y-3">
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                                                    Changes / Metadata
                                                                </span>
                                                                <Button 
                                                                    variant="ghost" 
                                                                    size="sm" 
                                                                    className="h-7 text-xs"
                                                                    onClick={() => setSelectedLog(log)}
                                                                >
                                                                    <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                                    View Full JSON
                                                                </Button>
                                                            </div>
                                                            <JsonPreview data={log.meta} maxLines={8} />
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </React.Fragment>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Full JSON Dialog */}
            <Dialog open={!!selectedLog} onOpenChange={() => setSelectedLog(null)}>
                <DialogContent className="max-w-3xl max-h-[85vh]">
                    <DialogHeader>
                        <DialogTitle className="text-base">Audit Entry Details</DialogTitle>
                    </DialogHeader>
                    {selectedLog && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span className="text-muted-foreground">Action:</span>
                                    <div className="mt-1"><ActionBadge action={selectedLog.action} /></div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Record:</span>
                                    <div className="mt-1 font-medium">
                                        {formatModelName(selectedLog.auditable_type)} #{selectedLog.auditable_id}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Timestamp:</span>
                                    <div className="mt-1">
                                        {selectedLog.created_at 
                                            ? new Date(selectedLog.created_at).toLocaleString('en-NZ')
                                            : '-'
                                        }
                                    </div>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">User:</span>
                                    <div className="mt-1">{selectedLog.user?.name || 'System'}</div>
                                </div>
                                {selectedLog.client?.name && (
                                    <div className="col-span-2">
                                        <span className="text-muted-foreground">Client:</span>
                                        <div className="mt-1">{selectedLog.client.name} (#{selectedLog.client.id})</div>
                                    </div>
                                )}
                            </div>
                            
                            <div>
                                <span className="text-sm text-muted-foreground">Full Metadata:</span>
                                <pre className="mt-2 max-h-[400px] overflow-auto rounded-md border bg-muted/50 p-4 text-xs font-mono">
                                    {JSON.stringify(selectedLog.meta, null, 2)}
                                </pre>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
