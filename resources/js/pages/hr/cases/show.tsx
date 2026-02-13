import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, User, Clock, MessageSquare, AlertTriangle, Plus, XCircle, Calendar } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type CaseEvent = {
    id: number;
    event_type: string;
    description: string;
    occurred_at: string;
    user: { id: number; name: string } | null;
};

type DisciplinaryAction = {
    id: number;
    action_type: string;
    description: string;
    issued_at: string;
    expires_at: string | null;
    status: string;
};

type HrCase = {
    id: number;
    case_number: string;
    category: string;
    status: string;
    priority: string;
    description: string;
    opened_at: string;
    closed_at: string | null;
    closure_reason: string | null;
    subject_user: { id: number; name: string; email?: string };
    opened_by: { id: number; name: string } | null;
    assigned_to: { id: number; name: string } | null;
    events: CaseEvent[];
    disciplinaryActions: DisciplinaryAction[];
};

type Props = {
    hrCase: HrCase;
    can: { manage: boolean };
};

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'open':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'under_investigation':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'pending_action':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'resolved':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'closed':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'appealed':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getCategoryColor = (category: string) => {
    switch (category) {
        case 'disciplinary':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'grievance':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'capability':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'absence':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'conduct':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'welfare':
            return 'bg-green-100 text-green-800 border-green-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getPriorityColor = (priority: string) => {
    switch (priority) {
        case 'critical':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'high':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getEventTypeColor = (type: string) => {
    switch (type) {
        case 'note':
            return 'bg-blue-500';
        case 'meeting':
            return 'bg-green-500';
        case 'decision':
            return 'bg-purple-500';
        case 'escalation':
            return 'bg-red-500';
        case 'outcome':
            return 'bg-amber-500';
        default:
            return 'bg-slate-400';
    }
};

const getActionStatusColor = (status: string) => {
    switch (status) {
        case 'active':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'expired':
            return 'bg-slate-100 text-slate-500 border-slate-200';
        case 'appealed':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'overturned':
            return 'bg-green-100 text-green-800 border-green-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

export default function HrCaseShow({ hrCase, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
    ];

    const isClosed = hrCase.status === 'closed' || hrCase.status === 'resolved';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Case ${hrCase.case_number}`} />

            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <Briefcase className="h-5 w-5 text-slate-500" />
                            {hrCase.case_number}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={getStatusColor(hrCase.status)}>
                                {hrCase.status.replace(/_/g, ' ')}
                            </Badge>
                            <Badge className={getCategoryColor(hrCase.category)}>
                                {hrCase.category.replace(/_/g, ' ')}
                            </Badge>
                            <Badge className={getPriorityColor(hrCase.priority)}>
                                {hrCase.priority} priority
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href="/hr/cases" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back to list
                        </Link>
                        {can.manage && !isClosed && (
                            <>
                                <Link href={`/hr/cases/${hrCase.id}/events/create`}>
                                    <Button size="sm" variant="outline">
                                        <Calendar className="mr-1.5 h-4 w-4" />
                                        Add Event
                                    </Button>
                                </Link>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="text-red-600 border-red-200 hover:bg-red-50"
                                    onClick={() => {
                                        const outcome = prompt('Please enter the outcome summary:');
                                        if (outcome) {
                                            const outcomeType = confirm('Was this resolved in favor of the employee?') ? 'resolved' : 'no_action';
                                            router.post(`/hr/cases/${hrCase.id}/close`, {
                                                outcome,
                                                outcome_type: outcomeType,
                                            });
                                        }
                                    }}
                                >
                                    <XCircle className="mr-1.5 h-4 w-4" />
                                    Close Case
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Briefcase className="h-5 w-5 text-blue-500" />
                                Case Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm text-slate-700 whitespace-pre-wrap">
                                {hrCase.description}
                            </div>
                            <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div className="text-xs text-slate-500">Opened</div>
                                    <div className="font-medium">{formatDate(hrCase.opened_at)}</div>
                                </div>
                                <div>
                                    <div className="text-xs text-slate-500">Closed</div>
                                    <div className="font-medium">{formatDate(hrCase.closed_at)}</div>
                                </div>
                            </div>
                            {hrCase.closure_reason && (
                                <div className="text-sm">
                                    <div className="text-xs text-slate-500">Closure Reason</div>
                                    <div className="text-slate-700">{hrCase.closure_reason}</div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-5 w-5 text-green-500" />
                                People
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Subject</div>
                                <div className="font-medium">{hrCase.subject_user.name}</div>
                                {hrCase.subject_user.email && (
                                    <div className="text-xs text-slate-400">{hrCase.subject_user.email}</div>
                                )}
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Opened By</div>
                                <div className="font-medium">{hrCase.opened_by?.name || 'Unknown'}</div>
                            </div>
                            <div className="text-sm">
                                <div className="text-xs text-slate-500">Assigned To</div>
                                <div className="font-medium">{hrCase.assigned_to?.name || 'Unassigned'}</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-5 w-5 text-purple-500" />
                            Timeline
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {hrCase.events.length > 0 ? (
                            <div className="relative space-y-4 pl-6">
                                <div className="absolute left-[9px] top-2 bottom-2 w-0.5 bg-slate-200" />
                                {hrCase.events.map((event) => (
                                    <div key={event.id} className="relative">
                                        <div className={`absolute -left-6 top-1.5 h-3 w-3 rounded-full ${getEventTypeColor(event.event_type)}`} />
                                        <div className="rounded-md border p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <Badge variant="outline" className="mb-1 capitalize">
                                                        {event.event_type.replace(/_/g, ' ')}
                                                    </Badge>
                                                    <div className="text-sm text-slate-700">{event.description}</div>
                                                </div>
                                                <div className="shrink-0 text-xs text-slate-500">
                                                    {formatDateTime(event.occurred_at)}
                                                </div>
                                            </div>
                                            {event.user && (
                                                <div className="mt-1 text-xs text-slate-400">
                                                    By {event.user.name}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-6 text-center text-sm text-slate-500">
                                No events recorded yet.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {(hrCase.disciplinaryActions.length > 0 || can.manage) && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-5 w-5 text-red-500" />
                                    Disciplinary Actions
                                </CardTitle>
                                {can.manage && !isClosed && (
                                    <Link href={`/hr/cases/${hrCase.id}/disciplinary/create`}>
                                        <Button size="sm" variant="outline">
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add Action
                                        </Button>
                                    </Link>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Issued</TableHead>
                                        <TableHead>Expires</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {hrCase.disciplinaryActions.map((action) => (
                                        <TableRow key={action.id}>
                                            <TableCell className="font-medium capitalize">
                                                {action.action_type.replace(/_/g, ' ')}
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate text-sm text-slate-600">
                                                {action.description}
                                            </TableCell>
                                            <TableCell>{formatDate(action.issued_at)}</TableCell>
                                            <TableCell>{formatDate(action.expires_at)}</TableCell>
                                            <TableCell>
                                                <Badge className={getActionStatusColor(action.status)}>
                                                    {action.status}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {!hrCase.disciplinaryActions.length && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-6 text-center text-sm text-slate-500">
                                                No disciplinary actions recorded.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
