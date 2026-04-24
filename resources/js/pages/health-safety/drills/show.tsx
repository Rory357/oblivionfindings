import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Users, AlertTriangle, CheckCircle2 } from 'lucide-react';

type Participant = {
    id: number;
    user: { id: number; name: string } | null;
    role: string | null;
    attended: boolean;
};

type Finding = {
    id: number;
    finding_type: string;
    description: string;
    severity: string;
    status: string;
    corrective_action: string | null;
    assigned_to: { id: number; name: string } | null;
    due_date: string | null;
};

type Drill = {
    id: number;
    site: { id: number; name: string } | null;
    drill_type: string;
    title: string;
    scheduled_at: string;
    scenario_description: string | null;
    status: string;
    duration_minutes: number | null;
    evacuation_time_seconds: number | null;
    outcome: string | null;
    observer_notes: string | null;
    improvements: string | null;
    all_areas_checked: boolean;
    assembly_point_reached: boolean;
    roll_call_completed: boolean;
    participants: Participant[];
    findings: Finding[];
};

type Props = {
    drill: Drill;
    staff: Array<{ id: number; name: string }>;
};

const statusColor = (status: string) => {
    switch (status) {
        case 'completed':
        case 'resolved':
            return 'bg-status-success-bg text-status-success';
        case 'scheduled':
        case 'open':
            return 'bg-status-info-bg text-status-info';
        case 'in_progress':
            return 'bg-status-warning-bg text-status-warning';
        case 'cancelled':
            return 'bg-muted text-foreground';
        default:
            return 'bg-muted text-foreground';
    }
};

const severityColor = (severity: string) => {
    switch (severity) {
        case 'critical':
            return 'bg-status-critical-bg text-status-critical';
        case 'high':
            return 'bg-status-warning-bg text-status-warning';
        case 'medium':
            return 'bg-status-warning-bg text-status-warning';
        case 'low':
            return 'bg-status-success-bg text-status-success';
        default:
            return 'bg-muted text-foreground';
    }
};

