import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Flag, MessageSquare, ChevronDown, ChevronUp } from 'lucide-react';
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
        className: 'bg-muted text-muted-foreground dark:bg-muted/40 dark:text-muted-foreground',
    },
    include_family: {
        label: 'Family Visible',
        className: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    },
    private: {
        label: 'Private',
        className: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    },
};

function moodColor(rating: number): string {
    if (rating <= 3) return 'bg-red-500';
    if (rating <= 6) return 'bg-amber-500';
    if (rating <= 8) return 'bg-blue-500';
    return 'bg-emerald-500';
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
    return new Date(dateStr).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatShiftTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export function ProgressNoteEntry({ note, compact = false }: ProgressNoteEntryProps) {
    const [expanded, setExpanded] = useState(false);

    const contentLong = note.content.length > 200;
    const visibilityInfo = note.visibility ? VISIBILITY_BADGE[note.visibility] : null;

    if (compact) {
        return (
            <div className={`flex items-center gap-2 py-1.5 px-2 rounded-md text-xs ${note.is_flagged ? 'bg-red-50 dark:bg-red-950/20 border-l-2 border-l-red-500' : ''}`}>
                {note.author && (
                    <div className="h-5 w-5 shrink-0 rounded-full bg-primary/10 dark:bg-primary/40 flex items-center justify-center">
                        <span className="text-[8px] font-semibold text-primary dark:text-primary/70">
                            {getInitials(note.author.name)}
                        </span>
                    </div>
                )}
                <span className="text-muted-foreground truncate flex-1">{note.content}</span>
                <span className="text-[10px] text-muted-foreground shrink-0">{relativeTime(note.created_at)}</span>
                {note.mood_rating != null && (
                    <span className={`h-2 w-2 rounded-full shrink-0 ${moodColor(note.mood_rating)}`} />
                )}
            </div>
        );
    }

    return (
        <Card className={`transition-shadow hover:shadow-sm ${note.is_flagged ? 'border-l-4 border-l-red-500' : ''}`}>
            <CardContent className="p-4">
                {/* Header: avatar, name, time, mood, badges */}
                <div className="flex items-center gap-2 flex-wrap">
                    {note.author && (
                        <div className="h-7 w-7 shrink-0 rounded-full bg-primary/10 dark:bg-primary/40 flex items-center justify-center">
                            <span className="text-[10px] font-semibold text-primary dark:text-primary/70">
                                {getInitials(note.author.name)}
                            </span>
                        </div>
                    )}
                    <div className="flex items-center gap-1.5 min-w-0">
                        {note.author && (
                            <span className="text-sm font-medium text-foreground truncate">{note.author.name}</span>
                        )}
                        <span className="text-[11px] text-muted-foreground">{relativeTime(note.created_at)}</span>
                    </div>

                    <div className="ml-auto flex items-center gap-1.5">
                        {note.mood_rating != null && (
                            <div className="flex items-center gap-1">
                                <span className={`h-2.5 w-2.5 rounded-full ${moodColor(note.mood_rating)}`} />
                                <span className="text-[10px] text-muted-foreground">{note.mood_rating}/10</span>
                            </div>
                        )}
                        {visibilityInfo && (
                            <Badge className={`${visibilityInfo.className} border-0 text-[10px]`}>
                                {visibilityInfo.label}
                            </Badge>
                        )}
                        {note.is_flagged && (
                            <div className="relative group">
                                <Flag className="h-3.5 w-3.5 text-red-500" />
                                {note.flagged_reason && (
                                    <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block z-10 rounded bg-slate-900 dark:bg-muted px-2 py-1 text-[10px] text-white dark:text-foreground whitespace-nowrap shadow-lg">
                                        {note.flagged_reason}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Note type badge */}
                {note.note_type && (
                    <Badge className="mt-2 border-0 bg-muted text-muted-foreground dark:bg-muted/40 dark:text-muted-foreground text-[10px] capitalize">
                        <MessageSquare className="h-3 w-3 mr-0.5" />
                        {note.note_type.replace(/_/g, ' ')}
                    </Badge>
                )}

                {/* Content */}
                <div className="mt-2">
                    <p className="text-sm text-foreground leading-relaxed whitespace-pre-line">
                        {contentLong && !expanded ? `${note.content.slice(0, 200)}...` : note.content}
                    </p>
                    {contentLong && (
                        <button
                            onClick={() => setExpanded(!expanded)}
                            className="mt-1 flex items-center gap-0.5 text-[11px] text-primary dark:text-primary hover:underline"
                        >
                            {expanded ? (
                                <>
                                    <ChevronUp className="h-3 w-3" /> Show less
                                </>
                            ) : (
                                <>
                                    <ChevronDown className="h-3 w-3" /> Read more
                                </>
                            )}
                        </button>
                    )}
                </div>

                {/* Linked goal & shift */}
                {(note.goal || note.shift) && (
                    <div className="mt-2.5 flex items-center gap-2 flex-wrap">
                        {note.goal && (
                            <Badge className="border-0 bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70 text-[10px]">
                                Goal: {note.goal.title}
                            </Badge>
                        )}
                        {note.shift && (
                            <span className="text-[10px] text-muted-foreground">
                                Shift: {formatShiftTime(note.shift.starts_at)} — {formatShiftTime(note.shift.ends_at)}
                            </span>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
