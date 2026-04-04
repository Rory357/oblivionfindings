import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Calendar, Check, Clock, MapPin, MessageSquare, Users, Video, X } from 'lucide-react';
import { useState } from 'react';

type VisitRequest = {
    id: number;
    requested_date: string;
    preferred_time_start?: string | null;
    preferred_time_end?: string | null;
    visit_type: string;
    notes?: string | null;
    status: string;
    review_notes?: string | null;
    reviewed_at?: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
    reviewer: { id: number; name: string } | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    requests: { data: VisitRequest[]; links: any[]; current_page: number; last_page: number; total: number };
    filter: string;
    stats: { pending: number; approved_this_month: number; total: number };
};

const statusColors: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800',
    approved: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-600',
};

const visitTypeIcons: Record<string, { icon: typeof Calendar; label: string }> = {
    in_person: { icon: Users, label: 'In Person' },
    video_call: { icon: Video, label: 'Video Call' },
    outing: { icon: MapPin, label: 'Outing' },
};

export default function VisitRequests({ client, requests, filter, stats }: Props) {
    const name = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const [reviewNotes, setReviewNotes] = useState<Record<number, string>>({});

    const setFilter = (f: string) => {
        router.get(`/operations/clients/${client.id}/visit-requests`, { filter: f }, { preserveState: true });
    };

    const approve = (visitId: number) => {
        router.post(`/operations/clients/${client.id}/visit-requests/${visitId}/approve`, {
            review_notes: reviewNotes[visitId] || null,
        }, { preserveScroll: true });
    };

    const decline = (visitId: number) => {
        router.post(`/operations/clients/${client.id}/visit-requests/${visitId}/decline`, {
            review_notes: reviewNotes[visitId] || null,
        }, { preserveScroll: true });
    };

    const filters = [
        { key: 'pending', label: 'Pending', count: stats.pending },
        { key: 'approved', label: 'Approved' },
        { key: 'declined', label: 'Declined' },
        { key: 'all', label: 'All' },
    ];

    return (
        <AppLayout breadcrumbs={[
            { title: 'Clients', href: '/clients' },
            { title: name, href: `/operations/clients/${client.id}` },
            { title: 'Visit Requests', href: `/operations/clients/${client.id}/visit-requests` },
        ]}>
            <Head title={`Visit Requests - ${name}`} />

            <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
                {/* Hero */}
                <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600/90 via-emerald-500 to-teal-500/80 p-6 text-white">
                    <div className="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/5" />
                    <div className="relative flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-bold">Family Visit Requests</h1>
                            <p className="mt-1 text-sm text-white/70">Manage visit requests for {name}</p>
                        </div>
                        <Button size="sm" variant="outline" className="border-white/20 bg-white/10 text-white hover:bg-white/20" asChild>
                            <Link href={`/operations/clients/${client.id}`}>
                                <ArrowLeft className="mr-1.5 h-3.5 w-3.5" />Back
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-xl border bg-gradient-to-br from-amber-50 to-yellow-50 p-4 text-center">
                        <div className="text-2xl font-bold text-amber-700">{stats.pending}</div>
                        <div className="text-[10px] uppercase tracking-wider text-amber-500">Pending</div>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-4 text-center">
                        <div className="text-2xl font-bold text-emerald-700">{stats.approved_this_month}</div>
                        <div className="text-[10px] uppercase tracking-wider text-emerald-500">Approved This Month</div>
                    </div>
                    <div className="rounded-xl border p-4 text-center">
                        <div className="text-2xl font-bold text-slate-700">{stats.total}</div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Total Requests</div>
                    </div>
                </div>

                {/* Filter tabs */}
                <div className="overflow-x-auto border-b">
                    <div className="flex w-max items-center gap-1">
                        {filters.map(f => (
                            <button
                                key={f.key}
                                onClick={() => setFilter(f.key)}
                                className={`inline-flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors ${
                                    filter === f.key
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {f.label}
                                {'count' in f && f.count != null && f.count > 0 && (
                                    <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none ${
                                        filter === f.key ? 'bg-primary/10 text-primary' : 'bg-amber-100 text-amber-700'
                                    }`}>{f.count}</span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Requests list */}
                {requests.data.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Calendar className="mb-3 h-10 w-10 text-muted-foreground/40" />
                            <p className="font-medium">No visit requests</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {filter === 'pending' ? 'No pending requests to review.' : 'No requests match this filter.'}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {requests.data.map(req => {
                            const vt = visitTypeIcons[req.visit_type] ?? visitTypeIcons.in_person;
                            const VtIcon = vt.icon;
                            return (
                                <Card key={req.id} className={`overflow-hidden transition-all hover:shadow-sm ${req.status === 'pending' ? 'border-amber-200' : ''}`}>
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-4">
                                            {/* Requester avatar */}
                                            <Avatar className="h-10 w-10 shrink-0">
                                                <AvatarFallback className="bg-emerald-100 text-emerald-700 text-xs">
                                                    {getInitials(req.user?.name ?? '?')}
                                                </AvatarFallback>
                                            </Avatar>

                                            <div className="min-w-0 flex-1">
                                                {/* Header */}
                                                <div className="flex items-start justify-between gap-2">
                                                    <div>
                                                        <p className="text-sm font-semibold">{req.user?.name ?? 'Unknown'}</p>
                                                        <p className="text-xs text-muted-foreground">{req.user?.email}</p>
                                                    </div>
                                                    <Badge className={`border-0 capitalize ${statusColors[req.status] ?? ''}`}>{req.status}</Badge>
                                                </div>

                                                {/* Visit details */}
                                                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                                        <Calendar className="h-3.5 w-3.5" />
                                                        {new Date(req.requested_date).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}
                                                    </div>
                                                    {req.preferred_time_start && (
                                                        <div className="flex items-center gap-1.5 text-muted-foreground">
                                                            <Clock className="h-3.5 w-3.5" />
                                                            {req.preferred_time_start}{req.preferred_time_end ? ` - ${req.preferred_time_end}` : ''}
                                                        </div>
                                                    )}
                                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                                        <VtIcon className="h-3.5 w-3.5" />
                                                        {vt.label}
                                                    </div>
                                                </div>

                                                {req.notes && (
                                                    <div className="mt-2 flex items-start gap-1.5">
                                                        <MessageSquare className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                                        <p className="text-sm text-muted-foreground">{req.notes}</p>
                                                    </div>
                                                )}

                                                {/* Review info (for approved/declined) */}
                                                {req.reviewer && (
                                                    <div className="mt-2 rounded-lg bg-muted/50 p-2 text-xs text-muted-foreground">
                                                        {req.status === 'approved' ? 'Approved' : 'Declined'} by {req.reviewer.name}
                                                        {req.reviewed_at && ` on ${new Date(req.reviewed_at).toLocaleDateString('en-NZ')}`}
                                                        {req.review_notes && ` — "${req.review_notes}"`}
                                                    </div>
                                                )}

                                                {/* Actions for pending */}
                                                {req.status === 'pending' && (
                                                    <div className="mt-3 space-y-2">
                                                        <Input
                                                            placeholder="Add a note (optional)..."
                                                            className="h-8 text-xs"
                                                            value={reviewNotes[req.id] ?? ''}
                                                            onChange={e => setReviewNotes({ ...reviewNotes, [req.id]: e.target.value })}
                                                        />
                                                        <div className="flex gap-2">
                                                            <Button size="sm" className="gap-1.5 bg-emerald-600 hover:bg-emerald-700" onClick={() => approve(req.id)}>
                                                                <Check className="h-3.5 w-3.5" />Approve
                                                            </Button>
                                                            <Button size="sm" variant="outline" className="gap-1.5 text-red-600 border-red-200 hover:bg-red-50" onClick={() => decline(req.id)}>
                                                                <X className="h-3.5 w-3.5" />Decline
                                                            </Button>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {/* Pagination */}
                {requests.last_page > 1 && (
                    <div className="flex justify-center gap-1">
                        {requests.links.map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url} onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
