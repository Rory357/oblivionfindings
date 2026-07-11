import {
    DailyNoteEntry,
    type ClientDailyNote,
} from '@/components/daily-note-entry';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import {
    Calendar,
    CheckCircle2,
    MessageSquare,
    Plus,
    Users,
} from 'lucide-react';

type CommunicationNotesTabProps = {
    notes: ClientDailyNote[];
    familyNotes: any[];
    familyNotesOpenCount: number;
    coverage?: {
        total?: number;
        loaded?: number;
        has_more?: boolean;
    };
    onCreate?: () => void;
    canReview?: boolean;
    canUpdate?: boolean;
    onMarkReviewed?: (noteId: number) => void;
    onClearFlag?: (noteId: number) => void;
    isLoading?: boolean;
};

function dateLabel(value?: string | null) {
    if (!value) return 'No date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function CommunicationNotesTab({
    notes,
    familyNotes,
    familyNotesOpenCount,
    coverage = {},
    onCreate,
    canReview = false,
    canUpdate = false,
    onMarkReviewed,
    onClearFlag,
    isLoading = false,
}: CommunicationNotesTabProps) {
    const openFamilyNotes = familyNotes.filter((note) =>
        ['open', 'in_progress'].includes(note.status),
    );

    if (isLoading) {
        return (
            <div className="space-y-6" aria-busy="true">
                <div className="grid gap-3 md:grid-cols-3">
                    {[0, 1, 2].map((item) => (
                        <div key={item} className="rounded-lg border p-4">
                            <Skeleton className="h-3 w-28" />
                            <Skeleton className="mt-3 h-8 w-12" />
                        </div>
                    ))}
                </div>
                <Skeleton className="h-72 rounded-lg" />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="grid gap-3 md:grid-cols-3">
                <div className="rounded-lg border bg-card p-4">
                    <p className="text-xs text-muted-foreground">
                        Communication notes
                    </p>
                    <p className="mt-1 text-2xl font-semibold">
                        {coverage.total ?? notes.length}
                    </p>
                </div>
                <div className="rounded-lg border bg-card p-4">
                    <p className="text-xs text-muted-foreground">
                        Open family notes
                    </p>
                    <p className="mt-1 text-2xl font-semibold">
                        {familyNotesOpenCount}
                    </p>
                </div>
                <div className="rounded-lg border bg-card p-4">
                    <p className="text-xs text-muted-foreground">
                        Completed this week
                    </p>
                    <p className="mt-1 text-2xl font-semibold">
                        {
                            familyNotes.filter(
                                (note) =>
                                    note.status === 'completed' &&
                                    note.completed_at &&
                                    new Date(note.completed_at) >=
                                        new Date(Date.now() - 7 * 86400000),
                            ).length
                        }
                    </p>
                </div>
            </div>

            {coverage.has_more ? (
                <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                    Showing the latest {coverage.loaded ?? notes.length} of{' '}
                    {coverage.total ?? notes.length} communication notes.
                </p>
            ) : null}

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">
                        Communication Record
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Family, provider, agency, and portal contact for this
                        client.
                    </p>
                </div>
                {onCreate ? (
                    <Button
                        type="button"
                        onClick={onCreate}
                        className="min-h-11"
                    >
                        <Plus className="mr-2 h-4 w-4" />
                        Add Communication
                    </Button>
                ) : null}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-3">
                    {notes.length > 0 ? (
                        notes.map((note) => (
                            <DailyNoteEntry
                                key={note.id}
                                note={note}
                                canReview={canReview}
                                canUpdate={canUpdate}
                                onMarkReviewed={onMarkReviewed}
                                onClearFlag={onClearFlag}
                                showCommunicationContext
                            />
                        ))
                    ) : (
                        <EmptyState
                            icon={MessageSquare}
                            title="No communication notes yet"
                            description={
                                onCreate
                                    ? 'Record calls, emails, portal messages, or in-person conversations from the button above.'
                                    : 'No communication notes are available.'
                            }
                        />
                    )}
                </div>

                <aside className="space-y-4">
                    <div className="rounded-lg border bg-card p-4">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <Users className="h-4 w-4 text-primary" />
                            Family Notes
                        </h3>
                        <div className="mt-4 space-y-3">
                            {openFamilyNotes.length > 0 ? (
                                openFamilyNotes.slice(0, 6).map((note) => (
                                    <div
                                        key={note.id}
                                        className={cn(
                                            'rounded-lg border p-3 text-sm',
                                            note.priority === 'urgent' &&
                                                'border-status-critical/30 bg-status-critical-bg',
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="font-medium">
                                                {note.title}
                                            </p>
                                            <Badge variant="outline">
                                                {String(
                                                    note.status ?? 'open',
                                                ).replace(/_/g, ' ')}
                                            </Badge>
                                        </div>
                                        {note.description ? (
                                            <p className="mt-1 line-clamp-3 text-muted-foreground">
                                                {note.description}
                                            </p>
                                        ) : null}
                                        {note.due_date ? (
                                            <p className="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                <Calendar className="h-3.5 w-3.5" />
                                                {dateLabel(note.due_date)}
                                            </p>
                                        ) : null}
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No family portal notes are open.
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="rounded-lg border bg-status-success-bg p-4 text-sm text-status-success">
                        <div className="flex items-center gap-2 font-medium">
                            <CheckCircle2 className="h-4 w-4" />
                            Timeline backed
                        </div>
                        <p className="mt-2">
                            New communication notes use the shared daily-note
                            model and project to the existing timeline.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    );
}
