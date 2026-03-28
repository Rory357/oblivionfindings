import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Users, Building2, CalendarDays, MessageSquare } from 'lucide-react';

type Representative = {
    id: number;
    user: { id: number; name: string } | null;
    site: { id: number; name: string } | null;
    work_group: string | null;
    election_method: string | null;
    elected_date: string | null;
    training_days: number;
    status: string;
};

type Meeting = {
    id: number;
    committee_name: string;
    meeting_date: string;
    location: string | null;
    status: string;
    action_items_count: number;
};

type Consultation = {
    id: number;
    title: string;
    consultation_type: string;
    consultation_date: string;
    workers_consulted: number;
    status: string;
};

type Props = {
    stats: {
        active_reps: number;
        active_committees: number;
        meetings_this_month: number;
        open_consultations: number;
    };
    representatives: Representative[];
    meetings: Meeting[];
    consultations: Consultation[];
    sites: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
};

const statusColor = (status: string) => {
    switch (status) {
        case 'active':
            return 'bg-green-100 text-green-800';
        case 'completed':
        case 'closed':
            return 'bg-slate-100 text-slate-800';
        case 'scheduled':
        case 'pending':
            return 'bg-blue-100 text-blue-800';
        case 'in_progress':
        case 'open':
            return 'bg-amber-100 text-amber-800';
        case 'inactive':
        case 'expired':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-800';
    }
};

