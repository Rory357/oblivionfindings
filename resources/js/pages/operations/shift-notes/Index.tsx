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
import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, CalendarDays, Lock, Search } from 'lucide-react';

const ANY = '__ANY__';

type ShiftNote = {
    id: number;
    note_type: string;
    content: string;
    is_private: boolean;
    created_at: string;
    shift: { id: number; starts_at: string; client: { id: number; first_name: string; last_name: string } | null } | null;
    author: { id: number; name: string } | null;
};

type Props = {
    notes: {
        data: ShiftNote[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { q?: string; note_type?: string };
};

const TYPE_LABELS: Record<string, string> = {
    general: 'General',
    handover: 'Handover',
    incident: 'Incident',
    observation: 'Observation',
    task_update: 'Task Update',
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export default function ShiftNotesIndex({ notes, filters }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/shift-notes', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Shift Notes" />
            <PageHeader title="Shift Notes" description="All shift notes across clients and staff." backHref="/operations" />
            <PageShell>
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search notes..." className="h-9 pl-8 text-sm" defaultValue={filters.q ?? ''} onChange={(e) => updateFilters('q', e.target.value || null)} />
                    </div>
                    <Select value={filters.note_type ?? ANY} onValueChange={(v) => updateFilters('note_type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[140px] text-xs"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(TYPE_LABELS).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-2">
                    {notes.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <BookOpen className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Shift Notes</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Notes will appear as staff record observations during shifts.</p>
                            </CardContent>
                        </Card>
                    )}
                    {notes.data.map((note) => (
                        <Card key={note.id} className="transition-all hover:shadow-sm">
                            <CardContent className="p-3">
                                <div className="flex items-start gap-3">
                                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${
                                        note.note_type === 'incident' ? 'bg-red-100 text-red-700 dark:bg-red-900/40' :
                                        note.note_type === 'handover' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40' :
                                        'bg-slate-100 text-slate-700 dark:bg-slate-800/40'
                                    }`}>
                                        <BookOpen className="h-3.5 w-3.5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs font-semibold">{note.author?.name ?? 'Unknown'}</span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">{TYPE_LABELS[note.note_type] ?? note.note_type}</Badge>
                                            {note.is_private && <Lock className="h-3 w-3 text-muted-foreground" />}
                                        </div>
                                        <p className="mt-0.5 text-sm">{note.content.length > 250 ? note.content.slice(0, 250) + '...' : note.content}</p>
                                        <div className="mt-1 flex items-center gap-3 text-[10px] text-muted-foreground">
                                            {note.shift?.client && (
                                                <Link href={`/operations/clients/${note.shift.client.id}`} className="hover:underline">
                                                    {note.shift.client.first_name} {note.shift.client.last_name}
                                                </Link>
                                            )}
                                            {note.shift && (
                                                <Link href={`/operations/shifts/${note.shift.id}`} className="flex items-center gap-1 hover:underline">
                                                    <CalendarDays className="h-3 w-3" /> Shift #{note.shift.id}
                                                </Link>
                                            )}
                                            <span>{formatRelativeTime(note.created_at)}</span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                {notes.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {notes.links.map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
