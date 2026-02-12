import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, AlertTriangle } from 'lucide-react';
import { useState } from 'react';

type OverdueAction = {
    id: number;
    reference_number?: string;
    description: string;
    severity: string;
    due_date: string;
    status: string;
    site?: { id: number; name: string; type: string; region?: string };
    assigned_to?: { id: number; name: string };
    actions: Array<{ id: number }>;
};

type Props = {
    overdueActions: OverdueAction[];
    sites: Array<{ id: number; name: string }>;
    filters: {
        site_id?: string;
        severity?: string;
        assigned_to?: string;
    };
};

const severityColors: Record<string, string> = {
    low: 'bg-slate-500/20 text-slate-400',
    medium: 'bg-yellow-500/20 text-yellow-400',
    high: 'bg-orange-500/20 text-orange-400',
    critical: 'bg-red-500/20 text-red-400',
};

export default function OverdueActions({ overdueActions, sites, filters }: Props) {
    const [siteId, setSiteId] = useState(filters.site_id || '');
    const [severity, setSeverity] = useState(filters.severity || '');

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (siteId) params.site_id = siteId;
        if (severity) params.severity = severity;
        router.get('/sites/reports/overdue-actions', params, { preserveState: true });
    };

    const daysOverdue = (dueDate: string) => {
        const due = new Date(dueDate);
        const now = new Date();
        return Math.floor((now.getTime() - due.getTime()) / (1000 * 60 * 60 * 24));
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Reports', href: '/sites/reports' },
            { title: 'Overdue Actions', href: '/sites/reports/overdue-actions' },
        ]}>
            <Head title="Overdue Corrective Actions" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div>
                    <Button asChild variant="ghost" size="sm" className="mb-2">
                        <Link href="/sites/reports">
                            <ArrowLeft className="w-4 h-4 mr-1" />
                            Back
                        </Link>
                    </Button>
                    <h1 className="text-lg font-semibold flex items-center gap-2">
                        <AlertTriangle className="w-5 h-5 text-red-400" />
                        Overdue Corrective Actions
                    </h1>
                    <p className="text-sm text-slate-400">
                        Hazards past their due date that remain open
                    </p>
                </div>

                {/* Summary */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">{overdueActions.length}</div>
                            <div className="text-sm text-slate-400">Total Overdue</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-orange-400">
                                {overdueActions.filter(a => a.severity === 'critical' || a.severity === 'high').length}
                            </div>
                            <div className="text-sm text-slate-400">High/Critical</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">
                                {overdueActions.filter(a => !a.assigned_to).length}
                            </div>
                            <div className="text-sm text-slate-400">Unassigned</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex gap-4 flex-wrap">
                            <div className="w-48">
                                <Select value={siteId || undefined} onValueChange={setSiteId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map(site => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="w-40">
                                <Select value={severity || undefined} onValueChange={setSeverity}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Severity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button variant="outline" onClick={applyFilters}>Apply</Button>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSiteId('');
                                    setSeverity('');
                                    router.get('/sites/reports/overdue-actions', {}, { preserveState: true });
                                }}
                            >
                                Clear
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Overdue Hazards ({overdueActions.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {overdueActions.length === 0 ? (
                            <p className="text-sm text-slate-400 text-center py-8">No overdue corrective actions found.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Assigned To</TableHead>
                                        <TableHead>Due Date</TableHead>
                                        <TableHead>Days Overdue</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {overdueActions.map((action) => (
                                        <TableRow key={action.id}>
                                            <TableCell className="font-mono text-sm">
                                                {action.reference_number || `#${action.id}`}
                                            </TableCell>
                                            <TableCell>{action.site?.name || 'N/A'}</TableCell>
                                            <TableCell className="max-w-xs truncate">
                                                {action.description}
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={severityColors[action.severity] || ''}>
                                                    {action.severity}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {action.assigned_to?.name || (
                                                    <span className="text-slate-500">Unassigned</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-slate-400">
                                                {new Date(action.due_date).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className="text-red-400">
                                                    {daysOverdue(action.due_date)} days
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/hazards/${action.id}`}>View</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
