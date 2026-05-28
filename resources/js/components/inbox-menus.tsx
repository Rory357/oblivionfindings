import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link, usePage, router } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    BellOff,
    Building2,
    ClipboardList,
    Megaphone,
    ShieldAlert,
    TriangleAlert,
    Users,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type InboxPayload = {
    notifications: {
        unread_count: number;
        items: Array<{
            id: string;
            type: string;
            data: any;
            read_at: string | null;
            acknowledged_at: string | null;
            escalation_count: number | null;
            created_at: string | null;
        }>;
    };
    announcements: {
        unread_count: number;
        items: Array<{
            id: number;
            title: string;
            body: string | null;
            author: { id: number; name: string } | null;
            read_at: string | null;
            created_at: string | null;
        }>;
    };
};

function UnreadBadge({ count }: { count: number }) {
    if (!count) return null;
    return (
        <span className="absolute -right-1 -top-1 inline-flex min-w-[18px] items-center justify-center rounded-full bg-status-critical px-1 text-[10px] font-bold leading-[18px] text-white shadow-sm">
            {count > 99 ? '99+' : count}
        </span>
    );
}

function notificationTitle(n: { type: string; data: any }) {
    const t = n?.data?.title || n?.data?.subject;
    if (typeof t === 'string' && t.trim() !== '') return t;
    const parts = String(n.type ?? '').split('\\');
    return parts[parts.length - 1] || 'Notification';
}

function notificationBody(n: { data: any }) {
    const msg = n?.data?.message || n?.data?.body || n?.data?.text;
    return typeof msg === 'string' ? msg : null;
}

function notificationContext(n: { data: any }): Array<{ label: string; value: string }> {
    const ctx = n?.data?.context;
    if (!ctx || typeof ctx !== 'object') return [];
    return Object.entries(ctx)
        .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
        .map(([k, v]) => ({ label: k, value: String(v) }));
}

const MODULE_DOT_COLOURS: Record<string, string> = {
    operations: 'bg-category-ops',
    hr: 'bg-category-hr',
    governance: 'bg-category-governance',
    sites: 'bg-category-sites',
    incidents: 'bg-category-incidents',
    fleet: 'bg-category-fleet',
    system: 'bg-muted-foreground',
};

function getModuleDotClass(module?: string): string {
    const key = (module ?? 'system').toLowerCase();
    return MODULE_DOT_COLOURS[key] ?? MODULE_DOT_COLOURS.system;
}

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '';
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    const diff = now - then;
    const seconds = Math.floor(diff / 1000);
    if (seconds < 60) return 'now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d`;
    return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function isToday(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const d = new Date(dateStr);
    const now = new Date();
    return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
}

