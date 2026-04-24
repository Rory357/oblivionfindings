import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type FamilyNote = {
    id: number;
    title: string;
    description?: string | null;
    note_type: string;
    priority: string;
    status: string;
    due_date?: string | null;
    due_time?: string | null;
    completed_at?: string | null;
    completed_by_name?: string | null;
    staff_response?: string | null;
    staff_responded_by_name?: string | null;
    staff_responded_at?: string | null;
    assigned_shift_date?: string | null;
    assigned_shift?: {
        id: number;
        starts_at?: string | null;
        ends_at?: string | null;
        shift_type?: string | null;
        location?: string | null;
        service_context?: string | null;
        staff_name?: string | null;
    } | null;
    creator_name: string;
    created_by: number;
    created_at: string;
    is_overdue: boolean;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    notes: FamilyNote[];
    stats: { open: number; completed: number; overdue: number };
};

const NOTE_TYPES = [
    {
        key: 'note',
        label: 'Note',
        emoji: '📝',
        color: 'bg-blue-100 text-blue-700 border-blue-200',
    },
    {
        key: 'todo',
        label: 'To-Do',
        emoji: '✅',
        color: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    },
    {
        key: 'request',
        label: 'Request',
        emoji: '🙏',
        color: 'bg-amber-100 text-amber-700 border-amber-200',
    },
    {
        key: 'reminder',
        label: 'Reminder',
        emoji: '⏰',
        color: 'bg-primary/10 text-primary border-primary',
    },
];

const PRIORITIES = [
    { key: 'low', label: 'Low', color: 'bg-muted text-muted-foreground' },
    { key: 'normal', label: 'Normal', color: 'bg-blue-100 text-blue-700' },
    { key: 'high', label: 'High', color: 'bg-orange-100 text-orange-700' },
    { key: 'urgent', label: 'Urgent', color: 'bg-red-100 text-red-700' },
];

const STATUS_COLORS: Record<string, string> = {
    open: 'bg-blue-100 text-blue-700',
    in_progress: 'bg-amber-100 text-amber-700',
    completed: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-muted text-muted-foreground',
};

const NOTE_TYPE_MAP = Object.fromEntries(NOTE_TYPES.map((t) => [t.key, t]));
const PRIORITY_MAP = Object.fromEntries(PRIORITIES.map((p) => [p.key, p]));

function formatShiftType(value?: string | null) {
    return (value ?? 'standard').replace(/_/g, ' ');
}

