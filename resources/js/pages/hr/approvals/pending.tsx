import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import { Check, X, ArrowUpRight, Settings } from 'lucide-react';
import { useState } from 'react';
import { Link } from '@inertiajs/react';

type ApprovalInstance = {
    id: number;
    process_type: string;
    chain_name: string;
    approvable_type: string;
    approvable_id: number;
    current_step: number;
    total_steps: number;
    status: string;
    initiated_by: string;
    initiated_at: string;
    actions_count: number;
};

type Props = {
    instances: {
        data: ApprovalInstance[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Approvals', href: '/hr/approvals/pending' },
    { title: 'Pending', href: '/hr/approvals/pending' },
];

const processTypeConfig: Record<string, { className: string }> = {
    leave: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10' },
    expense: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' },
    timesheet: { className: 'border-purple-500/30 text-purple-400 bg-purple-500/10' },
    document: { className: 'border-amber-500/30 text-amber-400 bg-amber-500/10' },
};

export default function PendingApprovals({ instances, can }: Props) {
    const [actionInstanceId, setActionInstanceId] = useState<number | null>(null);
    const [actionNotes, setActionNotes] = useState('');

    const handleAction = (instanceId: number, action: 'approved' | 'rejected') => {
        router.post(`/hr/approvals/${instanceId}/action`, {
            action,
            notes: actionNotes,
        }, {
            onSuccess: () => {
                setActionInstanceId(null);
                setActionNotes('');
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pending Approvals" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">Pending Approvals</h1>
                        <p className="text-sm text-muted-foreground">Review and action pending approval requests</p>
                    </div>
                    {can.manage && (
                        <Button asChild size="sm" variant="outline">
                            <Link href="/hr/approvals/chains">
                                <Settings className="mr-1.5 h-4 w-4" />
                                Manage Chains
                            </Link>
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Process</TableHead>
                                    <TableHead>Chain</TableHead>
                                    <TableHead>Item</TableHead>
                                    <TableHead>Progress</TableHead>
                                    <TableHead>Initiated By</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="w-48">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {instances.data.map((instance) => {
                                    const ptConfig = processTypeConfig[instance.process_type] || processTypeConfig.leave;
                                    return (
                                        <TableRow key={instance.id}>
                                            <TableCell>
                                                <Badge variant="outline" className={`capitalize ${ptConfig.className}`}>
                                                    {instance.process_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">{instance.chain_name}</TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {instance.approvable_type} #{instance.approvable_id}
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-sm">
                                                    Step {instance.current_step} of {instance.total_steps}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{instance.initiated_by}</TableCell>
                                            <TableCell className="text-muted-foreground">{instance.initiated_at}</TableCell>
                                            <TableCell>
                                                {actionInstanceId === instance.id ? (
                                                    <div className="space-y-2">
                                                        <Textarea
                                                            placeholder="Notes (optional)..."
                                                            value={actionNotes}
                                                            onChange={(e) => setActionNotes(e.target.value)}
                                                            className="h-16 text-xs"
                                                        />
                                                        <div className="flex gap-1">
                                                            <Button size="sm" variant="default" onClick={() => handleAction(instance.id, 'approved')}>
                                                                Approve
                                                            </Button>
                                                            <Button size="sm" variant="destructive" onClick={() => handleAction(instance.id, 'rejected')}>
                                                                Reject
                                                            </Button>
                                                            <Button size="sm" variant="ghost" onClick={() => setActionInstanceId(null)}>
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="flex gap-1">
                                                        <Button size="sm" variant="outline" onClick={() => setActionInstanceId(instance.id)}>
                                                            <Check className="mr-1 h-3 w-3" />
                                                            Review
                                                        </Button>
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {instances.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                            No pending approvals.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {instances.links?.length > 3 && (
                    <div className="flex flex-wrap gap-2">
                        {instances.links.map((l, i) => (
                            <Button
                                key={i}
                                variant={l.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true })}
                            >
                                <span dangerouslySetInnerHTML={{ __html: l.label }} />
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
