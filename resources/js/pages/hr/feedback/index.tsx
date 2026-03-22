import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { Plus, MessageSquare, Eye, Clock } from 'lucide-react';

type User = { id: number; name: string };

type FeedbackRequest = {
    id: number;
    subject: User | null;
    requester: User | null;
    reviewer: User | null;
    review_type: string;
    status: string;
    due_date: string | null;
    completed_at: string | null;
    created_at: string;
};

type Props = {
    requests: {
        data: FeedbackRequest[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    pendingCount: number;
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: { className: 'border-amber-500/30 text-amber-400 bg-amber-500/10', label: 'Pending' },
    completed: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Completed' },
    declined: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Declined' },
    expired: { className: 'border-slate-500/30 text-slate-400 bg-slate-500/10', label: 'Expired' },
};

const reviewTypeLabels: Record<string, string> = {
    peer: 'Peer Review',
    manager: 'Manager Review',
    direct_report: 'Direct Report',
    self: 'Self Assessment',
};

export default function FeedbackIndex({ requests, pendingCount, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="360 Feedback" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">360-Degree Feedback</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage and respond to feedback requests
                            {pendingCount > 0 && (
                                <Badge variant="secondary" className="ml-2">
                                    {pendingCount} pending
                                </Badge>
                            )}
                        </p>
                    </div>
                    {can.manage && (
                        <Button asChild size="sm">
                            <Link href="/hr/feedback/request">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Request Feedback
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Requests List */}
                <div className="grid gap-4">
                    {requests.data.map((req) => {
                        const config = statusConfig[req.status] || statusConfig.pending;
                        return (
                            <Card key={req.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div>
                                            <CardTitle className="text-base">
                                                Feedback for {req.subject?.name ?? 'Unknown'}
                                            </CardTitle>
                                            <div className="mt-1 flex items-center gap-2">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                                <Badge variant="secondary">
                                                    {reviewTypeLabels[req.review_type] || req.review_type}
                                                </Badge>
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            {req.status === 'pending' && (
                                                <Button variant="default" size="sm" asChild>
                                                    <Link href={`/hr/feedback/${req.id}/respond`}>
                                                        <MessageSquare className="mr-1.5 h-3.5 w-3.5" />
                                                        Respond
                                                    </Link>
                                                </Button>
                                            )}
                                            {can.manage && req.status === 'completed' && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/hr/feedback/summary/${req.subject?.id}`}>
                                                        <Eye className="mr-1.5 h-3.5 w-3.5" />
                                                        Summary
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-0">
                                    <div className="flex gap-6 text-sm text-muted-foreground">
                                        <span>Reviewer: {req.reviewer?.name ?? 'Unknown'}</span>
                                        <span>Requested by: {req.requester?.name ?? 'Unknown'}</span>
                                        {req.due_date && (
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3.5 w-3.5" />
                                                Due: {req.due_date}
                                            </span>
                                        )}
                                        {req.completed_at && <span>Completed: {req.completed_at}</span>}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                    {requests.data.length === 0 && (
                        <Card>
                            <CardContent className="py-12 text-center text-muted-foreground">
                                No feedback requests found.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                {requests.links?.length > 3 && (
                    <div className="flex flex-wrap gap-2">
                        {requests.links.map((l, i) => (
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
