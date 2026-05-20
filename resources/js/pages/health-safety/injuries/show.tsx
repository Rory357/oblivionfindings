import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
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
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ClipboardList, Stethoscope, Briefcase } from 'lucide-react';

type RtwGoal = {
    id: number;
    description: string;
    target_date: string | null;
    completed: boolean;
};

type RtwStage = {
    id: number;
    week_number: number;
    hours_per_week: number | null;
    duties: string | null;
    restrictions: string | null;
};

type RtwPlan = {
    id: number;
    status: string;
    plan_start_date: string;
    plan_end_date: string | null;
    medical_clearance_notes: string | null;
    goals: RtwGoal[];
    stages: RtwStage[];
};

type CapacityAssessment = {
    id: number;
    assessment_date: string;
    assessor: { id: number; name: string } | null;
    assessment_type: string;
    status: string;
    restrictions: string | null;
};

type ModifiedDuty = {
    id: number;
    start_date: string;
    end_date: string | null;
    description: string;
    hours_per_week: number | null;
    status: string;
};

type Injury = {
    id: number;
    user: { id: number; name: string } | null;
    site: { id: number; name: string } | null;
    injury_date: string;
    injury_type: string;
    body_part_affected: string | null;
    severity: string;
    status: string;
    description: string | null;
    immediate_treatment: string | null;
    medical_treatment_type: string | null;
    worksafe_notifiable: boolean;
    acc_claim_lodged: boolean;
    acc_claim_number: string | null;
    lost_time_days: number;
    expected_return_date: string | null;
    actual_return_date: string | null;
    rtw_plans: RtwPlan[];
    capacity_assessments: CapacityAssessment[];
    modified_duties: ModifiedDuty[];
};

type Props = {
    injury: Injury;
    staff: Array<{ id: number; name: string }>;
};

const statusColor = (status: string) => {
    switch (status) {
        case 'active':
        case 'open':
        case 'in_progress':
            return 'bg-status-warning-bg text-status-warning';
        case 'recovering':
        case 'pending':
            return 'bg-status-info-bg text-status-info';
        case 'returned_to_work':
        case 'completed':
        case 'approved':
            return 'bg-status-success-bg text-status-success';
        case 'closed':
        case 'ended':
            return 'bg-muted text-foreground';
        default:
            return 'bg-muted text-foreground';
    }
};

const severityColor = (severity: string) => {
    switch (severity) {
        case 'minor':
            return 'bg-status-success-bg text-status-success';
        case 'moderate':
            return 'bg-status-warning-bg text-status-warning';
        case 'serious':
            return 'bg-status-warning-bg text-status-warning';
        case 'critical':
            return 'bg-status-critical-bg text-status-critical';
        default:
            return 'bg-muted text-foreground';
    }
};

