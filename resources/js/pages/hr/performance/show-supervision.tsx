import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Calendar, User, CheckCircle, Clock, FileText } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type SupervisionNote = {
    id: number;
    employee: {
        id: number;
        name: string;
    };
    supervisor: {
        id: number;
        name: string;
    };
    session_date: string;
    session_type: string;
    duration_minutes: number | null;
    topics_discussed: string | null;
    actions_agreed: string[] | null;
    next_session_date: string | null;
    employee_acknowledged: boolean;
    employee_acknowledged_at: string | null;
    is_visible_to_employee: boolean;
    created_at: string;
};

type Props = {
    note: SupervisionNote;
    can: { manage: boolean };
};

export default function ShowSupervision({ note, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance & Supervision', href: '/hr/performance' },
        { title: 'Supervision Note', href: `/hr/performance/supervision/${note.id}` },
    ];

    const formatDate = (value?: string | null) => {
        if (!value) return 'Not set';
        const d = new Date(value);
        return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    const getSessionTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            one_to_one: 'One-to-One',
            supervision: 'Supervision',
            review: 'Review',
            check_in: 'Check-in',
            probation: 'Probation Review',
            other: 'Other',
        };
        return labels[type] || type.replace(/_/g, ' ');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Supervision Note - ${note.employee.name}`} />

            <div className="space-y-6 max-w-4xl">
                <div className="flex items-center gap-4">
                    <Link href="/hr/performance">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold">Supervision Note</h1>
                        <p className="text-muted-foreground">
                            {getSessionTypeLabel(note.session_type)} with {note.employee.name}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Badge variant={note.employee_acknowledged ? 'default' : 'secondary'}>
                        {note.employee_acknowledged ? (
                            <><CheckCircle className="mr-1 h-3 w-3" /> Acknowledged</>
                        ) : (
                            'Pending Acknowledgment'
                        )}
                    </Badge>
                    {!note.is_visible_to_employee && (
                        <Badge variant="outline">Not Visible to Employee</Badge>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-4 w-4" />
                                Participants
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-sm text-muted-foreground">Staff Member</div>
                                <div className="font-medium">{note.employee.name}</div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Supervisor</div>
                                <div className="font-medium">{note.supervisor.name}</div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-4 w-4" />
                                Session Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <div className="text-sm text-muted-foreground">Date</div>
                                    <div className="font-medium">{formatDate(note.session_date)}</div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">Duration</div>
                                    <div className="font-medium">
                                        {note.duration_minutes ? `${note.duration_minutes} min` : 'Not recorded'}
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">Type</div>
                                <div className="font-medium">{getSessionTypeLabel(note.session_type)}</div>
                            </div>
                            {note.next_session_date && (
                                <div>
                                    <div className="text-sm text-muted-foreground">Next Session</div>
                                    <div className="font-medium">{formatDate(note.next_session_date)}</div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-4 w-4" />
                            Discussion Notes
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {note.topics_discussed ? (
                            <div className="whitespace-pre-wrap text-sm">{note.topics_discussed}</div>
                        ) : (
                            <p className="text-muted-foreground italic">No discussion notes recorded</p>
                        )}
                    </CardContent>
                </Card>

                {note.actions_agreed && note.actions_agreed.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-4 w-4" />
                                Actions Agreed
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                {note.actions_agreed.map((action, index) => (
                                    <li key={index} className="flex items-start gap-2 text-sm">
                                        <span className="font-medium text-muted-foreground">{index + 1}.</span>
                                        <span>{action}</span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {note.employee_acknowledged && note.employee_acknowledged_at && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CheckCircle className="h-4 w-4 text-green-500" />
                                Employee Acknowledgment
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Acknowledged on {formatDate(note.employee_acknowledged_at)}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <div className="flex items-center justify-end gap-4 pt-4">
                    <Link href="/hr/performance">
                        <Button variant="outline">Back to List</Button>
                    </Link>
                    {can.manage && (
                        <Link href={`/hr/performance/supervision/${note.id}/edit`}>
                            <Button>Edit Note</Button>
                        </Link>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
