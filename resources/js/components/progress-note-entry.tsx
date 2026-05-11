import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ChevronDown, ChevronUp, Flag, MessageSquare } from 'lucide-react';
import { useState } from 'react';

interface ProgressNoteEntryProps {
    note: {
        id: number;
        content: string;
        note_type?: string;
        mood_rating?: number | null;
        visibility?: string;
        is_flagged?: boolean;
        flagged_reason?: string | null;
        created_at: string;
        author?: { id: number; name: string } | null;
        goal?: { id: number; title: string } | null;
        shift?: { id: number; starts_at: string; ends_at: string } | null;
    };
    compact?: boolean;
}

const VISIBILITY_BADGE: Record<string, { label: string; className: string }> = {
    staff_only: {
        label: 'Staff Only',
        className:
            'bg-muted text-muted-foreground dark:bg-muted/40 dark:text-muted-foreground',
    },
    include_family: {
        label: 'Family Visible',
        className:
            'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    },
    private: {
        label: 'Private',
        className:
            'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    },
};

function moodColor(rating: number): string {
    if (rating <= 3) return 'bg-status-critical';
    if (rating <= 6) return 'bg-status-warning';
    if (rating <= 8) return 'bg-status-info';
    return 'bg-status-success';
}

function relativeTime(dateStr: string): string {
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    const diffMs = now - then;
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return new Date(dateStr).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatShiftTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export function ProgressNoteEntry({
    note,
    compact = false,
}: ProgressNoteEntryProps) {
    const [expanded, setExpanded] = useState(false);

    const contentLong = note.content.length > 200;
    const visibilityInfo = note.visibility
        ? VISIBILITY_BADGE[note.visibility]
        : null;

    if (compact) {
        return (
            <div
                className={`flex items-center gap-2 rounded-md px-2 py-1.5 text-xs ${note.is_flagged ? 'border-l-2 border-l-red-500 bg-status-critical-bg' : ''}`}
            >
                {note.author && (
                    <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/40">
                        <span className="text-[8px] font-semibold text-primary dark:text-primary/70">
                            {getInitials(note.author.name)}
                        </span>
                    </div>
                )}
                <span className="flex-1 truncate text-muted-foreground">
                    {note.content}
                </span>
                <span className="shrink-0 text-[10px] text-muted-foreground">
                    {relativeTime(note.created_at)}
                </span>
                {note.mood_rating != null && (
                    <span
                        className={`h-2 w-2 shrink-0 rounded-full ${moodColor(note.mood_rating)}`}
                    />
                )}
            </div>
        );
    }

    return (
        <Card
            className={`transition-shadow hover:shadow-sm ${note.is_flagged ? 'border-l-4 border-l-red-500' : ''}`}
        >
            <CardContent className="p-4">
                {/* Header: avatar, name, time, mood, badges */}
                <div className="flex flex-wrap items-center gap-2">
                    {note.author && (
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 dark:bg-primary/40">
                            <span className="text-[10px] font-semibold text-primary dark:text-primary/70">
                                {getInitials(note.author.name)}
                            </span>
                        </div>
                    )}
                    <div className="flex min-w-0 items-center gap-1.5">
                        {note.author && (
                            <span className="truncate text-sm font-medium text-foreground">
                                {note.author.name}
                            </span>
                        )}
                        <span className="text-[11px] text-muted-foreground">
                            {relativeTime(note.created_at)}
                        </span>
                    </div>

                    <div className="ml-auto flex items-center gap-1.5">
                        {note.mood_rating != null && (
                            <div className="flex items-center gap-1">
                                <span
                                    className={`h-2.5 w-2.5 rounded-full ${moodColor(note.mood_rating)}`}
                                />
                                <span className="text-[10px] text-muted-foreground">
                                    {note.mood_rating}/10
                                </span>
                            </div>
                        )}
                        {visibilityInfo && (
                            <Badge
                                className={`${visibilityInfo.className} border-0 text-[10px]`}
                            >
                                {visibilityInfo.label}
                            </Badge>
                        )}
                        {note.is_flagged && (
                            <div className="group relative">
                                <Flag className="h-3.5 w-3.5 text-status-critical" />
                                {note.flagged_reason && (
                                    <div className="absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-muted px-2 py-1 text-[10px] whitespace-nowrap text-white shadow-lg group-hover:block dark:bg-muted dark:text-foreground">
                                        {note.flagged_reason}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Note type badge */}
                {note.note_type && (
                    <Badge className="mt-2 border-0 bg-muted text-[10px] text-muted-foreground capitalize dark:bg-muted/40 dark:text-muted-foreground">
                        <MessageSquare className="mr-0.5 h-3 w-3" />
                        {note.note_type.replace(/_/g, ' ')}
                    </Badge>
                )}

                {/* Content */}
                <div className="mt-2">
                    <p className="text-sm leading-relaxed whitespace-pre-line text-foreground">
                        {contentLong && !expanded
                            ? `${note.content.slice(0, 200)}...`
                            : note.content}
                    </p>
                    {contentLong && (
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            onClick={() => setExpanded(!expanded)}
                            className="mt-1 h-auto gap-0.5 p-0 text-[11px] text-primary dark:text-primary"
                        >
                            {expanded ? (
                                <>
                                    <ChevronUp className="h-3 w-3" /> Show less
                                </>
                            ) : (
                                <>
                                    <ChevronDown className="h-3 w-3" /> Read
                                    more
                                </>
                            )}
                        </Button>
                    )}
                </div>

                {/* Linked goal & shift */}
                {(note.goal || note.shift) && (
                    <div className="mt-2.5 flex flex-wrap items-center gap-2">
                        {note.goal && (
                            <Badge className="border-0 bg-primary/10 text-[10px] text-primary dark:bg-primary/30 dark:text-primary/70">
                                Goal: {note.goal.title}
                            </Badge>
                        )}
                        {note.shift && (
                            <span className="text-[10px] text-muted-foreground">
                                Shift: {formatShiftTime(note.shift.starts_at)} —{' '}
                                {formatShiftTime(note.shift.ends_at)}
                            </span>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
