import DraftResumePrompt from '@/components/draft-resume-prompt';
import DraftSavedIndicator from '@/components/draft-saved-indicator';
import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import DictateButton from '@/components/dictate-button';
import { PageHero } from '@/components/page';
import { useFormAutosave } from '@/hooks/use-form-autosave';
import AppLayout from '@/layouts/app-layout';
import { submitOffline } from '@/lib/offline-queue';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BookOpen, Flag, MessageSquareText, NotebookPen, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

const ANY = '__ANY__';

type Note = {
    id: number;
    note_type: string;
    content: string;
    mood_rating: number | null;
    emotions: string[] | null;
    is_flagged: boolean;
    flagged_reason: string | null;
    visibility: string;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    author: { id: number; name: string } | null;
    shift: { id: number; starts_at: string } | null;
    goal: { id: number; title: string } | null;
};

const EMOTIONS: Array<{
    key: string;
    emoji: string;
    label: string;
    color: string;
}> = [
    {
        key: 'happy',
        emoji: '😊',
        label: 'Happy',
        color: 'bg-status-success-bg text-status-success',
    },
    {
        key: 'calm',
        emoji: '😌',
        label: 'Calm',
        color: 'bg-status-info-bg text-status-info',
    },
    {
        key: 'excited',
        emoji: '🤩',
        label: 'Excited',
        color: 'bg-status-warning-bg text-status-warning',
    },
    {
        key: 'tired',
        emoji: '😴',
        label: 'Tired',
        color: 'bg-primary/10 text-primary',
    },
    {
        key: 'anxious',
        emoji: '😰',
        label: 'Anxious',
        color: 'bg-status-warning-bg text-status-warning',
    },
    {
        key: 'sad',
        emoji: '😢',
        label: 'Sad',
        color: 'bg-status-info-bg text-status-info',
    },
    {
        key: 'frustrated',
        emoji: '😤',
        label: 'Frustrated',
        color: 'bg-status-critical-bg text-status-critical',
    },
    {
        key: 'confused',
        emoji: '😕',
        label: 'Confused',
        color: 'bg-primary/10 text-primary',
    },
];
const EMOTION_MAP = Object.fromEntries(EMOTIONS.map((e) => [e.key, e]));

