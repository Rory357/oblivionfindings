import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    Info,
    Megaphone,
    Pin,
    Plus,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface Announcement {
    id: number;
    title: string;
    content: string;
    priority: string;
    target_audience: string;
    target_value: string | null;
    published_at: string | null;
    expires_at: string | null;
    is_pinned: boolean;
    requires_acknowledgement: boolean;
    acknowledgements_count: number;
    creator: { id: number; name: string } | null;
    created_at: string;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    announcements: { data: Announcement[]; links: any[] };
    acknowledgedIds: number[];
    priorities: Option[];
    audiences: Option[];
    filters: { priority: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Announcements', href: '/hr/announcements' },
];

const priorityConfig: Record<
    string,
    { className: string; label: string; icon: React.ComponentType<any> }
> = {
    low: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Low',
        icon: Info,
    },
    normal: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'Normal',
        icon: Info,
    },
    high: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'High',
        icon: AlertTriangle,
    },
    urgent: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Urgent',
        icon: AlertCircle,
    },
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

export default function AnnouncementsIndex({
    announcements,
    acknowledgedIds,
    priorities,
    audiences,
    filters,
    can,
}: Props) {
    const NONE = '__none__';
    const [open, setOpen] = useState(false);
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

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/announcements',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const submitCreate = (e: FormEvent) => {
        e.preventDefault();
        form.post('/hr/announcements', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    function handleAcknowledge(id: number) {
        router.post(
            `/hr/announcements/${id}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Announcements" />

            <PageShell>
                <PageHero category="hr"
                    icon={Megaphone}
                    title="Announcements"
                    description="Company-wide announcements and communications."
                    stats={[
                        { label: 'Total', value: announcements.data.length },
                        {
                            label: 'Pinned',
                            value: announcements.data.filter((a) => a.is_pinned).length,
                        },
                        {
                            label: 'Urgent',
                            value: announcements.data.filter((a) => a.priority === 'urgent').length,
                        },
                        {
                            label: 'Acknowledged',
                            value: acknowledgedIds.length,
                        },
                    ]}
                    actions={
                        can.manage && (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Announcement
                            </Button>
                        )
                    }
                />

                {/* Filters */}
                <Card className="mb-4">
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">
                                Priority
                            </Label>
                            <Select
                                value={filters.priority ?? NONE}
                                onValueChange={(v) =>
                                    onFilter({
                                        priority: v === NONE ? null : v,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All priorities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All Priorities
                                    </SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="normal">
                                        Normal
                                    </SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="urgent">
                                        Urgent
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Announcement Feed */}
                <div className="space-y-4">
                    {announcements.data.map((announcement) => {
                        const config =
                            priorityConfig[announcement.priority] ??
                            priorityConfig.normal;
                        const PriorityIcon = config.icon;
                        const isAcknowledged = acknowledgedIds.includes(
                            announcement.id,
                        );

                        return (
                            <Card
                                key={announcement.id}
                                className={
                                    announcement.is_pinned
                                        ? 'border-status-warning/30'
                                        : ''
                                }
                            >
                                <CardContent className="p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                                {announcement.is_pinned && (
                                                    <Pin className="h-4 w-4 text-status-warning" />
                                                )}
                                                <Link
                                                    href={`/hr/announcements/${announcement.id}`}
                                                    className="text-base font-semibold hover:underline"
                                                >
                                                    {announcement.title}
                                                </Link>
                                                <Badge
                                                    className={config.className}
                                                >
                                                    <PriorityIcon className="mr-1 h-3 w-3" />
                                                    {config.label}
                                                </Badge>
                                                {announcement.requires_acknowledgement && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs"
                                                    >
                                                        Requires Acknowledgement
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mb-3 line-clamp-3 text-sm text-muted-foreground">
                                                {announcement.content}
                                            </p>
                                            <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                                                <span>
                                                    By{' '}
                                                    {announcement.creator
                                                        ?.name ?? 'Unknown'}
                                                </span>
                                                <span>
                                                    Published{' '}
                                                    {formatDate(
                                                        announcement.published_at,
                                                    )}
                                                </span>
                                                {announcement.expires_at && (
                                                    <span>
                                                        Expires{' '}
                                                        {formatDate(
                                                            announcement.expires_at,
                                                        )}
                                                    </span>
                                                )}
                                                <span>
                                                    {
                                                        announcement.acknowledgements_count
                                                    }{' '}
                                                    acknowledged
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <Link
                                                href={`/hr/announcements/${announcement.id}`}
                                            >
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    View
                                                </Button>
                                            </Link>
                                            {announcement.requires_acknowledgement &&
                                                !isAcknowledged && (
                                                    <Button
                                                        size="sm"
                                                        variant="default"
                                                        onClick={() =>
                                                            handleAcknowledge(
                                                                announcement.id,
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle className="mr-1 h-3 w-3" />
                                                        Acknowledge
                                                    </Button>
                                                )}
                                            {isAcknowledged && (
                                                <Badge className="border-status-success/30 bg-status-success-bg text-status-success">
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                    Acknowledged
                                                </Badge>
                                            )}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {!announcements.data.length && (
                        <Card>
                            <CardContent className="py-12 text-center text-sm text-muted-foreground">
                                No announcements found.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                <LaravelPagination
                    links={announcements?.links ?? []}
                    className="mt-4"
                />
            </PageShell>

            {/* New Announcement Dialog */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>New Announcement</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div>
                            <Label htmlFor="title">Title</Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="Announcement title"
                            />
                            {form.errors.title && (
                                <p className="mt-1 text-xs text-status-critical">
                                    {form.errors.title}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label htmlFor="content">Content</Label>
                            <Textarea
                                id="content"
                                value={form.data.content}
                                onChange={(e) =>
                                    form.setData('content', e.target.value)
                                }
                                rows={5}
                                placeholder="Write your announcement..."
                            />
                            {form.errors.content && (
                                <p className="mt-1 text-xs text-status-critical">
                                    {form.errors.content}
                                </p>
                            )}
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label>Priority</Label>
                                <Select
                                    value={form.data.priority}
                                    onValueChange={(v) =>
                                        form.setData('priority', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {priorities.map((p) => (
                                            <SelectItem
                                                key={p.value}
                                                value={p.value}
                                            >
                                                {p.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Target Audience</Label>
                                <Select
                                    value={form.data.target_audience}
                                    onValueChange={(v) =>
                                        form.setData('target_audience', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {audiences.map((a) => (
                                            <SelectItem
                                                key={a.value}
                                                value={a.value}
                                            >
                                                {a.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        {form.data.target_audience !== 'all' && (
                            <div>
                                <Label htmlFor="target_value">
                                    Target Value
                                </Label>
                                <Input
                                    id="target_value"
                                    value={form.data.target_value}
                                    onChange={(e) =>
                                        form.setData(
                                            'target_value',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={`Enter ${form.data.target_audience} name or ID`}
                                />
                            </div>
                        )}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="published_at">Publish At</Label>
                                <Input
                                    id="published_at"
                                    type="datetime-local"
                                    value={form.data.published_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'published_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Leave blank to publish immediately.
                                </p>
                            </div>
                            <div>
                                <Label htmlFor="expires_at">Expires At</Label>
                                <Input
                                    id="expires_at"
                                    type="datetime-local"
                                    value={form.data.expires_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'expires_at',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Leave blank for no expiry.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col gap-3">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.is_pinned}
                                    onCheckedChange={(c) =>
                                        form.setData('is_pinned', c === true)
                                    }
                                />
                                Pin this announcement
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.requires_acknowledgement}
                                    onCheckedChange={(c) =>
                                        form.setData(
                                            'requires_acknowledgement',
                                            c === true,
                                        )
                                    }
                                />
                                Require staff acknowledgement
                            </label>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Publish Announcement
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
