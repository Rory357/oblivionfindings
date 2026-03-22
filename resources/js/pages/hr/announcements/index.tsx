import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Pin, Plus, CheckCircle, AlertTriangle, AlertCircle, Info } from 'lucide-react';

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

interface Props {
    announcements: { data: Announcement[]; links: any[] };
    acknowledgedIds: number[];
    filters: { priority: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Announcements', href: '/hr/announcements' },
];

const priorityConfig: Record<string, { className: string; label: string; icon: React.ComponentType<any> }> = {
    low: {
        className: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
        label: 'Low',
        icon: Info,
    },
    normal: {
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        label: 'Normal',
        icon: Info,
    },
    high: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'High',
        icon: AlertTriangle,
    },
    urgent: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Urgent',
        icon: AlertCircle,
    },
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

export default function AnnouncementsIndex({ announcements, acknowledgedIds, filters, can }: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/announcements', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    function handleAcknowledge(id: number) {
        router.post(`/hr/announcements/${id}/acknowledge`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Announcements" />

            <PageShell>
                <PageHeader title="Announcements" description="Company-wide announcements and communications.">
                    {can.manage && (
                        <Link href="/hr/announcements/create">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Announcement
                            </Button>
                        </Link>
                    )}
                </PageHeader>

                {/* Filters */}
                <Card className="mb-4">
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-slate-500">Priority</Label>
                            <Select
                                value={filters.priority ?? NONE}
                                onValueChange={(v) => onFilter({ priority: v === NONE ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="All priorities" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Priorities</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="normal">Normal</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="urgent">Urgent</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* Announcement Feed */}
                <div className="space-y-4">
                    {announcements.data.map((announcement) => {
                        const config = priorityConfig[announcement.priority] ?? priorityConfig.normal;
                        const PriorityIcon = config.icon;
                        const isAcknowledged = acknowledgedIds.includes(announcement.id);

                        return (
                            <Card key={announcement.id} className={announcement.is_pinned ? 'border-yellow-500/30' : ''}>
                                <CardContent className="p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                                {announcement.is_pinned && (
                                                    <Pin className="h-4 w-4 text-yellow-500" />
                                                )}
                                                <Link
                                                    href={`/hr/announcements/${announcement.id}`}
                                                    className="text-base font-semibold hover:underline"
                                                >
                                                    {announcement.title}
                                                </Link>
                                                <Badge className={config.className}>
                                                    <PriorityIcon className="mr-1 h-3 w-3" />
                                                    {config.label}
                                                </Badge>
                                                {announcement.requires_acknowledgement && (
                                                    <Badge variant="outline" className="text-xs">
                                                        Requires Acknowledgement
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mb-3 line-clamp-3 text-sm text-slate-600">
                                                {announcement.content}
                                            </p>
                                            <div className="flex flex-wrap items-center gap-4 text-xs text-slate-500">
                                                <span>By {announcement.creator?.name ?? 'Unknown'}</span>
                                                <span>Published {formatDate(announcement.published_at)}</span>
                                                {announcement.expires_at && (
                                                    <span>Expires {formatDate(announcement.expires_at)}</span>
                                                )}
                                                <span>{announcement.acknowledgements_count} acknowledged</span>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <Link href={`/hr/announcements/${announcement.id}`}>
                                                <Button size="sm" variant="outline">View</Button>
                                            </Link>
                                            {announcement.requires_acknowledgement && !isAcknowledged && (
                                                <Button
                                                    size="sm"
                                                    variant="default"
                                                    onClick={() => handleAcknowledge(announcement.id)}
                                                >
                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                    Acknowledge
                                                </Button>
                                            )}
                                            {isAcknowledged && (
                                                <Badge className="border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
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
                            <CardContent className="py-12 text-center text-sm text-slate-500">
                                No announcements found.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                {announcements?.links?.length ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {announcements.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
