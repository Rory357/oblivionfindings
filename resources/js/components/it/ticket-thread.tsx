/* Shared conversation surface for the ticket workspace — used by the
 * detail page and (item 6c) the quick-peek drawer. Renders the thread
 * (public replies + agent-only internal notes, already stripped from
 * requester payloads server-side), an Activity timeline lane fed by
 * it_ticket_events, and the composer (Reply ⇄ Internal note, Ctrl+Enter). */
import { Button } from '@/components/ui/button';
import { formatFileSize, StagedFileCard } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    CheckCircle2,
    Clock3,
    Eye,
    EyeOff,
    Flag,
    GitMerge,
    Lightbulb,
    Link2,
    ListChecks,
    Lock,
    Mail,
    MessageSquare,
    Paperclip,
    Pencil,
    PlusCircle,
    Radio,
    RotateCcw,
    Route,
    Send,
    ShieldCheck,
    Siren,
    Star,
    Unlink,
    UserCog,
    Webhook,
    Wrench,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

export interface ThreadAttachment {
    id: number;
    name: string;
    size: number;
    url: string;
}

export interface ThreadComment {
    id: number;
    body: string;
    is_internal: boolean;
    author: { id: number | null; name: string; is_requester: boolean };
    attachments: ThreadAttachment[];
    at: string | null;
    at_human: string | null;
}

export interface ThreadEvent {
    id: number;
    type: string;
    payload: Record<string, unknown> | null;
    actor: string | null;
    at: string | null;
    at_human: string | null;
}

/** A published article the composer can suggest to an agent (§I, agents only). */
export interface ThreadKbHint {
    id: number;
    title: string;
    category: string;
}

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

