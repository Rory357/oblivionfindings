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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type Staff = {
    id: number;
    name: string;
    email: string;
};

type Option = {
    value: string;
    label: string;
};

type GoodFaithOption = {
    key: string;
    label: string;
};

type HrCasePayload = {
    id: number;
    case_number: string;
    subject: { id: number; name: string } | null;
};

type ActionPayload = {
    id: number;
    employee_user_id: string;
    stage: string;
    action_type: string;
    allegation_summary: string;
    investigation_notes: string | null;
    investigator_user_id: string;
    notice_issued_at: string | null;
    notice_document_path: string | null;
    meeting_scheduled_at: string | null;
    meeting_location: string | null;
    support_person_advised: boolean;
    meeting_held_at: string | null;
    meeting_notes: string | null;
    meeting_attendees: string[];
    employee_response: string | null;
    response_deadline: string | null;
    outcome: string | null;
    outcome_rationale: string | null;
    outcome_document_path: string | null;
    good_faith_checklist: Record<string, boolean>;
    appeal_received: boolean;
    appeal_notes: string | null;
    appeal_outcome: string | null;
};

type Props = {
    hrCase: HrCasePayload;
    action: ActionPayload;
    staff: Staff[];
    actionTypes: Option[];
    stageOptions: Option[];
    goodFaithRequiredChecks: GoodFaithOption[];
};

const stageBadgeClass = 'border-border bg-muted text-foreground';

