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
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Clock } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type HrCase = {
    id: number;
    case_number: string;
    subject: {
        id: number;
        name: string;
    };
};

type Option = {
    value: string;
    label: string;
};

type Props = {
    hrCase: HrCase;
    eventTypes: Option[];
};

export default function CreateEvent({ hrCase, eventTypes }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Cases', href: '/hr/cases' },
        { title: hrCase.case_number, href: `/hr/cases/${hrCase.id}` },
        { title: 'Add Event', href: `/hr/cases/${hrCase.id}/events/create` },
    ];

    const { data, setData, post, processing, errors } = useForm({
        event_type: '',
        title: '',
        description: '',
        occurred_at: new Date().toISOString().slice(0, 16),
        visibility: 'internal',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/hr/cases/${hrCase.id}/events`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Add Event - ${hrCase.case_number}`} />

            <div className="max-w-3xl space-y-6">
                <div className="flex items-center gap-4">
                    <Link href={`/hr/cases/${hrCase.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Case
                        </Button>
                    </Link>
                    <div className="flex items-center gap-3">
                        <Clock className="h-6 w-6 text-status-info" />
                        <div>
                            <h1 className="text-2xl font-bold">
                                Add Timeline Event
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
                            <CardTitle>Event Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="event_type">
                                        Event Type{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={data.event_type}
                                        onValueChange={(value) =>
                                            setData('event_type', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="event_type"
                                            className={
                                                errors.event_type
                                                    ? 'border-status-critical/30'
                                                    : ''
                                            }
                                        >
                                            <SelectValue placeholder="Select event type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {eventTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.event_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.event_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="occurred_at">
                                        Date & Time{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        id="occurred_at"
                                        type="datetime-local"
                                        value={data.occurred_at}
                                        onChange={(e) =>
                                            setData(
                                                'occurred_at',
                                                e.target.value,
                                            )
                                        }
                                        className={
                                            errors.occurred_at
                                                ? 'border-status-critical/30'
                                                : ''
                                        }
                                    />
                                    {errors.occurred_at && (
                                        <p className="text-sm text-status-critical">
                                            {errors.occurred_at}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="title">
                                    Title{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="title"
                                    placeholder="Brief title for this event"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    className={
                                        errors.title
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.title && (
                                    <p className="text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    placeholder="Detailed description of what occurred..."
                                    rows={5}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    className={
                                        errors.description
                                            ? 'border-status-critical/30'
                                            : ''
                                    }
                                />
                                {errors.description && (
                                    <p className="text-sm text-status-critical">
                                        {errors.description}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="visibility">Visibility</Label>
                                <Select
                                    value={data.visibility}
                                    onValueChange={(value) =>
                                        setData('visibility', value)
                                    }
                                >
                                    <SelectTrigger id="visibility">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="internal">
                                            Internal (HR only)
                                        </SelectItem>
                                        <SelectItem value="restricted">
                                            Restricted (Managers + HR)
                                        </SelectItem>
                                        <SelectItem value="full">
                                            Full (Subject can see)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    Controls who can see this event on the
                                    timeline
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-4">
                        <Link href={`/hr/cases/${hrCase.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Adding...' : 'Add Event'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
