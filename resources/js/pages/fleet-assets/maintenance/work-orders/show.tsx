import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Calendar,
    DollarSign,
    Loader2,
    User,
    Wrench,
} from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/fleet-utils';


type Props = {
    work_order: {
        id: number;
        title: string;
        description: string | null;
        asset: { id: number; name: string; asset_tag: string | null; category: string | null; status: string | null } | null;
        priority: string;
        status: string;
        reported_by: { id: number; name: string; email: string | null } | null;
        assigned_to: { id: number; name: string; email: string | null } | null;
        due_at: string | null;
        completed_at: string | null;
        estimated_cost: number | null;
        actual_cost: number | null;
        notes: string | null;
        created_at: string | null;
        updated_at: string | null;
    };
};

const priorityBannerColors: Record<string, string> = {
    critical: 'bg-red-100 border-red-300 text-red-900 dark:bg-red-950/40 dark:border-red-700 dark:text-red-100',
    high: 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200',
    medium: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-200',
    low: 'bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/30 dark:border-blue-800 dark:text-blue-200',
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'completed': return 'default';
        case 'in_progress': return 'default';
        case 'open': return 'outline';
        default: return 'secondary';
    }
}

function priorityVariant(priority: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (priority) {
        case 'critical': return 'destructive';
        case 'high': return 'destructive';
        case 'medium': return 'default';
        default: return 'secondary';
    }
}

