import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Shield, AlertTriangle, Clock, FileText } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        request_type: string | null;
        status: string | null;
        overdue: string | null;
    };
    requests: any;
    stats?: {
        open: number;
        overdue: number;
        completed_30_days: number;
        pending_verification: number;
    };
};

export default function DataSubjectRequests({ filters, requests, stats }: Props) {
    const ANY = '__any__';
    const STATUS_LABELS: Record<string, string> = {
        received: 'received',
        under_review: 'under review',
        identity_verification: 'pending verification',
        in_progress: 'in progress',
        completed: 'completed',
        rejected: 'rejected',
        withdrawn: 'withdrawn',
    };
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/privacy/requests', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800 border-green-200';
            case 'in_progress':
                return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'received':
            case 'under_review':
            case 'identity_verification':
                return 'bg-yellow-100 text-yellow-800 border-yellow-200';
            case 'rejected':
                return 'bg-red-100 text-red-800 border-red-200';
            case 'overdue':
                return 'bg-orange-100 text-orange-800 border-orange-200';
            default:
                return 'bg-slate-100 text-slate-800 border-slate-200';
        }
    };

    const getRequestTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            'access': 'Right to Access (Art. 15)',
            'rectification': 'Right to Rectification (Art. 16)',
            'erasure': 'Right to Erasure (Art. 17)',
            'restriction': 'Right to Restriction (Art. 18)',
            'portability': 'Right to Portability (Art. 20)',
            'objection': 'Right to Object (Art. 21)',
            'automated_decision': 'Automated Decision Rights (Art. 22)',
        };
        return labels[type] || type;
    };

    const getDaysRemaining = (dueDate: string) => {
        const due = new Date(dueDate);
        const today = new Date();
        const diff = Math.ceil((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
        return diff;
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Subject Requests', href: '/privacy/requests' }
        ]}>
            <Head title="Data Subject Requests" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Data Subject Requests"
                    description="GDPR Article 15-22 compliance — 30-day response deadline"
                    icon={<FileText className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Open', value: stats.open },
                        { label: 'Overdue', value: stats.overdue },
                        { label: 'Completed (30d)', value: stats.completed_30_days },
                        { label: 'Pending', value: stats.pending_verification },
                    ] : undefined}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/privacy/dashboard">
                                <Button variant="outline" size="sm">Privacy Dashboard</Button>
                            </Link>
                            <Link href="/privacy/breaches">
                                <Button variant="outline" size="sm">Data Breaches</Button>
                            </Link>
                            {can.processRequests && (
                                <Link href="/privacy/requests/create">
                                    <Button size="sm">
                                        <FileText className="mr-1.5 h-4 w-4" />
                                        New Request
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search by reference or requester name"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Request Type</Label>
                            <Select
                                value={filters.request_type ?? ANY}
                                onValueChange={(v) => onFilter({ request_type: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="access">Access (Art. 15)</SelectItem>
                                    <SelectItem value="rectification">Rectification (Art. 16)</SelectItem>
                                    <SelectItem value="erasure">Erasure (Art. 17)</SelectItem>
                                    <SelectItem value="restriction">Restriction (Art. 18)</SelectItem>
                                    <SelectItem value="portability">Portability (Art. 20)</SelectItem>
                                    <SelectItem value="objection">Objection (Art. 21)</SelectItem>
                                    <SelectItem value="automated_decision">Automated Decision (Art. 22)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                    <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['received', 'under_review', 'identity_verification', 'in_progress', 'completed', 'rejected', 'withdrawn'].map((s) => (
                                        <SelectItem key={s} value={s}>{STATUS_LABELS[s]}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {requests.data.map((request: any) => {
                        const daysRemaining = getDaysRemaining(request.extended_due_date || request.due_date);
                        const isOverdue = daysRemaining < 0;
                        const isDueSoon = daysRemaining >= 0 && daysRemaining <= 7;

                        return (
                            <Card key={request.id}>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 font-semibold">
                                                    {isOverdue && <AlertTriangle className="h-4 w-4 text-red-500" />}
                                                    {request.reference_number}
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <Badge className={getStatusColor(request.status)}>
                                                        {STATUS_LABELS[request.status] ?? request.status.replace(/_/g, ' ')}
                                                    </Badge>
                                                    <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
                                                        {getRequestTypeLabel(request.request_type)}
                                                    </Badge>
                                                    {isOverdue && (
                                                        <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                                                            <AlertTriangle className="mr-1 h-3 w-3" />
                                                            {Math.abs(daysRemaining)} days overdue
                                                        </Badge>
                                                    )}
                                                    {!isOverdue && isDueSoon && (
                                                        <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700">
                                                            <Clock className="mr-1 h-3 w-3" />
                                                            {daysRemaining} days remaining
                                                        </Badge>
                                                    )}
                                                    {request.identity_verified && (
                                                        <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                                            Identity Verified
                                                        </Badge>
                                                    )}
                                                    {request.extension_requested && (
                                                        <Badge variant="outline" className="border-purple-200 bg-purple-50 text-purple-700">
                                                            Extended Deadline
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="mt-2 text-xs text-slate-500">
                                                    Requester: {request.subject_name}
                                                    {request.received_at && ` • Received: ${new Date(request.received_at).toLocaleDateString()}`}
                                                    {request.due_date && ` • Due: ${new Date(request.extended_due_date || request.due_date).toLocaleDateString()}`}
                                                    {request.assigned_to && ` • Assigned: ${request.assigned_to.name}`}
                                                </div>
                                            </div>
                                            <Link href={`/privacy/requests/${request.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                                View
                                            </Link>
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                            </Card>
                        );
                    })}
                    {!requests.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No data subject requests found.
                        </div>
                    )}
                </div>

                {requests?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {requests.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