/** Download chips for a message's evidence — served via the authorised route. */
function AttachmentChips({ attachments }: { attachments: ThreadAttachment[] }) {
    if (!attachments.length) return null;

    return (
        <div className="mt-2 flex flex-wrap gap-1.5">
            {attachments.map((a) => (
                <a
                    key={a.id}
                    href={a.url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-border/60 bg-card px-2 py-1 text-[11.5px] font-semibold text-primary hover:border-primary/50"
                >
                    <Paperclip className="h-3 w-3 flex-none" />
                    <span className="min-w-0 truncate">{a.name}</span>
                    <span className="flex-none text-muted-foreground">
                        {formatFileSize(a.size)}
                    </span>
                </a>
            ))}
        </div>
    );
}

/** Human sentence for an activity row. */
function eventLine(e: ThreadEvent): string {
    const p = e.payload ?? {};
    switch (e.type) {
        case 'created':
            return 'raised the ticket';
        case 'assigned':
            return p.to ? 'assigned the ticket' : 'unassigned the ticket';
        case 'status_changed':
            return `moved ${label(String(p.from ?? '?'))} → ${label(String(p.to ?? '?'))}`;
        case 'workflow_transitioned':
            return `moved ${label(String(p.from_workflow_state ?? p.from ?? '?'))} → ${label(String(p.to_workflow_state ?? p.to ?? '?'))}`;
        case 'priority_changed':
            return `set priority ${label(String(p.from ?? '?'))} → ${label(String(p.to ?? '?'))}`;
        case 'properties_updated':
            return 'updated ticket properties';
        case 'waiting_updated':
            return `updated the wait to ${label(String(p.waiting_party ?? 'another dependency'))}`;
        case 'first_response_recorded':
            return 'recorded the first public response';
        case 'csat_submitted':
            return 'submitted a satisfaction rating';
        case 'csat_updated':
            return 'updated the satisfaction rating';
        case 'watcher_added':
            return 'started watching';
        case 'watcher_removed':
            return 'stopped watching';
        case 'reopened':
            return 'reopened the ticket';
        case 'resolved':
            return 'resolved the ticket';
        case 'closed':
            return 'closed the ticket';
        case 'problem_updated':
            return 'updated the problem investigation';
        case 'change_updated':
            return 'updated the change plan';
        case 'major_incident_updated':
            return 'updated major incident command';
        case 'major_incident_update_published':
            return `published a ${label(String(p.audience ?? 'stakeholder'))} major incident update`;
        case 'approval_requested':
            return 'requested approval';
        case 'approval_approved':
            return 'approved the request';
        case 'approval_rejected':
            return 'rejected the request';
        case 'routing_applied':
            return 'updated queue routing';
        case 'merged':
            return p.direction === 'from'
                ? `merged ${String(p.source_reference ?? 'another ticket')} into this ticket`
                : `merged this ticket into ${String(p.target_reference ?? 'the surviving ticket')}`;
        case 'email_received':
            return 'received a public reply by email';
        case 'api_public_comment':
            return 'added a public update through an approved API';
        case 'context_linked':
            return p.device_name
                ? `linked affected Device ${String(p.device_name)}`
                : 'linked an affected Device';
        case 'context_unlinked':
            return p.device_name
                ? `removed affected Device ${String(p.device_name)}`
                : 'removed an affected Device';
        case 'work_task_created':
            return p.title
                ? `added work task ${String(p.title)}`
                : 'added a work task';
        case 'work_task_updated':
            return p.title
                ? `updated work task ${String(p.title)}`
                : 'updated a work task';
        case 'work_task_completed':
            return p.title
                ? `completed work task ${String(p.title)}`
                : 'completed a work task';
        case 'work_task_reopened':
            return p.title
                ? `reopened work task ${String(p.title)}`
                : 'reopened a work task';
        default:
            return label(e.type);
    }
}

function eventIcon(type: string) {
    switch (type) {
        case 'created':
            return PlusCircle;
        case 'assigned':
            return UserCog;
        case 'status_changed':
        case 'workflow_transitioned':
            return RotateCcw;
        case 'priority_changed':
            return Flag;
        case 'properties_updated':
            return Pencil;
        case 'waiting_updated':
            return Clock3;
        case 'first_response_recorded':
            return MessageSquare;
        case 'csat_submitted':
        case 'csat_updated':
            return Star;
        case 'watcher_added':
            return Eye;
        case 'watcher_removed':
            return EyeOff;
        case 'resolved':
        case 'closed':
            return CheckCircle2;
        case 'problem_updated':
        case 'change_updated':
            return Wrench;
        case 'major_incident_updated':
            return Siren;
        case 'major_incident_update_published':
            return Radio;
        case 'approval_requested':
        case 'approval_approved':
        case 'approval_rejected':
            return ShieldCheck;
        case 'routing_applied':
            return Route;
        case 'merged':
            return GitMerge;
        case 'email_received':
            return Mail;
        case 'api_public_comment':
            return Webhook;
        case 'context_linked':
            return Link2;
        case 'context_unlinked':
            return Unlink;
        case 'work_task_created':
            return ListChecks;
        case 'work_task_updated':
            return Pencil;
        case 'work_task_completed':
            return CheckCircle2;
        case 'work_task_reopened':
            return RotateCcw;
        default:
            return Activity;
    }
}

export function TicketThread({
    ticketId,
    requesterName,
    description,
    ticketAttachments = [],
    comments,
    events,
    canInternal,
    canReply = true,
    replyUnavailableReason,
    kbSuggestions = [],
    compact = false,
    onPosted,
}: {
    ticketId: number;
    requesterName: string;
    description: string | null;
    /** Files attached when the ticket was raised (thread replies carry their own). */
    ticketAttachments?: ThreadAttachment[];
    comments: ThreadComment[];
    events: ThreadEvent[];
    canInternal: boolean;
    canReply?: boolean;
    replyUnavailableReason?: string | null;
    /** Published articles for the composer's "Suggest from Knowledge" (agents only). */
    kbSuggestions?: ThreadKbHint[];
    compact?: boolean;
    /** Drawer hosts pass a refetch — their snapshot doesn't refresh via Inertia props. */
    onPosted?: () => void;
}) {
    const [lane, setLane] = useState<'conversation' | 'activity'>(
        'conversation',
    );
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<{
        body: string;
        is_internal: boolean;
        attachments: File[];
    }>({
        body: '',
        is_internal: false,
        attachments: [],
    });

    // §I deflection: as the agent types, match published articles on the words
    // they're using (≥3-letter tokens) and surface the closest few for
    // insertion. Empty for requesters — the server never sends them the list.
    const bodyTokens =
        form.data.body.toLowerCase().match(/[a-z0-9]{3,}/g) ?? [];
    const kbHints = bodyTokens.length
        ? kbSuggestions
              .map((a) => ({
                  a,
                  score: bodyTokens.filter((t) =>
                      a.title.toLowerCase().includes(t),
                  ).length,
              }))
              .filter((x) => x.score > 0)
              .sort((x, y) => y.score - x.score)
              .slice(0, 3)
              .map((x) => x.a)
        : [];

    /** Drop a plain-text reference to a guide into the reply — no dead link. */
    const insertArticle = (title: string) => {
        const ref = `Related guide: "${title}" — search it in the Knowledge tab.`;
        if (form.data.body.includes(ref)) return;
        const base = form.data.body.trimEnd();
        form.setData('body', base ? `${base}\n\n${ref}` : ref);
        toast.success(`Referenced "${title}".`);
    };

    const send = () => {
        if (!form.data.body.trim()) return;
        form.post(`/it/tickets/${ticketId}/comments`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                toast.success(
                    form.data.is_internal
                        ? 'Internal note added.'
                        : 'Reply sent.',
                );
                form.reset('body', 'attachments');
                onPosted?.();
            },
        });
    };

    const stageFiles = (list: FileList | null) => {
        if (!list?.length) return;
        form.setData(
            'attachments',
            [...form.data.attachments, ...Array.from(list)].slice(0, 5),
        );
        if (fileInput.current) fileInput.current.value = '';
    };

    return (
        <div className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-card">
            {/* Lane toggle */}
            <div className="flex items-center gap-1 border-b border-border bg-muted px-3 py-2">
                {(
                    [
                        {
                            id: 'conversation',
                            l: 'Conversation',
                            icon: MessageSquare,
                        },
                        { id: 'activity', l: 'Activity', icon: Activity },
                    ] as const
                ).map((o) => {
                    const Icon = o.icon;
                    const active = lane === o.id;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- segmented lane toggle, not button chrome
                        <button
                            key={o.id}
                            type="button"
                            aria-pressed={active}
                            onClick={() => setLane(o.id)}
                            className={
                                active
                                    ? 'inline-flex items-center gap-1.5 rounded-lg bg-card px-3 py-1.5 text-[12.5px] font-semibold shadow-sm'
                                    : 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground'
                            }
                        >
                            <Icon className="h-3.5 w-3.5" />
                            {o.l}
                            {o.id === 'activity' ? (
                                <span className="rounded-full bg-muted px-1.5 text-[10.5px] font-bold">
                                    {events.length}
                                </span>
                            ) : null}
                        </button>
                    );
                })}
            </div>

            {lane === 'conversation' ? (
                <>
                    <div
                        className={
                            compact
                                ? 'flex max-h-[46vh] flex-col gap-3 overflow-y-auto px-4 py-4'
                                : 'flex flex-col gap-3 px-4.5 py-4'
                        }
                    >
                        {description || ticketAttachments.length ? (
                            <div className="rounded-xl border border-border/60 bg-muted/40 px-3.5 py-2.5">
                                <div className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    {requesterName} — original report
                                </div>
                                {description ? (
                                    <p className="mt-1 text-[13px] whitespace-pre-wrap">
                                        {description}
                                    </p>
                                ) : null}
                                <AttachmentChips
                                    attachments={ticketAttachments}
                                />
                            </div>
                        ) : null}

                        {comments.map((c) => (
                            <div
                                key={c.id}
                                className={
                                    c.is_internal
                                        ? 'rounded-xl border border-border/60 bg-accent/50 px-3.5 py-2.5'
                                        : 'rounded-xl border border-border/60 bg-card px-3.5 py-2.5'
                                }
                            >
                                <div className="flex flex-wrap items-center gap-2 text-[11px] font-semibold text-muted-foreground">
                                    <span className="text-foreground">
                                        {c.author.name}
                                    </span>
                                    <span>
                                        ·{' '}
                                        {c.author.is_requester
                                            ? 'requester'
                                            : 'IT'}
                                    </span>
                                    {c.is_internal ? (
                                        <StatusBadge
                                            variant="warning"
                                            size="sm"
                                        >
                                            <Lock className="mr-1 h-3 w-3" />{' '}
                                            Internal
                                        </StatusBadge>
                                    ) : null}
                                    <span className="ml-auto">
                                        {c.at_human}
                                    </span>
                                </div>
                                <p className="mt-1 text-[13px] whitespace-pre-wrap">
                                    {c.body}
                                </p>
                                <AttachmentChips attachments={c.attachments} />
                            </div>
                        ))}

                        {comments.length === 0 && !description ? (
                            <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                                {canReply
                                    ? 'No messages yet — start the conversation below.'
                                    : 'No replies have been added to this conversation.'}
                            </p>
                        ) : null}
                    </div>

                    {/* Composer */}
                    {canReply ? (
                        <div className="border-t border-border px-4.5 py-3.5">
                            {canInternal ? (
                                <div className="mb-2 inline-flex gap-1 rounded-lg bg-muted p-1">
                                    {[
                                        { v: false, l: 'Reply' },
                                        { v: true, l: 'Internal note' },
                                    ].map((o) => (
                                        // eslint-disable-next-line no-restricted-syntax -- segmented-control option, not button chrome
                                        <button
                                            key={o.l}
                                            type="button"
                                            aria-pressed={
                                                form.data.is_internal === o.v
                                            }
                                            onClick={() =>
                                                form.setData('is_internal', o.v)
                                            }
                                            className={
                                                form.data.is_internal === o.v
                                                    ? 'rounded-md bg-card px-3 py-1 text-[12.5px] font-semibold shadow-sm'
                                                    : 'rounded-md px-3 py-1 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground'
                                            }
                                        >
                                            {o.l}
                                        </button>
                                    ))}
                                </div>
                            ) : null}
                            <Textarea
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                                onKeyDown={(e) => {
                                    if (
                                        e.key === 'Enter' &&
                                        (e.ctrlKey || e.metaKey)
                                    )
                                        send();
                                }}
                                placeholder={
                                    form.data.is_internal
                                        ? 'Add an internal note — the requester never sees these…'
                                        : 'Write a reply — the requester is emailed a heads-up…'
                                }
                                rows={compact ? 2 : 3}
                            />
                            {kbHints.length ? (
                                <div className="mt-2 rounded-xl border border-primary/20 bg-primary/5 px-2.5 py-2">
                                    <div className="flex items-center gap-1.5 text-[10.5px] font-bold tracking-wide text-primary uppercase">
                                        <Lightbulb className="h-3 w-3" />{' '}
                                        Suggest from Knowledge
                                    </div>
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                        {kbHints.map((a) => (
                                            // eslint-disable-next-line no-restricted-syntax -- KB suggestion chip, inserts a reference; not button chrome
                                            <button
                                                key={a.id}
                                                type="button"
                                                onClick={() =>
                                                    insertArticle(a.title)
                                                }
                                                title={`Insert a reference to "${a.title}"`}
                                                className="inline-flex max-w-full items-center gap-1 rounded-full border border-primary/30 bg-card px-2 py-0.5 text-[11.5px] font-semibold text-primary hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                                            >
                                                <BookOpen className="h-3 w-3 flex-none" />
                                                <span className="min-w-0 truncate">
                                                    {a.title}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                            {form.data.attachments.length ? (
                                <div className="mt-2 flex flex-col gap-1.5">
                                    {form.data.attachments.map((file, i) => (
                                        <StagedFileCard
                                            key={`${file.name}-${i}`}
                                            file={file}
                                            onRemove={() =>
                                                form.setData(
                                                    'attachments',
                                                    form.data.attachments.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        />
                                    ))}
                                </div>
                            ) : null}
                            <div className="mt-2 flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <input
                                        ref={fileInput}
                                        type="file"
                                        multiple
                                        className="hidden"
                                        accept=".jpg,.jpeg,.png,.webp,.gif,.heic,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx"
                                        onChange={(e) =>
                                            stageFiles(e.target.files)
                                        }
                                    />
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            fileInput.current?.click()
                                        }
                                        disabled={
                                            form.data.attachments.length >= 5
                                        }
                                    >
                                        <Paperclip className="h-3.5 w-3.5" />{' '}
                                        Attach
                                    </Button>
                                    <span className="text-[11.5px] text-muted-foreground">
                                        Ctrl+Enter to send
                                    </span>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={send}
                                    disabled={
                                        form.processing ||
                                        !form.data.body.trim()
                                    }
                                >
                                    <Send className="h-3.5 w-3.5" />
                                    {form.data.is_internal
                                        ? 'Add note'
                                        : 'Send reply'}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="border-t border-border bg-muted/40 px-4.5 py-4">
                            <div className="flex items-start gap-2.5">
                                <span className="grid h-8 w-8 flex-none place-items-center rounded-lg bg-muted text-muted-foreground">
                                    <Lock className="h-4 w-4" />
                                </span>
                                <div className="min-w-0">
                                    <p className="text-[13px] font-semibold">
                                        This conversation is read-only
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-muted-foreground">
                                        {replyUnavailableReason ??
                                            'This ticket cannot accept another reply.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}
                </>
            ) : (
                <div
                    className={
                        compact
                            ? 'flex max-h-[58vh] flex-col overflow-y-auto px-4 py-3'
                            : 'flex flex-col px-4.5 py-3'
                    }
                >
                    {events.length === 0 ? (
                        <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                            Nothing on the trail yet.
                        </p>
                    ) : (
                        events.map((e) => {
                            const Icon = eventIcon(e.type);
                            return (
                                <div
                                    key={e.id}
                                    className="flex items-start gap-2.5 border-b border-border/40 py-2.5 last:border-0"
                                >
                                    <span className="mt-0.5 grid h-6 w-6 flex-none place-items-center rounded-lg bg-muted text-muted-foreground">
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                    <div className="min-w-0 text-[12.5px]">
                                        <span className="font-semibold">
                                            {e.actor ?? 'System'}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {eventLine(e)}
                                        </span>
                                        <span className="ml-2 text-[11px] text-muted-foreground">
                                            {e.at_human}
                                        </span>
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>
            )}
        </div>
    );
}
