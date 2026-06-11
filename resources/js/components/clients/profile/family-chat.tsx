/* eslint-disable no-restricted-syntax -- Chat surface is a bespoke popup per
 * the redesign handoff (chat.jsx); colours map to semantic tokens. */
/* Family chat popup — the staff side of the whānau thread. Same
 * OpsConversation records the family portal messaging uses, resolved via
 * /operations/clients/{id}/family-chat. */
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Info, Loader2, MessageCircle, Send, Users, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type ChatMessage = {
    id: number;
    content: string;
    sender_id: number | null;
    sender_name: string | null;
    sender_type: string | null;
    mine: boolean;
    created_at: string | null;
};

type ChatPayload = {
    conversation: {
        id: number;
        title: string | null;
        participants: { id: number; name: string }[];
    } | null;
    messages: ChatMessage[];
    portal_users: { id: number; name: string }[];
};

function timeLabel(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function FamilyChatPopup({
    open,
    onClose,
    clientId,
    clientName,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    clientName: string;
}) {
    const [payload, setPayload] = useState<ChatPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const scrollRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        try {
            const res = await fetch(
                `/operations/clients/${clientId}/family-chat`,
                { headers: { Accept: 'application/json' } },
            );
            if (!res.ok) throw new Error('chat fetch failed');
            const json = (await res.json()) as ChatPayload;
            setPayload(json);
        } catch {
            // Keep the previous payload on poll failures.
        } finally {
            setLoading(false);
        }
    }, [clientId]);

    useEffect(() => {
        if (!open) return;
        setLoading(true);
        void load();
        const poll = window.setInterval(() => void load(), 8000);
        const t = window.setTimeout(() => inputRef.current?.focus(), 80);
        return () => {
            window.clearInterval(poll);
            window.clearTimeout(t);
        };
    }, [open, load]);

    useEffect(() => {
        const el = scrollRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [payload?.messages.length, open]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const send = async () => {
        const content = draft.trim();
        if (!content || sending) return;
        setSending(true);
        try {
            const token =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            const res = await fetch(
                `/operations/clients/${clientId}/family-chat`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ content }),
                },
            );
            if (!res.ok) throw new Error('send failed');
            setDraft('');
            await load();
        } catch {
            toast.error('Message could not be sent.');
        } finally {
            setSending(false);
        }
    };

    if (!open) return null;

    const whanauNames =
        payload?.portal_users.map((u) => u.name).join(', ') ||
        'No portal users yet';

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-end bg-black/30 sm:p-4"
            onMouseDown={onClose}
            role="dialog"
            aria-label={`Family chat about ${clientName}`}
        >
            <div
                className="flex h-[100dvh] w-full flex-col overflow-hidden border border-border bg-card shadow-2xl sm:h-[640px] sm:max-h-[86vh] sm:w-[390px] sm:rounded-2xl"
                onMouseDown={(e) => e.stopPropagation()}
            >
                {/* header */}
                <div className="flex items-center gap-3 bg-primary px-3 py-2.5 text-primary-foreground">
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary-foreground/15">
                        <Users className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1 leading-tight">
                        <div className="truncate text-sm font-semibold">
                            {payload?.conversation?.title ??
                                `${clientName} — whānau chat`}
                        </div>
                        <div className="truncate text-[11px] text-primary-foreground/75">
                            {whanauNames}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        title="Close"
                        className="rounded-full p-2 hover:bg-primary-foreground/15"
                    >
                        <X className="h-[18px] w-[18px]" />
                    </button>
                </div>

                {/* context banner */}
                <div className="flex items-center justify-center gap-1.5 border-b border-border bg-muted/40 px-3 py-1.5 text-[11px] text-muted-foreground">
                    <Info className="h-3 w-3" />
                    Conversation about{' '}
                    <span className="font-semibold text-foreground">
                        {clientName}
                    </span>{' '}
                    · visible to whānau on the portal
                </div>

                {/* messages */}
                <div
                    ref={scrollRef}
                    className="flex-1 space-y-1.5 overflow-y-auto bg-muted/20 px-3 py-3"
                >
                    {loading ? (
                        <div className="flex h-full items-center justify-center text-muted-foreground">
                            <Loader2 className="h-5 w-5 animate-spin" />
                        </div>
                    ) : payload?.messages.length ? (
                        payload.messages.map((m) => (
                            <div
                                key={m.id}
                                className={cn(
                                    'flex',
                                    m.mine ? 'justify-end' : 'justify-start',
                                )}
                            >
                                <div
                                    className={cn(
                                        'max-w-[78%] px-3 py-2 text-[13.5px] leading-snug shadow-sm',
                                        m.mine
                                            ? 'rounded-[12px_12px_3px_12px] bg-primary text-primary-foreground'
                                            : 'rounded-[12px_12px_12px_3px] border border-border bg-card',
                                    )}
                                >
                                    {!m.mine ? (
                                        <div className="mb-0.5 text-[11px] font-semibold text-primary">
                                            {m.sender_name ?? 'Whānau'}
                                        </div>
                                    ) : null}
                                    <div className="whitespace-pre-wrap">
                                        {m.content}
                                    </div>
                                    <div
                                        className={cn(
                                            'mt-0.5 text-right text-[10px]',
                                            m.mine
                                                ? 'text-primary-foreground/70'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {timeLabel(m.created_at)}
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
                            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <MessageCircle className="h-[22px] w-[22px]" />
                            </span>
                            <p className="text-sm font-medium">
                                Start the conversation
                            </p>
                            <p className="max-w-[260px] text-xs text-muted-foreground">
                                Messages here are shared with{' '}
                                {clientName}&apos;s whānau on the family
                                portal.
                            </p>
                        </div>
                    )}
                </div>

                {/* composer */}
                <div className="flex items-center gap-2 border-t border-border bg-card px-3 py-2.5">
                    <input
                        ref={inputRef}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                void send();
                            }
                        }}
                        placeholder="Type a message"
                        className="h-10 flex-1 rounded-full border border-border bg-background px-4 text-sm outline-none focus:ring-2 focus:ring-ring"
                    />
                    <Button
                        type="button"
                        size="icon"
                        className="h-10 w-10 shrink-0 rounded-full"
                        onClick={() => void send()}
                        disabled={sending || !draft.trim()}
                        aria-label="Send message"
                    >
                        {sending ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Send className="h-4 w-4" />
                        )}
                    </Button>
                </div>
            </div>
        </div>
    );
}
