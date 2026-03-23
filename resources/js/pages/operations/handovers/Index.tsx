import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock, GitBranch, Search, Users } from 'lucide-react';

type Handover = {
    id: number;
    handover_notes: string;
    client_mood: string | null;
    tasks_pending: any[] | null;
    acknowledged_at: string | null;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    outgoing_staff: { id: number; name: string } | null;
    incoming_staff: { id: number; name: string } | null;
    outgoing_shift: { id: number; starts_at: string; ends_at: string } | null;
};

type Props = {
    handovers: {
        data: Handover[];
        links: any[];
        total: number;
        last_page: number;
    };
    filters: { q?: string; date?: string };
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export default function HandoversIndex({ handovers, filters }: Props) {
    return (
        <AppLayout>
            <Head title="Shift Handovers" />
            <PageHeader title="Shift Handovers" description="Handover notes between outgoing and incoming shift staff." backHref="/operations" />
            <PageShell>
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search handovers..." className="h-9 pl-8 text-sm" defaultValue={filters.q ?? ''}
                            onChange={(e) => router.get('/operations/handovers', { ...filters, q: e.target.value || null }, { preserveState: true, replace: true })} />
                    </div>
                    <Input type="date" className="h-9 w-[160px] text-xs" value={filters.date ?? ''}
                        onChange={(e) => router.get('/operations/handovers', { ...filters, date: e.target.value || null }, { preserveState: true, replace: true })} />
                </div>
                <div className="space-y-2">
                    {handovers.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <GitBranch className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Handovers</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Shift handover notes will appear here.</p>
                            </CardContent>
                        </Card>
                    )}
                    {handovers.data.map((h) => (
                        <Card key={h.id} className="transition-all hover:shadow-sm">
                            <CardContent className="p-4">
                                <div className="flex items-start gap-3">
                                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${h.acknowledged_at ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40'}`}>
                                        {h.acknowledged_at ? <CheckCircle2 className="h-4 w-4" /> : <Clock className="h-4 w-4" />}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-semibold">
                                                {h.outgoing_staff?.name ?? 'Unknown'} → {h.incoming_staff?.name ?? 'TBD'}
                                            </span>
                                            <Badge variant={h.acknowledged_at ? 'default' : 'outline'} className="h-4 px-1.5 text-[9px]">
                                                {h.acknowledged_at ? 'Acknowledged' : 'Pending'}
                                            </Badge>
                                            {h.client_mood && <Badge variant="outline" className="h-4 px-1.5 text-[9px]">Mood: {h.client_mood}</Badge>}
                                        </div>
                                        <p className="mt-1 text-sm">{h.handover_notes.length > 200 ? h.handover_notes.slice(0, 200) + '...' : h.handover_notes}</p>
                                        {h.tasks_pending && h.tasks_pending.length > 0 && (
                                            <div className="mt-1.5 text-[10px] text-muted-foreground">
                                                {h.tasks_pending.length} pending task{h.tasks_pending.length !== 1 ? 's' : ''}
                                            </div>
                                        )}
                                        <div className="mt-1.5 flex items-center gap-3 text-[10px] text-muted-foreground">
                                            {h.client && (
                                                <Link href={`/operations/clients/${h.client.id}`} className="flex items-center gap-1 hover:underline">
                                                    <Users className="h-3 w-3" /> {h.client.first_name} {h.client.last_name}
                                                </Link>
                                            )}
                                            {h.outgoing_shift && (
                                                <Link href={`/operations/shifts/${h.outgoing_shift.id}`} className="hover:underline">
                                                    Shift {formatTime(h.outgoing_shift.starts_at)} - {formatTime(h.outgoing_shift.ends_at)}
                                                </Link>
                                            )}
                                            <span>{formatRelativeTime(h.created_at)}</span>
                                        </div>
                                    </div>
                                    {!h.acknowledged_at && (
                                        <Button size="sm" variant="outline" className="h-7 text-xs"
                                            onClick={() => router.patch(`/operations/handovers/${h.id}/acknowledge`, {}, { preserveScroll: true })}>
                                            Acknowledge
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                {handovers.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {handovers.links.map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
