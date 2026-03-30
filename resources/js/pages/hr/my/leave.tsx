import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { type BreadcrumbItem } from '@/types';
import { ChevronDown, Plus } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface LeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason: string | null;
    created_at: string;
}

interface LeaveBalance {
    leave_type: string;
    entitlement_hours: number;
    taken_hours: number;
    remaining_hours: number;
}

interface Props {
    requests: {
        data: LeaveRequest[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    balances: LeaveBalance[];
    leaveTypes: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Leave', href: '/hr/my/leave' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Pending',
    },
    approved: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Approved',
    },
    declined: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Declined',
    },
    cancelled: {
        className: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
        label: 'Cancelled',
    },
};

export default function MyLeave({ requests, balances, leaveTypes }: Props) {
    const [formOpen, setFormOpen] = useState(false);

    const form = useForm({
        leave_type: '',
        starts_at: '',
        ends_at: '',
        hours_requested: '',
        reason: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/hr/my/leave', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setFormOpen(false);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Leave" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Leave</h1>

                {/* Leave Balances */}
                {balances.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {balances.map((balance, i) => (
                            <Card key={i}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium capitalize text-muted-foreground">
                                        {balance.leave_type.replace(/_/g, ' ')}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-2xl font-bold">{balance.remaining_hours}h</p>
                                    <p className="text-xs text-muted-foreground">
                                        {balance.taken_hours}h taken of {balance.entitlement_hours}h
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {/* Request Leave Form */}
                <Collapsible open={formOpen} onOpenChange={setFormOpen}>
                    <Card>
                        <CollapsibleTrigger asChild>
                            <CardHeader className="cursor-pointer">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2">
                                        <Plus className="h-4 w-4" />
                                        Request Leave
                                    </CardTitle>
                                    <ChevronDown className={`h-4 w-4 transition-transform ${formOpen ? 'rotate-180' : ''}`} />
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent>
                                <form onSubmit={handleSubmit} className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="leave_type">Leave Type</Label>
                                        <Select
                                            value={form.data.leave_type || '__none__'}
                                            onValueChange={(v) => form.setData('leave_type', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select leave type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Select leave type</SelectItem>
                                                {leaveTypes.map((type) => (
                                                    <SelectItem key={type} value={type}>
                                                        <span className="capitalize">{type.replace(/_/g, ' ')}</span>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.leave_type && (
                                            <p className="text-xs text-destructive">{form.errors.leave_type}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="hours_requested">Hours</Label>
                                        <Input
                                            id="hours_requested"
                                            type="number"
                                            step="0.5"
                                            min="0.5"
                                            value={form.data.hours_requested}
                                            onChange={(e) => form.setData('hours_requested', e.target.value)}
                                        />
                                        {form.errors.hours_requested && (
                                            <p className="text-xs text-destructive">{form.errors.hours_requested}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="starts_at">Start Date</Label>
                                        <Input
                                            id="starts_at"
                                            type="date"
                                            value={form.data.starts_at}
                                            onChange={(e) => form.setData('starts_at', e.target.value)}
                                        />
                                        {form.errors.starts_at && (
                                            <p className="text-xs text-destructive">{form.errors.starts_at}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="ends_at">End Date</Label>
                                        <Input
                                            id="ends_at"
                                            type="date"
                                            value={form.data.ends_at}
                                            onChange={(e) => form.setData('ends_at', e.target.value)}
                                        />
                                        {form.errors.ends_at && (
                                            <p className="text-xs text-destructive">{form.errors.ends_at}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2 sm:col-span-2">
                                        <Label htmlFor="reason">Reason (optional)</Label>
                                        <Textarea
                                            id="reason"
                                            rows={3}
                                            value={form.data.reason}
                                            onChange={(e) => form.setData('reason', e.target.value)}
                                            placeholder="Add a note about your leave request..."
                                        />
                                        {form.errors.reason && (
                                            <p className="text-xs text-destructive">{form.errors.reason}</p>
                                        )}
                                    </div>

                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={form.processing}>
                                            {form.processing ? 'Submitting...' : 'Submit Request'}
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                {/* My Leave Requests Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>My Leave Requests</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-left font-medium">Dates</th>
                                    <th className="px-4 py-3 text-right font-medium">Hours</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {requests.data.map((request) => {
                                    const config = statusConfig[request.status] || statusConfig.pending;
                                    return (
                                        <tr key={request.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium capitalize">
                                                {request.leave_type.replace(/_/g, ' ')}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {request.start_date} &mdash; {request.end_date}
                                            </td>
                                            <td className="px-4 py-3 text-right text-muted-foreground">
                                                {request.hours}h
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{request.created_at}</td>
                                        </tr>
                                    );
                                })}
                                {requests.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                            No leave requests found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {requests.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(requests.current_page - 1) * requests.per_page + 1} to{' '}
                            {Math.min(requests.current_page * requests.per_page, requests.total)} of{' '}
                            {requests.total} results
                        </p>
                        <LaravelPagination links={requests.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
