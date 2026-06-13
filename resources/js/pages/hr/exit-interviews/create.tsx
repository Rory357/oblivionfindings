import PageShell from '@/components/page-shell';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

interface Employee {
    id: number;
    user_id: number;
    position_title: string;
    user: { id: number; name: string };
}

interface Interviewer {
    id: number;
    name: string;
}

interface Props {
    employees: Employee[];
    interviewers: Interviewer[];
    departureReasons: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Exit Interviews', href: '/hr/exit-interviews' },
    { title: 'Create', href: '/hr/exit-interviews/create' },
];

const NOT_SPECIFIED_VALUE = '__not_specified__';

export default function ExitInterviewCreate({
    employees,
    interviewers,
    departureReasons,
}: Props) {
    const form = useForm({
        employee_profile_id: '',
        interviewer_user_id: '',
        interview_date: new Date().toISOString().substring(0, 10),
        departure_reason: '',
        would_recommend: '' as string,
        overall_satisfaction: 0,
        what_went_well: '',
        what_could_improve: '',
        management_feedback: '',
        culture_feedback: '',
        additional_comments: '',
        is_confidential: true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const submitData: any = { ...form.data };
        if (
            submitData.would_recommend === '' ||
            submitData.would_recommend === NOT_SPECIFIED_VALUE
        ) {
            submitData.would_recommend = null;
        } else {
            submitData.would_recommend = submitData.would_recommend === 'true';
        }
        if (submitData.overall_satisfaction === 0) {
            submitData.overall_satisfaction = null;
        }
        router.post('/hr/exit-interviews', submitData);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record Exit Interview" />

            <PageShell>
                <PageHero category="hr" variant="compact"
                    title="Record Exit Interview"
                    description="Capture structured departure feedback."
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Basic Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Interview Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>
                                        Departing Employee{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={form.data.employee_profile_id}
                                        onValueChange={(v) =>
                                            form.setData(
                                                'employee_profile_id',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select employee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {employees.map((emp) => (
                                                <SelectItem
                                                    key={emp.id}
                                                    value={String(emp.id)}
                                                >
                                                    {emp.user?.name} -{' '}
                                                    {emp.position_title}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.employee_profile_id && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.employee_profile_id}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>
                                        Interviewer{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={form.data.interviewer_user_id}
                                        onValueChange={(v) =>
                                            form.setData(
                                                'interviewer_user_id',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select interviewer" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {interviewers.map((user) => (
                                                <SelectItem
                                                    key={user.id}
                                                    value={String(user.id)}
                                                >
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.interviewer_user_id && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.interviewer_user_id}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>
                                        Interview Date{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        type="date"
                                        value={form.data.interview_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'interview_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.interview_date && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.interview_date}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>
                                        Primary Departure Reason{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={form.data.departure_reason}
                                        onValueChange={(v) =>
                                            form.setData('departure_reason', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select reason" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {departureReasons.map((r) => (
                                                <SelectItem
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.departure_reason && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {form.errors.departure_reason}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Satisfaction */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Ratings</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Overall Satisfaction (1-5)</Label>
                                <div className="mt-2 flex items-center gap-1">
                                    {[1, 2, 3, 4, 5].map((star) => (
                                        <Button
                                            key={star}
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                form.setData(
                                                    'overall_satisfaction',
                                                    star,
                                                )
                                            }
                                            className="h-8 w-8"
                                        >
                                            <Star
                                                className={`h-6 w-6 ${
                                                    star <=
                                                    form.data
                                                        .overall_satisfaction
                                                        ? 'fill-yellow-400 text-status-warning'
                                                        : 'text-muted-foreground'
                                                }`}
                                            />
                                        </Button>
                                    ))}
                                    {form.data.overall_satisfaction > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="ml-2 text-xs text-muted-foreground"
                                            onClick={() =>
                                                form.setData(
                                                    'overall_satisfaction',
                                                    0,
                                                )
                                            }
                                        >
                                            Clear
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label>Would Recommend as Employer</Label>
                                <Select
                                    value={form.data.would_recommend}
                                    onValueChange={(v) =>
                                        form.setData('would_recommend', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NOT_SPECIFIED_VALUE}>
                                            Not specified
                                        </SelectItem>
                                        <SelectItem value="true">
                                            Yes
                                        </SelectItem>
                                        <SelectItem value="false">
                                            No
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Feedback */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Feedback
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>What Went Well</Label>
                                <Textarea
                                    value={form.data.what_went_well}
                                    onChange={(e) =>
                                        form.setData(
                                            'what_went_well',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Positive experiences during their time here..."
                                />
                            </div>
                            <div>
                                <Label>What Could Improve</Label>
                                <Textarea
                                    value={form.data.what_could_improve}
                                    onChange={(e) =>
                                        form.setData(
                                            'what_could_improve',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Areas for improvement..."
                                />
                            </div>
                            <div>
                                <Label>Management Feedback</Label>
                                <Textarea
                                    value={form.data.management_feedback}
                                    onChange={(e) =>
                                        form.setData(
                                            'management_feedback',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Feedback on management and leadership..."
                                />
                            </div>
                            <div>
                                <Label>Culture Feedback</Label>
                                <Textarea
                                    value={form.data.culture_feedback}
                                    onChange={(e) =>
                                        form.setData(
                                            'culture_feedback',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Feedback on company culture..."
                                />
                            </div>
                            <div>
                                <Label>Additional Comments</Label>
                                <Textarea
                                    value={form.data.additional_comments}
                                    onChange={(e) =>
                                        form.setData(
                                            'additional_comments',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                    placeholder="Any other feedback..."
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    id="is_confidential"
                                    checked={form.data.is_confidential}
                                    onChange={(e) =>
                                        form.setData(
                                            'is_confidential',
                                            e.target.checked,
                                        )
                                    }
                                    className="rounded border-border"
                                />
                                <Label htmlFor="is_confidential">
                                    Mark as confidential
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Save Exit Interview
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get('/hr/exit-interviews')}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
