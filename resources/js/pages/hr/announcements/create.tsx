import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type BreadcrumbItem = { title: string; href: string };

interface Props {
    priorities: { value: string; label: string }[];
    audiences: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Announcements', href: '/hr/announcements' },
    { title: 'Create', href: '/hr/announcements/create' },
];

export default function AnnouncementCreate({ priorities, audiences }: Props) {
    const form = useForm({
        title: '',
        content: '',
        priority: 'normal',
        target_audience: 'all',
        target_value: '',
        published_at: '',
        expires_at: '',
        is_pinned: false,
        requires_acknowledgement: false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/hr/announcements');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Announcement" />

            <PageShell>
                <PageHeader title="Create Announcement" description="Publish a new announcement to staff." />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Announcement Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Title</Label>
                                <Input
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder="Announcement title"
                                />
                                {form.errors.title && <p className="mt-1 text-xs text-red-500">{form.errors.title}</p>}
                            </div>

                            <div>
                                <Label>Content</Label>
                                <Textarea
                                    value={form.data.content}
                                    onChange={(e) => form.setData('content', e.target.value)}
                                    rows={6}
                                    placeholder="Write your announcement..."
                                />
                                {form.errors.content && <p className="mt-1 text-xs text-red-500">{form.errors.content}</p>}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Priority</Label>
                                    <Select
                                        value={form.data.priority}
                                        onValueChange={(v) => form.setData('priority', v)}
                                    >
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {priorities.map((p) => (
                                                <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Target Audience</Label>
                                    <Select
                                        value={form.data.target_audience}
                                        onValueChange={(v) => form.setData('target_audience', v)}
                                    >
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {audiences.map((a) => (
                                                <SelectItem key={a.value} value={a.value}>{a.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {form.data.target_audience !== 'all' && (
                                <div>
                                    <Label>Target Value</Label>
                                    <Input
                                        value={form.data.target_value}
                                        onChange={(e) => form.setData('target_value', e.target.value)}
                                        placeholder={`Enter ${form.data.target_audience} name or ID`}
                                    />
                                </div>
                            )}

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Publish At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.published_at}
                                        onChange={(e) => form.setData('published_at', e.target.value)}
                                    />
                                    <p className="mt-1 text-xs text-slate-500">Leave blank to publish immediately.</p>
                                </div>
                                <div>
                                    <Label>Expires At</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.expires_at}
                                        onChange={(e) => form.setData('expires_at', e.target.value)}
                                    />
                                    <p className="mt-1 text-xs text-slate-500">Leave blank for no expiry.</p>
                                </div>
                            </div>

                            <div className="flex flex-col gap-3">
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="is_pinned"
                                        checked={form.data.is_pinned}
                                        onChange={(e) => form.setData('is_pinned', e.target.checked)}
                                        className="rounded border-gray-300"
                                    />
                                    <Label htmlFor="is_pinned">Pin this announcement</Label>
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        id="requires_acknowledgement"
                                        checked={form.data.requires_acknowledgement}
                                        onChange={(e) => form.setData('requires_acknowledgement', e.target.checked)}
                                        className="rounded border-gray-300"
                                    />
                                    <Label htmlFor="requires_acknowledgement">Require staff acknowledgement</Label>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Publish Announcement
                        </Button>
                        <Button type="button" variant="outline" onClick={() => router.get('/hr/announcements')}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
