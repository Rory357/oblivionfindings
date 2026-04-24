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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type HrCase = {
    id: number;
    case_number: string;
    subject: {
        id: number;
        name: string;
    };
};

type Staff = {
    id: number;
    name: string;
    email: string;
};

type Option = {
    value: string;
    label: string;
};

type Props = {
    hrCase: HrCase;
    staff: Staff[];
    actionTypes: Option[];
};

export default function CreateDisciplinary({
    hrCase,
    staff,
    actionTypes,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
        {
            title: 'Add Disciplinary',
            href: `/hr/cases/${hrCase.id}/disciplinary/create`,
        },
    ];

    const { data, setData, post, processing, errors } = useForm({
        employee_user_id: String(hrCase.subject.id),
        action_type: '',
        allegation_summary: '',
        investigation_notes: '',
        investigator_user_id: '',
        meeting_scheduled_at: '',
        meeting_location: '',
        support_person_advised: false,
        response_deadline: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/hr/cases/${hrCase.id}/disciplinary`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Add Disciplinary - ${hrCase.case_number}`} />

            <div className="max-w-4xl space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/cases/${hrCase.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Case
                        </Button>
                    </Link>
                    <div className="flex items-center gap-3">
                        <AlertTriangle className="h-6 w-6 text-status-critical" />
                        <div>
                            <h1 className="text-2xl font-bold">
                                Add Disciplinary Action
                            </h1>
                            <p className="text-muted-foreground">
                                Case: {hrCase.case_number} • Subject:{' '}
                                {hrCase.subject.name}
                            </p>
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Action Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_user_id">
                                        Employee{' '}
                                        <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.employee_user_id}
                                        onValueChange={(value) =>
                                            setData('employee_user_id', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="employee_user_id"
                                            className={
                                                errors.employee_user_id
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select employee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.employee_user_id && (
                                        <p className="text-sm text-status-critical">
                                            {errors.employee_user_id}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="action_type">
                                        Action Type{' '}
                                        <span className="text-status-critical">*</span>
                                    </Label>
                                    <Select
                                        value={data.action_type}
                                        onValueChange={(value) =>
                                            setData('action_type', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="action_type"
                                            className={
                                                errors.action_type
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select action type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {actionTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.action_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.action_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="investigator_user_id">
                                        Investigator
                                    </Label>
                                    <Select
                                        value={
                                            data.investigator_user_id ||
                                            '__none__'
                                        }
                                        onValueChange={(value) =>
                                            setData(
                                                'investigator_user_id',
                                                value === '__none__'
                                                    ? ''
                                                    : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger id="investigator_user_id">
                                            <SelectValue placeholder="Select investigator" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Not assigned
                                            </SelectItem>
                                            {staff.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="response_deadline">
                                        Response Deadline
                                    </Label>
                                    <Input
                                        id="response_deadline"
                                        type="date"
                                        value={data.response_deadline}
                                        onChange={(e) =>
                                            setData(
                                                'response_deadline',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.response_deadline
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.response_deadline && (
                                        <p className="text-sm text-status-critical">
                                            {errors.response_deadline}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="allegation_summary">
                                    Allegation Summary{' '}
                                    <span className="text-status-critical">*</span>
                                </Label>
                                <Textarea
                                    id="allegation_summary"
                                    placeholder="Detailed summary of the allegations or concerns..."
                                    rows={5}
                                    value={data.allegation_summary}
                                    onChange={(e) =>
                                        setData(
                                            'allegation_summary',
                                            e.target.value,
                                        )
                                    }
                                    className={
                                        errors.allegation_summary
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.allegation_summary && (
                                    <p className="text-sm text-status-critical">
                                        {errors.allegation_summary}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="investigation_notes">
                                    Investigation Notes
                                </Label>
                                <Textarea
                                    id="investigation_notes"
                                    placeholder="Notes from any investigation conducted..."
                                    rows={4}
                                    value={data.investigation_notes}
                                    onChange={(e) =>
                                        setData(
                                            'investigation_notes',
                                            e.target.value,
                                        )
                                    }
                                    className={
                                        errors.investigation_notes
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.investigation_notes && (
                                    <p className="text-sm text-status-critical">
                                        {errors.investigation_notes}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Meeting Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="meeting_scheduled_at">
                                        Meeting Scheduled
                                    </Label>
                                    <Input
                                        id="meeting_scheduled_at"
                                        type="datetime-local"
                                        value={data.meeting_scheduled_at}
                                        onChange={(e) =>
                                            setData(
                                                'meeting_scheduled_at',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.meeting_scheduled_at
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.meeting_scheduled_at && (
                                        <p className="text-sm text-status-critical">
                                            {errors.meeting_scheduled_at}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="meeting_location">
                                        Meeting Location
                                    </Label>
                                    <Input
                                        id="meeting_location"
                                        placeholder="e.g., Conference Room A"
                                        value={data.meeting_location}
                                        onChange={(e) =>
                                            setData(
                                                'meeting_location',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.meeting_location
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.meeting_location && (
                                        <p className="text-sm text-status-critical">
                                            {errors.meeting_location}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center space-x-2 pt-2">
                                <Checkbox
                                    id="support_person_advised"
                                    checked={data.support_person_advised}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'support_person_advised',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="support_person_advised"
                                        className="text-sm font-medium"
                                    >
                                        Support person offered
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Employee has been advised of their right
                                        to bring a support person
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 h-5 w-5 text-status-warning" />
                            <div>
                                <h4 className="font-medium text-status-warning">
                                    Important Notice
                                </h4>
                                <p className="mt-1 text-sm text-status-warning">
                                    Before proceeding with any disciplinary
                                    action, ensure you have followed your
                                    organization's disciplinary procedure and
                                    the principles of natural justice. The
                                    employee must be given:
                                </p>
                                <ul className="mt-2 list-inside list-disc space-y-1 text-sm text-status-warning">
                                    <li>
                                        Clear communication of the allegations
                                    </li>
                                    <li>A genuine opportunity to respond</li>
                                    <li>The right to bring a support person</li>
                                    <li>Time to prepare their response</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/cases/${hrCase.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button
                            type="submit"
                            disabled={processing}
                            variant="destructive"
                        >
                            {processing
                                ? 'Creating...'
                                : 'Create Disciplinary Action'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