export default function InboxMenus() {
    const inbox = (usePage().props as any).inbox as InboxPayload | null;

    const [openNotifId, setOpenNotifId] = useState<string | null>(null);
    const [openAnnouncementId, setOpenAnnouncementId] = useState<number | null>(null);

    const openNotification = useMemo(
        () => inbox?.notifications.items.find((n) => n.id === openNotifId) ?? null,
        [inbox?.notifications.items, openNotifId],
    );
    const openAnnouncement = useMemo(
        () => inbox?.announcements.items.find((a) => a.id === openAnnouncementId) ?? null,
        [inbox?.announcements.items, openAnnouncementId],
    );

    if (!inbox) return null;

    const mustAckBeforeClose = !!openNotification?.data?.must_ack_before_close && !!openNotification?.data?.ack_required && !openNotification?.acknowledged_at;

    const reloadInbox = () => {
        router.reload({ only: ['inbox'] });
    };

    const markNotificationRead = (id: string) => {
        router.post(`/inbox/notifications/${id}/read`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

    const acknowledgeNotification = (id: string) => {
        router.post(`/inbox/notifications/${id}/acknowledge`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

    const markAnnouncementRead = (id: number) => {
        router.post(`/inbox/announcements/${id}/read`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

    const unreadNotifications = inbox.notifications?.unread_count ?? 0;
    const unreadAnnouncements = inbox.announcements?.unread_count ?? 0;

    return (
        <div className="flex items-center gap-1">
            {/* Notifications */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative h-9 w-9"
                        aria-label="Notifications"
                    >
                        <Bell className="h-5 w-5" />
                        <UnreadBadge count={unreadNotifications} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-96">
                    <div className="flex items-center justify-between px-1">
                        <DropdownMenuLabel className="text-sm font-semibold">Notifications</DropdownMenuLabel>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Mark all notifications read"
                            className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                            disabled={!unreadNotifications}
                            onClick={() =>
                                router.post(
                                    '/inbox/notifications/read-all',
                                    {},
                                    { preserveScroll: true, onSuccess: reloadInbox },
                                )
                            }
                        >
                            Mark all read
                        </Button>
                    </div>
                    <DropdownMenuSeparator />

                    {inbox.notifications.items.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-4 py-10">
                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-muted/60">
                                <BellOff className="h-7 w-7 text-muted-foreground/50" />
                            </div>
                            <p className="text-sm font-medium text-muted-foreground">No notifications yet</p>
                            <p className="mt-1 text-xs text-muted-foreground/70">You're all caught up</p>
                        </div>
                    ) : (
                        (() => {
                            const visible = inbox.notifications.items.slice(0, 10);
                            const todayItems = visible.filter((n) => isToday(n.created_at));
                            const earlierItems = visible.filter((n) => !isToday(n.created_at));

                            const renderItem = (n: (typeof visible)[0]) => {
                                const title = notificationTitle(n);
                                const isUnread = !n.read_at;
                                const dotClass = getModuleDotClass(n.data?.module);
                                return (
                                    <DropdownMenuItem
                                        key={n.id}
                                        className="cursor-pointer rounded-md px-3 py-2.5 focus:bg-accent"
                                        onSelect={(e) => {
                                            e.preventDefault();
                                            setOpenAnnouncementId(null);
                                            setOpenNotifId(n.id);
                                            if (isUnread) markNotificationRead(n.id);
                                        }}
                                    >
                                        <div className="flex w-full items-center gap-2.5">
                                            <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${dotClass}`} />
                                            <span className={`min-w-0 flex-1 truncate text-sm ${isUnread ? 'font-semibold text-foreground' : 'font-normal text-muted-foreground'}`}>
                                                {title}
                                            </span>
                                            <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground/70">
                                                {relativeTime(n.created_at)}
                                            </span>
                                            {isUnread && (
                                                <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                            )}
                                        </div>
                                    </DropdownMenuItem>
                                );
                            };

                            return (
                                <div className="max-h-[400px] overflow-y-auto">
                                    {todayItems.length > 0 && (
                                        <>
                                            <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/60">Today</div>
                                            {todayItems.map(renderItem)}
                                        </>
                                    )}
                                    {earlierItems.length > 0 && (
                                        <>
                                            {todayItems.length > 0 && <DropdownMenuSeparator className="my-1" />}
                                            <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/60">Earlier</div>
                                            {earlierItems.map(renderItem)}
                                        </>
                                    )}
                                </div>
                            );
                        })()
                    )}

                    {/* Always show View All link at the bottom */}
                    <DropdownMenuSeparator />
                    <div className="p-1.5">
                        <Link
                            href="/notifications"
                            className="flex w-full items-center justify-center gap-1.5 rounded-md px-2 py-2 text-sm font-medium text-primary transition-colors hover:bg-primary/10 dark:text-primary dark:hover:bg-primary/30"
                        >
                            View All Notifications
                            <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Announcements */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative h-9 w-9"
                        aria-label="Announcements"
                    >
                        <Megaphone className="h-5 w-5" />
                        <UnreadBadge count={unreadAnnouncements} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-96">
                    <div className="flex items-center justify-between px-1">
                        <DropdownMenuLabel className="text-sm font-semibold">Announcements</DropdownMenuLabel>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                            disabled={!unreadAnnouncements}
                            onClick={() =>
                                router.post(
                                    '/inbox/announcements/read-all',
                                    {},
                                    { preserveScroll: true, onSuccess: reloadInbox },
                                )
                            }
                        >
                            Mark all read
                        </Button>
                    </div>
                    <DropdownMenuSeparator />

                    {inbox.announcements.items.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-4 py-10">
                            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-muted/60">
                                <Megaphone className="h-7 w-7 text-muted-foreground/50" />
                            </div>
                            <p className="text-sm font-medium text-muted-foreground">No announcements yet</p>
                            <p className="mt-1 text-xs text-muted-foreground/70">Check back later</p>
                        </div>
                    ) : (
                        <div className="max-h-[400px] overflow-y-auto">
                            {inbox.announcements.items.map((a) => {
                                const isUnread = !a.read_at;
                                return (
                                    <DropdownMenuItem
                                        key={a.id}
                                        className="cursor-pointer rounded-md px-3 py-2.5 focus:bg-accent"
                                        onSelect={(e) => {
                                            e.preventDefault();
                                            setOpenNotifId(null);
                                            setOpenAnnouncementId(a.id);
                                            if (isUnread) markAnnouncementRead(a.id);
                                        }}
                                    >
                                        <div className="flex w-full flex-col gap-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className={`min-w-0 flex-1 truncate text-sm ${isUnread ? 'font-semibold' : 'font-medium text-muted-foreground'}`}>
                                                    {a.title}
                                                </span>
                                                {isUnread && (
                                                    <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-status-info" />
                                                )}
                                            </div>
                                            {a.body && (
                                                <span className="line-clamp-1 text-xs text-muted-foreground/80">
                                                    {a.body}
                                                </span>
                                            )}
                                            <div className="flex items-center justify-between">
                                                {a.author && (
                                                    <span className="text-[11px] text-muted-foreground/60">
                                                        {a.author.name}
                                                    </span>
                                                )}
                                                {a.created_at && (
                                                    <span className="text-[11px] tabular-nums text-muted-foreground/60">
                                                        {relativeTime(a.created_at)}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </DropdownMenuItem>
                                );
                            })}
                        </div>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Notification modal */}
            <Dialog open={!!openNotification} onOpenChange={(v) => {
                if (!v) {
                    if (mustAckBeforeClose) return;
                    setOpenNotifId(null);
                }
            }}>
                <DialogContent
                    className="sm:max-w-lg"
                    onEscapeKeyDown={(e) => {
                        if (mustAckBeforeClose) e.preventDefault();
                    }}
                    onPointerDownOutside={(e) => {
                        if (mustAckBeforeClose) e.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {openNotification ? notificationTitle(openNotification) : 'Notification'}
                        </DialogTitle>
                    </DialogHeader>

                    {openNotification && (
                        <div className="space-y-3">
                            {openNotification.created_at && (
                                <div className="text-xs text-muted-foreground">
                                    {new Date(openNotification.created_at).toLocaleString()}
                                </div>
                            )}
                            {notificationBody(openNotification) ? (
                                <div className="whitespace-pre-wrap text-sm">
                                    {notificationBody(openNotification)}
                                </div>
                            ) : (
                                <div className="text-sm text-muted-foreground">
                                    No message content.
                                </div>
                            )}

                            {notificationContext(openNotification).length > 0 && (
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground">
                                        Details
                                    </div>
                                    <div className="space-y-1">
                                        {notificationContext(openNotification).map((row) => (
                                            <div
                                                key={row.label}
                                                className="grid grid-cols-3 gap-2 text-sm"
                                            >
                                                <div className="text-xs font-medium text-muted-foreground">
                                                    {row.label}
                                                </div>
                                                <div className="col-span-2 text-xs">
                                                    {row.value}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        {openNotification?.data?.ack_required && !openNotification?.acknowledged_at && (
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => {
                                    if (openNotification?.id) {
                                        acknowledgeNotification(openNotification.id);
                                    }
                                }}
                            >
                                Acknowledge
                            </Button>
                        )}
                        {(openNotification?.data?.url || openNotification?.data?.action_url) && (
                            <Button
                                type="button"
                                onClick={() => {
                                    const url = openNotification?.data?.url || openNotification?.data?.action_url;
                                    if (typeof url === 'string' && url) {
                                        router.visit(url);
                                    }
                                }}
                            >
                                Open
                            </Button>
                        )}
                        <Button type="button" variant="outline" disabled={mustAckBeforeClose} onClick={() => {
                                if (mustAckBeforeClose) return;
                                setOpenNotifId(null);
                            }}>
                            Close
                        </Button>
                        {mustAckBeforeClose && (
                            <div className="w-full text-xs text-muted-foreground">
                                This notification requires acknowledgement before you can close it.
                            </div>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Announcement modal */}
            <Dialog open={!!openAnnouncement} onOpenChange={(v) => !v && setOpenAnnouncementId(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{openAnnouncement ? openAnnouncement.title : 'Announcement'}</DialogTitle>
                    </DialogHeader>

                    {openAnnouncement && (
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                {openAnnouncement.author ? (
                                    <div className="text-xs text-muted-foreground">
                                        From {openAnnouncement.author.name}
                                    </div>
                                ) : (
                                    <div />
                                )}
                                {openAnnouncement.created_at && (
                                    <div className="text-xs text-muted-foreground">
                                        {new Date(openAnnouncement.created_at).toLocaleString()}
                                    </div>
                                )}
                            </div>

                            {openAnnouncement.body ? (
                                <div className="whitespace-pre-wrap text-sm">{openAnnouncement.body}</div>
                            ) : (
                                <div className="text-sm text-muted-foreground">No content.</div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpenAnnouncementId(null)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
