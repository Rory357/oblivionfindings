import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
        { value: 'automated_decision', label: 'Automated Decision Rights (Art. 22)' },
    ];

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Subject Requests', href: '/privacy/requests' },
            { title: 'New Request', href: '/privacy/requests/create' },
        ]}>
            <Head title="New Data Subject Request" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">New Data Subject Request</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Record a new GDPR data subject request
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-5 w-5 text-blue-500" />
                                Request Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="request_type">Request Type *</Label>
                                    <Select
                                        value={data.request_type}
                                        onValueChange={(v) => setData('request_type', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select request type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {requestTypes.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.request_type && (
                                        <p className="text-xs text-red-500">{errors.request_type}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="assigned_to_user_id">Assign To</Label>
                                    <Select
                                        value={data.assigned_to_user_id}
                                        onValueChange={(v) => setData('assigned_to_user_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select staff member" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staff.map((user) => (
                                                <SelectItem key={user.id} value={String(user.id)}>
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="subject_name">Requester Name *</Label>
                                    <Input
                                        id="subject_name"
                                        value={data.subject_name}
                                        onChange={(e) => setData('subject_name', e.target.value)}
                                        placeholder="Full name of the data subject"
                                    />
                                    {errors.subject_name && (
                                        <p className="text-xs text-red-500">{errors.subject_name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="subject_email">Requester Email *</Label>
                                    <Input
                                        id="subject_email"
                                        type="email"
                                        value={data.subject_email}
                                        onChange={(e) => setData('subject_email', e.target.value)}
                                        placeholder="email@example.com"
                                    />
                                    {errors.subject_email && (
                                        <p className="text-xs text-red-500">{errors.subject_email}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="request_details">Request Details</Label>
                                <Textarea
                                    id="request_details"
                                    value={data.request_details}
                                    onChange={(e) => setData('request_details', e.target.value)}
                                    placeholder="Details of the request, specific data requested, etc."
                                    rows={4}
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-4">
                                <Button type="button" variant="outline" onClick={() => window.history.back()}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create Request'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
