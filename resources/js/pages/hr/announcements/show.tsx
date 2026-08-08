/* eslint-disable no-restricted-syntax -- The detail hero, manager action bar and
 * acknowledgement roster are bespoke surfaces styled with semantic tokens. */
import {
    AnnouncementWizard,
    type AnnouncementSegments,
    type AnnouncementWizardInitial,
} from '@/components/hr/announcement-wizard';
import PageShell from '@/components/page-shell';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Archive,
    BellRing,
    CalendarDays,
    CheckCheck,
    CheckCircle2,
    Eye,
    FileDown,
    FileText,
    Info,
    Megaphone,
    MessageSquare,
    Paperclip,
    Pencil,
    Pin,
    Send,
    Users,
} from 'lucide-react';
import { useState, type CSSProperties } from 'react';
import { toast } from 'sonner';

type Priority = 'low' | 'normal' | 'high' | 'urgent';

type Attachment = {
    id: number;
    name: string;
    size: number;
    is_image: boolean;
    url: string;
};
type AckRow = {
    user: { id: number; name: string } | null;
    acknowledged_at: string | null;
};
type RosterRow = {
    id: number;
    name: string;
    role: string;
    site: string;
    status: 'acknowledged' | 'reminded' | 'outstanding';
    acknowledged_at: string | null;
};

type Announcement = {
    id: number;
    title: string;
    content: string;
    priority: Priority;
    status: string;
    is_pinned: boolean;
    requires_acknowledgement: boolean;
    audience: string;
    audience_size: number;
    targets: { type: string; value: string | null }[];
    recurrence: string | null;
    published_at: string | null;
    expires_at: string | null;
    ack_deadline: string | null;
    creator: { id: number; name: string } | null;
    acknowledgements: AckRow[];
    attachments: Attachment[];
};

type Tracking = {
    total: number;
    acknowledged: number;
    outstanding: number;
    ack_pct: number;
    roster: RosterRow[];
} | null;

type ReplyItem = {
    id: number;
    user_name: string;
    body: string;
    created_at: string | null;
};
type Reactions = { counts: Record<string, number>; mine: string[] };

type Props = {
    announcement: Announcement;
    tracking: Tracking;
    userAcknowledged: boolean;
    segments: AnnouncementSegments | null;
    reactions: Reactions;
    replies: ReplyItem[];
    reactionEmojis: string[];
    can: { manage: boolean; react: boolean };
};