export default function DrillShow({ drill, staff }: Props) {
    const [participantOpen, setParticipantOpen] = useState(false);
    const [findingOpen, setFindingOpen] = useState(false);

    const completionForm = useForm({
        duration_minutes: drill.duration_minutes ?? '',
        evacuation_time_seconds: drill.evacuation_time_seconds ?? '',
        outcome: drill.outcome ?? 'satisfactory',
        observer_notes: drill.observer_notes ?? '',
        improvements: drill.improvements ?? '',
        all_areas_checked: drill.all_areas_checked,
        assembly_point_reached: drill.assembly_point_reached,
        roll_call_completed: drill.roll_call_completed,
    });

    const participantForm = useForm({
        user_id: '',
        role: 'participant',
    });

    const findingForm = useForm({
        finding_type: 'observation',
        description: '',
        severity: 'medium',
        corrective_action: '',
        assigned_to_user_id: '',
        due_date: '',
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Emergency Drills', href: '/health-safety/drills' },
                { title: drill.title, href: `/health-safety/drills/${drill.id}` },
            ]}
        >
            <Head title={drill.title} />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{drill.title}</h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span>{drill.site?.name ?? 'Unknown Site'}</span>
                            <span className="capitalize">{drill.drill_type.replace(/_/g, ' ')}</span>
                            <Badge className={statusColor(drill.status)}>{drill.status}</Badge>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {drill.status === 'scheduled' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(`/health-safety/drills/${drill.id}/start`, {}, { preserveScroll: true })
                                }
                            >
                                Start Drill
                            </Button>
                        )}
                        <Link href="/health-safety/drills" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Back
                        </Link>
                    </div>
                </div>

                {/* Drill Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Drill Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <div className="text-xs text-muted-foreground">Scheduled</div>
                                <div className="mt-0.5 text-sm">
                                    {new Date(drill.scheduled_at).toLocaleDateString('en-GB')}{' '}
                                    {new Date(drill.scheduled_at).toLocaleTimeString('en-GB', {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </div>
                            </div>
                            {drill.duration_minutes && (
                                <div>
                                    <div className="text-xs text-muted-foreground">Duration</div>
                                    <div className="mt-0.5 text-sm">{drill.duration_minutes} minutes</div>
                                </div>
                            )}
                            {drill.evacuation_time_seconds && (
                                <div>
                                    <div className="text-xs text-muted-foreground">Evacuation Time</div>
                                    <div className="mt-0.5 text-sm">
                                        {Math.floor(drill.evacuation_time_seconds / 60)}m{' '}
                                        {drill.evacuation_time_seconds % 60}s
                                    </div>
                                </div>
                            )}
                        </div>
                        {drill.scenario_description && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Scenario Description</div>
                                <div className="mt-0.5 text-sm whitespace-pre-wrap">
                                    {drill.scenario_description}
                                </div>
                            </div>
                        )}
                        {drill.outcome && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Outcome</div>
                                <div className="mt-0.5 text-sm capitalize">{drill.outcome}</div>
                            </div>
                        )}
                        {drill.observer_notes && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Observer Notes</div>
                                <div className="mt-0.5 text-sm whitespace-pre-wrap">{drill.observer_notes}</div>
                            </div>
                        )}
                        {drill.improvements && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Improvements Identified</div>
                                <div className="mt-0.5 text-sm whitespace-pre-wrap">{drill.improvements}</div>
                            </div>
                        )}
                        {drill.status === 'completed' && (
                            <div className="mt-4 flex flex-wrap gap-3">
                                <Badge className={drill.all_areas_checked ? 'bg-status-success-bg text-status-success' : 'bg-status-critical-bg text-status-critical'}>
                                    All areas checked: {drill.all_areas_checked ? 'Yes' : 'No'}
                                </Badge>
                                <Badge className={drill.assembly_point_reached ? 'bg-status-success-bg text-status-success' : 'bg-status-critical-bg text-status-critical'}>
                                    Assembly point reached: {drill.assembly_point_reached ? 'Yes' : 'No'}
                                </Badge>
                                <Badge className={drill.roll_call_completed ? 'bg-status-success-bg text-status-success' : 'bg-status-critical-bg text-status-critical'}>
                                    Roll call completed: {drill.roll_call_completed ? 'Yes' : 'No'}
                                </Badge>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Completion Form (only when in_progress) */}
                {drill.status === 'in_progress' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Complete Drill</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Duration (minutes)</Label>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={completionForm.data.duration_minutes}
                                        onChange={(e) =>
                                            completionForm.setData('duration_minutes', e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Evacuation Time (seconds)</Label>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={completionForm.data.evacuation_time_seconds}
                                        onChange={(e) =>
                                            completionForm.setData('evacuation_time_seconds', e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>Outcome</Label>
                                    <Select
                                        value={completionForm.data.outcome}
                                        onValueChange={(v) => completionForm.setData('outcome', v)}
                                    >
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="satisfactory">Satisfactory</SelectItem>
                                            <SelectItem value="unsatisfactory">Unsatisfactory</SelectItem>
                                            <SelectItem value="partially_satisfactory">Partially Satisfactory</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-1">
                                <Label>Observer Notes</Label>
                                <Textarea
                                    value={completionForm.data.observer_notes}
                                    onChange={(e) => completionForm.setData('observer_notes', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Improvements Identified</Label>
                                <Textarea
                                    value={completionForm.data.improvements}
                                    onChange={(e) => completionForm.setData('improvements', e.target.value)}
                                />
                            </div>
                            <div className="flex flex-wrap gap-4">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        checked={!!completionForm.data.all_areas_checked}
                                        onCheckedChange={(v) =>
                                            completionForm.setData('all_areas_checked', !!v)
                                        }
                                    />
                                    <Label>All areas checked</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        checked={!!completionForm.data.assembly_point_reached}
                                        onCheckedChange={(v) =>
                                            completionForm.setData('assembly_point_reached', !!v)
                                        }
                                    />
                                    <Label>Assembly point reached</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        checked={!!completionForm.data.roll_call_completed}
                                        onCheckedChange={(v) =>
                                            completionForm.setData('roll_call_completed', !!v)
                                        }
                                    />
                                    <Label>Roll call completed</Label>
                                </div>
                            </div>
                            <div className="flex items-center justify-end">
                                <Button
                                    disabled={completionForm.processing}
                                    onClick={() =>
                                        completionForm.post(`/health-safety/drills/${drill.id}/complete`, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Complete Drill
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Participants */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="h-4 w-4" />
                                Participants
                            </CardTitle>
                            <Button size="sm" onClick={() => setParticipantOpen(true)}>
                                Add Participant
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 pr-4 font-medium">Name</th>
                                        <th className="pb-2 pr-4 font-medium">Role</th>
                                        <th className="pb-2 font-medium">Attended</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {drill.participants.map((p) => (
                                        <tr key={p.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4 font-medium">{p.user?.name ?? 'Unknown'}</td>
                                            <td className="py-2 pr-4 capitalize">{p.role ?? '-'}</td>
                                            <td className="py-2">
                                                <Badge className={p.attended ? 'bg-status-success-bg text-status-success' : 'bg-status-critical-bg text-status-critical'}>
                                                    {p.attended ? 'Yes' : 'No'}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!drill.participants.length && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No participants recorded.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Findings */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-4 w-4" />
                                Findings
                            </CardTitle>
                            <Button size="sm" onClick={() => setFindingOpen(true)}>
                                Add Finding
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {drill.findings.map((f) => (
                                <div key={f.id} className="rounded-lg border p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className="capitalize">
                                                    {f.finding_type.replace(/_/g, ' ')}
                                                </Badge>
                                                <Badge className={severityColor(f.severity)}>
                                                    {f.severity}
                                                </Badge>
                                                <Badge className={statusColor(f.status)}>
                                                    {f.status}
                                                </Badge>
                                            </div>
                                            <div className="mt-2 text-sm">{f.description}</div>
                                            {f.corrective_action && (
                                                <div className="mt-2">
                                                    <div className="text-xs text-muted-foreground">Corrective Action</div>
                                                    <div className="text-sm">{f.corrective_action}</div>
                                                </div>
                                            )}
                                            <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                {f.assigned_to && <span>Assigned to: {f.assigned_to.name}</span>}
                                                {f.due_date && (
                                                    <span>
                                                        Due: {new Date(f.due_date).toLocaleDateString('en-GB')}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        {f.status !== 'resolved' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/health-safety/drills/${drill.id}/findings/${f.id}/resolve`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                                Resolve
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                            {!drill.findings.length && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No findings recorded.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Add Participant Dialog */}
            <Dialog open={participantOpen} onOpenChange={setParticipantOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Participant</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Staff Member</Label>
                            <Select
                                value={participantForm.data.user_id || '__none__'}
                                onValueChange={(v) => participantForm.setData('user_id', v === '__none__' ? '' : v)}
                            >
                                <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Role</Label>
                            <Select
                                value={participantForm.data.role}
                                onValueChange={(v) => participantForm.setData('role', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="participant">Participant</SelectItem>
                                    <SelectItem value="observer">Observer</SelectItem>
                                    <SelectItem value="warden">Fire Warden</SelectItem>
                                    <SelectItem value="first_aider">First Aider</SelectItem>
                                    <SelectItem value="coordinator">Coordinator</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setParticipantOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={participantForm.processing}
                            onClick={() =>
                                participantForm.post(`/health-safety/drills/${drill.id}/participants`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setParticipantOpen(false);
                                        participantForm.reset();
                                    },
                                })
                            }
                        >
                            Add Participant
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add Finding Dialog */}
            <Dialog open={findingOpen} onOpenChange={setFindingOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Finding</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Finding Type</Label>
                                <Select
                                    value={findingForm.data.finding_type}
                                    onValueChange={(v) => findingForm.setData('finding_type', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="observation">Observation</SelectItem>
                                        <SelectItem value="non_conformance">Non-Conformance</SelectItem>
                                        <SelectItem value="improvement">Improvement</SelectItem>
                                        <SelectItem value="positive">Positive</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Severity</Label>
                                <Select
                                    value={findingForm.data.severity}
                                    onValueChange={(v) => findingForm.setData('severity', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea
                                value={findingForm.data.description}
                                onChange={(e) => findingForm.setData('description', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Corrective Action</Label>
                            <Textarea
                                value={findingForm.data.corrective_action}
                                onChange={(e) => findingForm.setData('corrective_action', e.target.value)}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Assigned To</Label>
                                <Select
                                    value={findingForm.data.assigned_to_user_id || '__none__'}
                                    onValueChange={(v) =>
                                        findingForm.setData('assigned_to_user_id', v === '__none__' ? '' : v)
                                    }
                                >
                                    <SelectTrigger><SelectValue placeholder="Select staff" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">Unassigned</SelectItem>
                                        {staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Due Date</Label>
                                <Input
                                    type="date"
                                    value={findingForm.data.due_date}
                                    onChange={(e) => findingForm.setData('due_date', e.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setFindingOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={findingForm.processing}
                            onClick={() =>
                                findingForm.post(`/health-safety/drills/${drill.id}/findings`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setFindingOpen(false);
                                        findingForm.reset();
                                    },
                                })
                            }
                        >
                            Add Finding
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
