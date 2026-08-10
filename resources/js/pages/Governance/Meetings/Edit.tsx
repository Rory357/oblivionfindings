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
import { update as meetingsUpdate } from '@/routes/governance/meetings';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';

interface Meeting {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    virtual_link: string | null;
    notes: string | null;
    status: string;
    chair: { id: number; user: { name: string } } | null;
    secretary: { id: number; user: { name: string } } | null;
}

interface Props extends PageProps {
    meeting: Meeting;
    boardMembers: Array<{ id: number; user: { name: string } }>;
    committees: Array<{ id: number; name: string; committee_type: string }>;
}

const toLocalInput = (value: string) => {
    const date = new Date(value);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

export default function MeetingEdit({ auth, meeting, boardMembers }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        title: meeting.title ?? '',
        scheduled_at: meeting.scheduled_at
            ? toLocalInput(meeting.scheduled_at)
            : '',
        duration_minutes: meeting.duration_minutes ?? 120,
        location: meeting.location ?? '',
        virtual_link: meeting.virtual_link ?? '',
        notes: meeting.notes ?? '',
        status: meeting.status ?? 'scheduled',
        chair_id: meeting.chair?.id ? String(meeting.chair.id) : '',
        secretary_id: meeting.secretary?.id ? String(meeting.secretary.id) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        put(meetingsUpdate.url({ meeting: meeting.id }));
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
                {
                    title: 'Edit',
                    href: `/governance/meetings/${meeting.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit Meeting - ${meeting.title}`} />

            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <Card>
                    <CardHeader>
                        <CardTitle>Edit Meeting</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="title">Meeting Title</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                />
                                {errors.title && (
                                    <p className="text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="scheduled_at">
                                        Date & Time
                                    </Label>
                                    <Input
                                        id="scheduled_at"
                                        type="datetime-local"
                                        value={data.scheduled_at}
                                        onChange={(e) =>
                                            setData(
                                                'scheduled_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.scheduled_at && (
                                        <p className="text-sm text-status-critical">
                                            {errors.scheduled_at}
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
                                        value={data.duration_minutes}
                                        onChange={(e) =>
                                            setData(
                                                'duration_minutes',
                                                parseInt(e.target.value),
                                            )
                                        }
                                        min={30}
                                        max={480}
                                        step={15}
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    value={data.location}
                                    onChange={(e) =>
                                        setData('location', e.target.value)
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="virtual_link">
                                    Virtual Link
                                </Label>
                                <Input
                                    id="virtual_link"
                                    value={data.virtual_link}
                                    onChange={(e) =>
                                        setData('virtual_link', e.target.value)
                                    }
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="chair_id">Chair</Label>
                                    <Select
                                        value={data.chair_id || undefined}
                                        onValueChange={(value) =>
                                            setData('chair_id', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select chair" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {boardMembers.map((member) => (
                                                <SelectItem
                                                    key={member.id}
                                                    value={String(member.id)}
                                                >
                                                    {member.user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="secretary_id">
                                        Secretary
                                    </Label>
                                    <Select
                                        value={data.secretary_id || undefined}
                                        onValueChange={(value) =>
                                            setData('secretary_id', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select secretary" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {boardMembers.map((member) => (
                                                <SelectItem
                                                    key={member.id}
                                                    value={String(member.id)}
                                                >
                                                    {member.user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="status">Status</Label>
                                <Select
                                    value={data.status}
                                    onValueChange={(value) =>
                                        setData('status', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="scheduled">
                                            Scheduled
                                        </SelectItem>
                                        <SelectItem value="agenda_draft">
                                            Agenda draft
                                        </SelectItem>
                                        <SelectItem value="agenda_final">
                                            Agenda final
                                        </SelectItem>
                                        <SelectItem value="in_progress">
                                            In progress
                                        </SelectItem>
                                        <SelectItem value="minutes_draft">
                                            Minutes draft
                                        </SelectItem>
                                        <SelectItem value="minutes_review">
                                            Minutes review
                                        </SelectItem>
                                        <SelectItem value="minutes_approved">
                                            Minutes approved
                                        </SelectItem>
                                        <SelectItem value="minutes_signed">
                                            Minutes signed
                                        </SelectItem>
                                        <SelectItem value="archived">
                                            Archived
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                    rows={3}
                                />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