export default function InjuryShow({ injury, staff }: Props) {
    const [rtwOpen, setRtwOpen] = useState(false);
    const [assessmentOpen, setAssessmentOpen] = useState(false);
    const [dutyOpen, setDutyOpen] = useState(false);

    const statusForm = useForm({
        status: injury.status,
        lost_time_days: injury.lost_time_days,
        expected_return_date: injury.expected_return_date ?? '',
        actual_return_date: injury.actual_return_date ?? '',
    });

    // RTW Plan form with dynamic goals/stages
    const [rtwGoals, setRtwGoals] = useState<Array<{ description: string; target_date: string }>>([
        { description: '', target_date: '' },
    ]);
    const [rtwStages, setRtwStages] = useState<
        Array<{ week_number: number; hours_per_week: string; duties: string; restrictions: string }>
    >([{ week_number: 1, hours_per_week: '', duties: '', restrictions: '' }]);

    const rtwForm = useForm({
        plan_start_date: '',
        plan_end_date: '',
        medical_clearance_notes: '',
    });

    const assessmentForm = useForm({
        assessment_date: '',
        assessor_user_id: '',
        assessment_type: 'initial',
        restrictions: '',
    });

    const dutyForm = useForm({
        start_date: '',
        end_date: '',
        description: '',
        hours_per_week: '',
    });

    const addGoal = () => setRtwGoals([...rtwGoals, { description: '', target_date: '' }]);
    const removeGoal = (idx: number) => setRtwGoals(rtwGoals.filter((_, i) => i !== idx));
    const updateGoal = (idx: number, field: string, value: string) => {
        const updated = [...rtwGoals];
        (updated[idx] as any)[field] = value;
        setRtwGoals(updated);
    };

    const addStage = () =>
        setRtwStages([
            ...rtwStages,
            { week_number: rtwStages.length + 1, hours_per_week: '', duties: '', restrictions: '' },
        ]);
    const removeStage = (idx: number) => setRtwStages(rtwStages.filter((_, i) => i !== idx));
    const updateStage = (idx: number, field: string, value: string | number) => {
        const updated = [...rtwStages];
        (updated[idx] as any)[field] = value;
        setRtwStages(updated);
    };

    const submitRtwPlan = () => {
        router.post(
            `/health-safety/injuries/${injury.id}/rtw-plans`,
            {
                ...rtwForm.data,
                goals: rtwGoals.filter((g) => g.description.trim()),
                stages: rtwStages,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRtwOpen(false);
                    rtwForm.reset();
                    setRtwGoals([{ description: '', target_date: '' }]);
                    setRtwStages([{ week_number: 1, hours_per_week: '', duties: '', restrictions: '' }]);
                },
            },
        );
    };

    const infoRow = (label: string, value: string | null | undefined) =>
        value ? (
            <div>
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="mt-0.5 text-sm whitespace-pre-wrap">{value}</div>
            </div>
        ) : null;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Injuries & Return to Work', href: '/health-safety/injuries' },
                { title: `Injury #${injury.id}`, href: `/health-safety/injuries/${injury.id}` },
            ]}
        >
            <Head title={`Injury #${injury.id}`} />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/health-safety/injuries"
                        title={`Injury #${injury.id}`}
                        description={
                            <span className="flex flex-wrap items-center gap-2">
                                <span>{injury.user?.name ?? 'Unknown'}</span>
                                <span>{injury.site?.name}</span>
                                <Badge className={severityColor(injury.severity)}>{injury.severity}</Badge>
                                <Badge className={statusColor(injury.status)}>{injury.status}</Badge>
                                {injury.worksafe_notifiable && (
                                    <Badge variant="destructive">WorkSafe Notifiable</Badge>
                                )}
                            </span>
                        }
                    />
                }
            >
                {/* Injury Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Injury Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <div className="text-xs text-muted-foreground">Date</div>
                                <div className="mt-0.5 text-sm">
                                    {new Date(injury.injury_date).toLocaleDateString('en-GB')}
                                </div>
                            </div>
                            <div>
                                <div className="text-xs text-muted-foreground">Type</div>
                                <div className="mt-0.5 text-sm capitalize">
                                    {injury.injury_type.replace(/_/g, ' ')}
                                </div>
                            </div>
                            {infoRow('Body Part', injury.body_part_affected)}
                            {infoRow('Medical Treatment', injury.medical_treatment_type?.replace(/_/g, ' '))}
                            <div>
                                <div className="text-xs text-muted-foreground">Lost Time Days</div>
                                <div className="mt-0.5 text-sm">{injury.lost_time_days}</div>
                            </div>
                            {injury.acc_claim_lodged && (
                                <div>
                                    <div className="text-xs text-muted-foreground">ACC Claim</div>
                                    <div className="mt-0.5 text-sm">
                                        {injury.acc_claim_number ?? 'Lodged (no number)'}
                                    </div>
                                </div>
                            )}
                        </div>
                        {injury.description && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Description</div>
                                <div className="mt-0.5 text-sm whitespace-pre-wrap">{injury.description}</div>
                            </div>
                        )}
                        {injury.immediate_treatment && (
                            <div className="mt-4">
                                <div className="text-xs text-muted-foreground">Immediate Treatment</div>
                                <div className="mt-0.5 text-sm whitespace-pre-wrap">
                                    {injury.immediate_treatment}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Status Update */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Status & Return Dates</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <div className="space-y-1">
                                <Label>Status</Label>
                                <Select
                                    value={statusForm.data.status}
                                    onValueChange={(v) => statusForm.setData('status', v)}
                                >
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="open">Open</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="recovering">Recovering</SelectItem>
                                        <SelectItem value="returned_to_work">Returned to Work</SelectItem>
                                        <SelectItem value="closed">Closed</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Lost Time Days</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    value={statusForm.data.lost_time_days}
                                    onChange={(e) =>
                                        statusForm.setData('lost_time_days', parseInt(e.target.value) || 0)
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Expected Return Date</Label>
                                <Input
                                    type="date"
                                    value={statusForm.data.expected_return_date}
                                    onChange={(e) =>
                                        statusForm.setData('expected_return_date', e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Actual Return Date</Label>
                                <Input
                                    type="date"
                                    value={statusForm.data.actual_return_date}
                                    onChange={(e) =>
                                        statusForm.setData('actual_return_date', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex items-center justify-end">
                            <Button
                                disabled={statusForm.processing}
                                onClick={() =>
                                    statusForm.put(`/health-safety/injuries/${injury.id}`, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Update Status
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* RTW Plans */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <ClipboardList className="h-4 w-4" />
                                Return to Work Plans
                            </CardTitle>
                            <Button size="sm" onClick={() => setRtwOpen(true)}>
                                Create RTW Plan
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {injury.rtw_plans.map((plan) => (
                                <div key={plan.id} className="rounded-lg border p-4">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Badge className={statusColor(plan.status)}>{plan.status}</Badge>
                                            <span className="text-sm">
                                                {new Date(plan.plan_start_date).toLocaleDateString('en-GB')}
                                                {plan.plan_end_date &&
                                                    ` - ${new Date(plan.plan_end_date).toLocaleDateString('en-GB')}`}
                                            </span>
                                        </div>
                                    </div>
                                    {plan.medical_clearance_notes && (
                                        <div className="mt-2">
                                            <div className="text-xs text-muted-foreground">Medical Clearance Notes</div>
                                            <div className="text-sm">{plan.medical_clearance_notes}</div>
                                        </div>
                                    )}

                                    {/* Goals */}
                                    {plan.goals.length > 0 && (
                                        <div className="mt-3">
                                            <div className="text-xs font-medium text-muted-foreground">Goals</div>
                                            <div className="mt-1 space-y-1">
                                                {plan.goals.map((g) => (
                                                    <div key={g.id} className="flex items-center gap-2 text-sm">
                                                        <Badge
                                                            className={
                                                                g.completed
                                                                    ? 'bg-status-success-bg text-status-success'
                                                                    : 'bg-muted text-foreground'
                                                            }
                                                        >
                                                            {g.completed ? 'Done' : 'Pending'}
                                                        </Badge>
                                                        <span>{g.description}</span>
                                                        {g.target_date && (
                                                            <span className="text-xs text-muted-foreground">
                                                                (by {new Date(g.target_date).toLocaleDateString('en-GB')})
                                                            </span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Stages */}
                                    {plan.stages.length > 0 && (
                                        <div className="mt-3">
                                            <div className="text-xs font-medium text-muted-foreground">Stages</div>
                                            <div className="mt-1 overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead>
                                                        <tr className="border-b text-left text-xs text-muted-foreground">
                                                            <th className="pb-1 pr-3 font-medium">Week</th>
                                                            <th className="pb-1 pr-3 font-medium">Hours/Week</th>
                                                            <th className="pb-1 pr-3 font-medium">Duties</th>
                                                            <th className="pb-1 font-medium">Restrictions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {plan.stages.map((s) => (
                                                            <tr key={s.id} className="border-b last:border-0">
                                                                <td className="py-1 pr-3">{s.week_number}</td>
                                                                <td className="py-1 pr-3">{s.hours_per_week ?? '-'}</td>
                                                                <td className="py-1 pr-3">{s.duties ?? '-'}</td>
                                                                <td className="py-1">{s.restrictions ?? '-'}</td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))}
                            {!injury.rtw_plans.length && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No return to work plans created.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Capacity Assessments */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Stethoscope className="h-4 w-4" />
                                Capacity Assessments
                            </CardTitle>
                            <Button size="sm" onClick={() => setAssessmentOpen(true)}>
                                Record Assessment
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 pr-4 font-medium">Date</th>
                                        <th className="pb-2 pr-4 font-medium">Assessor</th>
                                        <th className="pb-2 pr-4 font-medium">Type</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2 font-medium">Restrictions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {injury.capacity_assessments.map((ca) => (
                                        <tr key={ca.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">
                                                {new Date(ca.assessment_date).toLocaleDateString('en-GB')}
                                            </td>
                                            <td className="py-2 pr-4">{ca.assessor?.name ?? '-'}</td>
                                            <td className="py-2 pr-4 capitalize">
                                                {ca.assessment_type.replace(/_/g, ' ')}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <Badge className={statusColor(ca.status)}>{ca.status}</Badge>
                                            </td>
                                            <td className="py-2 text-xs">{ca.restrictions ?? '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!injury.capacity_assessments.length && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No capacity assessments recorded.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Modified Duties */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Briefcase className="h-4 w-4" />
                                Modified Duties
                            </CardTitle>
                            <Button size="sm" onClick={() => setDutyOpen(true)}>
                                Add Modified Duty
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th className="pb-2 pr-4 font-medium">Start Date</th>
                                        <th className="pb-2 pr-4 font-medium">End Date</th>
                                        <th className="pb-2 pr-4 font-medium">Description</th>
                                        <th className="pb-2 pr-4 font-medium">Hours/Week</th>
                                        <th className="pb-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {injury.modified_duties.map((md) => (
                                        <tr key={md.id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">
                                                {new Date(md.start_date).toLocaleDateString('en-GB')}
                                            </td>
                                            <td className="py-2 pr-4">
                                                {md.end_date
                                                    ? new Date(md.end_date).toLocaleDateString('en-GB')
                                                    : 'Ongoing'}
                                            </td>
                                            <td className="py-2 pr-4">{md.description}</td>
                                            <td className="py-2 pr-4">{md.hours_per_week ?? '-'}</td>
                                            <td className="py-2">
                                                <Badge className={statusColor(md.status)}>{md.status}</Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            {!injury.modified_duties.length && (
                                <div className="py-4 text-center text-sm text-muted-foreground">
                                    No modified duties recorded.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Create RTW Plan Dialog */}
            <Dialog open={rtwOpen} onOpenChange={setRtwOpen}>
                <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Create Return to Work Plan</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Plan Start Date</Label>
                                <Input
                                    type="date"
                                    value={rtwForm.data.plan_start_date}
                                    onChange={(e) => rtwForm.setData('plan_start_date', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>Plan End Date</Label>
                                <Input
                                    type="date"
                                    value={rtwForm.data.plan_end_date}
                                    onChange={(e) => rtwForm.setData('plan_end_date', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Medical Clearance Notes</Label>
                            <Textarea
                                value={rtwForm.data.medical_clearance_notes}
                                onChange={(e) => rtwForm.setData('medical_clearance_notes', e.target.value)}
                            />
                        </div>

                        {/* Goals */}
                        <div>
                            <div className="flex items-center justify-between">
                                <Label className="text-sm font-medium">Goals</Label>
                                <Button type="button" variant="outline" size="sm" onClick={addGoal}>
                                    Add Goal
                                </Button>
                            </div>
                            <div className="mt-2 space-y-2">
                                {rtwGoals.map((goal, idx) => (
                                    <div key={idx} className="flex items-start gap-2">
                                        <div className="flex-1 space-y-1">
                                            <Input
                                                placeholder="Goal description"
                                                value={goal.description}
                                                onChange={(e) => updateGoal(idx, 'description', e.target.value)}
                                            />
                                        </div>
                                        <div className="w-40 space-y-1">
                                            <Input
                                                type="date"
                                                value={goal.target_date}
                                                onChange={(e) => updateGoal(idx, 'target_date', e.target.value)}
                                            />
                                        </div>
                                        {rtwGoals.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => removeGoal(idx)}
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Stages */}
                        <div>
                            <div className="flex items-center justify-between">
                                <Label className="text-sm font-medium">Stages</Label>
                                <Button type="button" variant="outline" size="sm" onClick={addStage}>
                                    Add Stage
                                </Button>
                            </div>
                            <div className="mt-2 space-y-3">
                                {rtwStages.map((stage, idx) => (
                                    <div key={idx} className="rounded-md border p-3">
                                        <div className="flex items-center justify-between mb-2">
                                            <Label className="text-xs font-medium">
                                                Stage {idx + 1} (Week {stage.week_number})
                                            </Label>
                                            {rtwStages.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-xs"
                                                    onClick={() => removeStage(idx)}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="space-y-1">
                                                <Label className="text-xs">Week</Label>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    value={stage.week_number}
                                                    onChange={(e) =>
                                                        updateStage(idx, 'week_number', parseInt(e.target.value) || 1)
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Hours/Week</Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={stage.hours_per_week}
                                                    onChange={(e) =>
                                                        updateStage(idx, 'hours_per_week', e.target.value)
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="mt-2 space-y-1">
                                            <Label className="text-xs">Duties</Label>
                                            <Input
                                                value={stage.duties}
                                                onChange={(e) => updateStage(idx, 'duties', e.target.value)}
                                                placeholder="Describe permitted duties"
                                            />
                                        </div>
                                        <div className="mt-2 space-y-1">
                                            <Label className="text-xs">Restrictions</Label>
                                            <Input
                                                value={stage.restrictions}
                                                onChange={(e) =>
                                                    updateStage(idx, 'restrictions', e.target.value)
                                                }
                                                placeholder="Describe any restrictions"
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRtwOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={rtwForm.processing} onClick={submitRtwPlan}>
                            Create RTW Plan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Record Assessment Dialog */}
            <Dialog open={assessmentOpen} onOpenChange={setAssessmentOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record Capacity Assessment</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="space-y-1">
                            <Label>Assessment Date</Label>
                            <Input
                                type="date"
                                value={assessmentForm.data.assessment_date}
                                onChange={(e) => assessmentForm.setData('assessment_date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Assessor</Label>
                            <Select
                                value={assessmentForm.data.assessor_user_id || '__none__'}
                                onValueChange={(v) =>
                                    assessmentForm.setData('assessor_user_id', v === '__none__' ? '' : v)
                                }
                            >
                                <SelectTrigger><SelectValue placeholder="Select assessor" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">Select...</SelectItem>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Assessment Type</Label>
                            <Select
                                value={assessmentForm.data.assessment_type}
                                onValueChange={(v) => assessmentForm.setData('assessment_type', v)}
                            >
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="initial">Initial</SelectItem>
                                    <SelectItem value="progress_review">Progress Review</SelectItem>
                                    <SelectItem value="final_clearance">Final Clearance</SelectItem>
                                    <SelectItem value="specialist">Specialist</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1">
                            <Label>Restrictions</Label>
                            <Textarea
                                value={assessmentForm.data.restrictions}
                                onChange={(e) => assessmentForm.setData('restrictions', e.target.value)}
                                placeholder="Document any work restrictions or limitations"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAssessmentOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={assessmentForm.processing}
                            onClick={() =>
                                assessmentForm.post(
                                    `/health-safety/injuries/${injury.id}/capacity-assessments`,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setAssessmentOpen(false);
                                            assessmentForm.reset();
                                        },
                                    },
                                )
                            }
                        >
                            Record Assessment
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add Modified Duty Dialog */}
            <Dialog open={dutyOpen} onOpenChange={setDutyOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Modified Duty</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>Start Date</Label>
                                <Input
                                    type="date"
                                    value={dutyForm.data.start_date}
                                    onChange={(e) => dutyForm.setData('start_date', e.target.value)}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label>End Date</Label>
                                <Input
                                    type="date"
                                    value={dutyForm.data.end_date}
                                    onChange={(e) => dutyForm.setData('end_date', e.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea
                                value={dutyForm.data.description}
                                onChange={(e) => dutyForm.setData('description', e.target.value)}
                                placeholder="Describe the modified duties"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Hours per Week</Label>
                            <Input
                                type="number"
                                min={0}
                                value={dutyForm.data.hours_per_week}
                                onChange={(e) => dutyForm.setData('hours_per_week', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDutyOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={dutyForm.processing}
                            onClick={() =>
                                dutyForm.post(`/health-safety/injuries/${injury.id}/modified-duties`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setDutyOpen(false);
                                        dutyForm.reset();
                                    },
                                })
                            }
                        >
                            Add Modified Duty
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
