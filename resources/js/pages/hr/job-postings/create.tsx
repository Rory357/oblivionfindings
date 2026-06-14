import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { questionTypeLabels } from '@/lib/job-posting-constants';
import { type BreadcrumbItem } from '@/types';
import type {
    JobPostingFormData,
    ScreeningQuestion,
} from '@/types/job-postings';
import { Head, useForm } from '@inertiajs/react';
import { GripVertical, Plus, Trash2, X } from 'lucide-react';
import { useCallback, useState } from 'react';

type Position = { id: number; title: string; department: string | null };
type UserOption = { id: number; name: string };

type Posting = JobPostingFormData;

type Props = {
    positions: Position[];
    users: UserOption[];
    posting?: Posting;
};

export default function CreateJobPosting({ positions, users, posting }: Props) {
    const isEditing = !!posting;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Job Postings', href: '/hr/job-postings' },
        { title: isEditing ? 'Edit Posting' : 'Create Posting', href: '#' },
    ];

    const { data, setData, post, put, processing, errors } = useForm({
        title: posting?.title || '',
        slug: posting?.slug || '',
        position_id: posting?.position_id?.toString() || '',
        department: posting?.department || '',
        location: posting?.location || '',
        employment_type: posting?.employment_type || 'full_time',
        is_remote: posting?.is_remote || false,
        is_internal: posting?.is_internal || false,
        summary: posting?.summary || '',
        description: posting?.description || '',
        requirements: posting?.requirements || '',
        responsibilities: posting?.responsibilities || '',
        salary_range_min: posting?.salary_range_min?.toString() || '',
        salary_range_max: posting?.salary_range_max?.toString() || '',
        show_salary: posting?.show_salary || false,
        requires_approval: posting?.requires_approval || false,
        hiring_manager_id: posting?.hiring_manager_id?.toString() || '',
        notification_emails: posting?.notification_emails || ([] as string[]),
        screening_questions:
            posting?.screening_questions || ([] as ScreeningQuestion[]),
        closes_at: posting?.closes_at || '',
    });

    const [emailInput, setEmailInput] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEditing) {
            put(`/hr/job-postings/${posting!.id}`);
        } else {
            post('/hr/job-postings');
        }
    };

    const addEmail = useCallback(() => {
        const email = emailInput.trim().toLowerCase();
        if (
            email &&
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) &&
            !data.notification_emails.includes(email)
        ) {
            setData('notification_emails', [
                ...data.notification_emails,
                email,
            ]);
            setEmailInput('');
        }
    }, [emailInput, data.notification_emails, setData]);

    const removeEmail = (email: string) => {
        setData(
            'notification_emails',
            data.notification_emails.filter((e) => e !== email),
        );
    };

    const addQuestion = () => {
        const newQ: ScreeningQuestion = {
            id: `q_${Date.now()}`,
            question: '',
            type: 'text',
            required: false,
            options: [],
        };
        setData('screening_questions', [...data.screening_questions, newQ]);
    };

    const updateQuestion = (index: number, field: string, value: any) => {
        const updated = [...data.screening_questions];
        (updated[index] as any)[field] = value;
        setData('screening_questions', updated);
    };

    const removeQuestion = (index: number) => {
        setData(
            'screening_questions',
            data.screening_questions.filter((_, i) => i !== index),
        );
    };

    const addOption = (qIndex: number) => {
        const updated = [...data.screening_questions];
        if (!updated[qIndex].options) updated[qIndex].options = [];
        updated[qIndex].options!.push('');
        setData('screening_questions', updated);
    };

    const updateOption = (qIndex: number, oIndex: number, value: string) => {
        const updated = [...data.screening_questions];
        updated[qIndex].options![oIndex] = value;
        setData('screening_questions', updated);
    };

    const removeOption = (qIndex: number, oIndex: number) => {
        const updated = [...data.screening_questions];
        updated[qIndex].options = updated[qIndex].options!.filter(
            (_, i) => i !== oIndex,
        );
        setData('screening_questions', updated);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={isEditing ? 'Edit Job Posting' : 'Create Job Posting'}
            />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/job-postings"
                        title={
                            isEditing ? 'Edit Job Posting' : 'Create Job Posting'
                        }
                    />
                }
            >
                <div className="mx-auto max-w-3xl">
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Card 1: Posting Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Posting Details</CardTitle>
                            <CardDescription>
                                Basic information about the position
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="col-span-2 sm:col-span-1">
                                    <Label htmlFor="title">Title *</Label>
                                    <Input
                                        id="title"
                                        value={data.title}
                                        onChange={(e) =>
                                            setData('title', e.target.value)
                                        }
                                        className="mt-1"
                                    />
                                    {errors.title && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {errors.title}
                                        </p>
                                    )}
                                </div>
                                <div className="col-span-2 sm:col-span-1">
                                    <Label htmlFor="position_id">
                                        Linked Position
                                    </Label>
                                    <Select
                                        value={data.position_id}
                                        onValueChange={(v) =>
                                            setData('position_id', v)
                                        }
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue placeholder="Select position (optional)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {positions.map((p) => (
                                                <SelectItem
                                                    key={p.id}
                                                    value={p.id.toString()}
                                                >
                                                    {p.title}{' '}
                                                    {p.department
                                                        ? `(${p.department})`
                                                        : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="department">
                                        Department
                                    </Label>
                                    <Input
                                        id="department"
                                        value={data.department}
                                        onChange={(e) =>
                                            setData(
                                                'department',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="location">Location</Label>
                                    <Input
                                        id="location"
                                        value={data.location}
                                        onChange={(e) =>
                                            setData('location', e.target.value)
                                        }
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="employment_type">
                                        Employment Type *
                                    </Label>
                                    <Select
                                        value={data.employment_type}
                                        onValueChange={(v) =>
                                            setData('employment_type', v)
                                        }
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="full_time">
                                                Full Time
                                            </SelectItem>
                                            <SelectItem value="part_time">
                                                Part Time
                                            </SelectItem>
                                            <SelectItem value="casual">
                                                Casual
                                            </SelectItem>
                                            <SelectItem value="fixed_term">
                                                Fixed Term
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="flex items-end gap-6 pb-1">
                                    <div className="flex items-center gap-2">
                                        <Switch
                                            id="is_remote"
                                            checked={data.is_remote}
                                            onCheckedChange={(v) =>
                                                setData('is_remote', v)
                                            }
                                        />
                                        <Label htmlFor="is_remote">
                                            Remote
                                        </Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Switch
                                            id="is_internal"
                                            checked={data.is_internal}
                                            onCheckedChange={(v) =>
                                                setData('is_internal', v)
                                            }
                                        />
                                        <Label htmlFor="is_internal">
                                            Internal Only
                                        </Label>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Card 2: Description */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Description</CardTitle>
                            <CardDescription>
                                Describe the role, responsibilities, and
                                requirements
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label htmlFor="summary">Summary</Label>
                                <Textarea
                                    id="summary"
                                    value={data.summary}
                                    onChange={(e) =>
                                        setData('summary', e.target.value)
                                    }
                                    rows={2}
                                    className="mt-1"
                                    placeholder="Brief overview shown on listing cards..."
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {data.summary.length}/1000
                                </p>
                            </div>
                            <div>
                                <Label htmlFor="description">
                                    Full Description *
                                </Label>
                                <Textarea
                                    id="description"
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={8}
                                    className="mt-1"
                                />
                                {errors.description && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {errors.description}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label htmlFor="responsibilities">
                                    Responsibilities
                                </Label>
                                <Textarea
                                    id="responsibilities"
                                    value={data.responsibilities}
                                    onChange={(e) =>
                                        setData(
                                            'responsibilities',
                                            e.target.value,
                                        )
                                    }
                                    rows={5}
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <Label htmlFor="requirements">
                                    Requirements
                                </Label>
                                <Textarea
                                    id="requirements"
                                    value={data.requirements}
                                    onChange={(e) =>
                                        setData('requirements', e.target.value)
                                    }
                                    rows={5}
                                    className="mt-1"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Card 3: Screening Questions */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle>Screening Questions</CardTitle>
                                    <CardDescription>
                                        Optional questions candidates must
                                        answer when applying
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addQuestion}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add
                                    Question
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {data.screening_questions.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No screening questions added yet.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {data.screening_questions.map((q, qi) => (
                                        <div
                                            key={q.id}
                                            className="space-y-3 rounded-lg border bg-muted/30 p-4"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    <GripVertical className="h-4 w-4" />
                                                    <span>
                                                        Question {qi + 1}
                                                    </span>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeQuestion(qi)
                                                    }
                                                >
                                                    <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                </Button>
                                            </div>
                                            <div>
                                                <Input
                                                    value={q.question}
                                                    onChange={(e) =>
                                                        updateQuestion(
                                                            qi,
                                                            'question',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Enter your question..."
                                                />
                                            </div>
                                            <div className="grid grid-cols-3 gap-3">
                                                <div>
                                                    <Label className="text-xs">
                                                        Type
                                                    </Label>
                                                    <Select
                                                        value={q.type}
                                                        onValueChange={(v) =>
                                                            updateQuestion(
                                                                qi,
                                                                'type',
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="mt-1">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(
                                                                questionTypeLabels,
                                                            ).map(([k, l]) => (
                                                                <SelectItem
                                                                    key={k}
                                                                    value={k}
                                                                >
                                                                    {l}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="flex items-end pb-1">
                                                    <div className="flex items-center gap-2">
                                                        <Switch
                                                            checked={q.required}
                                                            onCheckedChange={(
                                                                v,
                                                            ) =>
                                                                updateQuestion(
                                                                    qi,
                                                                    'required',
                                                                    v,
                                                                )
                                                            }
                                                        />
                                                        <Label className="text-xs">
                                                            Required
                                                        </Label>
                                                    </div>
                                                </div>
                                            </div>
                                            {q.type === 'select' && (
                                                <div className="space-y-2 border-l-2 border-muted pl-4">
                                                    <Label className="text-xs">
                                                        Options
                                                    </Label>
                                                    {(q.options || []).map(
                                                        (opt, oi) => (
                                                            <div
                                                                key={oi}
                                                                className="flex gap-2"
                                                            >
                                                                <Input
                                                                    value={opt}
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        updateOption(
                                                                            qi,
                                                                            oi,
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    placeholder={`Option ${oi + 1}`}
                                                                    className="h-8 text-sm"
                                                                />
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        removeOption(
                                                                            qi,
                                                                            oi,
                                                                        )
                                                                    }
                                                                >
                                                                    <X className="h-3 w-3" />
                                                                </Button>
                                                            </div>
                                                        ),
                                                    )}
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            addOption(qi)
                                                        }
                                                    >
                                                        <Plus className="mr-1 h-3 w-3" />{' '}
                                                        Add Option
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Card 4: Compensation, Closing & Notifications */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Compensation, Closing & Notifications
                            </CardTitle>
                            <CardDescription>
                                Salary, closing date, approval, and notification
                                settings
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="salary_range_min">
                                        Salary Range Min (NZD)
                                    </Label>
                                    <Input
                                        id="salary_range_min"
                                        type="number"
                                        step="0.01"
                                        value={data.salary_range_min}
                                        onChange={(e) =>
                                            setData(
                                                'salary_range_min',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="salary_range_max">
                                        Salary Range Max (NZD)
                                    </Label>
                                    <Input
                                        id="salary_range_max"
                                        type="number"
                                        step="0.01"
                                        value={data.salary_range_max}
                                        onChange={(e) =>
                                            setData(
                                                'salary_range_max',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1"
                                    />
                                </div>
                            </div>

                            <div className="flex items-center gap-6">
                                <div className="flex items-center gap-2">
                                    <Switch
                                        id="show_salary"
                                        checked={data.show_salary}
                                        onCheckedChange={(v) =>
                                            setData('show_salary', v)
                                        }
                                    />
                                    <Label htmlFor="show_salary">
                                        Show salary on public listing
                                    </Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Switch
                                        id="requires_approval"
                                        checked={data.requires_approval}
                                        onCheckedChange={(v) =>
                                            setData('requires_approval', v)
                                        }
                                    />
                                    <Label htmlFor="requires_approval">
                                        Require approval before publishing
                                    </Label>
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="closes_at">
                                        Closing Date
                                    </Label>
                                    <Input
                                        id="closes_at"
                                        type="date"
                                        value={data.closes_at}
                                        onChange={(e) =>
                                            setData('closes_at', e.target.value)
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="hiring_manager_id">
                                        Hiring Manager
                                    </Label>
                                    <Select
                                        value={data.hiring_manager_id}
                                        onValueChange={(v) =>
                                            setData('hiring_manager_id', v)
                                        }
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue placeholder="Select hiring manager..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {users.map((u) => (
                                                <SelectItem
                                                    key={u.id}
                                                    value={u.id.toString()}
                                                >
                                                    {u.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div>
                                <Label>Notification Emails</Label>
                                <p className="mb-2 text-xs text-muted-foreground">
                                    These email addresses will be notified when
                                    a candidate applies
                                </p>
                                <div className="mb-2 flex flex-wrap gap-2">
                                    {data.notification_emails.map((email) => (
                                        <Badge
                                            key={email}
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            {email}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove ${email}`}
                                                onClick={() =>
                                                    removeEmail(email)
                                                }
                                                className="ml-1 h-4 w-4 p-0 hover:text-destructive"
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    ))}
                                </div>
                                <div className="flex gap-2">
                                    <Input
                                        type="email"
                                        value={emailInput}
                                        onChange={(e) =>
                                            setEmailInput(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                addEmail();
                                            }
                                        }}
                                        placeholder="Enter email and press Enter..."
                                        className="flex-1"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addEmail}
                                    >
                                        Add
                                    </Button>
                                </div>
                                {errors.notification_emails && (
                                    <p className="mt-1 text-sm text-destructive">
                                        {errors.notification_emails}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {isEditing ? 'Update Posting' : 'Create Posting'}
                        </Button>
                    </div>
                </form>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
