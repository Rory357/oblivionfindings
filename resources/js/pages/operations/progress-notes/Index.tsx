import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BookOpen, Eye, Flag, MessageSquareText, Search, Smile } from 'lucide-react';

const ANY = '__ANY__';

type Note = {
    id: number;
    note_type: string;
    content: string;
    mood_rating: number | null;
    is_flagged: boolean;
    flagged_reason: string | null;
    visibility: string;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    author: { id: number; name: string } | null;
    shift: { id: number; starts_at: string } | null;
    goal: { id: number; title: string } | null;
};

type Props = {
    notes: {
        data: Note[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { q?: string; note_type?: string; flagged?: string; client_id?: string };
    stats: { total: number; flagged: number; this_week: number };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

const TYPE_LABELS: Record<string, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    incident: 'Incident',
    goal_update: 'Goal Update',
    general: 'General',
};

const MOOD_EMOJI = ['', '1', '2', '3', '4', '5'];

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export default function ProgressNotesIndex({ notes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any, clients = [] }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/progress-notes', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Progress Notes" />
            <PageHeader title="Progress Notes" description={`${clientSingular} progress notes, observations, and goal updates.`} backHref="/operations" />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-3 gap-3">
                    <OpsStatCard label="Total Notes" value={stats?.total ?? 0} icon={MessageSquareText} color="indigo" />
                    <OpsStatCard label="This Week" value={stats?.this_week ?? 0} icon={BookOpen} color="blue" />
                    <OpsStatCard label="Flagged" value={stats?.flagged ?? 0} icon={Flag} color={stats?.flagged > 0 ? 'red' : 'slate'} />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search notes..." className="h-9 pl-8 text-sm" defaultValue={filters?.q ?? ''} onChange={(e) => updateFilters('q', e.target.value || null)} />
                    </div>
                    <Select value={filters?.client_id ?? ANY} onValueChange={(v) => updateFilters('client_id', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[160px] text-xs"><SelectValue placeholder={`All ${clientPlural}`} /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>{`All ${clientPlural}`}</SelectItem>
                            {(clients ?? []).map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters?.note_type ?? ANY} onValueChange={(v) => updateFilters('note_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[120px] text-xs"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(TYPE_LABELS).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button size="sm" variant={filters?.flagged === '1' ? 'default' : 'outline'} className="h-9 text-xs"
                        onClick={() => updateFilters('flagged', filters?.flagged === '1' ? null : '1')}>
                        <Flag className="mr-1 h-3 w-3" /> Flagged
                    </Button>
                </div>

                {/* Notes list */}
                <div className="mt-4 space-y-2">
                    {(notes?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <MessageSquareText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Progress Notes</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Notes will appear as staff record observations and updates.</p>
                            </CardContent>
                        </Card>
                    )}
                    {(notes?.data ?? []).map((note) => (
                        <Card key={note.id} className={`transition-all hover:shadow-sm ${note.is_flagged ? 'border-red-200 bg-red-50/30 dark:border-red-900/30 dark:bg-red-950/10' : ''}`}>
                            <CardContent className="p-4">
                                <div className="flex items-start gap-3">
                                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${
                                        note.note_type === 'incident' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' :
                                        note.note_type === 'goal_update' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' :
                                        'bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300'
                                    }`}>
                                        <MessageSquareText className="h-3.5 w-3.5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-semibold">{note.author?.name ?? 'Unknown'}</span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">{TYPE_LABELS[note.note_type] ?? note.note_type}</Badge>
                                            {note.is_flagged && <Badge variant="destructive" className="h-4 px-1.5 text-[9px]">Flagged</Badge>}
                                            {note.mood_rating && (
                                                <span className="flex items-center gap-0.5 text-[10px] text-muted-foreground">
                                                    <Smile className="h-3 w-3" /> {note.mood_rating}/5
                                                </span>
                                            )}
                                            {note.visibility === 'include_family' && (
                                                <Badge variant="outline" className="h-4 px-1.5 text-[9px]">Family visible</Badge>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm leading-relaxed">{note.content.length > 300 ? note.content.slice(0, 300) + '...' : note.content}</p>
                                        <div className="mt-1.5 flex items-center gap-3 text-[10px] text-muted-foreground">
                                            {note.client && (
                                                <Link href={`/operations/clients/${note.client.id}`} className="hover:underline">
                                                    {note.client.first_name} {note.client.last_name}
                                                </Link>
                                            )}
                                            {note.goal && <span>Goal: {note.goal.title}</span>}
                                            {note.shift && <span>Shift #{note.shift.id}</span>}
                                            <span>{formatRelativeTime(note.created_at)}</span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(notes?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(notes?.links ?? []).map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
