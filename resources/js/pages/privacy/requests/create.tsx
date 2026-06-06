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
import { Head, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';

type Props = {
    staff: Array<{ id: number; name: string }>;
};

export default function CreateDataSubjectRequest({ staff }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        request_type: '',
        subject_name: '',
        subject_email: '',
        request_details: '',
        assigned_to_user_id: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/privacy/requests');
    };

    const requestTypes = [
        { value: 'access', label: 'Right to Access (Art. 15)' },
        { value: 'rectification', label: 'Right to Rectification (Art. 16)' },
        { value: 'erasure', label: 'Right to Erasure (Art. 17)' },
        { value: 'restriction', label: 'Right to Restriction (Art. 18)' },
        { value: 'portability', label: 'Right to Portability (Art. 20)' },
        { value: 'objection', label: 'Right to Object (Art. 21)' },
        {
            value: 'automated_decision',
            label: 'Automated Decision Rights (Art. 22)',
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Privacy Requests', href: '/privacy/requests' },
                { title: 'New Request', href: '/privacy/requests/create' },
            ]}
        >
            <Head title="New Privacy Request" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/privacy/requests"
                        title="New Privacy Request"
                        description="Record a new Privacy Act 2020 privacy request"
                    />
                }
            >
                <form
                    onSubmit={handleSubmit}
                    data-test="privacy-dsr-create-form"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-status-info" />
                                Request Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="request_type">
                                        Request Type *
                                    </Label>
                                    <Select
                                        value={data.request_type}
                                        onValueChange={(v) =>
                                            setData('request_type', v)
                                        }
                                    >
                                        <SelectTrigger data-test="privacy-dsr-request-type-select">
                                            <SelectValue placeholder="Select request type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {requestTypes.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.request_type && (
                                        <p className="text-xs text-status-critical">
                                            {errors.request_type}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="assigned_to_user_id">
                                        Assign To
                                    </Label>
                                    <Select
                                        value={data.assigned_to_user_id}
                                        onValueChange={(v) =>
                                            setData('assigned_to_user_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select staff member" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((user) => (
                                                <SelectItem
                                                    key={user.id}
                                                    value={String(user.id)}
                                                >
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="subject_name">
                                        Requester Name *
                                    </Label>
                                    <Input
                                        id="subject_name"
                                        data-test="privacy-dsr-subject-name"
                                        value={data.subject_name}
                                        onChange={(e) =>
                                            setData(
                                                'subject_name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Full name of the person"
                                    />
                                    {errors.subject_name && (
                                        <p className="text-xs text-status-critical">
                                            {errors.subject_name}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="subject_email">
                                        Requester Email *
                                    </Label>
                                    <Input
                                        id="subject_email"
                                        data-test="privacy-dsr-subject-email"
                                        type="email"
                                        value={data.subject_email}
                                        onChange={(e) =>
                                            setData(
                                                'subject_email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="email@example.com"
                                    />
                                    {errors.subject_email && (
                                        <p className="text-xs text-status-critical">
                                            {errors.subject_email}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="request_details">
                                    Request Details
                                </Label>
                                <Textarea
                                    id="request_details"
                                    data-test="privacy-dsr-details"
                                    value={data.request_details}
                                    onChange={(e) =>
                                        setData(
                                            'request_details',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Details of the request, specific data requested, etc."
                                    rows={4}
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => window.history.back()}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="privacy-dsr-submit"
                                >
                                    {processing
                                        ? 'Creating...'
                                        : 'Create Request'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
