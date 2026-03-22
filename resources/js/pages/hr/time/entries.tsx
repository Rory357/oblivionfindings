import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown, Clock, Plus } from 'lucide-react';

interface TimeEntry {
    id: number;
    user_name: string;
    entry_date: string;
    clock_in: string;
    clock_out: string | null;
    break_minutes: number;
    total_hours: number | null;
    entry_type: string;
    status: string;
    notes: string | null;
    project_code: string | null;
}

interface Props {
    entries: {
        data: TimeEntry[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        from?: string;
        to?: string;
    };
    can: {
        manage?: boolean;
    };
}

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Time Tracking', href: '/hr/time' },
    { title: 'Entries', href: '/hr/time/entries' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    active: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Active' },
    submitted: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Submitted' },
    approved: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
    rejected: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Rejected' },
};

export default function TimeEntries({ entries, filters, can }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [dateFrom, setDateFrom] = useState(filters.from ?? '');
    const [dateTo, setDateTo] = useState(filters.to ?? '');

    const form = useForm({
        clock_in: '',
        clock_out: '',
        break_minutes: '0',
        notes: '',
        project_code: '',
        cost_centre: '',
    });

    function handleDateFilter() {
        router.get('/hr/time/entries', { from: dateFrom || undefined, to: dateTo || undefined }, { preserveState: true, replace: true });
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/hr/time/entries', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFormOpen(false);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Time Entries" />

            <PageShell>
                <PageHeader
                    title="Time Entries"
                    description="View and manage all time entries."
                    backHref="/hr/time"
                    backLabel="Back to Time Tracking"
                />

                {/* Date Range Filter */}
                <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-3">
                    <div className="space-y-1">
                        <Label className="text-xs">From</Label>
                        <Input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            className="w-[160px]"
                        />
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">To</Label>
                        <Input
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            className="w-[160px]"
                        />
                    </div>
                    <Button variant="outline" size="sm" onClick={handleDateFilter}>
                        Filter
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setDateFrom('');
                            setDateTo('');
                            router.get('/hr/time/entries', {}, { preserveState: true });
                        }}
                    >
                        Clear
                    </Button>
                </div>

                {/* Manual Entry Form */}
                {can.manage && (
                    <Collapsible open={formOpen} onOpenChange={setFormOpen}>
                        <Card>
                            <CollapsibleTrigger asChild>
                                <CardHeader className="cursor-pointer">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="flex items-center gap-2">
                                            <Plus className="h-4 w-4" />
                                            Add Manual Entry
                                        </CardTitle>
                                        <ChevronDown className={`h-4 w-4 transition-transform ${formOpen ? 'rotate-180' : ''}`} />
                                    </div>
                                </CardHeader>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent>
                                    <form onSubmit={handleSubmit} className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="clock_in">Clock In</Label>
                                            <Input
                                                id="clock_in"
                                                type="datetime-local"
                                                value={form.data.clock_in}
                                                onChange={(e) => form.setData('clock_in', e.target.value)}
                                            />
                                            {form.errors.clock_in && <p className="text-xs text-destructive">{form.errors.clock_in}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="clock_out">Clock Out</Label>
                                            <Input
                                                id="clock_out"
                                                type="datetime-local"
                                                value={form.data.clock_out}
                                                onChange={(e) => form.setData('clock_out', e.target.value)}
                                            />
                                            {form.errors.clock_out && <p className="text-xs text-destructive">{form.errors.clock_out}</p>}
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="break_minutes">Break (minutes)</Label>
                                            <Input
                                                id="break_minutes"
                                                type="number"
                                                min="0"
                                                value={form.data.break_minutes}
                                                onChange={(e) => form.setData('break_minutes', e.target.value)}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="project_code">Project Code</Label>
                                            <Input
                                                id="project_code"
                                                value={form.data.project_code}
                                                onChange={(e) => form.setData('project_code', e.target.value)}
                                                placeholder="Optional"
                                            />
                                        </div>
                                        <div className="space-y-2 sm:col-span-2">
                                            <Label htmlFor="notes">Notes</Label>
                                            <Textarea
                                                id="notes"
                                                rows={2}
                                                value={form.data.notes}
                                                onChange={(e) => form.setData('notes', e.target.value)}
                                                placeholder="Optional notes..."
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Button type="submit" disabled={form.processing}>
                                                {form.processing ? 'Creating...' : 'Create Entry'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>
                )}

                {/* Entries Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Entries</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <Clock className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No time entries found.</p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Staff</th>
                                            <th className="px-4 py-3 text-left font-medium">Date</th>
                                            <th className="px-4 py-3 text-left font-medium">In</th>
                                            <th className="px-4 py-3 text-left font-medium">Out</th>
                                            <th className="px-4 py-3 text-right font-medium">Break</th>
                                            <th className="px-4 py-3 text-right font-medium">Hours</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entries.data.map((entry) => {
                                            const config = statusConfig[entry.status] || statusConfig.active;
                                            return (
                                                <tr key={entry.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                    <td className="px-4 py-3 font-medium">{entry.user_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{entry.entry_date}</td>
                                                    <td className="px-4 py-3">{entry.clock_in}</td>
                                                    <td className="px-4 py-3">{entry.clock_out ?? '-'}</td>
                                                    <td className="px-4 py-3 text-right text-muted-foreground">
                                                        {entry.break_minutes > 0 ? `${entry.break_minutes}m` : '-'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">
                                                        {entry.total_hours != null ? `${entry.total_hours}h` : '-'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground capitalize">{entry.entry_type}</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={config.className}>
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {entries.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(entries.current_page - 1) * entries.per_page + 1} to{' '}
                            {Math.min(entries.current_page * entries.per_page, entries.total)} of{' '}
                            {entries.total} entries
                        </p>
                        <div className="flex items-center gap-1">
                            {entries.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