type Props = {
    notes: {
        data: Note[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        note_type?: string;
        flagged?: string;
        client_id?: string;
        date_from?: string;
        date_to?: string;
        emotion?: string;
    };
    stats: { total: number; flagged: number; this_week: number };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

const NOTE_TYPE_STYLES: Record<
    string,
    { border: string; bg: string; label: string; dot: string }
> = {
    general: {
        border: 'border-l-violet-400',
        bg: 'bg-primary/10',
        label: 'General',
        dot: 'bg-primary',
    },
    goal_update: {
        border: 'border-l-indigo-400',
        bg: 'bg-primary/10',
        label: 'Goal Update',
        dot: 'bg-primary',
    },
    observation: {
        border: 'border-l-blue-400',
        bg: 'bg-status-info-bg',
        label: 'Observation',
        dot: 'bg-status-info',
    },
    handover: {
        border: 'border-l-cyan-400',
        bg: 'bg-status-info-bg',
        label: 'Handover',
        dot: 'bg-status-info',
    },
    incident: {
        border: 'border-l-red-400',
        bg: 'bg-status-critical-bg',
        label: 'Incident',
        dot: 'bg-status-critical',
    },
    daily: {
        border: 'border-l-emerald-400',
        bg: 'bg-status-success-bg',
        label: 'Daily',
        dot: 'bg-status-success',
    },
    weekly: {
        border: 'border-l-amber-400',
        bg: 'bg-status-warning-bg',
        label: 'Weekly',
        dot: 'bg-status-warning',
    },
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return d.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function ProgressNotesIndex({
    notes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {} as any,
    stats = {} as any,
    clients = [],
}: Props) {
    const page = usePage().props as {
        labels?: Record<string, string>;
        auth?: { user?: { id?: number } };
    };
    const labels = page.labels;
    const userId = page.auth?.user?.id ?? 0;
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const [showAddForm, setShowAddForm] = useState(false);
    const [noteData, setNoteData] = useState({
        client_id: '',
        content: '',
        note_type: 'general',
        mood_rating: '',
        visibility: 'staff_only',
    });

    const draftKey = `oblivion:progress-note:v1:u${userId}`;
    const { savedAt, load, clear } = useFormAutosave(
        noteData,
        {},
        { key: draftKey, enabled: showAddForm },
    );
    const [resumePayload, setResumePayload] = useState<{
        data: typeof noteData;
        savedAt: number;
    } | null>(null);
    const [bootstrapped, setBootstrapped] = useState(false);

    useEffect(() => {
        if (bootstrapped) return;
        const existing = load();
        if (existing && (existing.data as typeof noteData)?.content?.trim()) {
            setResumePayload({
                data: existing.data as typeof noteData,
                savedAt: existing.savedAt,
            });
            setShowAddForm(true);
        }
        setBootstrapped(true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const resumeDraft = () => {
        if (!resumePayload) return;
        setNoteData(resumePayload.data);
        setResumePayload(null);
    };

    const discardDraft = () => {
        clear();
        setResumePayload(null);
    };

    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/operations/progress-notes',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    const submitNote = () => {
        if (!noteData.client_id || !noteData.content.trim()) return;

        const payload = {
            ...noteData,
            mood_rating: noteData.mood_rating
                ? Number(noteData.mood_rating)
                : null,
        };

        const resetForm = () => {
            clear();
            setNoteData({
                client_id: '',
                content: '',
                note_type: 'general',
                mood_rating: '',
                visibility: 'staff_only',
            });
            setShowAddForm(false);
        };

        // PR 26 — offline path. Queue the note locally and close the form
        // with a calm "saved on this device" toast. The server dedupes on
        // `client_request_uuid` when the queued item replays on reconnect.
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            void submitOffline({
                action: 'progress_note',
                url: '/operations/progress-notes',
                payload,
                queuedMessage:
                    'Note saved on this device — we\u2019ll send it when you\u2019re back online.',
            }).then(() => {
                resetForm();
                router.reload({
                    only: ['notes', 'stats'],
                    preserveScroll: true,
                });
            });
            return;
        }

        router.post('/operations/progress-notes', payload, {
            preserveScroll: true,
            onSuccess: resetForm,
        });
    };

    const toggleFlag = (noteId: number) => {
        router.patch(
            `/operations/shift-notes/${noteId}/flag`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Progress Notes" />
            <PageHero
                icon={NotebookPen}
                title="Progress Notes"
                description="Goal updates, observations, and progress tracking across all clients."
                stats={[
                    { label: 'Total notes', value: stats?.total ?? 0 },
                    { label: 'This week', value: stats?.this_week ?? 0 },
                    { label: 'Flagged', value: stats?.flagged ?? 0 },
                ]}
                actions={
                    <Button
                        size="sm"
                        className="gap-1.5 bg-primary hover:bg-primary"
                        onClick={() => setShowAddForm(!showAddForm)}
                    >
                        <Plus className="h-4 w-4" /> Add Note
                    </Button>
                }
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard
                        label="Total Notes"
                        value={stats?.total ?? 0}
                        icon={MessageSquareText}
                        color="violet"
                    />
                    <OpsStatCard
                        label="This Week"
                        value={stats?.this_week ?? 0}
                        icon={BookOpen}
                        color="blue"
                    />
                    <OpsStatCard
                        label="Flagged"
                        value={stats?.flagged ?? 0}
                        icon={Flag}
                        color={stats?.flagged > 0 ? 'red' : 'slate'}
                    />
                </div>

                {/* Add Note Form */}
                {showAddForm && (
                    <Card className="overflow-hidden border-primary">
                        <div className="flex items-center justify-between bg-primary px-4 py-2.5">
                            <h3 className="text-sm font-semibold text-white">
                                New Progress Note
                            </h3>
                            <DraftSavedIndicator
                                savedAt={savedAt}
                                className="text-white/90 [&_svg]:text-white"
                            />
                        </div>
                        <CardContent className="p-4">
                            {resumePayload && (
                                <div className="mb-3">
                                    <DraftResumePrompt
                                        savedAt={resumePayload.savedAt}
                                        onResume={resumeDraft}
                                        onDiscard={discardDraft}
                                        description="We kept what you started writing for a progress note."
                                    />
                                </div>
                            )}
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div className="space-y-1">
                                    <Label className="text-xs">Client *</Label>
                                    <Select
                                        value={noteData.client_id}
                                        onValueChange={(v) =>
                                            setNoteData({
                                                ...noteData,
                                                client_id: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue placeholder="Select client..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(clients ?? []).map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Type</Label>
                                    <Select
                                        value={noteData.note_type}
                                        onValueChange={(v) =>
                                            setNoteData({
                                                ...noteData,
                                                note_type: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                NOTE_TYPE_STYLES,
                                            ).map(([k, v]) => (
                                                <SelectItem key={k} value={k}>
                                                    {v.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">
                                        Mood (1-10)
                                    </Label>
                                    <Input
                                        className="h-8 text-xs"
                                        type="number"
                                        min={1}
                                        max={10}
                                        placeholder="Optional"
                                        value={noteData.mood_rating}
                                        onChange={(e) =>
                                            setNoteData({
                                                ...noteData,
                                                mood_rating: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">
                                        Visibility
                                    </Label>
                                    <Select
                                        value={noteData.visibility}
                                        onValueChange={(v) =>
                                            setNoteData({
                                                ...noteData,
                                                visibility: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="h-8 text-xs">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="staff_only">
                                                Staff Only
                                            </SelectItem>
                                            <SelectItem value="include_family">
                                                Family Visible
                                            </SelectItem>
                                            <SelectItem value="private">
                                                Private
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="mt-3 flex items-center justify-between">
                                <Label className="text-xs">Note</Label>
                                <DictateButton
                                    value={noteData.content}
                                    onChange={(next) =>
                                        setNoteData({
                                            ...noteData,
                                            content: next,
                                        })
                                    }
                                    fieldLabel="Progress note"
                                />
                            </div>
                            <Textarea
                                className="mt-1 min-h-[80px] text-sm"
                                placeholder="Write your progress note..."
                                value={noteData.content}
                                onChange={(e) =>
                                    setNoteData({
                                        ...noteData,
                                        content: e.target.value,
                                    })
                                }
                            />
                            <div className="mt-3 flex items-center justify-between">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-xs"
                                    onClick={() => setShowAddForm(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    className="bg-primary hover:bg-primary"
                                    onClick={submitNote}
                                    disabled={
                                        !noteData.client_id ||
                                        !noteData.content.trim()
                                    }
                                >
                                    Save Note
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <Card className="bg-white/50 shadow-sm dark:bg-muted/50">
                    <CardContent className="space-y-2 p-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative flex-1">
                                <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                                <Input
                                    placeholder="Search notes..."
                                    className="h-9 pl-8 text-sm"
                                    defaultValue={filters?.q ?? ''}
                                    onChange={(e) =>
                                        updateFilters(
                                            'q',
                                            e.target.value || null,
                                        )
                                    }
                                />
                            </div>
                            <Select
                                value={filters?.client_id ?? ANY}
                                onValueChange={(v) =>
                                    updateFilters(
                                        'client_id',
                                        v === ANY ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="h-9 w-[160px] text-xs">
                                    <SelectValue
                                        placeholder={`All ${clientPlural}`}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        value={ANY}
                                    >{`All ${clientPlural}`}</SelectItem>
                                    {(clients ?? []).map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.first_name} {c.last_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters?.note_type ?? ANY}
                                onValueChange={(v) =>
                                    updateFilters(
                                        'note_type',
                                        v === ANY ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="h-9 w-[140px] text-xs">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        All Types
                                    </SelectItem>
                                    {Object.entries(NOTE_TYPE_STYLES).map(
                                        ([k, v]) => (
                                            <SelectItem key={k} value={k}>
                                                {v.label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters?.emotion ?? ANY}
                                onValueChange={(v) =>
                                    updateFilters(
                                        'emotion',
                                        v === ANY ? null : v,
                                    )
                                }
                            >
                                <SelectTrigger className="h-9 w-[150px] text-xs">
                                    <SelectValue placeholder="All Emotions" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        All Emotions
                                    </SelectItem>
                                    {EMOTIONS.map((em) => (
                                        <SelectItem key={em.key} value={em.key}>
                                            {em.emoji} {em.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                variant={
                                    filters?.flagged === '1'
                                        ? 'default'
                                        : 'outline'
                                }
                                className={`h-9 gap-1 text-xs ${filters?.flagged !== '1' ? 'border-status-critical/30 text-status-critical hover:bg-status-critical-bg' : ''}`}
                                onClick={() =>
                                    updateFilters(
                                        'flagged',
                                        filters?.flagged === '1' ? null : '1',
                                    )
                                }
                            >
                                <Flag className="h-3.5 w-3.5" /> Flagged
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Label className="text-xs text-muted-foreground">
                                Date range:
                            </Label>
                            <Input
                                type="date"
                                className="h-8 w-[140px] text-xs"
                                value={filters?.date_from ?? ''}
                                onChange={(e) =>
                                    updateFilters(
                                        'date_from',
                                        e.target.value || null,
                                    )
                                }
                            />
                            <span className="text-xs text-muted-foreground">
                                to
                            </span>
                            <Input
                                type="date"
                                className="h-8 w-[140px] text-xs"
                                value={filters?.date_to ?? ''}
                                onChange={(e) =>
                                    updateFilters(
                                        'date_to',
                                        e.target.value || null,
                                    )
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Notes list */}
                <div className="space-y-2">
                    {(notes?.data ?? []).length === 0 && (
                        <Card className="border-dashed">
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                    <MessageSquareText className="h-8 w-8 text-primary" />
                                </div>
                                <h2 className="text-lg font-semibold">
                                    No Progress Notes
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {filters?.q ||
                                    filters?.note_type ||
                                    filters?.client_id ||
                                    filters?.flagged
                                        ? 'No notes match your filters. Try adjusting your search.'
                                        : 'Notes will appear as staff record observations and updates.'}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    {(notes?.data ?? []).map((note) => {
                        const style =
                            NOTE_TYPE_STYLES[note.note_type] ??
                            NOTE_TYPE_STYLES.general;
                        return (
                            <Card
                                key={note.id}
                                className={`overflow-hidden border-l-4 transition-all hover:shadow-sm ${note.is_flagged ? 'border-l-red-500 bg-status-critical-bg' : style.border}`}
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start gap-3">
                                        {/* Author avatar */}
                                        <div
                                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${style.dot}`}
                                        >
                                            {(note.author?.name ?? '?')
                                                .split(' ')
                                                .map((w) => w[0])
                                                .join('')
                                                .slice(0, 2)}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {note.author?.name ??
                                                        'Unknown'}
                                                </span>
                                                <Badge
                                                    className={`border-0 text-[9px] ${style.bg} ${style.border.replace('border-l-', 'text-').replace('-400', '-700')}`}
                                                >
                                                    {style.label}
                                                </Badge>
                                                {(note.emotions ?? []).map(
                                                    (em) => (
                                                        <span
                                                            key={em}
                                                            className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[9px] font-medium ${EMOTION_MAP[em]?.color ?? 'bg-muted'}`}
                                                        >
                                                            {EMOTION_MAP[em]
                                                                ?.emoji ??
                                                                em}{' '}
                                                            {EMOTION_MAP[em]
                                                                ?.label ?? em}
                                                        </span>
                                                    ),
                                                )}
                                                {note.is_flagged && (
                                                    <Badge className="border-0 bg-status-critical-bg text-[9px] text-status-critical">
                                                        Flagged
                                                    </Badge>
                                                )}
                                                {note.visibility ===
                                                    'include_family' && (
                                                    <Badge className="border-0 bg-status-info-bg text-[9px] text-status-info">
                                                        Family
                                                    </Badge>
                                                )}
                                            </div>
                                            {/* Client + Goal + Shift */}
                                            <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                                {note.client && (
                                                    <Link
                                                        href={`/operations/clients/${note.client.id}`}
                                                        className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-foreground hover:bg-muted"
                                                    >
                                                        {note.client.first_name}{' '}
                                                        {note.client.last_name}
                                                    </Link>
                                                )}
                                                {note.goal && (
                                                    <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
                                                        Goal: {note.goal.title}
                                                    </span>
                                                )}
                                                {note.shift && (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        Shift #{note.shift.id}
                                                    </span>
                                                )}
                                            </div>
                                            {/* Content */}
                                            <p className="mt-1.5 text-sm leading-relaxed text-foreground">
                                                {note.content.length > 400
                                                    ? note.content.slice(
                                                          0,
                                                          400,
                                                      ) + '...'
                                                    : note.content}
                                            </p>
                                            {note.flagged_reason && (
                                                <div className="mt-1.5 rounded bg-status-critical-bg px-2 py-1 text-xs text-status-critical">
                                                    Flag reason:{' '}
                                                    {note.flagged_reason}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <span className="text-[10px] font-medium text-muted-foreground">
                                                {new Date(
                                                    note.created_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                {new Date(
                                                    note.created_at,
                                                ).toLocaleTimeString('en-NZ', {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}{' '}
                                                ·{' '}
                                                {formatRelativeTime(
                                                    note.created_at,
                                                )}
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className={`h-6 gap-1 px-1.5 text-[10px] ${note.is_flagged ? 'text-status-critical' : 'text-muted-foreground hover:text-status-critical'}`}
                                                onClick={() =>
                                                    toggleFlag(note.id)
                                                }
                                                title={
                                                    note.is_flagged
                                                        ? 'Unflag'
                                                        : 'Flag'
                                                }
                                            >
                                                <Flag className="h-3 w-3" />{' '}
                                                {note.is_flagged
                                                    ? 'Unflag'
                                                    : 'Flag'}
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
                            {(notes?.links ?? []).map(
                                (link: any, i: number) => (
                                    <Button
                                        key={i}
                                        size="sm"
                                        variant={
                                            link.active ? 'default' : 'outline'
                                        }
                                        className="h-7 min-w-[28px] px-2 text-xs"
                                        disabled={!link.url}
                                        onClick={() =>
                                            link.url &&
                                            router.get(
                                                link.url,
                                                {},
                                                { preserveState: true },
                                            )
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ),
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Page {notes?.current_page ?? 1} of{' '}
                            {notes?.last_page ?? 1} ({notes?.total ?? 0} notes)
                        </p>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