export default function WorkOrderShow({ work_order }: Props) {
    const wo = work_order ?? {} as Props['work_order'];

    const updateForm = useForm({
        status: wo.status ?? 'open',
        notes: '',
        actual_cost: wo.actual_cost != null ? String(wo.actual_cost) : '',
    });

    const [showCancelConfirm, setShowCancelConfirm] = useState(false);

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (updateForm.data.status === 'cancelled' && wo.status !== 'cancelled') {
            setShowCancelConfirm(true);
            return;
        }
        updateForm.put(`/fleet-assets/maintenance/work-orders/${wo.id}`, {
            preserveScroll: true,
        });
    };

    const confirmCancel = () => {
        updateForm.put(`/fleet-assets/maintenance/work-orders/${wo.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Work Orders', href: '/fleet-assets/maintenance/work-orders' },
                { title: wo.title ?? 'Work Order', href: '#' },
            ]}
        >
            <Head title={`Work Order: ${wo.title ?? ''}`} />
            <PageShell>
                <PageHeader
                    title={wo.title ?? 'Work Order'}
                    backHref="/fleet-assets/maintenance/work-orders"
                    backLabel="Back to Work Orders"
                />

                {/* Priority Banner */}
                <div className={cn('rounded-lg border px-5 py-4', priorityBannerColors[wo.priority] ?? priorityBannerColors.medium)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <Wrench className="h-5 w-5" />
                            <span className="font-medium">{wo.title}</span>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant={statusVariant(wo.status ?? '')} className="text-xs">{(wo.status ?? '').replace(/_/g, ' ')}</Badge>
                            <Badge variant={priorityVariant(wo.priority ?? '')} className="text-xs capitalize">{wo.priority}</Badge>
                        </div>
                    </div>
                </div>

                {/* 2-Column: Details (left), Status/Cost (right) */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left: Details */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Work Order Details</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-3 text-sm">
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Asset</dt>
                                        <dd className="mt-1">
                                            {wo.asset ? (
                                                <Link href={`/fleet-assets/assets/${wo.asset.id}`} className="text-primary hover:underline font-medium">
                                                    {wo.asset.name}{wo.asset.asset_tag ? ` (${wo.asset.asset_tag})` : ''}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">---</span>
                                            )}
                                        </dd>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <User className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Reported By</dt>
                                                <dd className="font-medium">{wo.reported_by?.name ?? 'Unknown'}</dd>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <User className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Assigned To</dt>
                                                <dd className="font-medium">{wo.assigned_to?.name ?? 'Unassigned'}</dd>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                            <Calendar className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <dt className="text-xs text-muted-foreground">Due Date</dt>
                                                <dd className="font-medium">{wo.due_at ? formatDate(wo.due_at) : '---'}</dd>
                                            </div>
                                        </div>
                                        {wo.completed_at && (
                                            <div className="flex items-center gap-2 rounded-md bg-muted/40 p-3">
                                                <Calendar className="h-4 w-4 text-green-600" />
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">Completed</dt>
                                                    <dd className="font-medium">{formatDate(wo.completed_at)}</dd>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Created</dt>
                                        <dd className="mt-1 font-medium">{wo.created_at ? formatDate(wo.created_at) : '---'}</dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        {wo.description && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Description</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm whitespace-pre-wrap leading-relaxed text-muted-foreground">{wo.description}</p>
                                </CardContent>
                            </Card>
                        )}

                        {wo.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">{wo.notes}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right: Cost + Status Update */}
                    <div className="space-y-4">
                        {/* Cost Info */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <DollarSign className="h-4 w-4" />
                                    Cost Information
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-md bg-muted/40 p-3 text-center">
                                        <div className="text-xs text-muted-foreground">Estimated</div>
                                        <div className="mt-1 text-lg font-bold">
                                            {wo.estimated_cost != null ? `{formatCurrency((wo.estimated_cost))}` : '---'}
                                        </div>
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-3 text-center">
                                        <div className="text-xs text-muted-foreground">Actual</div>
                                        <div className="mt-1 text-lg font-bold">
                                            {wo.actual_cost != null ? `{formatCurrency((wo.actual_cost))}` : '---'}
                                        </div>
                                    </div>
                                </div>
                                {wo.estimated_cost != null && wo.actual_cost != null && (
                                    <div className={cn(
                                        'mt-3 rounded-md border p-2 text-center text-sm font-medium',
                                        wo.actual_cost <= wo.estimated_cost
                                            ? 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/20 dark:text-green-400'
                                            : 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-400'
                                    )}>
                                        {wo.actual_cost <= wo.estimated_cost
                                            ? `{formatCurrency((wo.estimated_cost - wo.actual_cost))} under budget`
                                            : `{formatCurrency((wo.actual_cost - wo.estimated_cost))} over budget`
                                        }
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Update Form */}
                        <Card className="border-2 border-dashed">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Update Status</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleUpdate} className="space-y-3">
                                    <div>
                                        <label className="text-xs font-medium text-muted-foreground">Status</label>
                                        <Select value={updateForm.data.status} onValueChange={(v) => updateForm.setData('status', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="open">Open</SelectItem>
                                                <SelectItem value="in_progress">In Progress</SelectItem>
                                                <SelectItem value="on_hold">On Hold</SelectItem>
                                                <SelectItem value="completed">Completed</SelectItem>
                                                <SelectItem value="cancelled">Cancelled</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {updateForm.errors.status && <p className="mt-1 text-xs text-destructive">{updateForm.errors.status}</p>}
                                    </div>
                                    <div>
                                        <label className="text-xs font-medium text-muted-foreground">Actual Cost ($)</label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={updateForm.data.actual_cost}
                                            onChange={(e) => updateForm.setData('actual_cost', e.target.value)}
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <div>
                                        <label className="text-xs font-medium text-muted-foreground">Notes</label>
                                        <textarea
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={3}
                                            value={updateForm.data.notes}
                                            onChange={(e) => updateForm.setData('notes', e.target.value)}
                                            placeholder="Add notes..."
                                        />
                                    </div>
                                    <Button type="submit" disabled={updateForm.processing} className="w-full">
                                        {updateForm.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                        Update Work Order
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <ConfirmDialog
                    open={showCancelConfirm}
                    onClose={() => setShowCancelConfirm(false)}
                    onConfirm={confirmCancel}
                    title="Cancel Work Order"
                    description="Are you sure you want to cancel this work order? This action cannot be undone."
                    confirmText="Cancel Work Order"
                />
            </PageShell>
        </AppLayout>
    );
}
