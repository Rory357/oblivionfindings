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
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type Staff = {
    id: number;
    name: string;
    email: string;
};

type SessionType = {
    value: string;
    label: string;
};

type Props = {
    staff: Staff[];
    sessionTypes: SessionType[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance & Supervision', href: '/hr/performance' },
    {
        title: 'Add Supervision Note',
        href: '/hr/performance/supervision/create',
    },
];

export default function CreateSupervision({ staff, sessionTypes }: Props) {
    const [actions, setActions] = useState<string[]>(['']);

    const { data, setData, post, processing, errors } = useForm({
        employee_user_id: '',
        session_date: new Date().toISOString().split('T')[0],
        session_type: '',
        duration_minutes: '',
        topics_discussed: '',
        actions_agreed: [] as string[],
        next_session_date: '',
        is_visible_to_employee: true,
    });

    const addAction = () => {
        setActions([...actions, '']);
    };

    const updateAction = (index: number, value: string) => {
        const newActions = [...actions];
        newActions[index] = value;
        setActions(newActions);
        setData(
            'actions_agreed',
            newActions.filter((a) => a.trim() !== ''),
        );
    };

    const removeAction = (index: number) => {
        const newActions = actions.filter((_, i) => i !== index);
        setActions(newActions);
        setData(
            'actions_agreed',
            newActions.filter((a) => a.trim() !== ''),
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/performance/supervision', {
            onSuccess: () => {
                // Redirect handled by controller
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Supervision Note" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/hr/performance"
                        title="Add Supervision Note"
                        description="Record a supervision session or check-in."
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Session Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="employee_user_id">
                                        Staff Member{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
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
                                            <SelectValue placeholder="Select staff member" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name} ({s.email})
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
                                    <Label htmlFor="session_type">
                                        Session Type{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.session_type}
                                        onValueChange={(value) =>
                                            setData('session_type', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="session_type"
                                            className={
                                                errors.session_type
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select session type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sessionTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.session_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.session_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="session_date">
                                        Session Date{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="session_date"
                                        type="date"
                                        value={data.session_date}
                                        onChange={(e) =>
                                            setData(
                                                'session_date',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.session_date
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.session_date && (
                                        <p className="text-sm text-status-critical">
                                            {errors.session_date}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="duration_minutes">
                                        Duration (minutes)
                                    </Label>
                                    <Input
                                        id="duration_minutes"
                                        type="number"
                                        min="1"
                                        placeholder="e.g., 30"
                                        value={data.duration_minutes}
                                        onChange={(e) =>
                                            setData(
                                                'duration_minutes',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.duration_minutes
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.duration_minutes && (
                                        <p className="text-sm text-status-critical">
                                            {errors.duration_minutes}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="topics_discussed">
                                    Topics Discussed
                                </Label>
                                <Textarea
                                    id="topics_discussed"
                                    placeholder="What was discussed in this session..."
                                    rows={5}
                                    value={data.topics_discussed}
                                    onChange={(e) =>
                                        setData(
                                            'topics_discussed',
                                            e.target.value,
                                        )
                                    }
                                    className={
                                        errors.topics_discussed
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.topics_discussed && (
                                    <p className="text-sm text-status-critical">
                                        {errors.topics_discussed}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Actions Agreed</CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addAction}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                Add Action
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {actions.map((action, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        placeholder={`Action item ${index + 1}`}
                                        value={action}
                                        onChange={(e) =>
                                            updateAction(index, e.target.value)
                                        }
                                    />
                                    {actions.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeAction(index)}
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Follow-up</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="next_session_date">
                                        Next Session Date
                                    </Label>
                                    <Input
                                        id="next_session_date"
                                        type="date"
                                        value={data.next_session_date}
                                        onChange={(e) =>
                                            setData(
                                                'next_session_date',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.next_session_date
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.next_session_date && (
                                        <p className="text-sm text-status-critical">
                                            {errors.next_session_date}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="is_visible_to_employee"
                                    checked={data.is_visible_to_employee}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'is_visible_to_employee',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="is_visible_to_employee"
                                    className="text-sm font-normal"
                                >
                                    Make this note visible to the employee
                                </Label>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href="/hr/performance">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Supervision Note'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
