import { PageHero, PageLayout } from '@/components/page';
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface Staff {
    id: number;
    name: string;
    email: string;
}

interface LeaveType {
    value: string;
    label: string;
}

interface Props {
    staff: Staff[];
    leaveTypes: LeaveType[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave', href: '/hr/leave' },
    { title: 'Create Request', href: '/hr/leave/create' },
];

export default function CreateLeave({ staff, leaveTypes }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: '',
        leave_type: '',
        starts_at: '',
        ends_at: '',
        hours_requested: '',
        reason: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/hr/leave');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Leave Request" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/leave"
                        title="Create Leave Request"
                    />
                }
            >
                <div className="mx-auto max-w-2xl">
                <Card>
                    <CardHeader>
                        <CardTitle>Leave Request Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="user_id">
                                    Staff Member{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Select
                                    value={data.user_id}
                                    onValueChange={(value) =>
                                        setData('user_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="user_id"
                                        className={
                                            errors.user_id
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
                                {errors.user_id && (
                                    <p className="text-sm text-status-critical">
                                        {errors.user_id}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="leave_type">
                                    Leave Type{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Select
                                    value={data.leave_type}
                                    onValueChange={(value) =>
                                        setData('leave_type', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="leave_type"
                                        className={
                                            errors.leave_type
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    >
                                        <SelectValue placeholder="Select leave type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {leaveTypes.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.leave_type && (
                                    <p className="text-sm text-status-critical">
                                        {errors.leave_type}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="starts_at">
                                        Start Date{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="starts_at"
                                        type="date"
                                        value={data.starts_at}
                                        onChange={(e) =>
                                            setData('starts_at', e.target.value)
                                        }
                                        className={
                                            errors.starts_at
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.starts_at && (
                                        <p className="text-sm text-status-critical">
                                            {errors.starts_at}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="ends_at">
                                        End Date{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="ends_at"
                                        type="date"
                                        value={data.ends_at}
                                        onChange={(e) =>
                                            setData('ends_at', e.target.value)
                                        }
                                        className={
                                            errors.ends_at
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.ends_at && (
                                        <p className="text-sm text-status-critical">
                                            {errors.ends_at}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="hours_requested">
                                    Hours Requested{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="hours_requested"
                                    type="number"
                                    min="0.5"
                                    max="999"
                                    step="0.5"
                                    value={data.hours_requested}
                                    onChange={(e) =>
                                        setData(
                                            'hours_requested',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g., 8"
                                    className={
                                        errors.hours_requested
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.hours_requested && (
                                    <p className="text-sm text-status-critical">
                                        {errors.hours_requested}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="reason">Reason</Label>
                                <Textarea
                                    id="reason"
                                    value={data.reason}
                                    onChange={(e) =>
                                        setData('reason', e.target.value)
                                    }
                                    placeholder="Optional reason for leave..."
                                    rows={3}
                                />
                            </div>

                            <div className="flex items-center justify-end gap-3">
                                <Link href="/hr/leave">
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Submitting...'
                                        : 'Submit Request'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