export default function EditDisciplinary({
    hrCase,
    action,
    staff,
    actionTypes,
    stageOptions,
    goodFaithRequiredChecks,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
        {
            title: 'Edit Disciplinary',
            href: `/hr/cases/disciplinary/${action.id}/edit`,
        },
    ];

    const page = usePage<{ errors?: Record<string, string | string[]> }>();
    const goodFaithError = page.props?.errors?.good_faith;
    const stageError = page.props?.errors?.stage;

    const { data, setData, transform, put, processing, errors } = useForm({
        employee_user_id: action.employee_user_id,
        action_type: action.action_type ?? '',
        allegation_summary: action.allegation_summary ?? '',
        investigation_notes: action.investigation_notes ?? '',
        investigator_user_id: action.investigator_user_id ?? '',
        notice_issued_at: action.notice_issued_at ?? '',
        notice_document_path: action.notice_document_path ?? '',
        meeting_scheduled_at: action.meeting_scheduled_at ?? '',
        meeting_location: action.meeting_location ?? '',
        support_person_advised: Boolean(action.support_person_advised),
        meeting_held_at: action.meeting_held_at ?? '',
        meeting_notes: action.meeting_notes ?? '',
        meeting_attendees_text: (action.meeting_attendees ?? []).join('\n'),
        employee_response: action.employee_response ?? '',
        response_deadline: action.response_deadline ?? '',
        outcome: action.outcome ?? '',
        outcome_rationale: action.outcome_rationale ?? '',
        outcome_document_path: action.outcome_document_path ?? '',
        good_faith_checklist: action.good_faith_checklist ?? {},
        appeal_received: Boolean(action.appeal_received),
        appeal_notes: action.appeal_notes ?? '',
        appeal_outcome: action.appeal_outcome ?? '',
    });

    const currentStageLabel =
        stageOptions.find((option) => option.value === action.stage)?.label ??
        action.stage.replace(/_/g, ' ');

    const completedGoodFaithCount = goodFaithRequiredChecks.filter(
        (option) => data.good_faith_checklist?.[option.key],
    ).length;

    function toggleGoodFaith(key: string, checked: boolean) {
        setData('good_faith_checklist', {
            ...data.good_faith_checklist,
            [key]: checked,
        });
    }

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();

        transform((values) => ({
            employee_user_id: Number(values.employee_user_id),
            action_type: values.action_type,
            allegation_summary: values.allegation_summary,
            investigation_notes: values.investigation_notes || null,
            investigator_user_id: values.investigator_user_id
                ? Number(values.investigator_user_id)
                : null,
            notice_issued_at: values.notice_issued_at || null,
            notice_document_path: values.notice_document_path || null,
            meeting_scheduled_at: values.meeting_scheduled_at || null,
            meeting_location: values.meeting_location || null,
            support_person_advised: Boolean(values.support_person_advised),
            meeting_held_at: values.meeting_held_at || null,
            meeting_notes: values.meeting_notes || null,
            meeting_attendees: values.meeting_attendees_text
                .split('\n')
                .map((name) => name.trim())
                .filter((name) => name !== ''),
            employee_response: values.employee_response || null,
            response_deadline: values.response_deadline || null,
            outcome: values.outcome || null,
            outcome_rationale: values.outcome_rationale || null,
            outcome_document_path: values.outcome_document_path || null,
            good_faith_checklist: values.good_faith_checklist,
            appeal_received: Boolean(values.appeal_received),
            appeal_notes: values.appeal_notes || null,
            appeal_outcome: values.appeal_outcome || null,
        }));

        put(`/hr/cases/disciplinary/${action.id}`, {
            preserveScroll: true,
            onFinish: () => transform((values) => values),
        });
    }

    function advanceStage() {
        router.post(
            `/hr/cases/disciplinary/${action.id}/advance`,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Disciplinary - ${hrCase.case_number}`} />

            <div className="max-w-5xl space-y-6">
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
                                Edit Disciplinary Action
                            </h1>
                            <p className="text-muted-foreground">
                                Case: {hrCase.case_number} | Subject:{' '}
                                {hrCase.subject?.name ?? 'Unknown'}
                            </p>
                        </div>
                        <Badge variant="outline" className={stageBadgeClass}>
                            Stage: {currentStageLabel}
                        </Badge>
                    </div>
                </div>

                {goodFaithError || stageError ? (
                    <Card className="border-status-critical/30 bg-status-critical-bg">
                        <CardContent className="py-3 text-sm text-status-critical">
                            {Array.isArray(goodFaithError)
                                ? goodFaithError.join(' ')
                                : goodFaithError ||
                                  (Array.isArray(stageError)
                                      ? stageError.join(' ')
                                      : stageError)}
                        </CardContent>
                    </Card>
                ) : null}

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Action Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_user_id">
                                        Employee
                                    </Label>
                                    <Select
                                        value={data.employee_user_id}
                                        onValueChange={(value) =>
                                            setData('employee_user_id', value)
                                        }
                                    >
                                        <SelectTrigger id="employee_user_id">
                                            <SelectValue placeholder="Select employee" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((member) => (
                                                <SelectItem
                                                    key={member.id}
                                                    value={String(member.id)}
                                                >
                                                    {member.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.employee_user_id ? (
                                        <p className="text-sm text-status-critical">
                                            {errors.employee_user_id}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="action_type">
                                        Action Type
                                    </Label>
                                    <Select
                                        value={data.action_type}
                                        onValueChange={(value) =>
                                            setData('action_type', value)
                                        }
                                    >
                                        <SelectTrigger id="action_type">
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
                                    {errors.action_type ? (
                                        <p className="text-sm text-status-critical">
                                            {errors.action_type}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="allegation_summary">
                                    Allegation Summary
                                </Label>
                                <Textarea
                                    id="allegation_summary"
                                    rows={4}
                                    value={data.allegation_summary}
                                    onChange={(event) =>
                                        setData(
                                            'allegation_summary',
                                            event.target.value,
                                        )
                                    }
                                />
                                {errors.allegation_summary ? (
                                    <p className="text-sm text-status-critical">
                                        {errors.allegation_summary}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="investigation_notes">
                                    Investigation Notes
                                </Label>
                                <Textarea
                                    id="investigation_notes"
                                    rows={4}
                                    value={data.investigation_notes}
                                    onChange={(event) =>
                                        setData(
                                            'investigation_notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Meeting and Response</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
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
                                            {staff.map((member) => (
                                                <SelectItem
                                                    key={member.id}
                                                    value={String(member.id)}
                                                >
                                                    {member.name}
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
                                        onChange={(event) =>
                                            setData(
                                                'response_deadline',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="meeting_scheduled_at">
                                        Meeting Scheduled
                                    </Label>
                                    <Input
                                        id="meeting_scheduled_at"
                                        type="datetime-local"
                                        value={data.meeting_scheduled_at}
                                        onChange={(event) =>
                                            setData(
                                                'meeting_scheduled_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="meeting_held_at">
                                        Meeting Held
                                    </Label>
                                    <Input
                                        id="meeting_held_at"
                                        type="datetime-local"
                                        value={data.meeting_held_at}
                                        onChange={(event) =>
                                            setData(
                                                'meeting_held_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="meeting_location">
                                        Meeting Location
                                    </Label>
                                    <Input
                                        id="meeting_location"
                                        value={data.meeting_location}
                                        onChange={(event) =>
                                            setData(
                                                'meeting_location',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="notice_issued_at">
                                        Notice Issued
                                    </Label>
                                    <Input
                                        id="notice_issued_at"
                                        type="datetime-local"
                                        value={data.notice_issued_at}
                                        onChange={(event) =>
                                            setData(
                                                'notice_issued_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="meeting_attendees_text">
                                    Meeting Attendees (one per line)
                                </Label>
                                <Textarea
                                    id="meeting_attendees_text"
                                    rows={3}
                                    value={data.meeting_attendees_text}
                                    onChange={(event) =>
                                        setData(
                                            'meeting_attendees_text',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="meeting_notes">
                                    Meeting Notes
                                </Label>
                                <Textarea
                                    id="meeting_notes"
                                    rows={4}
                                    value={data.meeting_notes}
                                    onChange={(event) =>
                                        setData(
                                            'meeting_notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="employee_response">
                                    Employee Response
                                </Label>
                                <Textarea
                                    id="employee_response"
                                    rows={4}
                                    value={data.employee_response}
                                    onChange={(event) =>
                                        setData(
                                            'employee_response',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="flex items-center space-x-2 pt-1">
                                <Checkbox
                                    id="support_person_advised"
                                    checked={Boolean(
                                        data.support_person_advised,
                                    )}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'support_person_advised',
                                            Boolean(checked),
                                        )
                                    }
                                />
                                <Label htmlFor="support_person_advised">
                                    Support person offered and advised
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Good Faith Checklist ({completedGoodFaithCount}/
                                {goodFaithRequiredChecks.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {goodFaithRequiredChecks.map((option) => (
                                <label
                                    key={option.key}
                                    className="flex items-start gap-3 rounded-md border p-3 text-sm"
                                >
                                    <Checkbox
                                        checked={Boolean(
                                            data.good_faith_checklist?.[
                                                option.key
                                            ],
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggleGoodFaith(
                                                option.key,
                                                Boolean(checked),
                                            )
                                        }
                                    />
                                    <span>{option.label}</span>
                                </label>
                            ))}
                            {errors.good_faith_checklist ? (
                                <p className="text-sm text-status-critical">
                                    {errors.good_faith_checklist}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Outcome and Appeal</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="outcome">Outcome</Label>
                                <Textarea
                                    id="outcome"
                                    rows={3}
                                    value={data.outcome}
                                    onChange={(event) =>
                                        setData('outcome', event.target.value)
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="outcome_rationale">
                                    Outcome Rationale
                                </Label>
                                <Textarea
                                    id="outcome_rationale"
                                    rows={3}
                                    value={data.outcome_rationale}
                                    onChange={(event) =>
                                        setData(
                                            'outcome_rationale',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="outcome_document_path">
                                    Outcome Document Path
                                </Label>
                                <Input
                                    id="outcome_document_path"
                                    value={data.outcome_document_path}
                                    onChange={(event) =>
                                        setData(
                                            'outcome_document_path',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="appeal_received"
                                    checked={Boolean(data.appeal_received)}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'appeal_received',
                                            Boolean(checked),
                                        )
                                    }
                                />
                                <Label htmlFor="appeal_received">
                                    Appeal received
                                </Label>
                            </div>

                            {data.appeal_received ? (
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="appeal_notes">
                                            Appeal Notes
                                        </Label>
                                        <Textarea
                                            id="appeal_notes"
                                            rows={3}
                                            value={data.appeal_notes}
                                            onChange={(event) =>
                                                setData(
                                                    'appeal_notes',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="appeal_outcome">
                                            Appeal Outcome
                                        </Label>
                                        <Textarea
                                            id="appeal_outcome"
                                            rows={3}
                                            value={data.appeal_outcome}
                                            onChange={(event) =>
                                                setData(
                                                    'appeal_outcome',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <div className="flex flex-wrap items-center justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={advanceStage}
                        >
                            Advance Stage
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
