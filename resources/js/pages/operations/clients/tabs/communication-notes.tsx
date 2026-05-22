import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import {
    Calendar,
    CheckCircle2,
    MessageSquare,
    Phone,
    Plus,
    UserRound,
    Users,
} from 'lucide-react';
import type { ClientDailyNote } from './daily-notes';

type CommunicationNotesTabProps = {
    notes: ClientDailyNote[];
    familyNotes: any[];
    familyNotesOpenCount: number;
    onCreate: () => void;
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

const methodIcons: Record<string, typeof MessageSquare> = {
    phone: Phone,
    email: MessageSquare,
    portal: Users,
    in_person: UserRound,
};

export function CommunicationNotesTab({
    notes,
    familyNotes,
    familyNotesOpenCount,
    onCreate,
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
                        {notes.length}
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
                <Button type="button" onClick={onCreate} className="min-h-11">
                    <Plus className="mr-2 h-4 w-4" />
                    Add Communication
                </Button>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-3">
                    {notes.length > 0 ? (
                        notes.map((note) => {
                            const Icon =
                                methodIcons[
                                    String(note.contact_method ?? '')
                                ] ?? MessageSquare;

                            return (
                                <article
                                    key={note.id}
                                    className="rounded-lg border bg-card p-4"
                                >
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0 space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge className="bg-status-info-bg text-status-info">
                                                    <Icon className="mr-1 h-3.5 w-3.5" />
                                                    {String(
                                                        note.contact_method ??
                                                            'contact',
                                                    ).replace(/_/g, ' ')}
                                                </Badge>
                                                {note.is_flagged ? (
                                                    <Badge className="bg-status-warning-bg text-status-warning">
                                                        Needs review
                                                    </Badge>
                                                ) : null}
                                                {note.follow_up_action ? (
                                                    <Badge variant="outline">
                                                        Follow-up
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <h3 className="text-base font-semibold">
                                                {note.subject ||
                                                    note.contact_person ||
                                                    'Communication note'}
                                            </h3>
                                            {(note.contact_person ||
                                                note.contact_relationship) && (
                                                <p className="text-sm text-muted-foreground">
                                                    {[
                                                        note.contact_person,
                                                        note.contact_relationship,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' - ')}
                                                </p>
                                            )}
                                        </div>
                                        <p className="shrink-0 text-sm text-muted-foreground">
                                            {dateLabel(
                                                note.occurred_at ??
                                                    note.created_at,
                                            )}
                                        </p>
                                    </div>

                                    <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                        {note.body}
                                    </p>

                                    {note.follow_up_action ? (
                                        <div className="mt-4 rounded-lg border bg-muted/30 p-3 text-sm">
                                            <p className="font-medium">
                                                Follow-up
                                            </p>
                                            <p className="mt-1 text-muted-foreground">
                                                {note.follow_up_action}
                                            </p>
                                            {note.follow_up_due_at ? (
                                                <p className="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Calendar className="h-3.5 w-3.5" />
                                                    {dateLabel(
                                                        note.follow_up_due_at,
                                                    )}
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : null}

                                    <div className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                        <UserRound className="h-3.5 w-3.5" />
                                        {note.author?.name ?? 'Unknown worker'}
                                    </div>
                                </article>
                            );
                        })
                    ) : (
                        <EmptyState
                            icon={MessageSquare}
                            title="No communication notes yet"
                            description="Record calls, emails, portal messages, or in-person conversations from the button above."
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
