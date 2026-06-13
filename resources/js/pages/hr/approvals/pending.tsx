import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCircle2, Settings } from 'lucide-react';
import { useState } from 'react';

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
    leave: {
        className: 'border-status-info/30 text-status-info bg-status-info',
    },
    expense: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
    },
    timesheet: { className: 'border-primary/30 text-primary bg-primary/10' },
    document: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
    },
};

export default function PendingApprovals({ instances, can }: Props) {
    const [actionInstanceId, setActionInstanceId] = useState<number | null>(
        null,
    );
    const [actionNotes, setActionNotes] = useState('');

    const handleAction = (
        instanceId: number,
        action: 'approved' | 'rejected',
    ) => {
        router.post(
            `/hr/approvals/${instanceId}/action`,
            {
                action,
                notes: actionNotes,
            },
            {
                onSuccess: () => {
                    setActionInstanceId(null);
                    setActionNotes('');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pending Approvals" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={CheckCircle2}
                        title="Pending Approvals"
                        description="Review and action pending approval requests."
                        stats={[
                            { label: 'Pending', value: instances.data.length },
                        ]}
                        actions={
                            can.manage ? (
                                <Button
                                    asChild
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/hr/approvals/chains">
                                        <Settings className="mr-1.5 h-4 w-4" />
                                        Manage Chains
                                    </Link>
                                </Button>
                            ) : null
                        }
                    />
                }
            >
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
                                    <TableHead className="w-48">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {instances.data.map((instance) => {
                                    const ptConfig =
                                        processTypeConfig[
                                            instance.process_type
                                        ] || processTypeConfig.leave;
                                    return (
                                        <TableRow key={instance.id}>
                                            <TableCell>
                                                <Badge
                                                    variant="outline"
                                                    className={`capitalize ${ptConfig.className}`}
                                                >
                                                    {instance.process_type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {instance.chain_name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {instance.approvable_type} #
                                                {instance.approvable_id}
                                            </TableCell>
                                            <TableCell>
                                                <span className="text-sm">
                                                    Step {instance.current_step}{' '}
                                                    of {instance.total_steps}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {instance.initiated_by}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {instance.initiated_at}
                                            </TableCell>
                                            <TableCell>
                                                {actionInstanceId ===
                                                instance.id ? (
                                                    <div className="space-y-2">
                                                        <Textarea
                                                            placeholder="Notes (optional)..."
                                                            value={actionNotes}
                                                            onChange={(e) =>
                                                                setActionNotes(
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="h-16 text-xs"
                                                        />
                                                        <div className="flex gap-1">
                                                            <Button
                                                                size="sm"
                                                                variant="default"
                                                                onClick={() =>
                                                                    handleAction(
                                                                        instance.id,
                                                                        'approved',
                                                                    )
                                                                }
                                                            >
                                                                Approve
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    handleAction(
                                                                        instance.id,
                                                                        'rejected',
                                                                    )
                                                                }
                                                            >
                                                                Reject
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setActionInstanceId(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="flex gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setActionInstanceId(
                                                                    instance.id,
                                                                )
                                                            }
                                                        >
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
                                        <TableCell
                                            colSpan={7}
                                            className="py-8 text-center text-muted-foreground"
                                        >
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
                    <LaravelPagination links={instances.links} />
                )}
            </PageLayout>
        </AppLayout>
    );
}
