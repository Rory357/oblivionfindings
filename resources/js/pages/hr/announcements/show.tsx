import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { CheckCircle, Pin, AlertTriangle, AlertCircle, Info } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

interface Acknowledgement {
    id: number;
    user_id: number;
    acknowledged_at: string;
    user: { id: number; name: string };
}

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
    creator: { id: number; name: string } | null;
    acknowledgements: Acknowledgement[];
    created_at: string;
}

interface Props {
    announcement: Announcement;
    userAcknowledged: boolean;
    can: { manage: boolean };
}

const priorityConfig: Record<string, { className: string; label: string }> = {
    low: { className: 'border-slate-500/30 text-slate-400 bg-slate-500/10', label: 'Low' },
    normal: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Normal' },
    high: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'High' },
    urgent: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Urgent' },
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

export default function AnnouncementShow({ announcement, userAcknowledged, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Announcements', href: '/hr/announcements' },
        { title: announcement.title, href: `/hr/announcements/${announcement.id}` },
    ];

    const config = priorityConfig[announcement.priority] ?? priorityConfig.normal;

    function handleAcknowledge() {
        router.post(`/hr/announcements/${announcement.id}/acknowledge`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={announcement.title} />

            <PageShell>
                <PageHeader title={announcement.title} description={`Published ${formatDate(announcement.published_at)}`} />

                <div className="space-y-4">
                    {/* Meta info */}
                    <Card>
                        <CardContent className="p-5">
                            <div className="mb-4 flex flex-wrap items-center gap-2">
                                {announcement.is_pinned && <Pin className="h-4 w-4 text-yellow-500" />}
                                <Badge className={config.className}>{config.label} Priority</Badge>
                                <Badge variant="outline" className="capitalize">{announcement.target_audience}</Badge>
                                {announcement.requires_acknowledgement && (
                                    <Badge variant="outline">Requires Acknowledgement</Badge>
                                )}
                            </div>

                            <div className="prose max-w-none whitespace-pre-wrap text-sm">
                                {announcement.content}
                            </div>

                            <div className="mt-6 flex flex-wrap items-center gap-4 border-t pt-4 text-xs text-slate-500">
                                <span>Posted by {announcement.creator?.name ?? 'Unknown'}</span>
                                <span>Published {formatDateTime(announcement.published_at)}</span>
                                {announcement.expires_at && <span>Expires {formatDateTime(announcement.expires_at)}</span>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Acknowledgement Action */}
                    {announcement.requires_acknowledgement && (
                        <Card>
                            <CardContent className="flex items-center justify-between p-5">
                                <div>
                                    <p className="font-medium">Acknowledgement Required</p>
                                    <p className="text-sm text-slate-500">
                                        Please confirm you have read and understood this announcement.
                                    </p>
                                </div>
                                {userAcknowledged ? (
                                    <Badge className="border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
                                        <CheckCircle className="mr-1 h-3 w-3" />
                                        Acknowledged
                                    </Badge>
                                ) : (
                                    <Button onClick={handleAcknowledge}>
                                        <CheckCircle className="mr-1.5 h-4 w-4" />
                                        Acknowledge
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Acknowledgements List (for managers) */}
                    {can.manage && announcement.requires_acknowledgement && announcement.acknowledgements.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Acknowledgements ({announcement.acknowledgements.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Staff Member</TableHead>
                                            <TableHead>Acknowledged At</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {announcement.acknowledgements.map((ack) => (
                                            <TableRow key={ack.id}>
                                                <TableCell className="font-medium">{ack.user.name}</TableCell>
                                                <TableCell>{formatDateTime(ack.acknowledged_at)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