export default function WorkerParticipationIndex({
    stats,
    representatives,
    meetings,
    consultations,
    sites,
    staff,
}: Props) {
    const [repOpen, setRepOpen] = useState(false);
    const [meetingOpen, setMeetingOpen] = useState(false);
    const [consultationOpen, setConsultationOpen] = useState(false);

    const repForm = useForm({
        user_id: '',
        site_id: '',
        work_group: '',
        election_method: 'elected',
        elected_date: '',
        training_days: 0,
    });

    const meetingForm = useForm({
        committee_name: '',
        meeting_date: '',
        location: '',
        agenda: '',
    });

    const consultationForm = useForm({
        title: '',
        consultation_type: 'general',
        consultation_date: '',
        description: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Worker Participation', href: '/health-safety/worker-participation' },
            ]}
        >
            <Head title="Worker Participation" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Worker Participation</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage H&S representatives, committee meetings, and worker consultations
                        </div>
                    </div>
                </div>

                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-blue-50 p-2">
                                <Users className="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.active_reps}</div>
                                <div className="text-xs text-slate-500">Active Reps</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-green-50 p-2">
                                <Building2 className="h-5 w-5 text-green-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.active_committees}</div>
                                <div className="text-xs text-slate-500">Active Committees</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-amber-50 p-2">
                                <CalendarDays className="h-5 w-5 text-amber-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.meetings_this_month}</div>
                                <div className="text-xs text-slate-500">Meetings This Month</div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 pt-6">
                            <div className="rounded-lg bg-purple-50 p-2">
                                <MessageSquare className="h-5 w-5 text-purple-600" />
                            </div>
                            <div>
                                <div className="text-2xl font-bold">{stats.open_consultations}</div>
                                <div className="text-xs text-slate-500">Open Consultations</div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Tabbed Sections */}
                <TabsRoot defaultValue="representatives">
                    <TabsList>
                        <TabsTrigger value="representatives">H&S Representatives</TabsTrigger>
                        <TabsTrigger value="meetings">Committee Meetings</TabsTrigger>
                        <TabsTrigger value="consultations">Consultations</TabsTrigger>
                    </TabsList>

                    {/* Representatives Tab */}
                    <TabsContent value="representatives">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base">H&S Representatives</CardTitle>
                                    <Button size="sm" onClick={() => setRepOpen(true)}>
                                        Add Representative
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="pb-2 pr-4 font-medium">Name</th>
                                                <th className="pb-2 pr-4 font-medium">Site</th>
                                                <th className="pb-2 pr-4 font-medium">Work Group</th>
                                                <th className="pb-2 pr-4 font-medium">Election Method</th>
                                                <th className="pb-2 pr-4 font-medium">Elected Date</th>
                                                <th className="pb-2 pr-4 font-medium">Training Days</th>
                                                <th className="pb-2 font-medium">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {representatives.map((rep) => (
                                                <tr key={rep.id} className="border-b last:border-0">
                                                    <td className="py-2 pr-4 font-medium">
                                                        {rep.user?.name ?? 'Unknown'}
                                                    </td>
                                                    <td className="py-2 pr-4">{rep.site?.name ?? '-'}</td>
                                                    <td className="py-2 pr-4">{rep.work_group ?? '-'}</td>
                                                    <td className="py-2 pr-4 capitalize">{rep.election_method ?? '-'}</td>
                                                    <td className="py-2 pr-4">
                                                        {rep.elected_date
                                                            ? new Date(rep.elected_date).toLocaleDateString('en-GB')
                                                            : '-'}
                                                    </td>
                                                    <td className="py-2 pr-4">{rep.training_days}</td>
                                                    <td className="py-2">
                                                        <Badge className={statusColor(rep.status)}>
                                                            {rep.status}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {!representatives.length && (
                                        <div className="py-4 text-center text-sm text-slate-500">
                                            No representatives found.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Meetings Tab */}
                    <TabsContent value="meetings">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base">Committee Meetings</CardTitle>
                                    <Button size="sm" onClick={() => setMeetingOpen(true)}>
                                        Schedule Meeting
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="pb-2 pr-4 font-medium">Committee</th>
                                                <th className="pb-2 pr-4 font-medium">Date</th>
                                                <th className="pb-2 pr-4 font-medium">Location</th>
                                                <th className="pb-2 pr-4 font-medium">Status</th>
                                                <th className="pb-2 font-medium">Action Items</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {meetings.map((meeting) => (
                                                <tr key={meeting.id} className="border-b last:border-0">
                                                    <td className="py-2 pr-4 font-medium">
                                                        {meeting.committee_name}
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        {new Date(meeting.meeting_date).toLocaleDateString('en-GB')}
                                                    </td>
                                                    <td className="py-2 pr-4">{meeting.location ?? '-'}</td>
                                                    <td className="py-2 pr-4">
                                                        <Badge className={statusColor(meeting.status)}>
                                                            {meeting.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-2">{meeting.action_items_count}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {!meetings.length && (
                                        <div className="py-4 text-center text-sm text-slate-500">
                                            No meetings scheduled.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Consultations Tab */}
                    <TabsContent value="consultations">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base">Consultations</CardTitle>
                                    <Button size="sm" onClick={() => setConsultationOpen(true)}>
                                        New Consultation
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs text-slate-500">
                                                <th className="pb-2 pr-4 font-medium">Title</th>
                                                <th className="pb-2 pr-4 font-medium">Type</th>
                                                <th className="pb-2 pr-4 font-medium">Date</th>
                                                <th className="pb-2 pr-4 font-medium">Workers Consulted</th>
                                                <th className="pb-2 font-medium">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {consultations.map((c) => (
                                                <tr key={c.id} className="border-b last:border-0">
                                                    <td className="py-2 pr-4 font-medium">{c.title}</td>
                                                    <td className="py-2 pr-4 capitalize">{c.consultation_type}</td>
                                                    <td className="py-2 pr-4">
                                                        {new Date(c.consultation_date).toLocaleDateString('en-GB')}
                                                    </td>
                                                    <td className="py-2 pr-4">{c.workers_consulted}</td>
                                                    <td className="py-2">
                                                        <Badge className={statusColor(c.status)}>
                                                            {c.status}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {!consultations.length && (
                                        <div className="py-4 text-center text-sm text-slate-500">
                                            No consultations found.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </TabsRoot>
            </div>

            {/* Add Representative Dialog */}
            <Dialog open={repOpen} onOpenChange={setRepOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add H&S Representative</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Staff Member</Label>
                            <Select
                                value={repForm.data.user_id || '__none__'}
                                onValueChange={(v) => repForm.setData('user_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select staff" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Site</Label>
                            <Select
                                value={repForm.data.site_id || '__none__'}
                                onValueChange={(v) => repForm.setData('site_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select site" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Work Group</Label>
                            <Input
                                value={repForm.data.work_group}
                                onChange={(e) => repForm.setData('work_group', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Election Method</Label>
                            <Select
                                value={repForm.data.election_method}
                                onValueChange={(v) => repForm.setData('election_method', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="elected">Elected</SelectItem>
                                    <SelectItem value="appointed">Appointed</SelectItem>
                                    <SelectItem value="volunteered">Volunteered</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Elected Date</Label>
                            <Input
                                type="date"
                                value={repForm.data.elected_date}
                                onChange={(e) => repForm.setData('elected_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Training Days</Label>
                            <Input
                                type="number"
                                min={0}
                                value={repForm.data.training_days}
                                onChange={(e) => repForm.setData('training_days', parseInt(e.target.value) || 0)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRepOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={repForm.processing}
                            onClick={() =>
                                repForm.post('/health-safety/worker-participation/representatives', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setRepOpen(false);
                                        repForm.reset();
                                    },
                                })
                            }
                        >
                            Add Representative
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Schedule Meeting Dialog */}
            <Dialog open={meetingOpen} onOpenChange={setMeetingOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Schedule Committee Meeting</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Committee Name</Label>
                            <Input
                                value={meetingForm.data.committee_name}
                                onChange={(e) => meetingForm.setData('committee_name', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Meeting Date</Label>
                            <Input
                                type="datetime-local"
                                value={meetingForm.data.meeting_date}
                                onChange={(e) => meetingForm.setData('meeting_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Location</Label>
                            <Input
                                value={meetingForm.data.location}
                                onChange={(e) => meetingForm.setData('location', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Agenda</Label>
                            <Textarea
                                value={meetingForm.data.agenda}
                                onChange={(e) => meetingForm.setData('agenda', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMeetingOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={meetingForm.processing}
                            onClick={() =>
                                meetingForm.post('/health-safety/worker-participation/meetings', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setMeetingOpen(false);
                                        meetingForm.reset();
                                    },
                                })
                            }
                        >
                            Schedule Meeting
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* New Consultation Dialog */}
            <Dialog open={consultationOpen} onOpenChange={setConsultationOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New Consultation</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Title</Label>
                            <Input
                                value={consultationForm.data.title}
                                onChange={(e) => consultationForm.setData('title', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Type</Label>
                            <Select
                                value={consultationForm.data.consultation_type}
                                onValueChange={(v) => consultationForm.setData('consultation_type', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="general">General</SelectItem>
                                    <SelectItem value="policy_change">Policy Change</SelectItem>
                                    <SelectItem value="risk_assessment">Risk Assessment</SelectItem>
                                    <SelectItem value="workplace_change">Workplace Change</SelectItem>
                                    <SelectItem value="ppe">PPE</SelectItem>
                                    <SelectItem value="training">Training</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Date</Label>
                            <Input
                                type="date"
                                value={consultationForm.data.consultation_date}
                                onChange={(e) => consultationForm.setData('consultation_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea
                                value={consultationForm.data.description}
                                onChange={(e) => consultationForm.setData('description', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConsultationOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={consultationForm.processing}
                            onClick={() =>
                                consultationForm.post('/health-safety/worker-participation/consultations', {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setConsultationOpen(false);
                                        consultationForm.reset();
                                    },
                                })
                            }
                        >
                            Create Consultation
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
