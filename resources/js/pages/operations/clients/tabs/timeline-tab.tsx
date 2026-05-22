import { TimelineInteractions } from '@/components/timeline-interactions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Link, useForm } from '@inertiajs/react';
import { Clock, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type ClientTimelineTabProps = {
    clientId: number;
    events: Array<any>;
    handover: Array<any>;
    canCreateNote: boolean;
    canPinHandover: boolean;
    auth?: unknown;
};

const templates = [
    { key: 'note', label: 'Note', body: '' },
    {
        key: 'progress_note',
        label: 'Progress note',
        body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:',
    },
    {
        key: 'handover',
        label: 'Handover',
        body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-',
    },
];

export function ClientTimelineTab({
    clientId,
    events,
    handover,
    canCreateNote,
    canPinHandover,
    auth,
}: ClientTimelineTabProps) {
    const [timelineSearch, setTimelineSearch] = useState('');
    const [timelineTypeFilter, setTimelineTypeFilter] = useState('all');

    const noteForm = useForm<{
        type: string;
        subject: string;
        goal: string;
        body: string;
        visibility: string;
        pin: boolean;
    }>({
        type: 'note',
        subject: '',
        goal: '',
        body: '',
        visibility: 'internal',
        pin: false,
    });

    const eventTypes = useMemo(() => {
        const types = new Set<string>();
        events.forEach((e) => {
            if (e.type) types.add(e.type);
        });
        return Array.from(types).sort();
    }, [events]);

    const filteredEvents = useMemo(() => {
        return events.filter((e) => {
            if (timelineTypeFilter !== 'all' && e.type !== timelineTypeFilter)
                return false;
            if (timelineSearch) {
                const q = timelineSearch.toLowerCase();
                const searchable = [e.subject, e.body, e.type, e.actor?.name]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!searchable.includes(q)) return false;
            }
            return true;
        });
    }, [events, timelineSearch, timelineTypeFilter]);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Timeline</CardTitle>
                <div className="flex flex-wrap items-center gap-2 pt-2">
                    <div className="relative min-w-[180px] flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search events..."
                            value={timelineSearch}
                            onChange={(e) => setTimelineSearch(e.target.value)}
                            className="h-8 pl-8 text-xs"
                        />
                    </div>
                    <Select
                        value={timelineTypeFilter}
                        onValueChange={setTimelineTypeFilter}
                    >
                        <SelectTrigger className="h-8 w-[160px] text-xs">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {eventTypes.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {t}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </CardHeader>
            <CardContent className="space-y-2">
                {handover.length ? (
                    <div className="rounded-md border p-3">
                        <div className="text-sm font-medium">
                            Pinned handover
                        </div>
                        <div className="mt-2 space-y-2">
                            {handover.map((h) => (
                                <div
                                    key={h.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="text-sm font-medium">
                                            {h.subject || 'Handover'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {h.occurred_at
                                                ? new Date(
                                                      h.occurred_at,
                                                  ).toLocaleString()
                                                : ''}
                                        </div>
                                    </div>
                                    {h.body && (
                                        <div className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                            {h.body}
                                        </div>
                                    )}
                                    <div className="mt-2 flex items-center justify-between gap-2">
                                        <div className="text-xs text-muted-foreground">
                                            {h.actor?.name
                                                ? `By ${h.actor.name}`
                                                : ''}
                                        </div>
                                        {canPinHandover && h.source_id ? (
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0 text-xs underline"
                                                onClick={async () => {
                                                    await fetch(
                                                        `/operations/clients/${clientId}/notes/${h.source_id}/pin`,
                                                        {
                                                            method: 'POST',
                                                            headers: {
                                                                'X-Requested-With':
                                                                    'XMLHttpRequest',
                                                                'X-CSRF-TOKEN':
                                                                    (
                                                                        document.querySelector(
                                                                            'meta[name="csrf-token"]',
                                                                        ) as HTMLMetaElement
                                                                    )?.content,
                                                            },
                                                        },
                                                    );
                                                    window.location.reload();
                                                }}
                                            >
                                                Unpin
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {canCreateNote && (
                    <div className="rounded-md border p-3">
                        <div className="text-sm font-medium">Add note</div>
                        <div className="mt-3 grid grid-cols-1 gap-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <Label>Type</Label>
                                    <Select
                                        value={noteForm.data.type}
                                        onValueChange={(v) => {
                                            noteForm.setData('type', v);
                                            const tpl = templates.find(
                                                (t) => t.key === v,
                                            );
                                            if (
                                                tpl &&
                                                noteForm.data.body.trim() === ''
                                            ) {
                                                noteForm.setData(
                                                    'body',
                                                    tpl.body,
                                                );
                                            }
                                            noteForm.setData(
                                                'pin',
                                                v === 'handover',
                                            );
                                        }}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {templates.map((t) => (
                                                <SelectItem
                                                    key={t.key}
                                                    value={t.key}
                                                >
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Subject (optional)</Label>
                                    <Input
                                        value={noteForm.data.subject}
                                        onChange={(e) =>
                                            noteForm.setData(
                                                'subject',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            {noteForm.data.type === 'progress_note' ? (
                                <div>
                                    <Label>Goal/outcome (optional)</Label>
                                    <Input
                                        value={noteForm.data.goal}
                                        onChange={(e) =>
                                            noteForm.setData(
                                                'goal',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            ) : null}
                            <div>
                                <Label>Note</Label>
                                <Textarea
                                    rows={3}
                                    value={noteForm.data.body}
                                    onChange={(e) =>
                                        noteForm.setData('body', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <div className="flex items-center gap-2 text-xs">
                                <Checkbox
                                    checked={
                                        noteForm.data.visibility === 'portal'
                                    }
                                    onCheckedChange={(v) =>
                                        noteForm.setData(
                                            'visibility',
                                            v ? 'portal' : 'internal',
                                        )
                                    }
                                />
                                <span>Share in portal</span>
                            </div>
                            {noteForm.data.type === 'handover' ? (
                                <div className="flex items-center gap-2 text-xs">
                                    <Checkbox
                                        checked={noteForm.data.pin}
                                        onCheckedChange={(v) =>
                                            noteForm.setData('pin', Boolean(v))
                                        }
                                    />
                                    <span>Pin as handover</span>
                                </div>
                            ) : null}

                            <Button
                                onClick={() =>
                                    noteForm.post(
                                        `/operations/clients/${clientId}/notes`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                noteForm.reset();
                                                noteForm.setData({
                                                    type: 'note',
                                                    subject: '',
                                                    goal: '',
                                                    body: '',
                                                    visibility: 'internal',
                                                    pin: false,
                                                });
                                            },
                                        },
                                    )
                                }
                                disabled={
                                    noteForm.processing || !noteForm.data.body
                                }
                            >
                                Add
                            </Button>
                        </div>
                    </div>
                )}

                {/* Visual Timeline */}
                {filteredEvents.length === 0 ? (
                    <div className="flex flex-col items-center py-12 text-center">
                        <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                            <Clock className="h-7 w-7 text-primary" />
                        </div>
                        <p className="font-medium">
                            {events.length
                                ? 'No events match your filters'
                                : 'No timeline events yet'}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Events will appear here as care is delivered.
                        </p>
                    </div>
                ) : (
                    <div className="relative ml-4">
                        {/* Vertical line */}
                        <div className="absolute top-0 bottom-0 left-3 w-0.5 bg-gradient-to-b from-primary via-primary/10 to-transparent" />

                        <div className="space-y-0">
                            {filteredEvents.map((e, idx) => {
                                const TYPE_STYLES: Record<
                                    string,
                                    {
                                        dot: string;
                                        bg: string;
                                        icon: string;
                                    }
                                > = {
                                    note: {
                                        dot: 'bg-primary',
                                        bg: 'bg-primary/10',
                                        icon: '📝',
                                    },
                                    progress_note: {
                                        dot: 'bg-primary',
                                        bg: 'bg-primary/10',
                                        icon: '🎯',
                                    },
                                    handover: {
                                        dot: 'bg-status-info',
                                        bg: 'bg-status-info-bg',
                                        icon: '🤝',
                                    },
                                    incident: {
                                        dot: 'bg-status-critical',
                                        bg: 'bg-status-critical-bg',
                                        icon: '⚠️',
                                    },
                                    shift: {
                                        dot: 'bg-status-success',
                                        bg: 'bg-status-success-bg',
                                        icon: '📋',
                                    },
                                    medication: {
                                        dot: 'bg-status-info',
                                        bg: 'bg-status-info-bg',
                                        icon: '💊',
                                    },
                                    assessment: {
                                        dot: 'bg-status-warning',
                                        bg: 'bg-status-warning-bg',
                                        icon: '📊',
                                    },
                                };
                                const style = TYPE_STYLES[e.type] ?? {
                                    dot: 'bg-muted',
                                    bg: 'bg-muted',
                                    icon: '📌',
                                };

                                // Date grouping
                                const eventDate = e.occurred_at
                                    ? new Date(
                                          e.occurred_at,
                                      ).toLocaleDateString('en-NZ', {
                                          weekday: 'long',
                                          day: 'numeric',
                                          month: 'long',
                                      })
                                    : '';
                                const prevDate =
                                    idx > 0 &&
                                    filteredEvents[idx - 1].occurred_at
                                        ? new Date(
                                              filteredEvents[idx - 1]
                                                  .occurred_at,
                                          ).toLocaleDateString('en-NZ', {
                                              weekday: 'long',
                                              day: 'numeric',
                                              month: 'long',
                                          })
                                        : '';
                                const showDateHeader = eventDate !== prevDate;

                                return (
                                    <div key={e.id}>
                                        {showDateHeader && (
                                            <div className="relative mt-4 mb-2 flex items-center pl-8 first:mt-0">
                                                <div className="absolute left-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white bg-primary/20">
                                                    <div className="h-2 w-2 rounded-full bg-primary" />
                                                </div>
                                                <span className="text-xs font-semibold text-primary">
                                                    {eventDate}
                                                </span>
                                            </div>
                                        )}
                                        <div className="relative flex gap-3 pb-4 pl-8">
                                            {/* Dot on timeline */}
                                            <div
                                                className={`absolute top-1 left-0 flex h-6 w-6 items-center justify-center rounded-full border-2 border-white ${style.dot} shadow-sm`}
                                            >
                                                <span className="text-[10px]">
                                                    {style.icon}
                                                </span>
                                            </div>
                                            {/* Event card */}
                                            <div
                                                className={`flex-1 rounded-xl border ${style.bg} p-3 transition-all hover:shadow-sm`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-sm font-medium">
                                                                {e.subject ||
                                                                    e.type}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className="h-4 px-1.5 text-[9px] capitalize"
                                                            >
                                                                {e.type}
                                                            </Badge>
                                                        </div>
                                                        {e.actor?.name && (
                                                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                                                {e.actor.name}
                                                                {e.site?.name
                                                                    ? ` · ${e.site.name}`
                                                                    : ''}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <span className="shrink-0 text-[10px] text-muted-foreground">
                                                        {e.occurred_at
                                                            ? new Date(
                                                                  e.occurred_at,
                                                              ).toLocaleTimeString(
                                                                  'en-NZ',
                                                                  {
                                                                      hour: '2-digit',
                                                                      minute: '2-digit',
                                                                  },
                                                              )
                                                            : ''}
                                                    </span>
                                                </div>
                                                {e.body && (
                                                    <p className="mt-1.5 text-xs leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                                        {e.body.length > 250
                                                            ? e.body.slice(
                                                                  0,
                                                                  250,
                                                              ) + '...'
                                                            : e.body}
                                                    </p>
                                                )}
                                                {e.meta?.emotions &&
                                                    (
                                                        e.meta
                                                            .emotions as string[]
                                                    ).length > 0 && (
                                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                                            {(
                                                                e.meta
                                                                    .emotions as string[]
                                                            ).map(
                                                                (
                                                                    em: string,
                                                                ) => {
                                                                    const emojiMap: Record<
                                                                        string,
                                                                        string
                                                                    > = {
                                                                        happy: '😊',
                                                                        calm: '😌',
                                                                        excited:
                                                                            '🤩',
                                                                        tired: '😴',
                                                                        anxious:
                                                                            '😰',
                                                                        sad: '😢',
                                                                        frustrated:
                                                                            '😤',
                                                                        confused:
                                                                            '😕',
                                                                    };
                                                                    const colorMap: Record<
                                                                        string,
                                                                        string
                                                                    > = {
                                                                        happy: 'bg-status-success-bg text-status-success',
                                                                        calm: 'bg-status-info-bg text-status-info',
                                                                        excited:
                                                                            'bg-status-warning-bg text-status-warning',
                                                                        tired: 'bg-primary/10 text-primary',
                                                                        anxious:
                                                                            'bg-status-warning-bg text-status-warning',
                                                                        sad: 'bg-status-info-bg text-status-info',
                                                                        frustrated:
                                                                            'bg-status-critical-bg text-status-critical',
                                                                        confused:
                                                                            'bg-primary/10 text-primary',
                                                                    };
                                                                    return (
                                                                        <span
                                                                            key={
                                                                                em
                                                                            }
                                                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${colorMap[em] ?? 'bg-muted'}`}
                                                                        >
                                                                            {emojiMap[
                                                                                em
                                                                            ] ??
                                                                                em}{' '}
                                                                            {em}
                                                                        </span>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    )}
                                                {(e.comments?.length > 0 ||
                                                    e.reactions?.length > 0 ||
                                                    canCreateNote) && (
                                                    <TimelineInteractions
                                                        eventId={e.id}
                                                        comments={
                                                            e.comments ?? []
                                                        }
                                                        reactions={
                                                            e.reactions ?? []
                                                        }
                                                        currentUserId={
                                                            (auth as any)?.user
                                                                ?.id
                                                        }
                                                        commentUrl={`/clients/${clientId}/timeline/${e.id}/comments`}
                                                        deleteCommentUrl={`/clients/${clientId}/timeline/comments`}
                                                        likeCommentUrl={`/clients/${clientId}/timeline/comments`}
                                                        reactUrl={`/clients/${clientId}/timeline/${e.id}/react`}
                                                        canComment={
                                                            canCreateNote
                                                        }
                                                        canReact={true}
                                                        showStaffBadge={true}
                                                    />
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="flex justify-center pt-2">
                    <Button
                        variant="outline"
                        size="sm"
                        className="gap-1.5 text-xs"
                        asChild
                    >
                        <Link href={`/clients/${clientId}/timeline`}>
                            View Full Timeline
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
