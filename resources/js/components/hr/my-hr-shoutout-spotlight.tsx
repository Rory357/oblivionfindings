/* eslint-disable no-restricted-syntax -- The shout-out spotlight is a bespoke
 * coral-tinted recognition band: reaction toggle chips, an overlapping reactor
 * facepile, a carousel and an inline reply composer, all sized to the design
 * handoff. Every colour maps to a semantic token (or a decorative identity hue,
 * as elsewhere in My HR); the shadcn <Button> can't express these on-tint
 * layouts. */
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Send } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { cn } from '@/lib/utils';

import { hueFromId } from './my-hr-utils';
import {
    MY_HR_KUDOS_LABELS,
    MY_HR_REACTIONS,
    type MyHrReactionKey,
    type MyHrReactor,
    type MyHrShoutout,
} from './my-hr-types';

type Perspective = 'received' | 'given';

/** Identity avatar style — "you" uses the brand; everyone else a stable hue. */
function avatarBg(reactor: { id: number; you: boolean }) {
    return reactor.you
        ? 'var(--primary)'
        : `oklch(0.62 0.16 ${hueFromId(reactor.id)})`;
}

/** Toggle the viewer in/out of a reactor list (optimistic). */
function toggleYou(list: MyHrReactor[], me: { initials: string }): MyHrReactor[] {
    return list.some((r) => r.you)
        ? list.filter((r) => !r.you)
        : [...list, { id: -1, name: 'You', initials: me.initials, you: true }];
}

function firstName(name: string) {
    return name === 'You' ? 'You' : (name.split(/\s+/)[0] ?? name);
}