const EMOJI: Record<string, string> = { heart: '❤️', party: '🎉', hands: '🙌' };

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 60%, color-mix(in oklch, var(--primary) 92%, white 6%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

const PRIORITY_META: Record<
    Priority,
    { label: string; variant: StatusVariant; icon: typeof Info }
> = {
    low: { label: 'Low', variant: 'neutral', icon: Info },
    normal: { label: 'Normal', variant: 'info', icon: Info },
    high: { label: 'High', variant: 'warning', icon: AlertTriangle },
    urgent: { label: 'Urgent', variant: 'critical', icon: AlertCircle },
};

const STATUS_VARIANT: Record<string, StatusVariant> = {
    published: 'success',
    scheduled: 'info',
    draft: 'neutral',
    archived: 'neutral',
};

function fmtDateTime(value?: string | null) {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function AnnouncementShow({
    announcement,
    tracking,
    userAcknowledged,
    segments,
    reactions,
    replies,
    reactionEmojis,
    can,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [staffView, setStaffView] = useState(false);
    const replyForm = useForm({
        subject_type: 'announcement',
        subject_id: announcement.id,
        body: '',
    });

    const react = (emoji: string) =>
        router.post(
            '/hr/feed/react',
            {
                subject_type: 'announcement',
                subject_id: announcement.id,
                emoji,
            },
            { preserveScroll: true },
        );
    const submitReply = () => {
        if (!replyForm.data.body.trim()) return;
        replyForm.post('/hr/feed/reply', {
            preserveScroll: true,
            onSuccess: () => replyForm.setData('body', ''),
        });
    };
    const pm = PRIORITY_META[announcement.priority] ?? PRIORITY_META.normal;
    const PIcon = pm.icon;

    const breadcrumbs = [
        { title: 'HR', href: '/hr' },
        { title: 'Announcements', href: '/hr/announcements' },
        {
            title: announcement.title,
            href: `/hr/announcements/${announcement.id}`,
        },
    ];

    const post = (url: string, body: Record<string, unknown>, msg?: string) =>
        router.post(url, body as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            onSuccess: () => msg && toast.success(msg),
        });

    const initial: { id: number } & AnnouncementWizardInitial = {
        id: announcement.id,
        title: announcement.title,
        content: announcement.content,
        priority: announcement.priority,
        status: announcement.status,
        targets: announcement.targets as AnnouncementWizardInitial['targets'],
        recurrence: announcement.recurrence,
        published_at: announcement.published_at,
        expires_at: announcement.expires_at,
        ack_deadline: announcement.ack_deadline,
        is_pinned: announcement.is_pinned,
        requires_acknowledgement: announcement.requires_acknowledgement,
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={announcement.title} />
            <PageShell>
                {/* hero header */}
                <div
                    style={HERO_STYLE}
                    className="relative overflow-hidden rounded-3xl p-7 text-primary-foreground"
                >
                    <div className="pointer-events-none absolute -top-20 right-[20%] h-56 w-56 rounded-full bg-white/[0.05]" />
                    <div className="relative flex flex-wrap items-start justify-between gap-5">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {announcement.is_pinned && (
                                    <Pin
                                        className="h-4 w-4"
                                        style={{ color: 'var(--hr-amber)' }}
                                    />
                                )}
                                <StatusBadge variant={pm.variant} size="sm">
                                    <PIcon className="h-3 w-3" /> {pm.label}
                                </StatusBadge>
                                <StatusBadge
                                    variant={
                                        STATUS_VARIANT[announcement.status] ??
                                        'neutral'
                                    }
                                    size="sm"
                                >
                                    {announcement.status}
                                </StatusBadge>
                                {announcement.requires_acknowledgement && (
                                    <span className="inline-flex items-center gap-1 rounded-full border border-white/30 px-2 py-0.5 text-[10px] font-semibold">
                                        <CheckCheck className="h-3 w-3" />{' '}
                                        Requires ack
                                    </span>
                                )}
                            </div>
                            <h1 className="mt-3 text-2xl font-bold tracking-tight">
                                {announcement.title}
                            </h1>
                            <p className="mt-2 flex flex-wrap items-center gap-2.5 text-[12.5px] font-medium text-white/80">
                                <span className="inline-flex items-center gap-1.5">
                                    <Users className="h-3.5 w-3.5" />
                                    {announcement.audience} ·{' '}
                                    {announcement.audience_size} recipients
                                </span>
                                <span className="opacity-40">·</span>
                                <span className="inline-flex items-center gap-1.5">
                                    <CalendarDays className="h-3.5 w-3.5" />
                                    {fmtDateTime(announcement.published_at)}
                                </span>
                                <span className="opacity-40">·</span>
                                <span>
                                    by {announcement.creator?.name ?? 'Unknown'}
                                </span>
                            </p>
                        </div>

                        {can.manage && (
                            <div className="flex flex-wrap gap-2">
                                <HeroBtn
                                    icon={Pencil}
                                    label="Edit"
                                    onClick={() => setEditOpen(true)}
                                />
                                <HeroBtn
                                    icon={Pin}
                                    label={
                                        announcement.is_pinned ? 'Unpin' : 'Pin'
                                    }
                                    onClick={() =>
                                        post(
                                            '/hr/announcements/bulk',
                                            {
                                                action: announcement.is_pinned
                                                    ? 'unpin'
                                                    : 'pin',
                                                ids: [announcement.id],
                                            },
                                            announcement.is_pinned
                                                ? 'Unpinned'
                                                : 'Pinned',
                                        )
                                    }
                                />
                                {announcement.requires_acknowledgement && (
                                    <HeroBtn
                                        icon={BellRing}
                                        label="Remind"
                                        onClick={() =>
                                            post(
                                                `/hr/announcements/${announcement.id}/remind`,
                                                {},
                                                'Reminders sent',
                                            )
                                        }
                                    />
                                )}
                                <HeroBtn
                                    icon={Archive}
                                    label="Archive"
                                    onClick={() =>
                                        post(
                                            '/hr/announcements/bulk',
                                            {
                                                action: 'archive',
                                                ids: [announcement.id],
                                            },
                                            'Archived',
                                        )
                                    }
                                />
                            </div>
                        )}
                    </div>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_320px]">
                    {/* body */}
                    <div className="flex flex-col gap-4">
                        <div className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2 text-sm font-bold text-muted-foreground">
                                    <Megaphone className="h-4 w-4 text-primary" />{' '}
                                    Announcement
                                </div>
                                <button
                                    onClick={() => setStaffView((v) => !v)}
                                    className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1 text-xs font-semibold hover:bg-accent"
                                >
                                    <Eye className="h-3.5 w-3.5" />{' '}
                                    {staffView
                                        ? 'Manager view'
                                        : 'View as staff'}
                                </button>
                            </div>
                            <div className="mt-4 text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                {announcement.content}
                            </div>

                            {announcement.attachments.length > 0 && (
                                <div className="mt-5 border-t border-border pt-4">
                                    <div className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        <Paperclip className="h-3.5 w-3.5" />{' '}
                                        Attachments
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {announcement.attachments.map((att) => (
                                            <a
                                                key={att.id}
                                                href={att.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3 hover:border-primary/40"
                                            >
                                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                                    <FileText className="h-4 w-4" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-semibold">
                                                        {att.name}
                                                    </span>
                                                    <span className="block text-[11px] text-muted-foreground">
                                                        {Math.round(
                                                            att.size / 1024,
                                                        )}{' '}
                                                        KB
                                                    </span>
                                                </span>
                                                <FileDown className="ml-auto h-4 w-4 text-muted-foreground" />
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* acknowledge CTA */}
                        {announcement.requires_acknowledgement &&
                            !staffView && (
                                <div className="flex items-center justify-between rounded-2xl border border-border bg-card p-5 shadow-sm">
                                    <div>
                                        <p className="font-semibold">
                                            Acknowledgement required
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Confirm you have read and understood
                                            this announcement.
                                        </p>
                                    </div>
                                    {userAcknowledged ? (
                                        <StatusBadge variant="success">
                                            <CheckCircle2 className="h-3.5 w-3.5" />{' '}
                                            Acknowledged
                                        </StatusBadge>
                                    ) : (
                                        <button
                                            onClick={() =>
                                                post(
                                                    `/hr/announcements/${announcement.id}/acknowledge`,
                                                    {},
                                                    'Acknowledged',
                                                )
                                            }
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                                        >
                                            <CheckCircle2 className="h-4 w-4" />{' '}
                                            Acknowledge
                                        </button>
                                    )}
                                </div>
                            )}

                        {/* reaction + reply thread — same polymorphic rows as the Community feed */}
                        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2 text-sm font-bold text-muted-foreground">
                                    <MessageSquare className="h-4 w-4 text-primary" />{' '}
                                    Discussion
                                </div>
                                <a
                                    href="/hr/feed"
                                    className="text-xs font-semibold text-primary hover:underline"
                                >
                                    Open in feed
                                </a>
                            </div>

                            {/* reactions */}
                            <div className="mt-3 flex flex-wrap gap-2">
                                {reactionEmojis.map((emoji) => {
                                    const mine =
                                        reactions.mine?.includes(emoji);
                                    const count =
                                        reactions.counts?.[emoji] ?? 0;
                                    return (
                                        <button
                                            key={emoji}
                                            onClick={() =>
                                                can.react && react(emoji)
                                            }
                                            disabled={!can.react}
                                            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-semibold transition-colors ${mine ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card hover:border-primary/50'} ${can.react ? '' : 'cursor-default opacity-70'}`}
                                        >
                                            <span>{EMOJI[emoji] ?? emoji}</span>
                                            {count > 0 && (
                                                <span className="tabular-nums">
                                                    {count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* replies */}
                            {replies.length > 0 && (
                                <div className="mt-4 flex flex-col gap-3 border-t border-border pt-4">
                                    {replies.map((r) => (
                                        <div
                                            key={r.id}
                                            className="flex gap-2.5"
                                        >
                                            <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-accent text-[11px] font-bold text-primary">
                                                {r.user_name
                                                    .split(' ')
                                                    .map((w) => w[0])
                                                    .slice(0, 2)
                                                    .join('')}
                                            </span>
                                            <div className="min-w-0">
                                                <div className="text-[12.5px]">
                                                    <span className="font-semibold">
                                                        {r.user_name}
                                                    </span>
                                                    <span className="ml-2 text-[11px] text-muted-foreground">
                                                        {r.created_at}
                                                    </span>
                                                </div>
                                                <div className="text-sm text-foreground">
                                                    {r.body}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* composer */}
                            {can.react && (
                                <div className="mt-4 flex items-center gap-2 border-t border-border pt-4">
                                    <input
                                        value={replyForm.data.body}
                                        onChange={(e) =>
                                            replyForm.setData(
                                                'body',
                                                e.target.value,
                                            )
                                        }
                                        onKeyDown={(e) =>
                                            e.key === 'Enter' && submitReply()
                                        }
                                        maxLength={2000}
                                        placeholder="Write a reply…"
                                        className="h-9 flex-1 rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    />
                                    <button
                                        onClick={submitReply}
                                        disabled={
                                            replyForm.processing ||
                                            !replyForm.data.body.trim()
                                        }
                                        className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                    >
                                        <Send className="h-4 w-4" /> Reply
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* roster sidebar (managers) */}
                    {can.manage && tracking && (
                        <div className="flex flex-col gap-4">
                            <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                                <div className="flex items-center justify-between">
                                    <div className="text-[13px] font-bold">
                                        Acknowledgement
                                    </div>
                                    <button
                                        onClick={() =>
                                            window.open(
                                                `/hr/announcements/${announcement.id}/tracking/export`,
                                                '_blank',
                                            )
                                        }
                                        className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                                    >
                                        <FileDown className="h-3.5 w-3.5" />{' '}
                                        Export
                                    </button>
                                </div>
                                <div className="mt-3 flex items-center gap-4">
                                    <div
                                        className="relative grid h-20 w-20 place-items-center rounded-full"
                                        style={{
                                            background: `conic-gradient(var(--status-success) 0% ${tracking.ack_pct}%, var(--muted) ${tracking.ack_pct}% 100%)`,
                                        }}
                                    >
                                        <div className="absolute inset-2.5 grid place-items-center rounded-full bg-card">
                                            <span className="text-lg font-bold">
                                                {tracking.ack_pct}%
                                            </span>
                                        </div>
                                    </div>
                                    <div className="text-xs leading-relaxed">
                                        <div>
                                            <b>{tracking.acknowledged}</b>{' '}
                                            acknowledged
                                        </div>
                                        <div
                                            style={{ color: 'var(--hr-amber)' }}
                                        >
                                            <b>{tracking.outstanding}</b>{' '}
                                            outstanding
                                        </div>
                                        <div className="text-muted-foreground">
                                            {tracking.total} recipients
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                                <div className="border-b border-border px-4 py-3 text-[13px] font-bold">
                                    Recipients
                                </div>
                                <div className="max-h-96 overflow-y-auto">
                                    {tracking.roster.map((p) => (
                                        <div
                                            key={p.id}
                                            className="flex items-center gap-2.5 border-t border-border px-4 py-2.5 first:border-t-0"
                                        >
                                            <span className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-accent text-[11px] font-bold text-primary">
                                                {p.name
                                                    .split(' ')
                                                    .map((w) => w[0])
                                                    .slice(0, 2)
                                                    .join('')}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[12.5px] font-semibold">
                                                    {p.name}
                                                </span>
                                                <span className="block truncate text-[11px] text-muted-foreground">
                                                    {p.role} · {p.site}
                                                </span>
                                            </span>
                                            {p.status === 'acknowledged' ? (
                                                <StatusBadge
                                                    variant="success"
                                                    size="sm"
                                                >
                                                    Acked
                                                </StatusBadge>
                                            ) : (
                                                <button
                                                    onClick={() =>
                                                        post(
                                                            `/hr/announcements/${announcement.id}/remind`,
                                                            {
                                                                user_ids: [
                                                                    p.id,
                                                                ],
                                                            },
                                                            'Reminder sent',
                                                        )
                                                    }
                                                    className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-2 py-0.5 text-[10px] font-semibold hover:bg-accent"
                                                >
                                                    <BellRing className="h-3 w-3" />{' '}
                                                    Remind
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </PageShell>

            {can.manage && segments && (
                <AnnouncementWizard
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    segments={segments}
                    announcementId={announcement.id}
                    initial={initial}
                    onSuccess={() => router.reload()}
                />
            )}
        </AppLayout>
    );
}

function HeroBtn({
    icon: Icon,
    label,
    onClick,
}: {
    icon: typeof Info;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3 py-2 text-[12.5px] font-semibold hover:bg-white/25"
        >
            <Icon className="h-4 w-4" /> {label}
        </button>
    );
}
