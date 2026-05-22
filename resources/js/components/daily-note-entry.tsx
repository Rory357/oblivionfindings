import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    CheckCircle2,
    Eye,
    EyeOff,
    Flag,
    Phone,
    UserRound,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

/**
 * Shape of a daily/communication/quick note as projected by
 * `ClientDailyNoteResource`. Kept here so any surface that renders a note
 * card can import it without depending on the daily-notes tab module.
 */
export type ClientDailyNote = {
    id: number;
    type?: string | null;
    category?: string | null;
    subject?: string | null;
    body?: string | null;
    occurred_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    visibility?: string | null;
    is_flagged?: boolean;
    flagged_reason?: string | null;
    reviewed_at?: string | null;
    is_draft?: boolean;
    mood_rating?: number | null;
    behaviour_tags?: string[] | null;
    concerns_flags?: string[] | null;
    follow_up_action?: string | null;
    follow_up_due_at?: string | null;
    follow_up_completed_at?: string | null;
    contact_person?: string | null;
    contact_relationship?: string | null;
    contact_method?: string | null;
    appears_on_timeline?: boolean;
    author?: { id: number; name: string } | null;
    user_id?: number | null;
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

function categoryLabel(value?: string | null) {
    return String(value ?? 'other').replace(/_/g, ' ');
}

const CONTACT_METHOD_ICONS: Record<
    string,
    ComponentType<{ className?: string }>
> = {
    phone: Phone,
    email: UserRound,
    text: UserRound,
    meeting: Users,
    in_person: UserRound,
    portal: Users,
};

export type DailyNoteEntryProps = {
    note: ClientDailyNote;
    canReview?: boolean;
    canUpdate?: boolean;
    onMarkReviewed?: (noteId: number) => void;
    onClearFlag?: (noteId: number) => void;
    /** When true, render an extra communication header (contact, method). */
    showCommunicationContext?: boolean;
    /** Used to soften the styling for nested/right-rail surfaces. */
    compact?: boolean;
};

/**
 * Canonical note card used by Daily Notes tab, Communication Notes tab, and
 * any future surface (dashboards, drawer, mobile preview) that needs to
 * render a single `ClientNote` consistently.
 */
export function DailyNoteEntry({
    note,
    canReview = false,
    canUpdate = false,
    onMarkReviewed,
    onClearFlag,
    showCommunicationContext = false,
    compact = false,
}: DailyNoteEntryProps) {
    const isCommunication =
        showCommunicationContext || note.type === 'communication';
    const MethodIcon = isCommunication
        ? (CONTACT_METHOD_ICONS[note.contact_method ?? ''] ?? UserRound)
        : null;

    return (
        <article
            className={cn(
                'rounded-lg border bg-card transition-colors',
                compact ? 'p-3' : 'p-4',
                note.is_flagged &&
                    !note.reviewed_at &&
                    'border-status-warning/40 bg-status-warning-bg/40',
            )}
            data-test={`daily-note-entry-${note.id}`}
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="secondary">
                            {categoryLabel(note.category)}
                        </Badge>
                        {note.is_draft ? (
                            <Badge variant="outline">Draft</Badge>
                        ) : null}
                        {note.is_flagged ? (
                            <Badge className="bg-status-warning-bg text-status-warning">
                                <Flag className="mr-1 h-3 w-3" />
                                Needs review
                            </Badge>
                        ) : null}
                        {note.reviewed_at ? (
                            <Badge className="bg-status-success-bg text-status-success">
                                Reviewed
                            </Badge>
                        ) : null}
                        {note.visibility === 'portal' ? (
                            <Badge variant="outline" className="gap-1">
                                <Eye className="h-3 w-3" />
                                Family visible
                            </Badge>
                        ) : note.visibility === 'private' ? (
                            <Badge variant="outline" className="gap-1">
                                <EyeOff className="h-3 w-3" />
                                Private
                            </Badge>
                        ) : null}
                    </div>
                    {note.subject ? (
                        <h3 className="text-base font-semibold">
                            {note.subject}
                        </h3>
                    ) : null}
                </div>
                <p className="shrink-0 text-sm text-muted-foreground">
                    {dateLabel(note.occurred_at ?? note.created_at)}
                </p>
            </div>

            {isCommunication &&
            (note.contact_person ||
                note.contact_relationship ||
                note.contact_method) ? (
                <div className="mt-3 flex flex-wrap items-center gap-2 rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                    {MethodIcon ? <MethodIcon className="h-3.5 w-3.5" /> : null}
                    {note.contact_person ? (
                        <span className="font-medium text-foreground">
                            {note.contact_person}
                        </span>
                    ) : null}
                    {note.contact_relationship ? (
                        <span>({note.contact_relationship})</span>
                    ) : null}
                    {note.contact_method ? (
                        <span className="capitalize">
                            via {note.contact_method.replace('_', ' ')}
                        </span>
                    ) : null}
                </div>
            ) : null}

            {note.body ? (
                <p className="mt-3 text-sm leading-6 whitespace-pre-wrap text-foreground">
                    {note.body}
                </p>
            ) : null}

            {((note.behaviour_tags?.length ?? 0) > 0 ||
                (note.concerns_flags?.length ?? 0) > 0 ||
                note.mood_rating) && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {(note.behaviour_tags ?? []).map((tag) => (
                        <Badge key={tag} variant="outline">
                            {tag}
                        </Badge>
                    ))}
                    {(note.concerns_flags ?? []).map((flag) => (
                        <Badge
                            key={flag}
                            className="bg-status-critical-bg text-status-critical"
                        >
                            {flag}
                        </Badge>
                    ))}
                    {note.mood_rating ? (
                        <Badge variant="outline">
                            Mood {note.mood_rating}/10
                        </Badge>
                    ) : null}
                </div>
            )}

            {note.follow_up_action ? (
                <div className="mt-4 rounded-lg border bg-background p-3 text-sm">
                    <p className="font-medium">Follow-up</p>
                    <p className="mt-1 text-muted-foreground">
                        {note.follow_up_action}
                    </p>
                    {note.follow_up_due_at ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Due {dateLabel(note.follow_up_due_at)}
                            {note.follow_up_completed_at ? (
                                <span className="ml-2 text-status-success">
                                    · Completed{' '}
                                    {dateLabel(note.follow_up_completed_at)}
                                </span>
                            ) : null}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <UserRound className="h-3.5 w-3.5" />
                    {note.author?.name ?? 'Unknown worker'}
                </span>
                {canReview && note.is_flagged && !note.reviewed_at ? (
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => onMarkReviewed?.(note.id)}
                        data-test={`daily-note-entry-${note.id}-mark-reviewed`}
                    >
                        <CheckCircle2 className="mr-2 h-4 w-4" />
                        Mark Reviewed
                    </Button>
                ) : canUpdate && note.is_flagged && note.reviewed_at ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onClearFlag?.(note.id)}
                        data-test={`daily-note-entry-${note.id}-clear-flag`}
                    >
                        Clear Flag
                    </Button>
                ) : null}
            </div>
        </article>
    );
}
