import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { UserPlus, CheckCircle } from 'lucide-react';
import { useState, FormEvent } from 'react';
import { type BreadcrumbItem } from '@/types';

interface Session {
    id: number;
    session_date: string;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    facilitator: string | null;
    max_participants: number | null;
    status: string;
}

interface Enrollment {
    id: number;
    status: string;
    enrolled_at: string;
    completed_at: string | null;
    score: string | null;
    notes: string | null;
    user: { id: number; name: string };
}

interface Course {
    id: number;
    title: string;
    code: string;
    description: string | null;
    category: string | null;
    delivery_method: string;
    duration_hours: string;
    provider: string | null;
    cost: string | null;
    is_mandatory: boolean;
    is_active: boolean;
    max_participants: number | null;
    sessions: Session[];
    enrollments: Enrollment[];
}

interface UserItem {
    id: number;
    name: string;
}

interface Props {
    course: Course;
    users: UserItem[];
    can: { manage: boolean; enroll: boolean };
}

const deliveryLabels: Record<string, string> = {
    online: 'Online',
    in_person: 'In Person',
    blended: 'Blended',
    self_paced: 'Self-Paced',
};

const statusColors: Record<string, string> = {
    enrolled: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-yellow-100 text-yellow-800',
    completed: 'bg-green-100 text-green-800',
    withdrawn: 'bg-slate-100 text-slate-800',
    failed: 'bg-red-100 text-red-800',
    scheduled: 'bg-blue-100 text-blue-800',
    cancelled: 'bg-red-100 text-red-800',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const formatCurrency = (value: string | null) => {
    if (!value) return '-';
    const num = parseFloat(value);
    if (Number.isNaN(num)) return value;
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(num);
};

export default function CourseDetail({ course, users, can }: Props) {
    const [enrollOpen, setEnrollOpen] = useState(false);
    const [completeOpen, setCompleteOpen] = useState(false);
    const [selectedEnrollment, setSelectedEnrollment] = useState<Enrollment | null>(null);
    const [enrollForm, setEnrollForm] = useState({ user_id: '', session_id: '', notes: '' });
    const [completeForm, setCompleteForm] = useState({ score: '', notes: '' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Training', href: '/hr/training/catalog' },
        { title: course.title, href: `/hr/training/courses/${course.id}` },
    ];

    const submitEnroll = (e: FormEvent) => {
        e.preventDefault();
        router.post('/hr/training/enroll', {
            ...enrollForm,
            course_id: course.id,
            session_id: enrollForm.session_id || null,
        }, {
            onSuccess: () => {
                setEnrollOpen(false);
                setEnrollForm({ user_id: '', session_id: '', notes: '' });
            },
        });
    };

    const openComplete = (enrollment: Enrollment) => {
        setSelectedEnrollment(enrollment);
        setCompleteForm({ score: '', notes: '' });
        setCompleteOpen(true);
    };

    const submitComplete = (e: FormEvent) => {
        e.preventDefault();
        if (!selectedEnrollment) return;
        router.post(`/hr/training/enrollments/${selectedEnrollment.id}/complete`, {
            ...completeForm,
            score: completeForm.score || null,
        }, {
            onSuccess: () => setCompleteOpen(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={course.title} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-lg font-semibold">{course.title}</h1>
                            <Badge variant="outline" className="font-mono text-xs">{course.code}</Badge>
                            {course.is_mandatory && <Badge variant="destructive">Mandatory</Badge>}
                        </div>
                        <div className="mt-1 text-sm text-slate-500">
                            {deliveryLabels[course.delivery_method] || course.delivery_method} &middot; {course.duration_hours}h
                            {course.provider && ` &middot; ${course.provider}`}
                        </div>
                    </div>

                    {can.enroll && (
                        <Button size="sm" onClick={() => setEnrollOpen(true)}>
                            <UserPlus className="mr-1.5 h-4 w-4" />
                            Enroll Employee
                        </Button>
                    )}
                </div>

                {/* Course Info */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Course Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        {course.description && <p>{course.description}</p>}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <span className="text-slate-500">Category</span>
                                <p className="mt-0.5">{course.category || '-'}</p>
                            </div>
                            <div>
                                <span className="text-slate-500">Cost</span>
                                <p className="mt-0.5">{formatCurrency(course.cost)}</p>
                            </div>
                            <div>
                                <span className="text-slate-500">Max Participants</span>
                                <p className="mt-0.5">{course.max_participants ?? 'Unlimited'}</p>
                            </div>
                            <div>
                                <span className="text-slate-500">Status</span>
                                <p className="mt-0.5">
                                    <Badge variant={course.is_active ? 'default' : 'secondary'}>
                                        {course.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Sessions */}
                {course.sessions?.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Sessions</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Time</TableHead>
                                        <TableHead>Location</TableHead>
                                        <TableHead>Facilitator</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {course.sessions.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell>{formatDate(session.session_date)}</TableCell>
                                            <TableCell>
                                                {session.start_time ? `${session.start_time} - ${session.end_time || ''}` : '-'}
                                            </TableCell>
                                            <TableCell>{session.location || '-'}</TableCell>
                                            <TableCell>{session.facilitator || '-'}</TableCell>
                                            <TableCell>
                                                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[session.status] ?? ''}`}>
                                                    {session.status}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {/* Enrollments */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Enrollments ({course.enrollments?.length ?? 0})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Enrolled</TableHead>
                                    <TableHead>Completed</TableHead>
                                    <TableHead>Score</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && <TableHead className="w-24"></TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {course.enrollments?.map((enrollment) => (
                                    <TableRow key={enrollment.id}>
                                        <TableCell className="font-medium">{enrollment.user?.name}</TableCell>
                                        <TableCell className="text-sm">{formatDateTime(enrollment.enrolled_at)}</TableCell>
                                        <TableCell className="text-sm">{formatDateTime(enrollment.completed_at)}</TableCell>
                                        <TableCell>{enrollment.score ? `${enrollment.score}%` : '-'}</TableCell>
                                        <TableCell>
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[enrollment.status] ?? ''}`}>
                                                {enrollment.status}
                                            </span>
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                {enrollment.status !== 'completed' && enrollment.status !== 'withdrawn' && (
                                                    <Button size="sm" variant="outline" onClick={() => openComplete(enrollment)}>
                                                        <CheckCircle className="mr-1 h-3 w-3" />
                                                        Complete
                                                    </Button>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                                {!course.enrollments?.length && (
                                    <TableRow>
                                        <TableCell colSpan={can.manage ? 6 : 5} className="py-8 text-center text-sm text-slate-500">
                                            No enrollments yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {/* Enroll Dialog */}
            <Dialog open={enrollOpen} onOpenChange={setEnrollOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Enroll Employee</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEnroll} className="space-y-4">
                        <div>
                            <Label>Employee</Label>
                            <Select value={enrollForm.user_id} onValueChange={(val) => setEnrollForm((p) => ({ ...p, user_id: val }))}>
                                <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                                <SelectContent>
                                    {users.map((u) => (
                                        <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        {course.sessions?.length > 0 && (
                            <div>
                                <Label>Session (optional)</Label>
                                <Select value={enrollForm.session_id || 'none'} onValueChange={(val) => setEnrollForm((p) => ({ ...p, session_id: val === 'none' ? '' : val }))}>
                                    <SelectTrigger><SelectValue placeholder="No specific session" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No specific session</SelectItem>
                                        {course.sessions.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {formatDate(s.session_date)} {s.start_time || ''} {s.location ? `- ${s.location}` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div>
                            <Label>Notes</Label>
                            <Textarea value={enrollForm.notes} onChange={(e) => setEnrollForm((p) => ({ ...p, notes: e.target.value }))} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setEnrollOpen(false)}>Cancel</Button>
                            <Button type="submit">Enroll</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Complete Enrollment Dialog */}
            <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Complete Enrollment: {selectedEnrollment?.user?.name}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitComplete} className="space-y-4">
                        <div>
                            <Label>Score (%)</Label>
                            <Input type="number" step="0.01" min="0" max="100" value={completeForm.score} onChange={(e) => setCompleteForm((p) => ({ ...p, score: e.target.value }))} />
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea value={completeForm.notes} onChange={(e) => setCompleteForm((p) => ({ ...p, notes: e.target.value }))} />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setCompleteOpen(false)}>Cancel</Button>
                            <Button type="submit">Mark Completed</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