export default function FamilyNotes({ client, notes, stats }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const currentUserId = auth.user.id;
    const [showForm, setShowForm] = useState(false);

    const form = useForm({
        title: '',
        description: '',
        note_type: 'todo',
        priority: 'normal',
        due_date: '',
        due_time: '',
    });

    const submitNote = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/portal/clients/${client.id}/family-notes`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setShowForm(false);
                toast.success('Note created!');
            },
        });
    };

    const deleteNote = (id: number) => {
        router.delete(`/portal/clients/${client.id}/family-notes/${id}`, {
            preserveScroll: true,
        });
    };

    const openNotes = notes.filter((n) =>
        ['open', 'in_progress'].includes(n.status),
    );
    const completedNotes = notes.filter((n) => n.status === 'completed');
    const cancelledNotes = notes.filter((n) => n.status === 'cancelled');

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Notes & To-Dos',
                    href: `/portal/clients/${client.id}/family-notes`,
                },
            ]}
        >
            <Head title={`${clientName} - Notes & To-Dos`} />

            <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-bold">Notes & To-Dos</h1>
                        <p className="text-sm text-muted-foreground">
                            Share notes, requests, and reminders with{' '}
                            {client.first_name}'s care team
                        </p>
                    </div>
                    <Button
                        size="sm"
                        className="gap-1.5"
                        onClick={() => setShowForm(!showForm)}
                    >
                        <Plus className="h-3.5 w-3.5" />
                        {showForm ? 'Cancel' : 'Add Note'}
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-3 gap-3">
                    <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-sky-50 p-3 text-center dark:from-blue-950/20">
                        <div className="text-xl font-bold text-blue-700">
                            {stats.open}
                        </div>
                        <div className="text-[10px] tracking-wider text-blue-500 uppercase">
                            Open
                        </div>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-3 text-center dark:from-emerald-950/20">
                        <div className="text-xl font-bold text-emerald-700">
                            {stats.completed}
                        </div>
                        <div className="text-[10px] tracking-wider text-emerald-500 uppercase">
                            Completed
                        </div>
                    </div>
                    <div
                        className={`rounded-xl border p-3 text-center ${stats.overdue > 0 ? 'bg-gradient-to-br from-red-50 to-rose-50 dark:from-red-950/20' : ''}`}
                    >
                        <div
                            className={`text-xl font-bold ${stats.overdue > 0 ? 'text-red-700' : 'text-muted-foreground'}`}
                        >
                            {stats.overdue}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Overdue
                        </div>
                    </div>
                </div>

                {/* Add Form */}
                {showForm && (
                    <Card className="border-primary/20">
                        <CardContent className="p-4">
                            <form onSubmit={submitNote} className="space-y-4">
                                <div>
                                    <Label className="text-xs">Title *</Label>
                                    <Input
                                        value={form.data.title}
                                        onChange={(e) =>
                                            form.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Bring warm jacket for Wednesday outing"
                                    />
                                </div>

                                <div>
                                    <Label className="text-xs">Type</Label>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {NOTE_TYPES.map((t) => (
                                            <button
                                                key={t.key}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'note_type',
                                                        t.key,
                                                    )
                                                }
                                                className={`inline-flex items-center gap-1.5 rounded-full border-2 px-3 py-1.5 text-xs font-medium transition-all ${form.data.note_type === t.key ? `${t.color} scale-105 shadow-sm` : 'border-border bg-card text-muted-foreground hover:border-primary/30'}`}
                                            >
                                                <span>{t.emoji}</span>
                                                {t.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <Label className="text-xs">Priority</Label>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {PRIORITIES.map((p) => (
                                            <button
                                                key={p.key}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'priority',
                                                        p.key,
                                                    )
                                                }
                                                className={`rounded-full border-2 px-3 py-1.5 text-xs font-medium transition-all ${form.data.priority === p.key ? `${p.color} scale-105 border-current shadow-sm` : 'border-border text-muted-foreground hover:border-primary/30'}`}
                                            >
                                                {p.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label className="text-xs">
                                            Due Date
                                        </Label>
                                        <Input
                                            type="date"
                                            value={form.data.due_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'due_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label className="text-xs">
                                            Due Time
                                        </Label>
                                        <Input
                                            type="time"
                                            value={form.data.due_time}
                                            onChange={(e) =>
                                                form.setData(
                                                    'due_time',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div>
                                    <Label className="text-xs">
                                        Description
                                    </Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                        placeholder="Add more details..."
                                    />
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            !form.data.title.trim()
                                        }
                                    >
                                        Create Note
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Open Notes */}
                {openNotes.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-semibold text-muted-foreground">
                            Open ({openNotes.length})
                        </h2>
                        {openNotes.map((note) => (
                            <NoteCard
                                key={note.id}
                                note={note}
                                clientId={client.id}
                                currentUserId={currentUserId}
                                onDelete={deleteNote}
                            />
                        ))}
                    </div>
                )}

                {/* Completed Notes */}
                {completedNotes.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-sm font-semibold text-muted-foreground">
                            Completed ({completedNotes.length})
                        </h2>
                        {completedNotes.slice(0, 5).map((note) => (
                            <NoteCard
                                key={note.id}
                                note={note}
                                clientId={client.id}
                                currentUserId={currentUserId}
                                onDelete={deleteNote}
                            />
                        ))}
                    </div>
                )}

                {notes.length === 0 && !showForm && (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <span className="mb-3 text-4xl">📝</span>
                            <p className="font-medium">No notes yet</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Create a note or to-do for the care team.
                            </p>
                            <Button
                                size="sm"
                                className="mt-4 gap-1.5"
                                onClick={() => setShowForm(true)}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add Your First Note
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function NoteCard({
    note,
    clientId,
    currentUserId,
    onDelete,
}: {
    note: FamilyNote;
    clientId: number;
    currentUserId: number;
    onDelete: (id: number) => void;
}) {
    const typeInfo = NOTE_TYPE_MAP[note.note_type] ?? NOTE_TYPES[0]!;
    const priorityInfo = PRIORITY_MAP[note.priority] ?? PRIORITIES[1]!;

    return (
        <Card
            className={`overflow-hidden transition-all hover:shadow-sm ${note.is_overdue ? 'border-red-300 bg-red-50/20' : note.status === 'completed' ? 'opacity-70' : ''}`}
        >
            <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold">
                                {note.title}
                            </span>
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${typeInfo.color}`}
                            >
                                {typeInfo.emoji} {typeInfo.label}
                            </span>
                            {note.priority !== 'normal' && (
                                <Badge
                                    className={`border-0 text-[9px] ${priorityInfo.color}`}
                                >
                                    {priorityInfo.label}
                                </Badge>
                            )}
                            <Badge
                                className={`border-0 text-[9px] capitalize ${STATUS_COLORS[note.status] ?? ''}`}
                            >
                                {note.status.replace('_', ' ')}
                            </Badge>
                            {note.is_overdue && (
                                <Badge className="gap-0.5 border-0 bg-red-100 text-[9px] text-red-700">
                                    <AlertTriangle className="h-2.5 w-2.5" />
                                    Overdue
                                </Badge>
                            )}
                        </div>

                        {note.due_date && (
                            <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                <Calendar className="h-3 w-3" />
                                Due:{' '}
                                {new Date(
                                    note.due_date + 'T00:00:00',
                                ).toLocaleDateString('en-NZ', {
                                    weekday: 'short',
                                    day: 'numeric',
                                    month: 'short',
                                })}
                                {note.due_time && ` at ${note.due_time}`}
                            </p>
                        )}

                        {note.description && (
                            <p className="mt-1.5 text-sm text-muted-foreground">
                                {note.description}
                            </p>
                        )}

                        {note.assigned_shift && (
                            <div className="mt-2 rounded-lg border border-primary bg-primary/10/60 p-2 text-xs text-primary">
                                <p className="font-medium">
                                    Assigned to{' '}
                                    {formatShiftType(
                                        note.assigned_shift.shift_type,
                                    )}{' '}
                                    shift
                                    {note.assigned_shift.starts_at
                                        ? ` on ${new Date(note.assigned_shift.starts_at).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })}`
                                        : ''}
                                </p>
                                <p className="mt-0.5 text-primary">
                                    {note.assigned_shift.staff_name ??
                                        'Unassigned'}
                                    {note.assigned_shift.service_context
                                        ? ` · ${note.assigned_shift.service_context}`
                                        : ''}
                                    {note.assigned_shift.location
                                        ? ` · ${note.assigned_shift.location}`
                                        : ''}
                                </p>
                            </div>
                        )}

                        {!note.assigned_shift && note.assigned_shift_date && (
                            <p className="mt-1 text-xs text-primary">
                                📋 Assigned to shift on{' '}
                                {note.assigned_shift_date}
                            </p>
                        )}

                        {/* Staff Response */}
                        {note.staff_response && (
                            <div className="mt-2 rounded-lg border-l-2 border-l-blue-400 bg-blue-50/50 p-2 dark:bg-blue-950/10">
                                <p className="text-xs">
                                    <span className="font-medium">
                                        {note.staff_responded_by_name}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="ml-1 border-blue-200 bg-blue-50 text-[9px] text-blue-700"
                                    >
                                        Staff
                                    </Badge>
                                    {note.staff_responded_at && (
                                        <span className="ml-1 text-muted-foreground">
                                            {new Date(
                                                note.staff_responded_at,
                                            ).toLocaleDateString('en-NZ')}
                                        </span>
                                    )}
                                </p>
                                <p className="mt-0.5 text-sm">
                                    {note.staff_response}
                                </p>
                            </div>
                        )}

                        {/* Completed info */}
                        {note.status === 'completed' &&
                            note.completed_by_name && (
                                <p className="mt-1 flex items-center gap-1 text-xs text-emerald-600">
                                    <CheckCircle2 className="h-3 w-3" />
                                    Completed by {note.completed_by_name}
                                    {note.completed_at &&
                                        ` on ${new Date(note.completed_at).toLocaleDateString('en-NZ')}`}
                                </p>
                            )}

                        <p className="mt-1 text-[10px] text-muted-foreground">
                            By {note.creator_name} ·{' '}
                            {new Date(note.created_at).toLocaleDateString(
                                'en-NZ',
                            )}
                        </p>
                    </div>

                    {/* Delete button (own open notes only) */}
                    {note.created_by === currentUserId &&
                        ['open', 'in_progress'].includes(note.status) && (
                            <button
                                onClick={() => onDelete(note.id)}
                                className="shrink-0 text-muted-foreground/50 transition-colors hover:text-red-500"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        )}
                </div>
            </CardContent>
        </Card>
    );
}
