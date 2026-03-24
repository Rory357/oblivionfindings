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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { BookOpen, Flag, MessageSquareText, Plus, Search } from 'lucide-react';
import { useState } from 'react';

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

const NOTE_TYPE_STYLES: Record<string, { border: string; bg: string; label: string; dot: string }> = {
    general: { border: 'border-l-violet-400', bg: 'bg-violet-50', label: 'General', dot: 'bg-violet-500' },
    goal_update: { border: 'border-l-indigo-400', bg: 'bg-indigo-50', label: 'Goal Update', dot: 'bg-indigo-500' },
    observation: { border: 'border-l-blue-400', bg: 'bg-blue-50', label: 'Observation', dot: 'bg-blue-500' },
    handover: { border: 'border-l-cyan-400', bg: 'bg-cyan-50', label: 'Handover', dot: 'bg-cyan-500' },
    incident: { border: 'border-l-red-400', bg: 'bg-red-50', label: 'Incident', dot: 'bg-red-500' },
    daily: { border: 'border-l-emerald-400', bg: 'bg-emerald-50', label: 'Daily', dot: 'bg-emerald-500' },
    weekly: { border: 'border-l-amber-400', bg: 'bg-amber-50', label: 'Weekly', dot: 'bg-amber-500' },
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function ProgressNotesIndex({ notes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any, clients = [] }: Props) {
    const { labels } = usePage().props as any;
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const [showAddForm, setShowAddForm] = useState(false);
    const [noteData, setNoteData] = useState({ client_id: '', content: '', note_type: 'general', mood_rating: '', visibility: 'staff_only' });

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/progress-notes', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const submitNote = () => {
        if (!noteData.client_id || !noteData.content.trim()) return;
        router.post('/operations/progress-notes', {
            ...noteData,
            mood_rating: noteData.mood_rating ? Number(noteData.mood_rating) : null,
        }, {
            preserveScroll: true,
            onSuccess: () => { setNoteData({ ...noteData, content: '', mood_rating: '' }); setShowAddForm(false); },
        });
    };

    const toggleFlag = (noteId: number) => {
        router.patch(`/operations/shift-notes/${noteId}/flag`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Progress Notes" />
            <PageHeader title="Progress Notes" description="Goal updates, observations, and progress tracking across all clients." backHref="/operations"
                actions={
                    <Button size="sm" className="gap-1.5 bg-violet-600 hover:bg-violet-700" onClick={() => setShowAddForm(!showAddForm)}>
                        <Plus className="h-4 w-4" /> Add Note
                    </Button>
                }
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard label="Total Notes" value={stats?.total ?? 0} icon={MessageSquareText} color="violet" />
                    <OpsStatCard label="This Week" value={stats?.this_week ?? 0} icon={BookOpen} color="blue" />
                    <OpsStatCard label="Flagged" value={stats?.flagged ?? 0} icon={Flag} color={stats?.flagged > 0 ? 'red' : 'slate'} />
                </div>

                {/* Add Note Form */}
                {showAddForm && (
                    <Card className="overflow-hidden border-violet-200">
                        <div className="bg-gradient-to-r from-violet-500 to-purple-600 px-4 py-2.5">
                            <h3 className="text-sm font-semibold text-white">New Progress Note</h3>
                        </div>
                        <CardContent className="p-4">
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div className="space-y-1">
                                    <Label className="text-xs">Client *</Label>
                                    <Select value={noteData.client_id} onValueChange={(v) => setNoteData({ ...noteData, client_id: v })}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Select client..." /></SelectTrigger>
                                        <SelectContent>
                                            {(clients ?? []).map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Type</Label>
                                    <Select value={noteData.note_type} onValueChange={(v) => setNoteData({ ...noteData, note_type: v })}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(NOTE_TYPE_STYLES).map(([k, v]) => (
                                                <SelectItem key={k} value={k}>{v.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Mood (1-10)</Label>
                                    <Input className="h-8 text-xs" type="number" min={1} max={10} placeholder="Optional" value={noteData.mood_rating} onChange={(e) => setNoteData({ ...noteData, mood_rating: e.target.value })} />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Visibility</Label>
                                    <Select value={noteData.visibility} onValueChange={(v) => setNoteData({ ...noteData, visibility: v })}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="staff_only">Staff Only</SelectItem>
                                            <SelectItem value="include_family">Family Visible</SelectItem>
                                            <SelectItem value="private">Private</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <Textarea className="mt-3 min-h-[80px] text-sm" placeholder="Write your progress note..." value={noteData.content} onChange={(e) => setNoteData({ ...noteData, content: e.target.value })} />
                            <div className="mt-3 flex items-center justify-between">
                                <Button size="sm" variant="ghost" className="text-xs" onClick={() => setShowAddForm(false)}>Cancel</Button>
                                <Button size="sm" className="bg-violet-600 hover:bg-violet-700" onClick={submitNote} disabled={!noteData.client_id || !noteData.content.trim()}>Save Note</Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-white/50 p-3 shadow-sm">
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
                        <SelectTrigger className="h-9 w-[140px] text-xs"><SelectValue placeholder="All Types" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {Object.entries(NOTE_TYPE_STYLES).map(([k, v]) => (
                                <SelectItem key={k} value={k}>{v.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button size="sm" variant={filters?.flagged === '1' ? 'default' : 'outline'}
                        className={`h-9 gap-1 text-xs ${filters?.flagged !== '1' ? 'text-red-600 border-red-200 hover:bg-red-50' : ''}`}
                        onClick={() => updateFilters('flagged', filters?.flagged === '1' ? null : '1')}>
                        <Flag className="h-3.5 w-3.5" /> Flagged
                    </Button>
                </div>

                {/* Notes list */}
                <div className="space-y-2">
                    {(notes?.data ?? []).length === 0 && (
                        <Card className="border-dashed">
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-50">
                                    <MessageSquareText className="h-8 w-8 text-violet-400" />
                                </div>
                                <h2 className="text-lg font-semibold">No Progress Notes</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {filters?.q || filters?.note_type || filters?.client_id || filters?.flagged
                                        ? 'No notes match your filters. Try adjusting your search.'
                                        : 'Notes will appear as staff record observations and updates.'}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    {(notes?.data ?? []).map((note) => {
                        const style = NOTE_TYPE_STYLES[note.note_type] ?? NOTE_TYPE_STYLES.general;
                        return (
                            <Card key={note.id} className={`overflow-hidden border-l-4 transition-all hover:shadow-sm ${note.is_flagged ? 'border-l-red-500 bg-red-50/30' : style.border}`}>
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-3">
                                        {/* Author avatar */}
                                        <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${style.dot}`}>
                                            {(note.author?.name ?? '?').split(' ').map(w => w[0]).join('').slice(0, 2)}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="text-sm font-semibold">{note.author?.name ?? 'Unknown'}</span>
                                                <Badge className={`border-0 text-[9px] ${style.bg} ${style.border.replace('border-l-', 'text-').replace('-400', '-700')}`}>{style.label}</Badge>
                                                {note.mood_rating && (
                                                    <span className={`inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold text-white ${
                                                        note.mood_rating >= 7 ? 'bg-emerald-500' : note.mood_rating >= 4 ? 'bg-amber-500' : 'bg-red-500'
                                                    }`}>{note.mood_rating}</span>
                                                )}
                                                {note.is_flagged && <Badge className="border-0 bg-red-100 text-red-700 text-[9px]">Flagged</Badge>}
                                                {note.visibility === 'include_family' && <Badge className="border-0 bg-blue-100 text-blue-700 text-[9px]">Family</Badge>}
                                            </div>
                                            {/* Client + Goal + Shift */}
                                            <div className="mt-0.5 flex items-center gap-2 flex-wrap">
                                                {note.client && (
                                                    <Link href={`/operations/clients/${note.client.id}`} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-700 hover:bg-slate-200">
                                                        {note.client.first_name} {note.client.last_name}
                                                    </Link>
                                                )}
                                                {note.goal && <span className="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-600">Goal: {note.goal.title}</span>}
                                                {note.shift && <span className="text-[10px] text-muted-foreground">Shift #{note.shift.id}</span>}
                                            </div>
                                            {/* Content */}
                                            <p className="mt-1.5 text-sm leading-relaxed text-slate-700">{note.content.length > 400 ? note.content.slice(0, 400) + '...' : note.content}</p>
                                            {note.flagged_reason && (
                                                <div className="mt-1.5 rounded bg-red-50 px-2 py-1 text-xs text-red-600">
                                                    Flag reason: {note.flagged_reason}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <span className="text-[10px] text-muted-foreground">{formatRelativeTime(note.created_at)}</span>
                                            <Button variant="ghost" size="sm" className={`h-6 gap-1 px-1.5 text-[10px] ${note.is_flagged ? 'text-red-600' : 'text-muted-foreground hover:text-red-600'}`}
                                                onClick={() => toggleFlag(note.id)} title={note.is_flagged ? 'Unflag' : 'Flag'}>
                                                <Flag className="h-3 w-3" /> {note.is_flagged ? 'Unflag' : 'Flag'}
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(notes?.last_page ?? 1) > 1 && (
                    <div className="flex flex-col items-center gap-2">
                        <div className="flex items-center justify-center gap-1">
                            {(notes?.links ?? []).map((link: any, i: number) => (
                                <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Page {notes?.current_page ?? 1} of {notes?.last_page ?? 1} ({notes?.total ?? 0} notes)
                        </p>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