export function MyHrShoutoutSpotlight({
    shoutouts,
    perspective = 'received',
    me,
    onGiveShoutout,
    compact = false,
}: {
    shoutouts: MyHrShoutout[];
    perspective?: Perspective;
    /** The viewer, for optimistic "you" reactions + the composer avatar. */
    me: { initials: string; firstName: string };
    /** Opens the "give a shout-out" wizard from the empty state. */
    onGiveShoutout?: () => void;
    /** Tighter padding when embedded in the Overview row. */
    compact?: boolean;
}) {
    const [items, setItems] = useState<MyHrShoutout[]>(shoutouts);
    const [index, setIndex] = useState(0);
    const [threadOpen, setThreadOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [draft, setDraft] = useState('');
    const [thanked, setThanked] = useState<Set<number>>(new Set());

    // Resync when the server returns fresh data (after a persisted react/reply,
    // or a Received/Given switch). Keeps the carousel position if still valid.
    useEffect(() => {
        setItems(shoutouts);
        setIndex((i) => (i < shoutouts.length ? i : 0));
    }, [shoutouts]);

    const total = items.length;
    const current = total > 0 ? items[Math.min(index, total - 1)] : null;

    const reactors = useMemo(() => {
        if (!current) return [];
        const seen = new Map<string, MyHrReactor>();
        for (const key of ['heart', 'party', 'hands'] as MyHrReactionKey[]) {
            for (const r of current.reactions[key]) {
                const id = r.you ? 'you' : `u${r.id}`;
                if (!seen.has(id)) seen.set(id, r);
            }
        }
        return [...seen.values()].sort((a, b) => (a.you ? -1 : b.you ? 1 : 0));
    }, [current]);

    if (!current) {
        return (
            <div
                className="rounded-[20px] border p-6 text-center"
                style={{
                    background:
                        'linear-gradient(135deg, color-mix(in oklch, var(--category-hr) 10%, var(--card)), var(--card) 72%)',
                    borderColor:
                        'color-mix(in oklch, var(--category-hr) 24%, var(--border))',
                }}
            >
                <div className="text-2xl">📣</div>
                <div className="mt-1.5 text-[14px] font-bold">
                    {perspective === 'given'
                        ? 'No shout-outs given yet'
                        : 'No shout-outs yet'}
                </div>
                <p className="mx-auto mt-1 max-w-sm text-[12.5px] text-muted-foreground">
                    {perspective === 'given'
                        ? 'Recognise a teammate and it’ll show up here.'
                        : 'When a teammate recognises you, it’ll appear here. Be the one to start it.'}
                </p>
                {onGiveShoutout ? (
                    <button
                        type="button"
                        onClick={onGiveShoutout}
                        className="mt-3 inline-flex items-center gap-1.5 rounded-[10px] border border-primary bg-card px-3 py-2 text-[12.5px] font-bold text-primary transition-colors hover:bg-accent"
                    >
                        <Send className="h-3.5 w-3.5" /> Give a shout-out
                    </button>
                ) : null}
            </div>
        );
    }

    const other = perspective === 'given' ? current.recipient : current.giver;
    const otherHue = hueFromId(other.id);
    const isThanked = thanked.has(current.id) || current.replies.some((r) => r.you);
    const canThanks = perspective === 'received';

    const visibleReplies =
        expanded || current.replies.length <= 2
            ? current.replies
            : current.replies.slice(-2);
    const hiddenCount = current.replies.length - visibleReplies.length;

    /* ---- mutations (optimistic + persisted) ---- */
    function paging(next: number) {
        setIndex(next);
        setThreadOpen(false);
        setExpanded(false);
        setDraft('');
    }

    function react(key: MyHrReactionKey) {
        const target = current;
        if (!target) return;
        setItems((prev) =>
            prev.map((s) =>
                s.id === target.id
                    ? {
                          ...s,
                          reactions: {
                              ...s.reactions,
                              [key]: toggleYou(s.reactions[key], me),
                          },
                      }
                    : s,
            ),
        );
        router.post(
            `/hr/my/kudos/${target.id}/react`,
            { emoji: key },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    toast.error('Could not save your reaction');
                    setItems(shoutouts);
                },
            },
        );
    }

    function postReply(target: MyHrShoutout, body: string) {
        const text = body.trim();
        if (!text) return;
        setItems((prev) =>
            prev.map((s) =>
                s.id === target.id
                    ? {
                          ...s,
                          replies: [
                              ...s.replies,
                              {
                                  id: -Date.now(),
                                  user_id: -1,
                                  name: 'You',
                                  initials: me.initials,
                                  you: true,
                                  body: text,
                                  created_at: null,
                              },
                          ],
                      }
                    : s,
            ),
        );
        setThreadOpen(true);
        router.post(
            `/hr/my/kudos/${target.id}/reply`,
            { body: text },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    toast.error('Could not post your reply');
                    setItems(shoutouts);
                },
            },
        );
    }

    function sendDraft() {
        if (!current || !draft.trim()) return;
        postReply(current, draft);
        setDraft('');
    }

    function sayThanks() {
        if (!current) return;
        setThanked((p) => new Set(p).add(current.id));
        postReply(
            current,
            `Thank you ${firstName(other.name)} — that means a lot. 💛`,
        );
        toast.success('Thanks sent 💛', {
            description: `${firstName(other.name)} will see your reply.`,
        });
    }

    const reactSummary = (() => {
        if (reactors.length === 0) return '';
        if (reactors.length === 1) return `${firstName(reactors[0].name)} reacted`;
        const named = reactors.slice(0, 2).map((r) => firstName(r.name));
        const rest = reactors.length - named.length;
        return rest > 0
            ? `${named.join(', ')} + ${rest} more reacted`
            : `${named.join(' & ')} reacted`;
    })();

    const eyebrow =
        perspective === 'given' ? 'You gave a shout-out 💛' : 'You got a shout-out 🎉';
    const heading =
        perspective === 'given' ? `To ${other.name}` : `From ${other.name}`;

    return (
        <div
            className="relative overflow-hidden rounded-[20px] border"
            style={{
                padding: compact ? '16px 20px' : '18px 22px',
                background:
                    'linear-gradient(135deg, color-mix(in oklch, var(--category-hr) 13%, var(--card)), color-mix(in oklch, var(--status-warning) 7%, var(--card)) 72%)',
                borderColor:
                    'color-mix(in oklch, var(--category-hr) 30%, var(--border))',
                boxShadow:
                    '0 5px 24px -12px color-mix(in oklch, var(--category-hr) 50%, transparent)',
            }}
        >
            <div
                aria-hidden
                className="pointer-events-none absolute -top-3.5 right-[-10px] text-[90px] leading-none"
                style={{ opacity: 0.12, transform: 'rotate(8deg)' }}
            >
                🎉
            </div>

            {/* header */}
            <div className="relative flex items-center gap-3">
                <span
                    className="grid h-9 w-9 shrink-0 place-items-center rounded-[11px] text-[17px]"
                    style={{
                        background:
                            'color-mix(in oklch, var(--category-hr) 18%, var(--card))',
                        color: 'var(--category-hr)',
                    }}
                >
                    📣
                </span>
                <div className="min-w-0">
                    <div className="text-[10px] font-bold uppercase tracking-[0.08em] text-category-hr">
                        {eyebrow}
                    </div>
                    <h2 className="mt-px truncate text-[16px] font-bold">{heading}</h2>
                </div>
                {total > 1 ? (
                    <div className="ml-auto flex items-center gap-2">
                        <span className="text-[11px] font-bold tabular-nums text-muted-foreground">
                            {Math.min(index, total - 1) + 1} / {total}
                        </span>
                        <div className="flex items-center gap-1.5">
                            {items.map((s, i) => (
                                <span
                                    key={s.id}
                                    className="h-1.5 rounded-full transition-all"
                                    style={{
                                        width: i === index ? 18 : 6,
                                        background:
                                            i === index
                                                ? 'var(--category-hr)'
                                                : 'color-mix(in oklch, var(--category-hr) 30%, var(--border))',
                                    }}
                                />
                            ))}
                        </div>
                        <button
                            type="button"
                            aria-label="Previous shout-out"
                            onClick={() => paging((index - 1 + total) % total)}
                            className="grid h-7 w-7 place-items-center rounded-lg border bg-card text-foreground transition-colors hover:bg-accent"
                            style={{
                                borderColor:
                                    'color-mix(in oklch, var(--category-hr) 28%, var(--border))',
                            }}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            aria-label="Next shout-out"
                            onClick={() => paging((index + 1) % total)}
                            className="grid h-7 w-7 place-items-center rounded-lg border bg-card text-foreground transition-colors hover:bg-accent"
                            style={{
                                borderColor:
                                    'color-mix(in oklch, var(--category-hr) 28%, var(--border))',
                            }}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                ) : null}
            </div>

            {/* quote */}
            <p className="relative mt-3 max-w-[760px] text-[14.5px] font-semibold leading-relaxed">
                <span className="text-category-hr">“</span>
                {current.message}
                <span className="text-category-hr">”</span>
            </p>

            {/* footer: giver + category + reactions + actions */}
            <div className="relative mt-4 flex flex-wrap items-center gap-3">
                <span
                    className="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-full text-[13px] font-bold text-white"
                    style={{ background: `oklch(0.62 0.17 ${otherHue})` }}
                >
                    {other.initials}
                </span>
                <div className="min-w-0">
                    <div className="text-[12.5px] font-bold">{other.name}</div>
                    <div className="text-[11px] text-muted-foreground">
                        {[other.role, timeAgoShort(current.created_at)]
                            .filter(Boolean)
                            .join(' · ')}
                    </div>
                </div>
                <span
                    className="rounded-full border bg-card px-2.5 py-1 text-[10.5px] font-bold text-category-hr"
                    style={{
                        borderColor:
                            'color-mix(in oklch, var(--category-hr) 26%, var(--border))',
                    }}
                >
                    {MY_HR_KUDOS_LABELS[current.category] ?? 'Recognition'}
                </span>

                <div className="ml-auto flex flex-wrap items-center gap-2.5">
                    <div className="flex gap-1.5">
                        {MY_HR_REACTIONS.map(({ key, emoji }) => {
                            const list = current.reactions[key];
                            const youReacted = list.some((r) => r.you);
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => react(key)}
                                    title={
                                        list.length
                                            ? `${emoji}  ${list
                                                  .map((r) => r.name)
                                                  .join(', ')}`
                                            : 'Be the first to react'
                                    }
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] font-semibold transition-colors',
                                        youReacted
                                            ? 'border-primary bg-accent text-primary'
                                            : 'border-border bg-card hover:bg-muted',
                                    )}
                                >
                                    {emoji} {list.length}
                                </button>
                            );
                        })}
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setThreadOpen((o) => !o);
                            setExpanded(false);
                        }}
                        className={cn(
                            'rounded-full border px-3 py-1.5 text-[12px] font-semibold transition-colors',
                            threadOpen
                                ? 'border-primary bg-accent text-primary'
                                : 'border-border bg-card hover:bg-muted',
                        )}
                    >
                        💬{' '}
                        {current.replies.length > 0
                            ? `${current.replies.length} ${current.replies.length === 1 ? 'reply' : 'replies'}`
                            : 'Reply'}
                    </button>
                    {canThanks ? (
                        <button
                            type="button"
                            onClick={sayThanks}
                            disabled={isThanked}
                            className={cn(
                                'rounded-[9px] px-3.5 py-1.5 text-[12px] font-bold transition-colors',
                                isThanked
                                    ? 'border border-status-success bg-status-success-bg text-status-success'
                                    : 'text-white hover:opacity-90',
                            )}
                            style={
                                isThanked
                                    ? undefined
                                    : { background: 'var(--category-hr)' }
                            }
                        >
                            {isThanked ? 'Thanks sent ✓' : '💛 Say thanks'}
                        </button>
                    ) : null}
                </div>
            </div>

            {/* reactor facepile */}
            {reactors.length > 0 ? (
                <div className="relative mt-3 flex items-center gap-2.5">
                    <div className="flex pl-1.5">
                        {reactors.slice(0, 4).map((r) => (
                            <span
                                key={r.you ? 'you' : r.id}
                                className="-ml-1.5 grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full border-2 border-card text-[9px] font-bold text-white"
                                style={{ background: avatarBg(r) }}
                            >
                                {r.initials}
                            </span>
                        ))}
                        {reactors.length > 4 ? (
                            <span className="-ml-1.5 grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full border-2 border-card bg-muted text-[9px] font-bold text-muted-foreground">
                                +{reactors.length - 4}
                            </span>
                        ) : null}
                    </div>
                    <span className="text-[12px] text-muted-foreground">
                        {reactSummary}
                    </span>
                </div>
            ) : null}

            {/* reply thread */}
            {threadOpen ? (
                <div
                    className="relative mt-3.5 border-t pt-3.5"
                    style={{
                        borderColor:
                            'color-mix(in oklch, var(--category-hr) 18%, var(--border))',
                    }}
                >
                    {hiddenCount > 0 ? (
                        <button
                            type="button"
                            onClick={() => setExpanded(true)}
                            className="mb-2.5 text-[12px] font-bold text-primary hover:underline"
                        >
                            View all {current.replies.length} replies ›
                        </button>
                    ) : null}
                    {current.replies.length === 0 ? (
                        <p className="mb-2.5 text-[12px] text-muted-foreground">
                            {canThanks
                                ? 'No replies yet — say thanks to start the conversation.'
                                : 'No replies yet — write the first message.'}
                        </p>
                    ) : (
                        <div className="mb-3 flex flex-col gap-2">
                            {visibleReplies.map((rp) => (
                                <div
                                    key={rp.id}
                                    className="flex items-start gap-2.5"
                                >
                                    <span
                                        className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white"
                                        style={{
                                            background: rp.you
                                                ? 'var(--primary)'
                                                : `oklch(0.62 0.17 ${otherHue})`,
                                        }}
                                    >
                                        {rp.initials}
                                    </span>
                                    <div
                                        className="min-w-0 flex-1 rounded-xl px-3 py-2"
                                        style={{
                                            background: rp.you
                                                ? 'var(--accent)'
                                                : 'color-mix(in oklch, var(--category-hr) 8%, var(--card))',
                                        }}
                                    >
                                        <div className="flex items-baseline gap-2">
                                            <span className="text-[11.5px] font-bold">
                                                {rp.you ? `You · ${me.firstName}` : rp.name}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                {timeAgoShort(rp.created_at) || 'just now'}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-[12.5px] leading-snug">
                                            {rp.body}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="flex items-center gap-2.5">
                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-[10px] font-bold text-white">
                            {me.initials}
                        </span>
                        <input
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    sendDraft();
                                }
                            }}
                            placeholder="Write a reply…"
                            className="min-w-0 flex-1 rounded-[10px] border border-border bg-card px-3 py-2 text-[12.5px] outline-none focus:border-primary"
                        />
                        <button
                            type="button"
                            onClick={sendDraft}
                            disabled={!draft.trim()}
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2 text-[12px] font-bold text-primary-foreground disabled:opacity-50"
                        >
                            <Send className="h-3.5 w-3.5" /> Send
                        </button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

/** Compact "time ago" — local to the spotlight to avoid importing Date logic
 *  into the band; mirrors `timeAgo` but kept terse for the meta line. */
function timeAgoShort(iso: string | null | undefined): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    const weeks = Math.floor(days / 7);
    if (weeks < 5) return `${weeks}w ago`;
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

export default MyHrShoutoutSpotlight;
